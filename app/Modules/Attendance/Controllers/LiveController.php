<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Modules\Attendance\Services\DashboardStats;

/**
 * Mode « en direct » : GET /api/direct, appelé toutes les 5 s par les pages
 * qui portent data-live (public/assets/js/app.js).
 *   since_punch : dernier id de pointage connu par la page
 *   since_day   : dernier attendance_days.computed_at connu (AAAA-MM-JJ HH:MM:SS)
 * Renvoie les nouveaux pointages, les journées du jour modifiées, les
 * indicateurs du tableau de bord et l'état des pointeuses.
 * Sans session : 401 {"error":"session"} (contrôle fait par le routeur).
 */
final class LiveController extends Controller
{
    private const MAX_PUNCHES = 30;
    // Les journées sont relues avec une marge : un calcul en cours peut
    // enregistrer une journée avec une heure antérieure à la dernière vue.
    // La page ignore les lignes qui n'ont pas changé.
    private const DAY_OVERLAP_SEC = 15;

    public function index(): void
    {
        // Utilisateur vérifié par le routeur : libère le verrou de session
        // pour ne pas bloquer les autres onglets pendant la requête.
        session_write_close();

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $today = date('Y-m-d');
        $sincePunch = max(0, (int) ($_GET['since_punch'] ?? 0));
        $sinceDay = (string) ($_GET['since_day'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $sinceDay)) {
            $sinceDay = null;
        } else {
            $sinceDay = str_replace('T', ' ', $sinceDay);
        }

        $lastPunch = (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM punches');
        [$days, $lastDay] = $this->days($today, $sinceDay);

        echo json_encode([
            'server_time'          => date('Y-m-d H:i:s'),
            'date'                 => $today,
            'last_punch_id'        => $lastPunch,
            'punches'              => $sincePunch > 0 && $lastPunch > $sincePunch ? $this->punches($sincePunch) : [],
            'days'                 => $days,
            'last_day_computed_at' => $lastDay,
            'kpis'                 => DashboardStats::kpis($today),
            'lates'                => array_map(static fn(array $l) => [
                'id'          => (int) $l['id'],
                'name'        => $l['name'],
                'group_name'  => $l['group_name'] ?? 'Sans groupe',
                'first_in'    => time_label($l['first_in']),
                'late_minutes'=> (int) $l['late_minutes'],
            ], DashboardStats::lates($today)),
            'devices'              => $this->devices(),
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Nouveaux pointages (index primaire), du plus récent au plus ancien. */
    private function punches(int $since): array
    {
        $rows = Database::all(
            "SELECT p.id, p.punched_at, p.device_user_id, d.id device_id, d.name device, d.direction, d.usage_type,
                    e.id employee_id, COALESCE(e.name, CONCAT('Agent ', p.device_user_id)) employee
               FROM punches p JOIN devices d ON d.id = p.device_id
               LEFT JOIN employees e ON e.device_user_id = p.device_user_id
              WHERE p.id > ? ORDER BY p.id DESC LIMIT " . self::MAX_PUNCHES,
            [$since]
        );
        usort($rows, static fn($a, $b) => [$b['punched_at'], $b['id']] <=> [$a['punched_at'], $a['id']]);
        return array_map(static fn(array $p) => [
            'id'         => (int) $p['id'],
            'punched_at' => $p['punched_at'],
            'time'       => date('d/m H:i', strtotime($p['punched_at'])),
            'employee'   => ['id' => $p['employee_id'] !== null ? (int) $p['employee_id'] : null, 'name' => $p['employee']],
            'device'     => ['id' => (int) $p['device_id'], 'name' => $p['device']],
            'direction'  => $p['direction'],
            'usage_type' => $p['usage_type'],
        ], $rows);
    }

    /** Journées du jour modifiées depuis $since, avec les libellés déjà formatés. */
    private function days(string $today, ?string $since): array
    {
        $last = Database::value('SELECT MAX(computed_at) FROM attendance_days WHERE work_date = ?', [$today]);
        if ($since === null) {
            return [[], $last];
        }
        $rows = Database::all(
            'SELECT d.employee_id, d.first_in, d.last_out, d.corrected_out, d.correction_note, d.worked_minutes,
                    d.late_minutes, d.overtime_minutes, d.status, d.computed_at,
                    e.name, e.matricule, e.device_user_id, e.group_id
               FROM attendance_days d JOIN employees e ON e.id = d.employee_id
              WHERE d.computed_at >= ? - INTERVAL ' . self::DAY_OVERLAP_SEC . ' SECOND
                AND d.work_date = ? AND e.active = 1
              ORDER BY d.computed_at LIMIT 500',
            [$since, $today]
        );
        $days = array_map(static function (array $d): array {
            [$statusLabel, $statusClass] = status_info($d['status']);
            return [
                'employee_id'      => (int) $d['employee_id'],
                'name'             => $d['name'],
                'matricule'        => $d['matricule'],
                'device_user_id'   => $d['device_user_id'],
                'group_id'         => $d['group_id'] !== null ? (int) $d['group_id'] : null,
                'first_in'         => $d['first_in'],
                'last_out'         => $d['last_out'],
                'worked_minutes'   => (int) $d['worked_minutes'],
                'late_minutes'     => (int) $d['late_minutes'],
                'overtime_minutes' => (int) $d['overtime_minutes'],
                'status'           => $d['status'],
                'computed_at'      => $d['computed_at'],
                'corrected'        => $d['corrected_out'] !== null,
                'correction_note'  => $d['correction_note'] ?? 'Saisie manuelle',
                'labels'           => [
                    'first_in' => time_label($d['first_in']),
                    'last_out' => time_label($d['last_out']),
                    'worked'   => minutes_label((int) $d['worked_minutes']),
                    'late'     => late_label((int) $d['late_minutes']),
                    'overtime' => minutes_label((int) $d['overtime_minutes']),
                    'status'   => $statusLabel,
                    'status_class' => 'badge-' . $statusClass,
                ],
            ];
        }, $rows);
        return [$days, max((string) $last, $since)];
    }

    private function devices(): array
    {
        return array_map(static fn(array $d) => [
            'id'               => (int) $d['id'],
            'active'           => (bool) $d['active'],
            'last_sync_ok'     => $d['last_sync_ok'] !== null ? (int) $d['last_sync_ok'] : null,
            'last_sync_at'     => $d['last_sync_at'],
            'last_error'       => $d['last_error'],
            'last_event_at'    => $d['last_event_at'],
            'listening'        => $d['live_since'] !== null,
            'clock_offset_sec' => $d['clock_offset_sec'] !== null ? (int) $d['clock_offset_sec'] : null,
        ], Database::all(
            'SELECT id, active, last_sync_ok, last_sync_at, last_error, last_event_at, live_since, clock_offset_sec
               FROM devices ORDER BY id'
        ));
    }
}
