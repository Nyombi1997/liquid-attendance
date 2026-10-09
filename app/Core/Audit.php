<?php
declare(strict_types=1);

namespace App\Core;

final class Audit
{
    public static function log(string $action, array|string|null $details = null): void
    {
        if (is_array($details)) {
            $details = json_encode($details, JSON_UNESCAPED_UNICODE);
        }
        Database::run(
            'INSERT INTO audit_log (user_id, action, details, ip) VALUES (?, ?, ?, ?)',
            [$_SESSION['user_id'] ?? null, $action, $details, PHP_SAPI === 'cli' ? 'cli' : client_ip()]
        );
    }
}
