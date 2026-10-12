<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Modules\Attendance\Services\AttendanceComputer;
use App\Modules\Attendance\Services\ScheduleResolver;
use App\Modules\Platform\Services\Departments;

final class EmployeeController extends Controller
{
    public function index(): void
    {
        $q = mb_substr((string) $this->input('q', ''), 0, 60);
        $group = $this->input('group', '');
        $state = $this->input('etat', 'actifs');

        $where = ['1 = 1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(e.name LIKE ? OR e.device_name LIKE ? OR e.matricule LIKE ? OR e.device_user_id = ?)';
            array_push($params, "%$q%", "%$q%", "%$q%", $q);
        }
        if ($group === 'aucun') {
            $where[] = 'e.group_id IS NULL';
        } elseif ((int) $group > 0) {
            $where[] = 'e.group_id = ?';
            $params[] = (int) $group;
        }
        if ($state !== 'tous') {
            $where[] = 'e.active = ' . ($state === 'inactifs' ? 0 : 1);
        }

        $employees = Database::all(
            'SELECT e.*, g.name group_name, dp.name department_name,
                    (SELECT MAX(punched_at) FROM punches p WHERE p.device_user_id = e.device_user_id) last_punch
               FROM employees e LEFT JOIN employee_groups g ON g.id = e.group_id
               LEFT JOIN departments dp ON dp.id = e.department_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY e.name',
            $params
        );

        $this->render('Attendance:employees', [
            'title'     => 'Employés',
            'employees' => $employees,
            'groups'    => Database::all('SELECT id, name FROM employee_groups ORDER BY name'),
            'departments' => Departments::tree(),
            'q'         => $q,
            'group'     => $group,
            'state'     => $state,
        ]);
    }

    /**
     * Propositions des barres de recherche : GET /api/suggestions?q=…
     * Agents actifs dont le nom, le titre, le matricule ou le n° de pointeuse
     * correspond, dans le périmètre de l'utilisateur (managers : leur département).
     * Lien de chaque proposition : fiche employé, ou page Agents sans droit « staff ».
     */
    public function suggest(): void
    {
        session_write_close();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 60);
        if ($q === '') {
            echo json_encode(['items' => []]);
            return;
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $rows = Database::all(
            'SELECT e.id, e.name, e.job_title, e.device_user_id, e.matricule, e.department_id, e.photo_updated_at
               FROM employees e
              WHERE e.active = 1
                AND (e.name LIKE ? OR e.device_name LIKE ? OR e.job_title LIKE ? OR e.matricule LIKE ? OR e.device_user_id = ?)'
                . Departments::sql(Auth::departmentScope()) . '
              ORDER BY e.name LIKE ? DESC, e.name
              LIMIT 8',
            [$like, $like, $like, $like, $q, addcslashes($q, '%_\\') . '%']
        );

        $staff = Auth::can('staff');
        $items = array_map(static function (array $r) use ($staff): array {
            $dep = $r['department_id'] !== null ? (Departments::all()[(int) $r['department_id']]['name'] ?? null) : null;
            return [
                'id'     => (int) $r['id'],
                'name'   => $r['name'],
                'detail' => implode(' · ', array_filter([$r['job_title'], $dep, 'n° ' . $r['device_user_id']])),
                'photo'  => photo_url($r),
                'url'    => $staff ? '/employes/' . (int) $r['id'] : '/agents?q=' . rawurlencode($r['name']),
            ];
        }, $rows);
        echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
    }

    public function show(int $id): void
    {
        $emp = Database::one(
            'SELECT e.*, g.name group_name FROM employees e LEFT JOIN employee_groups g ON g.id = e.group_id WHERE e.id = ?',
            [$id]
        ) ?? $this->notFound();

        $month = (string) $this->input('mois', date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }
        $from = $month . '-01';
        $to = date('Y-m-t', strtotime($from));

        $days = Database::all(
            'SELECT d.*, s.name schedule_name FROM attendance_days d LEFT JOIN schedules s ON s.id = d.schedule_id
              WHERE d.employee_id = ? AND d.work_date BETWEEN ? AND ? ORDER BY d.work_date DESC',
            [$id, $from, $to]
        );
        $stats = ['worked' => 0, 'late' => 0, 'late_days' => 0, 'overtime' => 0, 'absent' => 0, 'missing' => 0, 'present' => 0];
        foreach ($days as $d) {
            $stats['worked'] += $d['worked_minutes'];
            $stats['late'] += $d['late_minutes'];
            $stats['overtime'] += $d['overtime_minutes'];
            $stats['late_days'] += $d['late_minutes'] > 0 ? 1 : 0;
            $stats['absent'] += $d['status'] === 'absent' ? 1 : 0;
            $stats['missing'] += $d['status'] === 'sortie_manquante' ? 1 : 0;
            $stats['present'] += $d['first_in'] ? 1 : 0;
        }

        $schedule = (new ScheduleResolver())->resolve($emp, date('Y-m-d'));
        $access = Database::all(
            "SELECT p.punched_at, d.name device FROM punches p JOIN devices d ON d.id = p.device_id
              WHERE p.device_user_id = ? AND d.usage_type = 'acces' ORDER BY p.punched_at DESC LIMIT 10",
            [$emp['device_user_id']]
        );

        $this->render('Attendance:employee', [
            'title'    => $emp['name'],
            'emp'      => $emp,
            'month'    => $month,
            'days'     => $days,
            'stats'    => $stats,
            'schedule' => $schedule,
            'access'   => $access,
        ]);
    }

    public function edit(int $id): void
    {
        $emp = Database::one('SELECT * FROM employees WHERE id = ?', [$id]) ?? $this->notFound();
        $this->render('Attendance:employee_form', [
            'title'  => 'Modifier ' . $emp['name'],
            'emp'    => $emp,
            'groups' => Database::all('SELECT id, name FROM employee_groups ORDER BY name'),
            'departments' => Departments::tree(),
        ]);
    }

    public function update(int $id): void
    {
        $emp = Database::one('SELECT * FROM employees WHERE id = ?', [$id]) ?? $this->notFound();
        $data = [
            'name'      => mb_substr((string) $this->input('name', ''), 0, 120),
            'job_title' => mb_substr((string) $this->input('job_title', ''), 0, 120) ?: null,
            'department_id' => (int) $this->input('department_id', 0) ?: null,
            'matricule' => mb_substr((string) $this->input('matricule', ''), 0, 40) ?: null,
            'email'     => mb_substr((string) $this->input('email', ''), 0, 150) ?: null,
            'phone'     => mb_substr((string) $this->input('phone', ''), 0, 40) ?: null,
            'group_id'  => (int) $this->input('group_id', 0) ?: null,
            'active'    => isset($_POST['active']) ? 1 : 0,
        ];
        if ($data['name'] === '') {
            $this->withInput();
            flash('error', 'Le nom est obligatoire.');
            $this->redirect("/employes/$id/modifier");
        }
        if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $this->withInput();
            flash('error', 'Adresse e-mail invalide.');
            $this->redirect("/employes/$id/modifier");
        }

        if ($data['department_id'] !== null && !isset(Departments::all()[$data['department_id']])) {
            $data['department_id'] = null;
        }

        Database::run(
            'UPDATE employees SET name = ?, job_title = ?, matricule = ?, email = ?, phone = ?, group_id = ?, department_id = ?, active = ?
              WHERE id = ?',
            [$data['name'], $data['job_title'], $data['matricule'], $data['email'], $data['phone'], $data['group_id'],
                $data['department_id'], $data['active'], $id]
        );
        Audit::log('employe_modifie', ['id' => $id, 'avant' => $emp['name'], 'apres' => $data['name']]);

        // Changement de groupe : l'horaire peut changer, on recalcule le mois en cours
        if ((int) $emp['group_id'] !== (int) $data['group_id']) {
            (new AttendanceComputer())->run(date('Y-m-01'), date('Y-m-d'), [$id]);
        }
        flash('success', 'Fiche employé enregistrée.');
        $this->redirect("/employes/$id");
    }

    /** Affecte plusieurs employés à un département en une fois (sans recalcul : pas d'effet sur les horaires). */
    public function bulkDepartment(): void
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $depId = (int) $this->input('department_id', 0) ?: null;
        if (!$ids || ($depId !== null && !isset(Departments::all()[$depId]))) {
            flash('error', $ids ? 'Département introuvable.' : 'Aucun employé sélectionné.');
            $this->back();
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        Database::run("UPDATE employees SET department_id = ? WHERE id IN ($in)", array_merge([$depId], $ids));
        Audit::log('employes_departement', ['departement' => $depId, 'employes' => $ids]);
        flash('success', count($ids) . ' employé(s) mis à jour.');
        $this->back();
    }

    /** Affecte plusieurs employés à un groupe en une fois. */
    public function bulkGroup(): void
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $groupId = (int) $this->input('group_id', 0) ?: null;
        if (!$ids) {
            flash('error', 'Aucun employé sélectionné.');
            $this->back();
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        Database::run("UPDATE employees SET group_id = ? WHERE id IN ($in)", array_merge([$groupId], $ids));
        (new AttendanceComputer())->run(date('Y-m-01'), date('Y-m-d'), $ids);
        Audit::log('employes_groupe', ['groupe' => $groupId, 'employes' => $ids]);
        flash('success', count($ids) . ' employé(s) mis à jour.');
        $this->back();
    }
}
