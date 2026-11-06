<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Modules\Attendance\Services\AgentPhoto;
use App\Modules\Attendance\Services\AttendanceComputer;
use App\Modules\Attendance\Services\ScheduleResolver;
use App\Modules\Platform\Services\Departments;

/**
 * Liste et fiche des employés (droit « staff »), création et modification (« manage »).
 * Province de rattachement : droit « province » (RH, profil Parc automobile, administrateur).
 * Compte utilisateur relié à la fiche : administrateur (« manage »).
 * Managers : uniquement les agents de leur département (Auth::departmentScope()).
 */
final class EmployeeController extends Controller
{
    public function index(): void
    {
        $q = mb_substr((string) $this->input('q', ''), 0, 60);
        $group = $this->input('group', '');
        $state = $this->input('etat', 'actifs');

        $where = ['1' . Departments::sql(Auth::departmentScope())];
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
            'SELECT e.*, g.name group_name, dp.name department_name, pr.name province_name,
                    (SELECT MAX(punched_at) FROM punches p WHERE p.device_user_id = e.device_user_id) last_punch
               FROM employees e LEFT JOIN employee_groups g ON g.id = e.group_id
               LEFT JOIN departments dp ON dp.id = e.department_id
               LEFT JOIN provinces pr ON pr.id = e.province_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY e.name',
            $params
        );

        $this->render('Attendance:employees', [
            'title'     => 'Employés',
            'employees' => $employees,
            'groups'    => Database::all('SELECT id, name FROM employee_groups ORDER BY name'),
            'departments' => Departments::tree(),
            'provinces' => self::provinces(),
            'q'         => $q,
            'group'     => $group,
            'state'     => $state,
        ]);
    }

    /**
     * Propositions des barres de recherche : GET /api/suggestions?q=…
     * Agents actifs dont le nom, le titre, le matricule ou le n° de pointeuse
     * correspond, dans le périmètre de l'utilisateur (managers : leur département).
     * Lien de chaque proposition : fiche employé.
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

        $items = array_map(static function (array $r): array {
            $dep = $r['department_id'] !== null ? (Departments::all()[(int) $r['department_id']]['name'] ?? null) : null;
            return [
                'id'     => (int) $r['id'],
                'name'   => $r['name'],
                'detail' => implode(' · ', array_filter([$r['job_title'], $dep, 'n° ' . $r['device_user_id']])),
                'photo'  => photo_url($r),
                'url'    => '/employes/' . (int) $r['id'],
            ];
        }, $rows);
        echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
    }

    public function show(int $id): void
    {
        $emp = Database::one(
            'SELECT e.*, g.name group_name, pr.name province_name FROM employees e LEFT JOIN employee_groups g ON g.id = e.group_id
               LEFT JOIN provinces pr ON pr.id = e.province_id WHERE e.id = ?'
                . Departments::sql(Auth::departmentScope()),
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
            'provinces'=> can('province') ? self::provinces() : [],
            // Compte relié à la fiche, et comptes libres que l'administrateur peut relier
            'account'  => can('manage') ? Database::one('SELECT id, username, full_name, role, active FROM users WHERE employee_id = ?', [$id]) : null,
            'freeAccounts' => can('manage') ? Database::all('SELECT id, username, full_name, role FROM users WHERE employee_id IS NULL ORDER BY full_name') : [],
        ]);
    }

    /** Provinces actives (assignation). */
    private static function provinces(): array
    {
        return Database::all('SELECT id, name FROM provinces WHERE active = 1 ORDER BY name');
    }

    /** Province de rattachement d'un employé (fiche employé). */
    public function province(int $id): void
    {
        $emp = Database::one('SELECT id, name, province_id FROM employees WHERE id = ?', [$id]) ?? $this->notFound();
        $province = $this->provinceInput();
        Database::run('UPDATE employees SET province_id = ? WHERE id = ?', [$province, $id]);
        Audit::log('employe_province', ['id' => $id, 'avant' => $emp['province_id'], 'province' => $province]);
        flash('success', $emp['name'] . ($province ? ' : province « ' . Database::value('SELECT name FROM provinces WHERE id = ?', [$province]) . ' ».' : ' : sans province.'));
        $this->redirect("/employes/$id");
    }

    /** Province de plusieurs employés en une fois (liste des employés). */
    public function bulkProvince(): void
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $province = $this->provinceInput();
        if (!$ids) {
            flash('error', 'Aucun employé sélectionné.');
            $this->back();
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        Database::run("UPDATE employees SET province_id = ? WHERE id IN ($in)", array_merge([$province], $ids));
        Audit::log('employes_province', ['province' => $province, 'employes' => $ids]);
        flash('success', count($ids) . ' employé(s) mis à jour.');
        $this->back();
    }

    private function provinceInput(): ?int
    {
        $province = (int) $this->input('province_id', 0) ?: null;
        if ($province !== null && !Database::value('SELECT id FROM provinces WHERE id = ? AND active = 1', [$province])) {
            flash('error', 'Province introuvable.');
            $this->back();
        }
        return $province;
    }

    /**
     * Relie un compte utilisateur existant à la fiche (ou détache le compte relié).
     * Un compte ne peut être relié qu'à une fiche, et une fiche qu'à un compte.
     * Un compte « employé », chauffeur ou validateur ne peut pas être détaché :
     * il lui faut une fiche (le relier d'abord à une autre depuis Utilisateurs).
     */
    public function account(int $id): void
    {
        $emp = Database::one('SELECT id, name FROM employees WHERE id = ?', [$id]) ?? $this->notFound();
        $current = Database::one('SELECT id, username, role, fleet_role FROM users WHERE employee_id = ?', [$id]);
        if (isset($_POST['detach'])) {
            if ($current === null) {
                $this->redirect("/employes/$id");
            }
            if ($current['role'] === 'employe' || in_array($current['fleet_role'], ['chauffeur', 'validateur'], true)) {
                flash('error', 'Le compte « ' . $current['username'] . ' » doit rester relié à une fiche (compte employé, chauffeur ou validateur).');
                $this->redirect("/employes/$id");
            }
            Database::run('UPDATE users SET employee_id = NULL WHERE id = ?', [$current['id']]);
            Audit::log('compte_detache', ['employe' => $id, 'compte' => $current['id']]);
            flash('success', 'Compte « ' . $current['username'] . ' » détaché de la fiche.');
            $this->redirect("/employes/$id");
        }
        $user = Database::one('SELECT id, username, employee_id FROM users WHERE id = ?', [(int) $this->input('user_id', 0)]);
        $error = match (true) {
            $user === null => 'Choisissez un compte.',
            $current !== null => 'Cette fiche est déjà reliée au compte « ' . $current['username'] . ' » : détachez-le d\'abord.',
            $user['employee_id'] !== null => 'Le compte « ' . $user['username'] . ' » est déjà relié à une autre fiche.',
            default => null,
        };
        if ($error) {
            flash('error', $error);
            $this->redirect("/employes/$id");
        }
        Database::run('UPDATE users SET employee_id = ? WHERE id = ? AND employee_id IS NULL', [$id, $user['id']]);
        Audit::log('compte_relie', ['employe' => $id, 'compte' => $user['id'], 'identifiant' => $user['username']]);
        flash('success', 'Compte « ' . $user['username'] . ' » relié à la fiche de ' . $emp['name'] . '.');
        $this->redirect("/employes/$id");
    }

    public function edit(int $id): void
    {
        $emp = Database::one('SELECT * FROM employees WHERE id = ?', [$id]) ?? $this->notFound();
        $this->render('Attendance:employee_form', [
            'title'  => 'Modifier ' . $emp['name'],
            'photo'  => photo_url($emp),
            'emp'    => $emp,
            'groups' => Database::all('SELECT id, name FROM employee_groups ORDER BY name'),
            'departments' => Departments::tree(),
            'provinces' => self::provinces(),
        ]);
    }

    /** Formulaire de création d'un employé (administrateur), avant ou sans synchronisation des pointeuses. */
    public function create(): void
    {
        $this->render('Attendance:employee_form', [
            'title'  => 'Nouvel employé',
            'photo'  => null,
            'emp'    => null,
            'groups' => Database::all('SELECT id, name FROM employee_groups ORDER BY name'),
            'departments' => Departments::tree(),
            'provinces' => self::provinces(),
        ]);
    }

    /**
     * Création d'un employé. Le n° de pointeuse est celui sous lequel l'agent est (ou sera)
     * enrôlé : à la synchronisation, le worker relie ses badges à cette fiche sans changer
     * le nom saisi ici (seul le nom côté pointeuse, device_name, est mis à jour).
     */
    public function store(): void
    {
        $data = $this->formData();
        $deviceId = trim((string) $this->input('device_user_id', ''));
        $error = match (true) {
            !preg_match('/^[A-Za-z0-9_-]{1,20}$/', $deviceId) => 'N° de pointeuse invalide (chiffres, lettres, - ou _, 20 caractères au plus).',
            Database::value('SELECT 1 FROM employees WHERE device_user_id = ?', [$deviceId]) !== null
                => "Le n° de pointeuse $deviceId est déjà attribué à un employé.",
            default => $this->formError($data),
        };
        if ($error) {
            $this->withInput();
            flash('error', $error);
            $this->redirect('/employes/nouveau');
        }

        $id = Database::insert(
            'INSERT INTO employees (device_user_id, name, job_title, matricule, email, phone, group_id, department_id, province_id, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$deviceId, $data['name'], $data['job_title'], $data['matricule'], $data['email'], $data['phone'],
                $data['group_id'], $data['department_id'], $data['province_id'], $data['active']]
        );
        [$photoChange] = AgentPhoto::fromForm(Database::one('SELECT * FROM employees WHERE id = ?', [$id]));
        Audit::log('employe_cree', array_filter(['id' => $id, 'nom' => $data['name'], 'pointeuse' => $deviceId,
            'departement' => $data['department_id'], 'groupe' => $data['group_id'], 'photo' => $photoChange],
            static fn($v) => $v !== null));

        // Badges déjà présents sous ce numéro (agent enrôlé avant la création de la fiche)
        if ($data['active']) {
            (new AttendanceComputer())->run(date('Y-m-01'), date('Y-m-d'), [$id]);
        }
        flash('success', 'Employé ' . $data['name'] . ' créé.');
        $this->redirect("/employes/$id");
    }

    public function update(int $id): void
    {
        $emp = Database::one('SELECT * FROM employees WHERE id = ?', [$id]) ?? $this->notFound();
        $data = $this->formData();
        if ($error = $this->formError($data)) {
            $this->withInput();
            flash('error', $error);
            $this->redirect("/employes/$id/modifier");
        }

        Database::run(
            'UPDATE employees SET name = ?, job_title = ?, matricule = ?, email = ?, phone = ?, group_id = ?, department_id = ?,
                    province_id = ?, active = ?
              WHERE id = ?',
            [$data['name'], $data['job_title'], $data['matricule'], $data['email'], $data['phone'], $data['group_id'],
                $data['department_id'], $data['province_id'], $data['active'], $id]
        );
        [$photoChange] = AgentPhoto::fromForm($emp);
        Audit::log('employe_modifie', array_filter(['id' => $id, 'avant' => $emp['name'], 'apres' => $data['name'],
            'photo' => $photoChange], static fn($v) => $v !== null));

        // Changement de groupe : l'horaire peut changer, on recalcule le mois en cours
        if ((int) $emp['group_id'] !== (int) $data['group_id']) {
            (new AttendanceComputer())->run(date('Y-m-01'), date('Y-m-d'), [$id]);
        }
        flash('success', 'Fiche employé enregistrée.');
        $this->redirect("/employes/$id");
    }

    /** Champs du formulaire employé (création et modification). */
    private function formData(): array
    {
        $data = [
            'name'      => trim(mb_substr((string) $this->input('name', ''), 0, 120)),
            'job_title' => mb_substr((string) $this->input('job_title', ''), 0, 120) ?: null,
            'department_id' => (int) $this->input('department_id', 0) ?: null,
            'matricule' => mb_substr((string) $this->input('matricule', ''), 0, 40) ?: null,
            'email'     => mb_substr((string) $this->input('email', ''), 0, 150) ?: null,
            'phone'     => mb_substr((string) $this->input('phone', ''), 0, 40) ?: null,
            'group_id'  => (int) $this->input('group_id', 0) ?: null,
            'province_id' => (int) $this->input('province_id', 0) ?: null,
            'active'    => isset($_POST['active']) ? 1 : 0,
        ];
        if ($data['province_id'] !== null && Database::value('SELECT 1 FROM provinces WHERE id = ?', [$data['province_id']]) === null) {
            $data['province_id'] = null;
        }
        if ($data['department_id'] !== null && !isset(Departments::all()[$data['department_id']])) {
            $data['department_id'] = null;
        }
        if ($data['group_id'] !== null && Database::value('SELECT 1 FROM employee_groups WHERE id = ?', [$data['group_id']]) === null) {
            $data['group_id'] = null;
        }
        return $data;
    }

    private function formError(array $data): ?string
    {
        return match (true) {
            $data['name'] === '' => 'Le nom est obligatoire.',
            $data['email'] !== null && !filter_var($data['email'], FILTER_VALIDATE_EMAIL) => 'Adresse e-mail invalide.',
            default => AgentPhoto::check(),
        };
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
