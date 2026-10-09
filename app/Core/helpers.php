<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;

/** Échappement HTML. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '/', array $query = []): string
{
    $path = '/' . ltrim($path, '/');
    return $query ? $path . '?' . http_build_query($query) : $path;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function auth_user(): ?array
{
    return Auth::user();
}

function can(string $ability): bool
{
    return Auth::can($ability);
}

function flash(?string $type = null, ?string $message = null): ?array
{
    if ($type !== null) {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
        return null;
    }
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}

function old(string $key, mixed $default = ''): mixed
{
    return $_SESSION['_old'][$key] ?? $default;
}

/** Durée en minutes → « 7 h 05 ». */
function minutes_label(?int $minutes): string
{
    if (!$minutes) {
        return '—';
    }
    $sign = $minutes < 0 ? '-' : '';
    $minutes = abs($minutes);
    return sprintf('%s%d h %02d', $sign, intdiv($minutes, 60), $minutes % 60);
}

function time_label(?string $datetime): string
{
    return $datetime ? substr($datetime, 11, 5) : '—';
}

function date_fr(string $date, bool $withDay = true): string
{
    $jours = ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août',
        'septembre', 'octobre', 'novembre', 'décembre'];
    $ts = strtotime($date);
    $label = date('j', $ts) . ' ' . $mois[(int) date('n', $ts)] . ' ' . date('Y', $ts);
    return $withDay ? $jours[(int) date('N', $ts)] . ' ' . $label : $label;
}

/** « Ven. 09/10 » : format court pour les tableaux. */
function day_short(string $date): string
{
    $jours = ['', 'Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.', 'Dim.'];
    $ts = strtotime($date);
    return $jours[(int) date('N', $ts)] . ' ' . date('d/m', $ts);
}

/** Libellé et classe CSS (badge-…) d'un statut de journée. */
function status_info(string $status): array
{
    $map = [
        'present'          => ['Présent', 'ok'],
        'retard'           => ['Retard', 'warn'],
        'absent'           => ['Absent', 'danger'],
        'sortie_manquante' => ['Sortie manquante', 'accent'],
        'en_cours'         => ['En cours', 'info'],
        'repos'            => ['Repos', 'muted'],
        'hors_horaire'     => ['Hors horaire', 'info'],
    ];
    return $map[$status] ?? [$status, 'muted'];
}

function status_badge(string $status): string
{
    [$label, $class] = status_info($status);
    return '<span class="badge badge-' . $class . '">' . e($label) . '</span>';
}

/** Retard affiché dans les tableaux : « 16 min » ou « — ». */
function late_label(int $minutes): string
{
    return $minutes > 0 ? $minutes . ' min' : '—';
}

/** « il y a 12 s », « il y a 5 min », « il y a 2 h », puis la date. */
function ago_label(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $s = max(0, time() - strtotime($datetime));
    return match (true) {
        $s < 60    => 'il y a ' . $s . ' s',
        $s < 3600  => 'il y a ' . intdiv($s, 60) . ' min',
        $s < 86400 => 'il y a ' . intdiv($s, 3600) . ' h',
        default    => 'le ' . date('d/m à H:i', strtotime($datetime)),
    };
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $letters .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $letters ?: '?';
}

/**
 * URL d'un fichier de public/assets, versionnée par sa date de modification
 * (« /assets/css/app.css?v=1760000000 ») : le navigateur peut la garder en
 * cache un an, et la récupère d'elle-même dès que le fichier change.
 */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $mtime = @filemtime(BASE_PATH . '/public/assets/' . $path);
    return '/assets/' . $path . ($mtime ? '?v=' . $mtime : '');
}

/** Icônes du sprite SVG (public/assets/icons.svg). */
function icon(string $name, string $class = 'icon'): string
{
    static $sprite = null;
    $sprite ??= asset('icons.svg');
    return '<svg class="' . e($class) . '" aria-hidden="true"><use href="' . e($sprite) . '#' . e($name) . '"></use></svg>';
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
