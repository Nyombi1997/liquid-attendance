<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Core\Database;
use App\Modules\Platform\Services\Departments;

/**
 * Chiffres du tableau de bord, partagés par la page (DashboardController) et
 * le mode direct (LiveController, /api/direct) : les deux affichent toujours
 * les mêmes valeurs. Requêtes légères, sur les index de attendance_days.
 *
 * $scope : départements visibles (Auth::departmentScope()), null = tous.
 */
final class DashboardStats
{
    /** Indicateurs du jour : présents / attendus, taux, retards, absents, sorties manquantes. */
    public static function kpis(string $today, ?array $scope = null): array
    {
        $day = Database::one(
            "SELECT COALESCE(SUM(d.first_in IS NOT NULL), 0) arrived,
                    COALESCE(SUM(d.expected_start IS NOT NULL), 0) expected,
                    COALESCE(SUM(d.late_minutes > 0), 0) late,
                    COALESCE(SUM(d.status = 'absent'), 0) absent
               FROM attendance_days d JOIN employees e ON e.id = d.employee_id
              WHERE e.active = 1 AND d.work_date = ?" . Departments::sql($scope),
            [$today]
        );
        $pending = (int) Database::value(
            "SELECT COUNT(*) FROM attendance_days d JOIN employees e ON e.id = d.employee_id
              WHERE e.active = 1 AND d.status = 'sortie_manquante' AND d.work_date >= ?" . Departments::sql($scope),
            [date('Y-m-d', strtotime($today . ' -30 days'))]
        );
        $arrived = (int) $day['arrived'];
        $expected = (int) $day['expected'];
        return [
            'arrived'  => $arrived,
            'expected' => $expected,
            'rate'     => $expected > 0 ? (int) round($arrived / $expected * 100) : 0,
            'late'     => (int) $day['late'],
            'absent'   => (int) $day['absent'],
            'pending'  => $pending,
        ];
    }

    /** Tendance sur 14 jours (barres empilées du tableau de bord). */
    public static function trend(string $today, ?array $scope = null): array
    {
        $from = date('Y-m-d', strtotime($today . ' -13 days'));
        $rows = Database::all(
            "SELECT work_date,
                    SUM(status = 'present' OR status = 'hors_horaire') present,
                    SUM(status = 'retard') retard,
                    SUM(status = 'sortie_manquante' OR status = 'en_cours') incomplet,
                    SUM(status = 'absent') absent
               FROM attendance_days d JOIN employees e ON e.id = d.employee_id
              WHERE e.active = 1 AND work_date BETWEEN ? AND ?" . Departments::sql($scope) . "
              GROUP BY work_date ORDER BY work_date",
            [$from, $today]
        );
        $trend = [];
        for ($t = strtotime($from); $t <= strtotime($today); $t += 86400) {
            $trend[date('Y-m-d', $t)] = ['present' => 0, 'retard' => 0, 'incomplet' => 0, 'absent' => 0];
        }
        foreach ($rows as $row) {
            $trend[$row['work_date']] = array_map('intval', array_diff_key($row, ['work_date' => 1]));
        }
        return $trend;
    }

    /** Derniers pointages, du plus récent au plus ancien (sans les agents désactivés). */
    public static function latestPunches(int $limit = 8, ?array $scope = null): array
    {
        return Database::all(
            "SELECT p.id, p.punched_at, d.name device, d.direction, d.usage_type,
                    COALESCE(e.name, CONCAT('Agent ', p.device_user_id)) employee, e.id employee_id
               FROM punches p JOIN devices d ON d.id = p.device_id
               LEFT JOIN employees e ON e.device_user_id = p.device_user_id
              WHERE (e.id IS NULL OR e.active = 1)" . Departments::sql($scope) . "
              ORDER BY p.punched_at DESC, p.id DESC LIMIT " . max(1, $limit)
        );
    }

    /** Plus forts retards du jour. */
    public static function lates(string $today, ?array $scope = null): array
    {
        return Database::all(
            'SELECT e.id, e.name, d.first_in, d.late_minutes, g.name group_name
               FROM attendance_days d JOIN employees e ON e.id = d.employee_id
               LEFT JOIN employee_groups g ON g.id = e.group_id
              WHERE d.work_date = ? AND d.late_minutes > 0 AND e.active = 1' . Departments::sql($scope) . '
              ORDER BY d.late_minutes DESC, e.name LIMIT 6',
            [$today]
        );
    }

    /** Pointeuses actives, avec leur état de synchronisation. */
    public static function devices(): array
    {
        return Database::all(
            'SELECT d.*, o.name office FROM devices d JOIN offices o ON o.id = d.office_id
              WHERE d.active = 1 ORDER BY d.usage_type DESC, o.name, d.direction'
        );
    }

    /**
     * Point de départ du mode direct pour une page qui vient d'être affichée :
     * dernier pointage connu et dernière journée du jour calculée.
     */
    public static function liveState(string $today): array
    {
        return [
            'punch' => (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM punches'),
            'day'   => (string) (Database::value('SELECT MAX(computed_at) FROM attendance_days WHERE work_date = ?', [$today])
                ?? date('Y-m-d H:i:s')),
        ];
    }
}
