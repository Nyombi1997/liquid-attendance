<?php
declare(strict_types=1);

/*
 * Tâches planifiées du Parc automobile, lancées par le worker toutes les
 * FLEET_TASKS_INTERVAL secondes (60 par défaut) :
 *   1. rappel avant le départ (paramètre fleet_reminder_minutes, 60 min) au
 *      demandeur et au chauffeur, une seule fois par demande ;
 *   2. alertes d'expiration (assurance, contrôle technique, permis) aux
 *      gestionnaires, fleet_doc_alert_days jours avant (30), une fois par document ;
 *   3. envoi des e-mails des notifications en attente (SMTP de .env ; sans SMTP,
 *      rien n'est envoyé), adresse du compte ou, à défaut, de sa fiche employé ;
 *   4. envoi des e-mails adressés directement à une adresse (mail_outbox :
 *      bienvenue, disponibilité d'un employé sans compte, véhicule attribué…) ;
 *   0. (Présence) fin d'une disponibilité saisie (congé, mission, télétravail) :
 *      message aux RH et à la personne qui l'avait saisie, une fois par période.
 *   Trois essais au plus par e-mail. Toute la plateforme passe par ici : avec
 *   FLEET_TASKS_INTERVAL=0, aucun e-mail n'est envoyé.
 * Si une migration n'est pas appliquée : aucune tâche, message et code de sortie 3.
 *   docker compose exec worker php bin/fleet-tasks.php
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Mailer;
use App\Core\Migrations;
use App\Modules\Attendance\Services\StatusNotify;
use App\Modules\Fleet\Services\Fleet;
use App\Modules\Fleet\Services\FleetNotify;
use App\Modules\Platform\Services\Notifications;

// Base en retard sur le code (migration non appliquée) : rien n'est lancé, un
// message explicite au lieu d'erreurs SQL. Code de sortie 3 (le worker ne répète
// le message que s'il change, ou une fois par heure).
if (($warning = Migrations::warning()) !== null) {
    fwrite(STDERR, '[flotte] tâches suspendues : ' . $warning . "\n");
    exit(3);
}

$settings = Fleet::settings();
$done = ['fins de statut' => 0, 'rappels' => 0, 'alertes' => 0, 'e-mails' => 0, 'échecs' => 0];

// 0. Fin des disponibilités saisies (date de fin passée) : RH et auteur prévenus
$done['fins de statut'] = StatusNotify::ended();

// 1. Rappels avant le départ
$minutes = (int) $settings['fleet_reminder_minutes'];
if ($minutes > 0) {
    foreach (Database::all(
        "SELECT id FROM fleet_requests WHERE status = 'affectee' AND reminder_sent_at IS NULL
            AND depart_at > NOW() AND depart_at <= NOW() + INTERVAL ? MINUTE",
        [$minutes]
    ) as $row) {
        // Une seule fois, même si deux exécutions se croisaient
        if (Database::run('UPDATE fleet_requests SET reminder_sent_at = NOW() WHERE id = ? AND reminder_sent_at IS NULL', [$row['id']]) !== 1) {
            continue;
        }
        $req = Fleet::request((int) $row['id']);
        $left = max(1, (int) round((strtotime($req['depart_at']) - time()) / 60));
        FleetNotify::reminder($req, $left);
        Fleet::event((int) $req['id'], 'rappel', 'départ dans ' . $left . ' min');
        $done['rappels']++;
    }
}

// 2. Alertes d'expiration : documents des véhicules (le plus récent de chaque type) et permis
$days = (int) $settings['fleet_doc_alert_days'];
$managers = Fleet::usersWithRole('gestionnaire');
foreach (Database::all(
    "SELECT d.id, d.kind, d.expires_on, v.id vehicle_id, v.plate
       FROM fleet_vehicle_documents d
       JOIN (SELECT vehicle_id, kind, MAX(id) id FROM fleet_vehicle_documents
              WHERE kind IN ('assurance', 'controle_technique') GROUP BY vehicle_id, kind) last ON last.id = d.id
       JOIN fleet_vehicles v ON v.id = d.vehicle_id AND v.active = 1
      WHERE d.alerted_at IS NULL AND d.expires_on IS NOT NULL AND d.expires_on <= CURDATE() + INTERVAL ? DAY",
    [$days]
) as $d) {
    $label = Fleet::DOC_KINDS[$d['kind']];
    $left = (int) floor((strtotime($d['expires_on']) - strtotime(date('Y-m-d'))) / 86400);
    Notifications::send($managers, 'document_expire',
        $label . ' de ' . $d['plate'] . ($left < 0 ? ' expiré(e)' : ' : expiration dans ' . $left . ' jour(s)'),
        $label . ($left < 0 ? ' expiré(e) depuis le ' : ' valable jusqu\'au ') . date('d/m/Y', strtotime($d['expires_on']))
            . '. Ajoutez le nouveau document sur la fiche du véhicule.',
        '/flotte/vehicules/' . (int) $d['vehicle_id']);
    Database::run('UPDATE fleet_vehicle_documents SET alerted_at = NOW() WHERE id = ?', [$d['id']]);
    $done['alertes']++;
}
foreach (Database::all(
    'SELECT dr.id, dr.employee_id, dr.license_expires, e.name FROM fleet_drivers dr JOIN employees e ON e.id = dr.employee_id
      WHERE dr.active = 1 AND dr.license_alerted_at IS NULL AND dr.license_expires IS NOT NULL
        AND dr.license_expires <= CURDATE() + INTERVAL ? DAY',
    [$days]
) as $d) {
    $left = (int) floor((strtotime($d['license_expires']) - strtotime(date('Y-m-d'))) / 86400);
    $text = 'Permis ' . ($left < 0 ? 'expiré depuis le ' : 'valable jusqu\'au ') . date('d/m/Y', strtotime($d['license_expires'])) . '.';
    Notifications::send($managers, 'permis_expire', 'Permis de ' . $d['name'] . ($left < 0 ? ' expiré' : ' : expiration dans ' . $left . ' jour(s)'),
        $text . ' Mettez à jour la fiche du chauffeur.', '/flotte/chauffeurs');
    Notifications::send([Fleet::userOf((int) $d['employee_id'])], 'permis_expire', 'Votre permis de conduire ' . ($left < 0 ? 'a expiré' : 'expire bientôt'),
        $text . ' Transmettez le nouveau permis au gestionnaire de flotte.', '/flotte/missions');
    Database::run('UPDATE fleet_drivers SET license_alerted_at = NOW() WHERE id = ?', [$d['id']]);
    $done['alertes']++;
}

// 3. E-mails en attente
$pending = Database::all(
    "SELECT n.id, n.kind, n.title, n.body, n.url, n.email_attempts, COALESCE(NULLIF(u.email, ''), NULLIF(e.email, '')) email
       FROM notifications n JOIN users u ON u.id = n.user_id LEFT JOIN employees e ON e.id = u.employee_id
      WHERE n.email_status = 'en_attente' ORDER BY n.id LIMIT 50"
);
if ($pending && !Mailer::configured()) {
    // SMTP retiré de la configuration : plus d'envoi
    Database::run("UPDATE notifications SET email_status = 'aucun' WHERE email_status = 'en_attente'");
    $pending = [];
}
$mailer = new Mailer();
$base = rtrim((string) Config::get('app_url'), '/');
foreach ($pending as $n) {
    // Logo, titre MAIL_NAME et signature ajoutés par Mailer ; pas de titre au-dessus
    // d'un message rédigé comme une lettre (« Bonjour …, »)
    $letter = in_array($n['kind'], Notifications::LETTER_KINDS, true);
    $error = $n['email']
        ? $mailer->send($n['email'], $n['title'], (string) ($n['body'] ?? ''), $letter ? null : $n['title'],
                        $n['url'] ? $base . $n['url'] : null)
        : 'adresse e-mail absente';
    if ($error === null) {
        Database::run("UPDATE notifications SET email_status = 'envoye', emailed_at = NOW(), email_error = NULL, email_attempts = email_attempts + 1 WHERE id = ?", [$n['id']]);
        $done['e-mails']++;
    } else {
        $final = (int) $n['email_attempts'] + 1 >= 3 || !$n['email'];
        Database::run('UPDATE notifications SET email_status = ?, email_error = ?, email_attempts = email_attempts + 1 WHERE id = ?',
            [$final ? 'echec' : 'en_attente', mb_substr($error, 0, 255), $n['id']]);
        $done['échecs']++;
        fwrite(STDERR, '[flotte] e-mail ' . $n['id'] . ' non envoyé : ' . $error . "\n");
    }
}

// 4. E-mails adressés directement à une adresse
$outbox = Database::all("SELECT id, to_email, subject, body, attempts FROM mail_outbox WHERE status = 'en_attente' ORDER BY id LIMIT 50");
if ($outbox && !Mailer::configured()) {
    Database::run("UPDATE mail_outbox SET status = 'echec', error = 'SMTP non configuré' WHERE status = 'en_attente'");
    $outbox = [];
}
foreach ($outbox as $m) {
    $error = $mailer->send($m['to_email'], $m['subject'], $m['body']);
    if ($error === null) {
        Database::run("UPDATE mail_outbox SET status = 'envoye', sent_at = NOW(), error = NULL, attempts = attempts + 1 WHERE id = ?", [$m['id']]);
        $done['e-mails']++;
    } else {
        Database::run('UPDATE mail_outbox SET status = ?, error = ?, attempts = attempts + 1 WHERE id = ?',
            [(int) $m['attempts'] + 1 >= 3 ? 'echec' : 'en_attente', mb_substr($error, 0, 255), $m['id']]);
        $done['échecs']++;
        fwrite(STDERR, '[e-mails] message ' . $m['id'] . ' non envoyé : ' . $error . "\n");
    }
}

if (array_sum($done) > 0) {
    echo '[flotte ' . date('Y-m-d H:i:s') . '] ' . implode(', ', array_map(static fn($k, $v) => "$v $k", array_keys($done), $done)) . "\n";
}
