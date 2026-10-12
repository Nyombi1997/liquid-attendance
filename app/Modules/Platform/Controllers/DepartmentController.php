<?php
declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Database;
use App\Modules\Platform\Services\Departments;

/** Départements (arborescence) : création, renommage, déplacement, suppression. */
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
        $this->render('Platform:departments', [
            'title'    => 'Départements',
            'tree'     => Departments::tree(),
            'agents'   => $agents,
            'managers' => $managers,
            'none'     => (int) Database::value('SELECT COUNT(*) FROM employees WHERE active = 1 AND department_id IS NULL'),
        ]);
    }

    public function store(): void
    {
        [$name, $parent] = $this->validated(null);
        $id = Database::insert('INSERT INTO departments (name, parent_id) VALUES (?, ?)', [$name, $parent]);
        Audit::log('departement_cree', ['id' => $id, 'nom' => $name, 'parent' => $parent]);
        flash('success', "Département « $name » créé.");
        $this->redirect('/departements');
    }

    public function update(int $id): void
    {
        Database::one('SELECT id FROM departments WHERE id = ?', [$id]) ?? $this->notFound();
        [$name, $parent] = $this->validated($id);
        Database::run('UPDATE departments SET name = ?, parent_id = ? WHERE id = ?', [$name, $parent, $id]);
        Audit::log('departement_modifie', ['id' => $id, 'nom' => $name, 'parent' => $parent]);
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

    /** @return array{0:string, 1:?int} */
    private function validated(?int $id): array
    {
        $name = mb_substr((string) $this->input('name', ''), 0, 100);
        $parent = (int) $this->input('parent_id', 0) ?: null;
        $error = null;
        if ($name === '') {
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
        return [$name, $parent];
    }
}
