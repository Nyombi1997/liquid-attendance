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

/** Libellé et classe CSS des statuts de journée. */
function status_badge(string $status): string
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
    [$label, $class] = $map[$status] ?? [$status, 'muted'];
    return '<span class="badge badge-' . $class . '">' . e($label) . '</span>';
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

/** Icônes du sprite SVG (public/assets/icons.svg). */
function icon(string $name, string $class = 'icon'): string
{
    return '<svg class="' . e($class) . '" aria-hidden="true"><use href="/assets/icons.svg#' . e($name) . '"></use></svg>';
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
