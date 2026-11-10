<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Core\Database;
use App\Modules\Platform\Services\Notifications;

/**
 * Messages liés aux disponibilités des agents (congé, mission, télétravail,
 * bureau, retour au statut automatique), envoyés par la cloche et par e-mail
 * (file d'attente, envoi par le worker) :
 *   - changed() : à l'agent, à la personne qui a fait la modification et aux
 *     comptes RH (rôle « rh ») ;
 *   - ended()   : fin de la période saisie (date de fin passée), aux comptes RH
 *     et à la personne qui l'avait saisie ; lancé par bin/fleet-tasks.php.
 * Chaque destinataire reçoit un message à son nom (« Bonjour …, »).
 */
final class StatusNotify
{
    /** Nom de la période qui se termine (fin de statut) */
    private const ENDED = [
        'conge'       => 'le congé',
        'mission'     => 'la mission',
        'teletravail' => 'le télétravail',
        'bureau'      => 'le statut « au bureau »',
    ];

    /**
     * Changement de disponibilité saisi par $by (compte connecté).
     * Rien si rien ne change. Renvoie vrai si l'agent a été prévenu.
     *
     * @param array $emp ligne employees avant la modification (id, name, work_status, work_status_until)
     * @param array $by  compte qui fait la modification (id, full_name, username)
     */
    public static function changed(array $emp, ?string $status, ?string $until, ?string $note, array $by): bool
    {
        if ($emp['work_status'] === $status && $emp['work_status_until'] === $until && $status !== null && $note === null) {
            return false;
        }
        $byId = (int) $by['id'];
        $byName = (string) (($by['full_name'] ?? '') ?: ($by['username'] ?? ''));
        $name = (string) $emp['name'];
        $period = self::period($status, $until);
        $label = $status !== null ? mb_strtolower(AgentStatus::WORK[$status][0]) : null;
        $noteText = $note ? "\n\nNote : " . $note : '';
        // Compte de l'agent lui-même : il reçoit déjà son propre message
        $agentUser = (int) (Database::value('SELECT id FROM users WHERE employee_id = ? AND active = 1', [(int) $emp['id']]) ?? 0);

        // 1. L'agent
        $sent = Notifications::employee((int) $emp['id'], 'statut_travail',
            $label !== null ? 'Votre statut : ' . $label . $period : 'Votre statut : retour au bureau',
            'Bonjour ' . $name . ",\n\n"
                . ($label !== null
                    ? $byName . ' a changé votre statut à « ' . $label . ' »' . $period . '.'
                    : $byName . ' a remis votre statut en automatique : vous êtes compté « au bureau » dès votre premier badge.')
                . $noteText);

        // 2. La personne qui a fait la modification, puis 3. les comptes RH
        $title = 'Statut de ' . $name . ' : ' . ($label !== null ? $label . $period : 'retour au bureau');
        $what = $label !== null
            ? 'le statut de ' . $name . ' à « ' . $label . ' »' . $period . '.'
            : 'le statut de ' . $name . ' en automatique (« au bureau » dès son premier badge).';
        if ($byId !== $agentUser) {
            Notifications::send([$byId], 'statut_saisi', $title,
                'Bonjour ' . $byName . ",\n\nVous avez " . ($label !== null ? 'changé ' : 'remis ') . $what
                    . ($sent ? ' ' . $name . ' en a été informé(e).' : '') . $noteText,
                '/agents', null, 'presence');
        }
        foreach (Notifications::usersWithRole('rh') as $id => $rhName) {
            if ($id === $byId || $id === $agentUser) {
                continue;
            }
            Notifications::send([$id], 'statut_rh', $title,
                'Bonjour ' . $rhName . ",\n\n" . $byName . ' a ' . ($label !== null ? 'changé ' : 'remis ') . $what . $noteText,
                '/agents', null, 'presence');
        }
        return $sent;
    }

    /**
     * Périodes saisies dont la date de fin est passée et pas encore signalées
     * (work_statuses.end_notified_at) : message aux RH et à l'auteur de la saisie.
     * Une seule fois par période, même si deux exécutions se croisent.
     * Renvoie le nombre de périodes signalées.
     */
    public static function ended(): int
    {
        $count = 0;
        foreach (Database::all(
            "SELECT w.id, w.status, w.date_to, w.created_by, e.id employee_id, e.name, e.active,
                    COALESCE(NULLIF(u.full_name, ''), u.username) by_name, a.id agent_user
               FROM work_statuses w
               JOIN employees e ON e.id = w.employee_id
               LEFT JOIN users u ON u.id = w.created_by
               LEFT JOIN users a ON a.employee_id = e.id AND a.active = 1
              WHERE w.end_notified_at IS NULL AND w.date_to IS NOT NULL
                AND w.date_to < CURDATE() AND w.date_to >= CURDATE() - INTERVAL 7 DAY
              ORDER BY w.id LIMIT 100"
        ) as $w) {
            if (Database::run('UPDATE work_statuses SET end_notified_at = NOW() WHERE id = ? AND end_notified_at IS NULL', [$w['id']]) !== 1
                || !(int) $w['active']) {
                continue;
            }
            $what = self::ENDED[$w['status']] ?? 'le statut';
            $date = date('d/m/Y', strtotime($w['date_to']));
            $title = 'Fin de ' . preg_replace('/^(le |la )/u', '', $what) . ' : ' . $w['name'] . ' (' . $date . ')';
            $text = ucfirst($what) . ' de ' . $w['name'] . ' a pris fin le ' . $date
                . ($w['by_name'] ? ' (saisi par ' . $w['by_name'] . ')' : '') . '.'
                . "\n\nSon statut est de nouveau automatique : « au bureau » dès son premier badge.";

            $to = Notifications::usersWithRole('rh');
            if ($w['created_by'] !== null && !isset($to[(int) $w['created_by']])) {
                $to[(int) $w['created_by']] = (string) $w['by_name'];
            }
            foreach ($to as $id => $userName) {
                if ($id === (int) $w['agent_user']) {
                    continue;
                }
                Notifications::send([$id], 'statut_fin', $title, 'Bonjour ' . $userName . ",\n\n" . $text, '/agents', null, 'presence');
            }
            $count++;
        }
        return $count;
    }

    /** « jusqu'au 15/11/2026 inclus », « jusqu'à nouvel ordre » ou rien (statut automatique). */
    private static function period(?string $status, ?string $until): string
    {
        if ($status === null) {
            return '';
        }
        return $until ? ' jusqu\'au ' . date('d/m/Y', strtotime($until)) . ' inclus' : ' jusqu\'à nouvel ordre';
    }
}
