<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Xlsx;
use App\Modules\Attendance\Services\AgentPhoto;
use App\Modules\Attendance\Services\AgentStatus;
use App\Modules\Attendance\Services\DashboardStats;
use App\Modules\Attendance\Services\WorkStatuses;
use App\Modules\Platform\Services\Departments;

/**
 * Page « Agents » : une carte par agent (photo, titre, contacts, statut de
 * travail, heure d'arrivée du jour), filtrable par département.
 * Managers : leur département seulement (Auth::departmentScope()).
 * Modification de la fiche, de la photo et désactivation : droit « manage » (administrateur) ;
 * disponibilité (statut de travail) : droit « status » (administrateur et RH),
 * conservée dans l'historique work_statuses (affiché par le calendrier).
 * Rapport Excel de la vue filtrée : droit « export » (administrateur et RH).
 */
final class AgentController extends Controller
{
    public function index(): void
    {
        $today = date('Y-m-d');
        $scope = Auth::departmentScope();
        [$dep, $agents, $counts] = $this->agents((string) $this->input('dep', ''), $today);

        $this->render('Attendance:agents', [
            'title'       => 'Agents',
            'agents'      => $agents,
            'counts'      => $counts,
            'dep'         => $dep,
            'tree'        => $this->tree($scope),
            'departments' => Departments::tree(),
            'today'       => $today,
            'live'        => DashboardStats::liveState($today),
        ]);
    }

    /**
     * Rapport Excel de la page Agents (droit « export » : administrateur et RH),
     * avec les mêmes critères que l'écran : département, puce de filtre, recherche.
     */
    public function export(): void
    {
        $today = date('Y-m-d');
        [$dep, $agents, $counts] = $this->agents((string) $this->input('dep', ''), $today);
        $filter = (string) $this->input('filtre', 'tous');
        if (!isset(AgentStatus::FILTERS[$filter])) {
            $filter = 'tous';
        }
        $search = trim(mb_substr((string) $this->input('q', ''), 0, 60));
        $q = self::fold($search);

        $agents = array_values(array_filter($agents, static fn(array $a) => in_array($filter, $a['filters'], true)
            && ($q === '' || str_contains(self::fold(implode(' ', array_filter([$a['name'], $a['job_title'], $a['email'],
                $a['phone'], $a['department'], $a['group_name']]))), $q))));

        $depLabel = match (true) {
            $dep === 'aucun' => 'Sans département',
            $dep !== ''      => Departments::path((int) $dep),
            default          => 'Tous',
        };
        $criteria = 'Filtre : ' . AgentStatus::FILTERS[$filter] . ' · Département : ' . $depLabel
            . ($q !== '' ? ' · Recherche : « ' . $search . ' »' : '');

        $rows = [
            ['Rapport des agents · ' . date_fr($today)],
            [$criteria],
            ['Généré le ' . date('d/m/Y à H:i') . ' par ' . (Auth::user()['full_name'] ?: Auth::user()['username']) . ' · ' . count($agents) . ' agent(s)'],
            [],
            ['Nom', 'Titre', 'Département', 'Groupe', 'E-mail', 'Téléphone', 'Disponibilité', 'Jusqu\'au', 'Note',
                'Arrivée', 'Retard (min)', 'Situation'],
        ];
        foreach ($agents as $a) {
            $w = $a['work'];
            $arr = $a['arrival'];
            $rows[] = [
                $a['name'], $a['job_title'], $a['department'], $a['group_name'], $a['email'], $a['phone'],
                $w['label'],
                $w['manual'] && $a['work_status_until'] ? date('d/m/Y', strtotime($a['work_status_until'])) : null,
                $w['manual'] ? $a['work_status_note'] : null,
                in_array($arr['kind'], ['ontime', 'late'], true) ? $arr['label'] : null,
                $arr['kind'] === 'late' ? (int) $a['late_minutes'] : null,
                self::SITUATIONS[$arr['kind']] ?? $arr['title'],
            ];
        }

        // Synthèse : nombre d'agents par puce (département et recherche appliqués)
        $summary = [['Synthèse · ' . date_fr($today)], ['Département : ' . $depLabel], [], ['Filtre', 'Agents']];
        foreach (AgentStatus::FILTERS as $k => $label) {
            $summary[] = [$label, (int) $counts[$k]];
        }

        Audit::log('agents_export', ['filtre' => $filter, 'departement' => $dep ?: null, 'recherche' => $q ?: null, 'lignes' => count($agents)]);
        Xlsx::send(sprintf('agents_%s_%s.xlsx', $today, $filter), [
            ['name' => 'Agents', 'rows' => $rows, 'header' => 4, 'title' => 3,
             'widths' => [28, 26, 30, 16, 32, 18, 20, 12, 30, 10, 12, 30]],
            ['name' => 'Synthèse', 'rows' => $summary, 'header' => 3, 'title' => 2, 'widths' => [24, 10]],
        ]);
    }

    /** Libellé de la colonne « Situation » selon l'arrivée (AgentStatus::arrival()) ; sinon le détail. */
    private const SITUATIONS = [
        'ontime'  => 'À l\'heure',
        'late'    => 'En retard',
        'missing' => 'Absent (pas arrivé)',
    ];

    /**
     * Agents actifs du périmètre (département choisi compris) avec leur statut du jour.
     * @return array{0:string, 1:array, 2:array} département retenu, agents, nombre par puce
     */
    private function agents(string $dep, string $today): array
    {
        $scope = Auth::departmentScope();

        // Département choisi dans l'arbre (avec ses sous-départements)
        $depFilter = '';
        if ($dep === 'aucun' && $scope === null) {
            $depFilter = ' AND e.department_id IS NULL';
        } elseif ((int) $dep > 0 && ($scope === null || in_array((int) $dep, $scope, true))) {
            $depFilter = Departments::sql(Departments::descendants((int) $dep));
        } else {
            $dep = '';
        }

        $rows = Database::all(
            'SELECT e.id, e.name, e.job_title, e.email, e.phone, e.department_id, e.group_id, e.photo_updated_at,
                    e.work_status, e.work_status_until, e.work_status_note,
                    g.name group_name, d.first_in, d.late_minutes, d.status day_status, d.expected_start
               FROM employees e
               LEFT JOIN employee_groups g ON g.id = e.group_id
               LEFT JOIN attendance_days d ON d.employee_id = e.id AND d.work_date = ?
              WHERE e.active = 1' . Departments::sql($scope) . $depFilter . '
              ORDER BY e.name',
            [$today]
        );

        $counts = array_fill_keys(array_keys(AgentStatus::FILTERS), 0);
        $agents = [];
        foreach ($rows as $r) {
            $day = $r['day_status'] !== null ? ['first_in' => $r['first_in'], 'late_minutes' => $r['late_minutes'],
                'status' => $r['day_status'], 'expected_start' => $r['expected_start']] : null;
            $r['work'] = AgentStatus::work($r, $r['first_in'], $today);
            $r['arrival'] = AgentStatus::arrival($day, $r['work']['key']);
            $depId = $r['department_id'] !== null ? (int) $r['department_id'] : null;
            $r['department'] = Departments::path($depId);
            $r['department_name'] = Departments::all()[$depId]['name'] ?? '';
            $r['filters'] = AgentStatus::filters($r['work']['key'], $r['arrival']['kind']);
            foreach ($r['filters'] as $f) {
                $counts[$f]++;
            }
            $agents[] = $r;
        }
        return [$dep, $agents, $counts];
    }

    /** Minuscules sans accents (même recherche que fold() dans app.js). */
    private static function fold(string $s): string
    {
        $s = mb_strtolower(trim($s));
        return strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e',
            'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n']);
    }

    /** Arbre des départements avec le nombre d'agents (sous-départements compris). */
    private function tree(?array $scope): array
    {
        $direct = [];
        foreach (Database::all('SELECT e.department_id, COUNT(*) n FROM employees e WHERE e.active = 1'
            . Departments::sql($scope) . ' GROUP BY e.department_id') as $c) {
            $direct[(int) $c['department_id']] = (int) $c['n']; // 0 : sans département
        }
        // Manager : l'arbre commence à son département
        $nodes = Departments::tree(Departments::roots($scope));
        foreach ($nodes as &$n) {
            $n['count'] = array_sum(array_map(static fn(int $id) => $direct[$id] ?? 0, Departments::descendants($n['id'])));
        }
        unset($n);
        return [
            'nodes' => $nodes,
            'total' => array_sum($direct),
            'none'  => $scope === null ? ($direct[0] ?? 0) : null,
        ];
    }

    /** Photo d'un agent (fichier hors du dossier public, contrôle du périmètre). */
    public function photo(int $id): void
    {
        session_write_close();
        $emp = Database::one('SELECT id, department_id, photo_updated_at FROM employees WHERE id = ?', [$id]);
        $scope = Auth::departmentScope();
        $file = AgentPhoto::path($id);
        if (!$emp || !$emp['photo_updated_at'] || !is_file($file)
            || ($scope !== null && !in_array((int) $emp['department_id'], $scope, true))) {
            http_response_code(404);
            return;
        }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($file));
        // URL versionnée (?v=…, helper photo_url) : contenu figé, cache privé d'un an
        header('Cache-Control: private, max-age=31536000, immutable');
        readfile($file);
    }

    /** Fiche de la carte : nom, titre, contacts, département, photo recadrée. */
    public function update(int $id): void
    {
        $emp = Database::one('SELECT * FROM employees WHERE id = ?', [$id]) ?? $this->notFound();
        $back = $this->backUrl();
        $data = [
            'name'          => mb_substr((string) $this->input('name', ''), 0, 120),
            'job_title'     => mb_substr((string) $this->input('job_title', ''), 0, 120) ?: null,
            'email'         => mb_substr((string) $this->input('email', ''), 0, 150) ?: null,
            'phone'         => mb_substr((string) $this->input('phone', ''), 0, 40) ?: null,
            'department_id' => (int) $this->input('department_id', 0) ?: null,
            'active'        => isset($_POST['active']) ? 1 : 0,
        ];
        $error = match (true) {
            $data['name'] === '' => 'Le nom est obligatoire.',
            $data['email'] !== null && !filter_var($data['email'], FILTER_VALIDATE_EMAIL) => 'Adresse e-mail invalide.',
            $data['phone'] !== null && !preg_match('/^[0-9+().\/\s-]{4,40}$/', $data['phone']) => 'Numéro de téléphone invalide.',
            $data['department_id'] !== null && !isset(Departments::all()[$data['department_id']]) => 'Département introuvable.',
            default => null,
        };

        $error ??= AgentPhoto::check();
        if ($error) {
            flash('error', $error);
            $this->redirect($back);
        }

        Database::run(
            'UPDATE employees SET name = ?, job_title = ?, email = ?, phone = ?, department_id = ?, active = ? WHERE id = ?',
            [$data['name'], $data['job_title'], $data['email'], $data['phone'], $data['department_id'], $data['active'], $id]
        );

        [$photoChange] = AgentPhoto::fromForm($emp);

        Audit::log('agent_modifie', array_filter([
            'id' => $id, 'nom' => $data['name'], 'titre' => $data['job_title'], 'departement' => $data['department_id'],
            'photo' => $photoChange, 'actif' => $data['active'] !== (int) $emp['active'] ? $data['active'] : null,
        ], static fn($v) => $v !== null));
        flash('success', $data['active'] ? 'Fiche de ' . $data['name'] . ' enregistrée.'
            : $data['name'] . ' est désactivé : ses badges ne sont plus comptés ni affichés (réactivation : Employés → Inactifs).');
        $this->redirect($back);
    }

    /** Disponibilité (statut de travail) saisie par les RH ou l'administrateur (vide = retour à l'automatique). */
    public function status(int $id): void
    {
        $emp = Database::one('SELECT id, name FROM employees WHERE id = ?', [$id]) ?? $this->notFound();
        $back = $this->backUrl();
        $status = (string) $this->input('work_status', '');
        $until = (string) $this->input('work_status_until', '');
        $note = mb_substr((string) $this->input('work_status_note', ''), 0, 255) ?: null;

        if ($status === '') {
            Database::run('UPDATE employees SET work_status = NULL, work_status_until = NULL, work_status_note = NULL,
                           work_status_by = ?, work_status_at = NOW() WHERE id = ?', [Auth::id(), $id]);
            WorkStatuses::record($id, null, null, null);
            Audit::log('agent_statut', ['id' => $id, 'statut' => 'automatique']);
            flash('success', $emp['name'] . ' : statut automatique (au bureau dès le premier badge).');
            $this->redirect($back);
        }
        if (!isset(AgentStatus::WORK[$status]) || $status === 'none') {
            flash('error', 'Statut invalide.');
            $this->redirect($back);
        }
        if ($until !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) || $until < date('Y-m-d'))) {
            flash('error', 'La date de fin doit être aujourd\'hui ou plus tard.');
            $this->redirect($back);
        }

        Database::run(
            'UPDATE employees SET work_status = ?, work_status_until = ?, work_status_note = ?, work_status_by = ?, work_status_at = NOW()
              WHERE id = ?',
            [$status, $until ?: null, $note, Auth::id(), $id]
        );
        WorkStatuses::record($id, $status, $until ?: null, $note);
        Audit::log('agent_statut', ['id' => $id, 'statut' => $status, 'jusqu_au' => $until ?: null, 'note' => $note]);
        flash('success', $emp['name'] . ' : ' . mb_strtolower(AgentStatus::WORK[$status][0])
            . ($until ? ' jusqu\'au ' . date('d/m/Y', strtotime($until)) : '') . '.');
        $this->redirect($back);
    }

    /** Retour à la page Agents avec les mêmes filtres (adresse locale uniquement). */
    private function backUrl(): string
    {
        $to = (string) ($_POST['_back'] ?? '');
        return preg_match('#^/agents(\?[^\s]*)?$#', $to) ? $to : '/agents';
    }
}
