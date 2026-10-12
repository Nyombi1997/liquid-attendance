<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Modules\Attendance\Services\Calendar;
use App\Modules\Attendance\Services\DashboardStats;
use App\Modules\Attendance\Services\WorkStatuses;
use App\Modules\Platform\Services\Departments;

/**
 * Calendrier de présence (droit « calendar » : administrateur, RH, managers) :
 * une ligne par agent, temps passé au bureau (en direct pour aujourd'hui),
 * raisons d'absence (télétravail, mission, congé) et absences en rouge.
 *   vue=jour    : ligne des heures de 0 h à 24 h (barre arrivée → départ)
 *   vue=semaine : 7 jours, vue=mois : le mois entier (une case par jour)
 * Managers : agents de leur département seulement (Auth::departmentScope()).
 * presence=venus : seulement les agents venus au bureau (badge d'entrée) dans
 * la période, même s'ils sont déjà sortis.
 */
final class CalendarController extends Controller
{
    private const VIEWS = ['jour' => 'Jour', 'semaine' => 'Semaine', 'mois' => 'Mois'];

    public function index(): void
    {
        $scope = Auth::departmentScope();
        $now = date('Y-m-d H:i:s');
        $today = substr($now, 0, 10);

        $view = (string) $this->input('vue', 'jour');
        if (!isset(self::VIEWS[$view])) {
            $view = 'jour';
        }
        $date = (string) $this->input('date', $today);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
            $date = $today;
        }
        [$from, $to, $prev, $next] = $this->range($view, $date);

        // Département choisi (avec ses sous-départements), dans le périmètre
        $dep = (int) $this->input('dep', 0);
        if ($dep && $scope !== null && !in_array($dep, $scope, true)) {
            $dep = 0;
        }
        $q = mb_substr((string) $this->input('q', ''), 0, 60);
        $presence = $this->input('presence') === 'venus' ? 'venus' : '';

        $where = 'e.active = 1' . Departments::sql($scope) . ($dep ? Departments::sql(Departments::descendants($dep)) : '');
        $params = [];
        if ($q !== '') {
            $where .= ' AND (e.name LIKE ? OR e.job_title LIKE ? OR e.matricule LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like);
        }
        $employees = Database::all(
            'SELECT e.id, e.name, e.job_title, e.department_id, e.photo_updated_at
               FROM employees e WHERE ' . $where . ' ORDER BY e.name LIMIT 500',
            $params
        );
        $ids = array_map(static fn(array $e) => (int) $e['id'], $employees);

        $days = [];
        if ($ids) {
            foreach (Database::all(
                'SELECT employee_id, work_date, first_in, last_out, worked_minutes, late_minutes, status, expected_start, expected_end
                   FROM attendance_days
                  WHERE employee_id IN (' . implode(',', $ids) . ') AND work_date BETWEEN ? AND ?',
                [$from, $to]
            ) as $d) {
                $days[(int) $d['employee_id']][$d['work_date']] = $d;
            }
        }
        $reasons = WorkStatuses::reasons($ids, $from, $to);

        $dates = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $dates[] = $d;
        }
        $rows = [];
        foreach ($employees as $e) {
            $id = (int) $e['id'];
            if ($presence === 'venus' && !array_filter($days[$id] ?? [], static fn(array $d) => $d['first_in'] !== null)) {
                continue;
            }
            $cells = [];
            $total = 0;
            $absent = 0;
            foreach ($dates as $d) {
                $cell = Calendar::cell($days[$id][$d] ?? null, $reasons[$id][$d] ?? null, $d, $now);
                $total += $cell['kind'] === 'ongoing' || $cell['kind'] === 'work' ? $cell['minutes'] : 0;
                $absent += $cell['kind'] === 'absent' ? 1 : 0;
                $cells[$d] = $cell;
            }
            $rows[] = $e + [
                'cells'      => $cells,
                'total'      => $total,
                'absent'     => $absent,
                'department' => Departments::all()[(int) $e['department_id']]['name'] ?? '',
            ];
        }

        $this->render('Attendance:calendar', [
            'title'   => 'Calendrier',
            'views'   => self::VIEWS,
            'view'    => $view,
            'date'    => $date,
            'from'    => $from,
            'to'      => $to,
            'prev'    => $prev,
            'next'    => $next,
            'dates'   => $dates,
            'rows'    => $rows,
            'dep'     => $dep,
            'q'       => $q,
            'presence' => $presence,
            'today'   => $today,
            'now'     => $now,
            'departments' => Departments::tree(Departments::roots($scope)),
            // Mode direct si la période affichée contient aujourd'hui
            'live'    => $from <= $today && $today <= $to ? DashboardStats::liveState($today) : null,
        ]);
    }

    /** Premier et dernier jour affichés, dates des périodes précédente et suivante. */
    private function range(string $view, string $date): array
    {
        return match ($view) {
            'semaine' => [
                $monday = date('Y-m-d', strtotime($date . ' -' . (date('N', strtotime($date)) - 1) . ' days')),
                date('Y-m-d', strtotime($monday . ' +6 days')),
                date('Y-m-d', strtotime($monday . ' -7 days')),
                date('Y-m-d', strtotime($monday . ' +7 days')),
            ],
            'mois' => [
                date('Y-m-01', strtotime($date)),
                date('Y-m-t', strtotime($date)),
                date('Y-m-01', strtotime(date('Y-m-01', strtotime($date)) . ' -1 month')),
                date('Y-m-01', strtotime(date('Y-m-01', strtotime($date)) . ' +1 month')),
            ],
            default => [
                $date,
                $date,
                date('Y-m-d', strtotime($date . ' -1 day')),
                date('Y-m-d', strtotime($date . ' +1 day')),
            ],
        };
    }
}
