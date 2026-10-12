<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Services;

/**
 * Cases du calendrier de présence (une par agent et par jour), sans accès à
 * la base (testable seul). Le calendrier (CalendarController) ne fait que les
 * afficher ; app.js ne fait qu'avancer le temps des journées « en cours ».
 *
 * Genre de case (kind), par ordre de priorité :
 *   work    : arrivé et reparti (sortie badgée ou corrigée), temps calculé
 *   ongoing : arrivé aujourd'hui, pas encore reparti : temps qui avance en direct
 *   open    : arrivé un jour passé, heure de départ inconnue (0 h tant que non corrigée)
 *   reason  : pas de badge, raison d'absence saisie (télétravail, mission, congé)
 *   absent  : jour travaillé, pas de badge, aucune raison : en rouge
 *   pending : attendu plus tard aujourd'hui
 *   off     : repos, pas d'horaire, ou jour à venir
 */
final class Calendar
{
    /** Raison d'absence → [libellé, icône Font Awesome] (mêmes icônes que la page Agents) */
    public const REASONS = [
        'teletravail' => ['Télétravail', 'fa-solid fa-house-laptop'],
        'mission'     => ['En mission', 'fa-solid fa-plane'],
        'conge'       => ['En congé', 'fa-solid fa-umbrella-beach'],
    ];

    /**
     * @param array|null $day    ligne attendance_days (first_in, last_out, worked_minutes, late_minutes,
     *                           status, expected_start, expected_end) ou null
     * @param array|null $reason ['status' => …, 'note' => …, 'until' => …] (WorkStatuses::reasons()) ou null
     * @param string     $now    'Y-m-d H:i:s'
     * @return array{kind:string, late:bool, reason:?string, label:string, title:string,
     *               in:?string, out:?string, sched:?array, minutes:int}
     */
    public static function cell(?array $day, ?array $reason, string $date, string $now): array
    {
        $today = substr($now, 0, 10);
        $in = $day['first_in'] ?? null;
        $out = $day['last_out'] ?? null;
        $late = (int) ($day['late_minutes'] ?? 0);
        $reasonKey = isset($reason['status'], self::REASONS[$reason['status']]) ? $reason['status'] : null;
        $sched = !empty($day['expected_start']) && !empty($day['expected_end'])
            ? [$day['expected_start'], $day['expected_end']] : null;

        $c = [
            'kind' => 'off', 'late' => $late > 0, 'reason' => $reasonKey, 'label' => '', 'title' => '',
            'in' => $in, 'out' => $out, 'sched' => $sched, 'minutes' => (int) ($day['worked_minutes'] ?? 0),
        ];
        $reasonText = $reasonKey ? self::reasonText($reason) : '';
        $lateText = $late > 0 ? " ($late min de retard)" : '';

        if ($in !== null) {
            $arrival = 'Arrivé à ' . time_label($in) . $lateText;
            if ($out !== null) {
                $c['kind'] = 'work';
                $c['label'] = $c['minutes'] > 0 ? minutes_label($c['minutes']) : time_label($in);
                $c['title'] = $arrival . ', reparti à ' . time_label($out) . ' · ' . minutes_label($c['minutes']) . ' de présence';
            } elseif ($date === $today) {
                $c['kind'] = 'ongoing';
                $c['minutes'] = self::elapsed($in, $now);
                $c['label'] = minutes_label(max(1, $c['minutes']));
                $c['title'] = $arrival . ' · au bureau depuis ' . minutes_label(max(1, $c['minutes']));
            } else {
                $c['kind'] = 'open';
                $c['label'] = time_label($in);
                $c['title'] = $arrival;
            }
            if ($reasonText !== '') {
                $c['title'] .= ' · ' . $reasonText;
            }
            return $c;
        }

        if ($reasonKey !== null) {
            $c['kind'] = 'reason';
            $c['label'] = self::REASONS[$reasonKey][0];
            $c['title'] = $reasonText;
            return $c;
        }

        $status = $day['status'] ?? null;
        if ($status === null || $status === 'repos' || $sched === null) {
            $c['title'] = $date > $today ? '' : ($status === 'repos' ? 'Jour non travaillé' : 'Pas d\'horaire');
            $c['label'] = $status === 'repos' ? 'Repos' : '';
            return $c;
        }
        if ($date === $today && $now < $sched[0]) {
            $c['kind'] = 'pending';
            $c['label'] = time_label($sched[0]);
            $c['title'] = 'Attendu à ' . time_label($sched[0]);
            return $c;
        }
        $c['kind'] = 'absent';
        $c['label'] = 'Absent';
        $c['title'] = 'Absent : aucun badge, aucune raison signalée (attendu à ' . time_label($sched[0]) . ')';
        return $c;
    }

    /** Minutes entre deux dates 'Y-m-d H:i:s' (0 si négatif). */
    public static function elapsed(string $from, string $to): int
    {
        return max(0, intdiv(strtotime($to) - strtotime($from), 60));
    }

    /**
     * Position d'une heure dans la ligne du jour $date, en minutes depuis minuit,
     * bornée à la journée (0 à 1440).
     */
    public static function minuteOf(string $datetime, string $date): int
    {
        return max(0, min(1440, intdiv(strtotime($datetime) - strtotime($date . ' 00:00:00'), 60)));
    }

    /** « En congé jusqu'au 20/10/2026 · note » */
    public static function reasonText(array $reason): string
    {
        $text = self::REASONS[$reason['status']][0] ?? $reason['status'];
        if (!empty($reason['until'])) {
            $text .= ' jusqu\'au ' . date('d/m/Y', strtotime($reason['until']));
        }
        if (!empty($reason['note'])) {
            $text .= ' · ' . $reason['note'];
        }
        return $text;
    }
}
