<?php
declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Core\Database;
use App\Core\Mailer;

/**
 * Notifications adressées à un compte (table notifications) : affichées dans la
 * cloche de l'en-tête (/api/notifications) et envoyées par e-mail par le worker
 * (bin/fleet-tasks.php) quand le SMTP est configuré et que le compte a une
 * adresse (users.email, sinon celle de la fiche employé reliée).
 * E-mails à une simple adresse (employé sans compte) : mail(), file mail_outbox,
 * envoyée par le même worker. Jamais d'envoi pendant une requête web.
 * Communes à tous les modules.
 */
final class Notifications
{
    /**
     * Types de message rédigés comme une lettre (« Bonjour …, ») : l'e-mail
     * n'a pas de titre au-dessus du texte (le titre reste l'objet de l'e-mail).
     */
    public const LETTER_KINDS = ['compte_cree', 'statut_travail', 'statut_saisi', 'statut_rh', 'statut_fin'];

    /**
     * Comptes actifs d'un rôle de la plateforme (ex. 'rh'), avec leur nom.
     * @return array<int, string> id du compte → nom affiché
     */
    public static function usersWithRole(string $role): array
    {
        $out = [];
        foreach (Database::all('SELECT id, full_name, username FROM users WHERE active = 1 AND role = ? ORDER BY id', [$role]) as $u) {
            $out[(int) $u['id']] = (string) ($u['full_name'] ?: $u['username']);
        }
        return $out;
    }
    /**
     * @param array<int|null> $userIds destinataires (doublons et null ignorés)
     * @param int|null $except compte à ne pas prévenir (celui qui fait l'action)
     */
    public static function send(array $userIds, string $kind, string $title, ?string $body = null, ?string $url = null,
                                ?int $except = null, string $module = 'fleet', bool $email = true): void
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn($id) => $id !== null ? (int) $id : 0, $userIds))));
        $ids = array_values(array_filter($ids, static fn(int $id) => $id !== $except));
        if (!$ids) {
            return;
        }
        $emails = [];
        if ($email && Mailer::configured()) {
            foreach (Database::all(
                'SELECT u.id FROM users u LEFT JOIN employees e ON e.id = u.employee_id
                  WHERE u.active = 1 AND COALESCE(NULLIF(u.email, \'\'), NULLIF(e.email, \'\')) IS NOT NULL
                    AND u.id IN (' . implode(',', $ids) . ')'
            ) as $r) {
                $emails[(int) $r['id']] = true;
            }
        }
        foreach ($ids as $id) {
            Database::run(
                'INSERT INTO notifications (user_id, module, kind, title, body, url, email_status) SELECT id, ?, ?, ?, ?, ?, ? FROM users WHERE id = ? AND active = 1',
                [$module, $kind, mb_substr($title, 0, 160), $body !== null ? mb_substr($body, 0, 500) : null, $url,
                 isset($emails[$id]) ? 'en_attente' : 'aucun', $id]
            );
        }
    }

    /**
     * E-mail à une adresse (sans compte ni cloche), mis en file pour le worker.
     * Sans SMTP configuré ou avec une adresse invalide : rien n'est fait.
     */
    public static function mail(?string $to, string $subject, string $body): bool
    {
        $to = trim((string) $to);
        if (!Mailer::configured() || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        Database::run('INSERT INTO mail_outbox (to_email, subject, body) VALUES (?, ?, ?)',
            [mb_substr($to, 0, 150), mb_substr($subject, 0, 160), mb_substr($body, 0, 5000)]);
        return true;
    }

    /**
     * Prévient un employé : par son compte s'il en a un (cloche + e-mail), sinon
     * par un e-mail à l'adresse de sa fiche. Renvoie vrai si un message est parti
     * (cloche ou e-mail en file).
     */
    public static function employee(int $employeeId, string $kind, string $title, string $body, ?string $url = null,
                                    string $module = 'presence'): bool
    {
        $emp = Database::one(
            'SELECT e.email, u.id user_id FROM employees e LEFT JOIN users u ON u.employee_id = e.id AND u.active = 1 WHERE e.id = ?',
            [$employeeId]
        );
        if ($emp === null) {
            return false;
        }
        if ($emp['user_id'] !== null) {
            self::send([(int) $emp['user_id']], $kind, $title, $body, $url, null, $module);
            return true;
        }
        return self::mail($emp['email'], $title, in_array($kind, self::LETTER_KINDS, true) ? $body : $title . "\n\n" . $body);
    }

    /** Dernières notifications d'un compte. */
    public static function latest(int $userId, int $limit = 15): array
    {
        return Database::all(
            'SELECT id, kind, title, body, url, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)),
            [$userId]
        );
    }

    public static function unread(int $userId): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    /** Marque comme lues les notifications du compte (jusqu'à $upTo inclus si donné). */
    public static function markRead(int $userId, ?int $upTo = null): int
    {
        return Database::run(
            'UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL' . ($upTo !== null ? ' AND id <= ' . (int) $upTo : ''),
            [$userId]
        );
    }
}
