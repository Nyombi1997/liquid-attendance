<?php
declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Database;
use App\Modules\Platform\Services\Departments;

/**
 * Départements (arborescence) : création, renommage, déplacement, suppression.
 * Chaque département est une direction ou un service, avec un responsable (N+1)
 * qui valide les demandes de véhicule de son équipe (module Parc automobile).
 */
final class DepartmentController extends Controller
{
    public function index(): void
    {
        $agents = [];
        foreach (Database::all('SELECT department_id, COUNT(*) n FROM employees WHERE active = 1 AND department_id IS NOT NULL GROUP BY department_id') as $r) {
            $agents[(int) $r['department_id']] = (int) $r['n'];
        }
        $managers = [];
        foreach (Database::all("SELECT department_id, full_name FROM users WHERE role = 'manager' AND active = 1 AND department_id IS NOT NULL ORDER BY full_name") as $r) {
            $managers[(int) $r['department_id']][] = $r['full_name'];
        }
        $heads = [];
        foreach (Database::all('SELECT d.id, e.name, u.id user_id FROM departments d JOIN employees e ON e.id = d.head_employee_id
                                 LEFT JOIN users u ON u.employee_id = e.id AND u.active = 1') as $r) {
            $heads[(int) $r['id']] = ['name' => $r['name'], 'has_account' => $r['user_id'] !== null];
        }
        $this->render('Platform:departments', [
            'title'    => 'Départements',
            'tree'     => Departments::tree(),
            'agents'   => $agents,
            'managers' => $managers,
            'heads'    => $heads,
            'employees'=> Database::all('SELECT id, name, department_id FROM employees WHERE active = 1 ORDER BY name'),
            'none'     => (int) Database::value('SELECT COUNT(*) FROM employees WHERE active = 1 AND department_id IS NULL'),
        ]);
    }

    public function store(): void
    {
        [$name, $parent, $kind, $head] = $this->validated(null);
        $id = Database::insert('INSERT INTO departments (name, parent_id, kind, head_employee_id) VALUES (?, ?, ?, ?)', [$name, $parent, $kind, $head]);
        Audit::log('departement_cree', ['id' => $id, 'nom' => $name, 'parent' => $parent, 'type' => $kind, 'responsable' => $head]);
        flash('success', "Département « $name » créé.");
        $this->redirect('/departements');
    }

    public function update(int $id): void
    {
        Database::one('SELECT id FROM departments WHERE id = ?', [$id]) ?? $this->notFound();
        [$name, $parent, $kind, $head] = $this->validated($id);
        Database::run('UPDATE departments SET name = ?, parent_id = ?, kind = ?, head_employee_id = ? WHERE id = ?', [$name, $parent, $kind, $head, $id]);
        Audit::log('departement_modifie', ['id' => $id, 'nom' => $name, 'parent' => $parent, 'type' => $kind, 'responsable' => $head]);
        flash('success', "Département « $name » enregistré.");
        $this->redirect('/departements');
    }

    /** Les sous-départements remontent au parent ; agents et managers n'ont plus de département. */
    public function destroy(int $id): void
    {
        $dep = Database::one('SELECT * FROM departments WHERE id = ?', [$id]) ?? $this->notFound();
        Database::run('UPDATE departments SET parent_id = ? WHERE parent_id = ?', [$dep['parent_id'], $id]);
        Database::run('DELETE FROM departments WHERE id = ?', [$id]);
        Audit::log('departement_supprime', ['id' => $id, 'nom' => $dep['name']]);
        flash('success', "Département « {$dep['name']} » supprimé.");
        $this->redirect('/departements');
    }

    /** @return array{0:string, 1:?int, 2:string, 3:?int} nom, parent, type, responsable (N+1) */
    private function validated(?int $id): array
    {
        $name = mb_substr((string) $this->input('name', ''), 0, 100);
        $parent = (int) $this->input('parent_id', 0) ?: null;
        $kind = $this->input('kind') === 'direction' ? 'direction' : 'service';
        $head = (int) $this->input('head_employee_id', 0) ?: null;
        $error = null;
        if ($head !== null && !Database::value('SELECT id FROM employees WHERE id = ? AND active = 1', [$head])) {
            $error = 'Responsable introuvable.';
        } elseif ($name === '') {
            $error = 'Le nom est obligatoire.';
        } elseif ($parent !== null && !isset(Departments::all()[$parent])) {
            $error = 'Département parent introuvable.';
        } elseif ($id !== null && $parent !== null && in_array($parent, Departments::descendants($id), true)) {
            $error = 'Un département ne peut pas être placé sous lui-même ou sous un de ses sous-départements.';
        } elseif (Database::value('SELECT id FROM departments WHERE name = ? AND parent_id <=> ? AND id <> ?', [$name, $parent, $id ?? 0])) {
            $error = 'Ce département existe déjà à cet endroit.';
        }
        if ($error) {
            flash('error', $error);
            $this->redirect('/departements');
        }
        return [$name, $parent, $kind, $head];
    }
}
