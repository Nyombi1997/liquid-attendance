<?php
declare(strict_types=1);

namespace App\Core;

use App\Modules\Platform\Services\Departments;

/**
 * Authentification et rôles.
 *   admin   : tout (seul rôle qui modifie fiches, départements, groupes, horaires, corrections…)
 *   rh      : tableau de bord, Agents (disponibilité uniquement), Employés en consultation, Calendrier
 *   ceo     : consultation de tous les départements, aucune modification
 *   manager : Agents, tableau de bord, présence du jour, Employés et Calendrier de son département uniquement
 *   lecteur : consultation uniquement
 * Les rubriques réservées au droit « view » (sorties manquantes, modules…) ne
 * s'affichent ni pour les RH ni pour les managers.
 *
 * Droits (« abilities ») vérifiés par le routeur sur chaque route :
 *   board    : page Agents, tableau de bord, /api/*, profil
 *   presence : présence du jour
 *   staff    : liste et fiche des employés (managers : leur département)
 *   calendar : calendrier de présence (temps au bureau, absences et leurs raisons)
 *   view     : autres pages de consultation (corrections, accès, horaires, groupes, pointeuses…)
 *   status   : disponibilité des agents (statut de travail)
 *   manage   : toutes les autres modifications
 *   admin    : comptes, pointeuses, journal
 * Les données d'un manager sont en plus filtrées par departmentScope().
 */
final class Auth
{
    private const ABILITIES = [
        'board'    => ['admin', 'rh', 'ceo', 'manager', 'lecteur'],
        'presence' => ['admin', 'ceo', 'manager', 'lecteur'],
        'staff'    => ['admin', 'rh', 'ceo', 'manager', 'lecteur'],
        'calendar' => ['admin', 'rh', 'manager'],
        'view'     => ['admin', 'ceo', 'lecteur'],
        'status'   => ['admin', 'rh'],
        'manage'   => ['admin'],
        'admin'    => ['admin'],
    ];

    public const ROLES = [
        'admin'   => 'Administrateur',
        'rh'      => 'Ressources humaines',
        'ceo'     => 'Direction (CEO)',
        'manager' => 'Manager de département',
        'lecteur' => 'Lecteur',
    ];

    /** @var int[]|null|false false = pas encore calculé */
    private static array|null|false $scope = false;

    private static ?array $user = null;

    public static function user(): ?array
    {
        if (self::$user === null && !empty($_SESSION['user_id'])) {
            self::$user = Database::one(
                'SELECT id, username, full_name, role, department_id FROM users WHERE id = ? AND active = 1',
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

    public static function role(): ?string
    {
        return self::user()['role'] ?? null;
    }

    /**
     * Départements visibles : null = tous ; sinon le département du manager et
     * ses sous-départements (liste vide si aucun département n'est attribué).
     * À appliquer à toute requête sur les agents, avec Departments::sql().
     *
     * @return int[]|null
     */
    public static function departmentScope(): ?array
    {
        if (self::$scope === false) {
            $user = self::user();
            self::$scope = ($user['role'] ?? null) !== 'manager' ? null
                : ($user['department_id'] ? Departments::descendants((int) $user['department_id']) : []);
        }
        return self::$scope;
    }

    /** Page d'accueil après connexion : la page Agents pour la direction et les managers. */
    public static function home(): string
    {
        return in_array(self::role(), ['manager', 'ceo'], true) ? '/agents' : '/';
    }

    /** Identifiant insensible à la casse : toujours enregistré et comparé en minuscules. */
    public static function normalizeUsername(string $username): string
    {
        return mb_strtolower(trim($username));
    }

    public static function isThrottled(string $username, string $ip): bool
    {
        $username = self::normalizeUsername($username);
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
        $username = self::normalizeUsername($username);
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
        self::$scope = false;
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
        self::$scope = false;
    }
}
