<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Authentification et rôles.
 *   admin   : tout, y compris utilisateurs et pointeuses
 *   rh      : employés, groupes, horaires, corrections
 *   lecteur : consultation uniquement
 */
final class Auth
{
    private const ABILITIES = [
        'view'   => ['admin', 'rh', 'lecteur'],
        'manage' => ['admin', 'rh'],
        'admin'  => ['admin'],
    ];

    public const ROLES = [
        'admin'   => 'Administrateur',
        'rh'      => 'Ressources humaines',
        'lecteur' => 'Lecteur',
    ];

    private static ?array $user = null;

    public static function user(): ?array
    {
        if (self::$user === null && !empty($_SESSION['user_id'])) {
            self::$user = Database::one(
                'SELECT id, username, full_name, role FROM users WHERE id = ? AND active = 1',
                [$_SESSION['user_id']]
            );
            if (self::$user === null) {
                unset($_SESSION['user_id']);
            }
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        return isset(self::user()['id']) ? (int) self::user()['id'] : null;
    }

    public static function can(string $ability): bool
    {
        $user = self::user();
        return $user !== null && in_array($user['role'], self::ABILITIES[$ability] ?? [], true);
    }

    public static function isThrottled(string $username, string $ip): bool
    {
        $window = (int) Config::get('login_window_minutes', 15);
        $failures = (int) Database::value(
            'SELECT COUNT(*) FROM login_attempts WHERE username = ? AND ip = ? AND success = 0
             AND attempted_at > NOW() - INTERVAL ? MINUTE',
            [$username, $ip, $window]
        );
        return $failures >= (int) Config::get('login_max_attempts', 5);
    }

    public static function attempt(string $username, string $password): bool
    {
        $ip = client_ip();
        $user = Database::one('SELECT id, password_hash, active FROM users WHERE username = ?', [$username]);
        $ok = $user !== null && (int) $user['active'] === 1 && password_verify($password, $user['password_hash']);

        Database::run('INSERT INTO login_attempts (username, ip, success) VALUES (?, ?, ?)', [$username, $ip, $ok ? 1 : 0]);

        if (!$ok) {
            return false;
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Database::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        self::$user = null;
        Database::run('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
        Audit::log('connexion');
        return true;
    }

    public static function logout(): void
    {
        Audit::log('deconnexion');
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::$user = null;
    }
}
