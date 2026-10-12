<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use DateTimeImmutable;

/**
 * Calcul d'une journée de présence, sans accès à la base (testable seul).
 *
 * Règles :
 * - Arrivée = premier pointage d'une pointeuse « entrée » dans la fenêtre du jour.
 * - Départ  = dernier pointage d'une pointeuse « sortie » après l'arrivée,
 *             ou l'heure saisie par un admin (correction).
 * - Retard  : arrivée après début + tolérance → retard compté depuis l'heure de début.
 * - Heures sup : départ après la fin de l'horaire, comptées si elles atteignent le seuil.
 * - Pause déduite du temps de présence si la journée est plus longue que la pause.
 * - Pas de sortie : « en cours » toute la journée (le temps de présence avance
 *   sans attendre de sortie), « sortie manquante » ensuite (0 h tant que non corrigée).
 * - Une entrée badgée après la dernière sortie (agent revenu) annule cette sortie :
 *   la journée redevient « en cours », puis « sortie manquante » s'il ne ressort pas.
 * - Jour non travaillé : « repos », ou « hors horaire » si l'agent a pointé
 *   (tout le temps passé est alors compté en heures sup).
 */
final class DayCalculator
{
    /**
     * @param array|null $schedule  ligne de `schedules` (null = aucun horaire)
     * @param string[]   $ins       pointages d'entrée 'Y-m-d H:i:s' (fenêtre du jour)
     * @param string[]   $outs      pointages de sortie 'Y-m-d H:i:s' (fenêtre du jour)
     */
    public static function compute(?array $schedule, string $date, array $ins, array $outs,
                                   ?string $correctedOut, DateTimeImmutable $now): array
    {
        sort($ins);
        sort($outs);

        $day = new DateTimeImmutable($date);
        $isWorkday = $schedule !== null
            && in_array((string) $day->format('N'), array_map('trim', explode(',', $schedule['workdays'])), true);

        [$expStart, $expEnd] = $schedule ? self::expectedWindow($schedule, $date) : [null, null];

        $firstIn = $ins[0] ?? null;
        $lastOut = null;
        if ($correctedOut !== null) {
            $lastOut = $correctedOut;
        } elseif ($firstIn !== null) {
            foreach ($outs as $o) {
                if ($o > $firstIn) {
                    $lastOut = $o;
                }
            }
            // Revenu après sa dernière sortie : toujours au bureau, pas de sortie
            if ($lastOut !== null && end($ins) > $lastOut) {
                $lastOut = null;
            }
        }

        $r = [
            'schedule_id'         => $schedule['id'] ?? null,
            'expected_start'      => $isWorkday ? $expStart?->format('Y-m-d H:i:s') : null,
            'expected_end'        => $isWorkday ? $expEnd?->format('Y-m-d H:i:s') : null,
            'first_in'            => $firstIn,
            'last_out'            => $lastOut,
            'worked_minutes'      => 0,
            'late_minutes'        => 0,
            'early_leave_minutes' => 0,
            'overtime_minutes'    => 0,
            'status'              => 'absent',
        ];

        if ($firstIn === null) {
            $r['status'] = $isWorkday ? 'absent' : 'repos';
            // Journée future ou pas encore commencée : rien à reprocher
            if ($isWorkday && $expStart !== null && $now < $expStart) {
                $r['status'] = 'en_cours';
            }
            return $r;
        }

        $in = new DateTimeImmutable($firstIn);

        if ($lastOut !== null) {
            $out = new DateTimeImmutable($lastOut);
            $span = self::minutes($in, $out);
            // La pause n'est déduite que les jours travaillés
            $break = $isWorkday ? (int) ($schedule['break_minutes'] ?? 0) : 0;
            $r['worked_minutes'] = max(0, $span > $break ? $span - $break : $span);
        }

        if (!$isWorkday) {
            $r['status'] = 'hors_horaire';
            $r['overtime_minutes'] = $r['worked_minutes'];
            if ($lastOut === null && $day->format('Y-m-d') < $now->format('Y-m-d')) {
                $r['status'] = 'sortie_manquante';
            }
            return $r;
        }

        // Retard
        $tolerance = (int) $schedule['late_tolerance_minutes'];
        if ($in > $expStart->modify("+{$tolerance} minutes")) {
            $r['late_minutes'] = self::minutes($expStart, $in);
        }

        if ($lastOut !== null) {
            $out = new DateTimeImmutable($lastOut);
            if ($out < $expEnd) {
                $r['early_leave_minutes'] = self::minutes($out, $expEnd);
            } else {
                $extra = self::minutes($expEnd, $out);
                if ($extra > 0 && $extra >= (int) $schedule['overtime_after_minutes']) {
                    $r['overtime_minutes'] = $extra;
                }
            }
            $r['status'] = $r['late_minutes'] > 0 ? 'retard' : 'present';
        } else {
            // Pas de sortie : en cours jusqu'à la fin du jour (ou fin d'horaire + 6 h la nuit)
            $r['status'] = $now->format('Y-m-d') <= $date || $now <= $expEnd->modify('+6 hours')
                ? 'en_cours' : 'sortie_manquante';
        }

        return $r;
    }

    /** Début et fin attendus ; une fin avant le début signifie le lendemain. */
    public static function expectedWindow(array $schedule, string $date): array
    {
        $start = new DateTimeImmutable($date . ' ' . $schedule['start_time']);
        $end = new DateTimeImmutable($date . ' ' . $schedule['end_time']);
        if ($end <= $start) {
            $end = $end->modify('+1 day');
        }
        return [$start, $end];
    }

    /**
     * Fenêtre dans laquelle on cherche les pointages d'un jour :
     * journée calendaire, ou de début-4 h à fin+8 h pour un horaire de nuit.
     */
    public static function punchWindow(?array $schedule, string $date): array
    {
        if ($schedule !== null && $schedule['end_time'] <= $schedule['start_time']) {
            [$start, $end] = self::expectedWindow($schedule, $date);
            return [$start->modify('-4 hours')->format('Y-m-d H:i:s'), $end->modify('+8 hours')->format('Y-m-d H:i:s')];
        }
        return [$date . ' 00:00:00', $date . ' 23:59:59'];
    }

    private static function minutes(DateTimeImmutable $a, DateTimeImmutable $b): int
    {
        return intdiv($b->getTimestamp() - $a->getTimestamp(), 60);
    }
}
