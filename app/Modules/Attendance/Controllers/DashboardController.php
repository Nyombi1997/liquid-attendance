<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Core\Controller;
use App\Core\Database;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $today = date('Y-m-d');

        $counts = array_column(Database::all(
            'SELECT status, COUNT(*) n FROM attendance_days d JOIN employees e ON e.id = d.employee_id
              WHERE e.active = 1 AND d.work_date = ? GROUP BY status',
            [$today]
        ), 'n', 'status');
        $arrived = (int) Database::value(
            'SELECT COUNT(*) FROM attendance_days d JOIN employees e ON e.id = d.employee_id
              WHERE e.active = 1 AND d.work_date = ? AND d.first_in IS NOT NULL',
            [$today]
        );
        $expected = (int) Database::value(
            'SELECT COUNT(*) FROM attendance_days d JOIN employees e ON e.id = d.employee_id
              WHERE e.active = 1 AND d.work_date = ? AND d.expected_start IS NOT NULL',
            [$today]
        );
        $lateToday = (int) Database::value(
            'SELECT COUNT(*) FROM attendance_days WHERE work_date = ? AND late_minutes > 0', [$today]
        );
        $pendingCorrections = (int) Database::value(
            "SELECT COUNT(*) FROM attendance_days d JOIN employees e ON e.id = d.employee_id
              WHERE e.active = 1 AND d.status = 'sortie_manquante' AND d.work_date >= ? ",
            [date('Y-m-d', strtotime('-30 days'))]
        );

        // Tendance sur 14 jours ouvrés ou non
        $from = date('Y-m-d', strtotime('-13 days'));
        $trendRows = Database::all(
            "SELECT work_date,
                    SUM(status = 'present' OR status = 'hors_horaire') present,
                    SUM(status = 'retard') retard,
                    SUM(status = 'sortie_manquante' OR status = 'en_cours') incomplet,
                    SUM(status = 'absent') absent
               FROM attendance_days d JOIN employees e ON e.id = d.employee_id
              WHERE e.active = 1 AND work_date BETWEEN ? AND ?
              GROUP BY work_date ORDER BY work_date",
            [$from, $today]
        );
        $trend = [];
        for ($t = strtotime($from); $t <= strtotime($today); $t += 86400) {
            $trend[date('Y-m-d', $t)] = ['present' => 0, 'retard' => 0, 'incomplet' => 0, 'absent' => 0];
        }
        foreach ($trendRows as $row) {
            $trend[$row['work_date']] = array_map('intval', array_diff_key($row, ['work_date' => 1]));
        }

        $latestPunches = Database::all(
            "SELECT p.punched_at, d.name device, d.direction, d.usage_type,
                    COALESCE(e.name, CONCAT('Agent ', p.device_user_id)) employee, e.id employee_id
               FROM punches p JOIN devices d ON d.id = p.device_id
               LEFT JOIN employees e ON e.device_user_id = p.device_user_id
              ORDER BY p.punched_at DESC LIMIT 8"
        );

        $lates = Database::all(
            'SELECT e.id, e.name, d.first_in, d.late_minutes, g.name group_name
               FROM attendance_days d JOIN employees e ON e.id = d.employee_id
               LEFT JOIN employee_groups g ON g.id = e.group_id
              WHERE d.work_date = ? AND d.late_minutes > 0 AND e.active = 1
              ORDER BY d.late_minutes DESC LIMIT 6',
            [$today]
        );

        $devices = Database::all(
            'SELECT d.*, o.name office FROM devices d JOIN offices o ON o.id = d.office_id WHERE d.active = 1 ORDER BY d.usage_type DESC, o.name, d.direction'
        );

        $this->render('Attendance:dashboard', [
            'title'    => 'Tableau de bord',
            'today'    => $today,
            'arrived'  => $arrived,
            'expected' => $expected,
            'late'     => $lateToday,
            'absent'   => (int) ($counts['absent'] ?? 0),
            'pending'  => $pendingCorrections,
            'trend'    => $trend,
            'punches'  => $latestPunches,
            'lates'    => $lates,
            'devices'  => $devices,
        ]);
    }
}
