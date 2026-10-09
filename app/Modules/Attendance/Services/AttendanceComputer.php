<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Core\Database;
use DateTimeImmutable;

/**
 * Recalcule les journées (table attendance_days) sur une période.
 * Appelé par le service de synchronisation et après chaque changement
 * d'horaire, d'attribution ou de correction.
 */
final class AttendanceComputer
{
    public function run(string $from, string $to, ?array $employeeIds = null): int
    {
        $now = new DateTimeImmutable();
        $today = $now->format('Y-m-d');
        if ($to > $today) {
            $to = $today;
        }
        if ($from > $to) {
            return 0;
        }

        $resolver = new ScheduleResolver();

        $sql = 'SELECT id, device_user_id, group_id, created_at FROM employees WHERE active = 1';
        $params = [];
        if ($employeeIds) {
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($employeeIds), '?')) . ')';
            $params = array_values($employeeIds);
        }
        $employees = Database::all($sql, $params);
        if (!$employees) {
            return 0;
        }

        // Pointages de présence de la période (avec marge pour les horaires de nuit)
        $punches = [];
        $rows = Database::all(
            "SELECT p.device_user_id, p.punched_at, d.direction
               FROM punches p JOIN devices d ON d.id = p.device_id
              WHERE d.usage_type = 'presence'
                AND p.punched_at BETWEEN ? AND ?",
            [$from . ' 00:00:00', (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 23:59:59']
        );
        foreach ($rows as $p) {
            $punches[$p['device_user_id']][$p['direction']][] = $p['punched_at'];
        }

        // Corrections existantes (à conserver)
        $corrections = [];
        foreach (Database::all(
            'SELECT employee_id, work_date, corrected_out FROM attendance_days
              WHERE work_date BETWEEN ? AND ? AND corrected_out IS NOT NULL',
            [$from, $to]
        ) as $c) {
            $corrections[$c['employee_id'] . '|' . $c['work_date']] = $c['corrected_out'];
        }

        $pdo = Database::pdo();
        $upsert = $pdo->prepare(
            'INSERT INTO attendance_days
                (employee_id, work_date, schedule_id, expected_start, expected_end, first_in, last_out,
                 worked_minutes, late_minutes, early_leave_minutes, overtime_minutes, status, computed_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE schedule_id = VALUES(schedule_id), expected_start = VALUES(expected_start),
                expected_end = VALUES(expected_end), first_in = VALUES(first_in), last_out = VALUES(last_out),
                worked_minutes = VALUES(worked_minutes), late_minutes = VALUES(late_minutes),
                early_leave_minutes = VALUES(early_leave_minutes), overtime_minutes = VALUES(overtime_minutes),
                status = VALUES(status), computed_at = NOW()'
        );

        $count = 0;
        $pdo->beginTransaction();
        foreach ($employees as $emp) {
            $own = $punches[$emp['device_user_id']] ?? [];
            // Pas de journées « absent » avant l'apparition de l'agent dans le système
            $firstSeen = min(array_merge($own['entree'] ?? [], $own['sortie'] ?? [], [$emp['created_at']]));
            $start = max($from, substr($firstSeen, 0, 10));

            for ($d = new DateTimeImmutable($start); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
                $date = $d->format('Y-m-d');
                $schedule = $resolver->resolve($emp, $date);
                [$wStart, $wEnd] = DayCalculator::punchWindow($schedule, $date);
                $in = array_values(array_filter($own['entree'] ?? [], fn($t) => $t >= $wStart && $t <= $wEnd));
                $out = array_values(array_filter($own['sortie'] ?? [], fn($t) => $t >= $wStart && $t <= $wEnd));

                $r = DayCalculator::compute($schedule, $date, $in, $out,
                    $corrections[$emp['id'] . '|' . $date] ?? null, $now);

                $upsert->execute([
                    $emp['id'], $date, $r['schedule_id'], $r['expected_start'], $r['expected_end'],
                    $r['first_in'], $r['last_out'], $r['worked_minutes'], $r['late_minutes'],
                    $r['early_leave_minutes'], $r['overtime_minutes'], $r['status'],
                ]);
                $count++;
            }
        }
        $pdo->commit();
        return $count;
    }
}
