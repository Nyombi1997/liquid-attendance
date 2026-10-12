<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Services;

/**
 * Pastilles des cartes de la page Agents.
 *
 * Statut de travail (icône en haut à droite) :
 *   - statut saisi par les RH (employees.work_status) tant qu'il est valable
 *     (work_status_until vide ou pas encore passé) ;
 *   - sinon « au bureau » automatiquement dès le premier badge d'entrée du jour ;
 *   - sinon « pas encore au bureau ».
 *
 * Arrivée (en bas à droite) : heure du premier badge, à l'heure ou en retard,
 * d'après attendance_days (même règle que le calcul : late_minutes > 0).
 *
 * Le mode direct (/api/direct, champ days[].card) renvoie ces mêmes valeurs :
 * la page Agents (app.js) ne fait que les afficher, sans règle en JavaScript.
 */
final class AgentStatus
{
    /** Clé → [libellé, classes Font Awesome, variante CSS] */
    public const WORK = [
        'bureau'      => ['Au bureau', 'fa-solid fa-building', 'ok'],
        'teletravail' => ['Télétravail', 'fa-solid fa-house-laptop', 'info'],
        'mission'     => ['En mission', 'fa-solid fa-plane', 'warn'],
        'conge'       => ['En congé', 'fa-solid fa-umbrella-beach', 'accent'],
        'none'        => ['Pas encore au bureau', 'fa-regular fa-building', 'muted'],
    ];

    /** Filtres rapides de la page Agents (puces) : clé → libellé */
    public const FILTERS = [
        'tous'        => 'Tous',
        'arrives'     => 'Arrivés',
        'heure'       => 'À l\'heure',
        'retard'      => 'En retard',
        'absents'     => 'Pas arrivés',
        'teletravail' => 'Télétravail',
        'mission'     => 'Mission',
        'conge'       => 'Congé',
    ];

    /**
     * @param array $emp ligne employees (work_status, work_status_until, work_status_note)
     * @return array{key:string, manual:bool, label:string, icon:string, variant:string, title:string}
     */
    public static function work(array $emp, ?string $firstIn, string $today): array
    {
        $manual = self::manualActive($emp, $today);
        $key = $manual ? $emp['work_status'] : ($firstIn ? 'bureau' : 'none');
        [$label, $icon, $variant] = self::WORK[$key];

        $title = $label;
        if ($manual) {
            $title .= $emp['work_status_until'] ? ' jusqu\'au ' . date('d/m/Y', strtotime($emp['work_status_until'])) : '';
            $title .= $emp['work_status_note'] ? ' · ' . $emp['work_status_note'] : '';
            $title .= ' (saisi par les RH)';
        } elseif ($key === 'bureau') {
            $title .= ' depuis ' . time_label($firstIn) . ' (badge)';
        }
        return ['key' => $key, 'manual' => $manual, 'label' => $label, 'icon' => $icon, 'variant' => $variant, 'title' => $title];
    }

    public static function manualActive(array $emp, string $today): bool
    {
        return !empty($emp['work_status']) && isset(self::WORK[$emp['work_status']])
            && (empty($emp['work_status_until']) || $emp['work_status_until'] >= $today);
    }

    /**
     * @param array $day colonnes first_in, late_minutes, status, expected_start de attendance_days (ou null)
     * @param string $work clé du statut de travail (self::work()) : en télétravail, mission ou congé,
     *                     l'absence de badge est normale et n'est pas signalée
     * @return array{kind:string, label:string, title:string}
     *   kind : ontime | late | expected (attendu plus tard) | missing (pas arrivé) | off (repos, absence prévue, pas d'horaire)
     */
    public static function arrival(?array $day, string $work = 'none', ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        if ($day === null || empty($day['status'])) {
            return ['kind' => 'off', 'label' => '—', 'title' => 'Pas d\'horaire aujourd\'hui'];
        }
        if (!empty($day['first_in'])) {
            $time = time_label($day['first_in']);
            $late = (int) $day['late_minutes'];
            return $late > 0
                ? ['kind' => 'late', 'label' => $time, 'title' => "Arrivé à $time, $late min de retard"]
                : ['kind' => 'ontime', 'label' => $time, 'title' => "Arrivé à l'heure, à $time"];
        }
        if ($day['status'] === 'repos' || empty($day['expected_start'])) {
            return ['kind' => 'off', 'label' => 'Repos', 'title' => 'Jour non travaillé'];
        }
        if (in_array($work, ['teletravail', 'mission', 'conge'], true)) {
            return ['kind' => 'off', 'label' => '—', 'title' => self::WORK[$work][0] . ' : pas de badge attendu'];
        }
        $start = time_label($day['expected_start']);
        return $now < $day['expected_start']
            ? ['kind' => 'expected', 'label' => $start, 'title' => "Attendu à $start"]
            : ['kind' => 'missing', 'label' => 'Absent', 'title' => "Pas encore arrivé (attendu à $start)"];
    }

    /** Puces (FILTERS) auxquelles appartient une carte. */
    public static function filters(string $work, string $arrival): array
    {
        $f = ['tous'];
        if (in_array($arrival, ['ontime', 'late'], true)) {
            $f[] = 'arrives';
            $f[] = $arrival === 'late' ? 'retard' : 'heure';
        } elseif (in_array($work, ['none', 'bureau'], true) && in_array($arrival, ['expected', 'missing'], true)) {
            $f[] = 'absents';
        }
        if (in_array($work, ['teletravail', 'mission', 'conge'], true)) {
            $f[] = $work;
        }
        return $f;
    }
}
