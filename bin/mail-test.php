<?php
declare(strict_types=1);

/*
 * Diagnostic des e-mails (lancé dans le worker, qui envoie les e-mails) :
 *   - configuration SMTP lue dans .env (sans le mot de passe) ;
 *   - e-mails des notifications par état, dernières erreurs ;
 *   - comptes RH actifs et leur adresse (compte, sinon fiche employé) ;
 *   - avec une adresse : envoi d'un e-mail de test et réponse du serveur SMTP ;
 *   - --relancer : remet en file les e-mails des notifications des dernières
 *     24 h non envoyés (« aucun » ou « echec ») dont le compte a une adresse.
 *
 *   docker compose exec worker php bin/mail-test.php [adresse@exemple.com] [--relancer]
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Mailer;

$args = array_slice($argv, 1);
$retry = in_array('--relancer', $args, true);
$to = null;
foreach ($args as $a) {
    if ($a !== '--relancer') {
        $to = $a;
    }
}

echo "Configuration SMTP (.env du conteneur)\n";
foreach (['host' => 'SMTP_HOST', 'port' => 'SMTP_PORT', 'secure' => 'SMTP_SECURE', 'user' => 'SMTP_USER', 'from' => 'SMTP_FROM / MAIL_FROM', 'from_name' => 'MAIL_NAME'] as $k => $label) {
    printf("  %-22s %s\n", $label, (string) Config::get('mail.' . $k, '') ?: '(vide)');
}
printf("  %-22s %s\n", 'SMTP_PASSWORD', (string) Config::get('mail.password', '') !== '' ? '(renseigné)' : '(vide)');
echo '  => ' . (Mailer::configured() ? 'SMTP configuré' : 'SMTP NON configuré : aucun e-mail ne part (conteneurs à recréer après une modification du .env)') . "\n\n";

echo "E-mails des notifications (7 derniers jours)\n";
foreach (Database::all("SELECT email_status, COUNT(*) n FROM notifications WHERE created_at >= NOW() - INTERVAL 7 DAY GROUP BY email_status") as $r) {
    printf("  %-12s %d\n", $r['email_status'], $r['n']);
}
foreach (Database::all("SELECT id, created_at, email_error FROM notifications WHERE email_error IS NOT NULL ORDER BY id DESC LIMIT 5") as $r) {
    echo '  erreur #' . $r['id'] . ' (' . $r['created_at'] . ') : ' . $r['email_error'] . "\n";
}
foreach (Database::all("SELECT status, COUNT(*) n FROM mail_outbox WHERE created_at >= NOW() - INTERVAL 7 DAY GROUP BY status") as $r) {
    printf("  file directe %-10s %d\n", $r['status'], $r['n']);
}
echo "\nComptes RH actifs\n";
$rh = Database::all(
    "SELECT u.username, COALESCE(NULLIF(u.email, ''), NULLIF(e.email, '')) email FROM users u
       LEFT JOIN employees e ON e.id = u.employee_id WHERE u.active = 1 AND u.role = 'rh' ORDER BY u.username"
);
foreach ($rh as $r) {
    echo '  ' . $r['username'] . ' : ' . ($r['email'] ?: 'AUCUNE ADRESSE (pas d\'e-mail, cloche seulement)') . "\n";
}
if (!$rh) {
    echo "  aucun compte avec le rôle « rh »\n";
}

if ($retry) {
    $n = Database::run(
        "UPDATE notifications n JOIN users u ON u.id = n.user_id LEFT JOIN employees e ON e.id = u.employee_id
            SET n.email_status = 'en_attente', n.email_attempts = 0, n.email_error = NULL
          WHERE n.email_status IN ('aucun', 'echec') AND n.created_at >= NOW() - INTERVAL 1 DAY
            AND u.active = 1 AND COALESCE(NULLIF(u.email, ''), NULLIF(e.email, '')) IS NOT NULL"
    );
    echo "\n$n e-mail(s) remis en file (envoi par le worker dans la minute).\n";
}

if ($to !== null) {
    echo "\nEnvoi d'un e-mail de test à {$to}…\n";
    $error = (new Mailer())->send($to, 'Test des e-mails de ' . Mailer::name(),
        "Bonjour,\n\nCeci est un e-mail de test envoyé par bin/mail-test.php : la configuration SMTP fonctionne.", null);
    echo $error === null ? "  envoyé.\n" : "  ÉCHEC : $error\n";
    exit($error === null ? 0 : 1);
}
