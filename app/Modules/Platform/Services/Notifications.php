<?php
declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Core\Database;
use App\Core\Mailer;

/**
 * Notifications adressées à un compte (table notifications) : affichées dans la
 * cloche de l'en-tête (/api/notifications) et envoyées par e-mail par le worker
 * (bin/fleet-tasks.php) quand le SMTP est configuré et que la fiche employé
 * reliée au compte a une adresse e-mail. Communes à tous les modules.
 */
final class Notifications
{
    /**
     * @param array<int|null> $userIds destinataires (doublons et null ignorés)
     * @param int|null $except compte à ne pas prévenir (celui qui fait l'action)
     */
    public static function send(array $userIds, string $kind, string $title, ?string $body = null, ?string $url = null,
                                ?int $except = null, string $module = 'fleet'): void
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn($id) => $id !== null ? (int) $id : 0, $userIds))));
        $ids = array_values(array_filter($ids, static fn(int $id) => $id !== $except));
        if (!$ids) {
            return;
        }
        $mail = Mailer::configured();
        $emails = [];
        if ($mail) {
            foreach (Database::all(
                'SELECT u.id, e.email FROM users u JOIN employees e ON e.id = u.employee_id
                  WHERE u.active = 1 AND e.email IS NOT NULL AND e.email <> \'\' AND u.id IN (' . implode(',', $ids) . ')'
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
