<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Modules\Attendance\Services\DashboardStats;
use App\Modules\Platform\Services\Departments;

/**
 * Présence d'une journée (ou d'une période), filtrable et exportable.
 * Manager : journée d'aujourd'hui uniquement, agents de son département.
 */
final class DailyController extends Controller
{
    private const STATUSES = ['present', 'retard', 'absent', 'sortie_manquante', 'en_cours', 'repos', 'hors_horaire'];

    public function index(): void
    {
        [$filters, $rows] = $this->query();
        $totals = [
            'worked'   => array_sum(array_column($rows, 'worked_minutes')),
            'late'     => array_sum(array_column($rows, 'late_minutes')),
            'overtime' => array_sum(array_column($rows, 'overtime_minutes')),
        ];
        $today = date('Y-m-d');
        $this->render('Attendance:daily', [
            'title'   => 'Présence',
            'filters' => $filters,
            'rows'    => $rows,
            'totals'  => $totals,
            'groups'  => Database::all('SELECT id, name FROM employee_groups ORDER BY name'),
            'departments' => Departments::tree(Departments::roots(Auth::departmentScope())),
            // Mode direct seulement pour la journée d'aujourd'hui
            'live'    => $filters['from'] === $today && $filters['to'] === $today ? DashboardStats::liveState($today) : null,
        ]);
    }

    public function export(): void
    {
        [$f, $rows] = $this->query();
        $name = sprintf('presence_%s_%s.csv', $f['from'], $f['to']);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM pour Excel
        fputcsv($out, ['Date', 'Employé', 'Matricule', 'Groupe', 'Horaire', 'Arrivée', 'Départ', 'Sortie corrigée',
            'Présence (min)', 'Retard (min)', 'Départ anticipé (min)', 'Heures sup (min)', 'Statut'], ';');
        foreach ($rows as $r) {
            fputcsv($out, [$r['work_date'], $r['name'], $r['matricule'], $r['group_name'], $r['schedule_name'],
                time_label($r['first_in']), time_label($r['last_out']), $r['corrected_out'] ? 'oui' : '',
                $r['worked_minutes'], $r['late_minutes'], $r['early_leave_minutes'], $r['overtime_minutes'],
                $r['status']], ';');
        }
        fclose($out);
    }

    private function query(): array
    {
        $date = fn($v, $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? $v : $d;
        $scope = Auth::departmentScope();
        $f = [
            'from'   => $date($this->input('from'), date('Y-m-d')),
            'to'     => null,
            'group'  => (int) $this->input('group', 0),
            'dep'    => (int) $this->input('dep', 0),
            'status' => in_array($this->input('status'), self::STATUSES, true) ? $this->input('status') : '',
            'q'      => mb_substr((string) $this->input('q', ''), 0, 60),
        ];
        $f['to'] = $date($this->input('to'), $f['from']);
        if ($f['to'] < $f['from']) {
            $f['to'] = $f['from'];
        }
        if (!Auth::can('view')) {
            // Manager : présence du jour seulement
            $f['from'] = $f['to'] = date('Y-m-d');
        }
        if ($f['dep'] && $scope !== null && !in_array($f['dep'], $scope, true)) {
            $f['dep'] = 0;
        }

        $where = ['e.active = 1', 'd.work_date BETWEEN ? AND ?'];
        $params = [$f['from'], $f['to']];
        $deps = $f['dep'] ? Departments::descendants($f['dep']) : $scope;
        $where[] = '1' . Departments::sql($deps);
        if ($f['group']) {
            $where[] = 'e.group_id = ?';
            $params[] = $f['group'];
        }
        if ($f['status']) {
            $where[] = 'd.status = ?';
            $params[] = $f['status'];
        } else {
            $where[] = "d.status <> 'repos'";
        }
        if ($f['q'] !== '') {
            $where[] = '(e.name LIKE ? OR e.matricule LIKE ? OR e.device_user_id = ?)';
            array_push($params, '%' . $f['q'] . '%', '%' . $f['q'] . '%', $f['q']);
        }

        $rows = Database::all(
            'SELECT d.*, e.name, e.matricule, e.department_id, e.photo_updated_at, g.name group_name, s.name schedule_name
               FROM attendance_days d
               JOIN employees e ON e.id = d.employee_id
               LEFT JOIN employee_groups g ON g.id = e.group_id
               LEFT JOIN schedules s ON s.id = d.schedule_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY d.work_date DESC, e.name LIMIT 5000',
            $params
        );
        return [$f, $rows];
    }
}
