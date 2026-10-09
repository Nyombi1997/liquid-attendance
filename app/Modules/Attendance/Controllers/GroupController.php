<?php
declare(strict_types=1);

namespace App\Modules\Attendance\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Database;

final class GroupController extends Controller
{
    public function index(): void
    {
        $groups = Database::all(
            'SELECT g.*, (SELECT COUNT(*) FROM employees e WHERE e.group_id = g.id AND e.active = 1) members
               FROM employee_groups g ORDER BY g.name'
        );
        $this->render('Attendance:groups', ['title' => 'Groupes d\'agents', 'groups' => $groups]);
    }

    public function store(): void
    {
        [$name, $description] = $this->fields();
        if (Database::value('SELECT id FROM employee_groups WHERE name = ?', [$name])) {
            flash('error', 'Un groupe porte déjà ce nom.');
            $this->redirect('/groupes');
        }
        $id = Database::insert('INSERT INTO employee_groups (name, description) VALUES (?, ?)', [$name, $description]);
        Audit::log('groupe_cree', ['id' => $id, 'nom' => $name]);
        flash('success', "Groupe « $name » créé. Ajoutez-y des agents depuis la liste des employés.");
        $this->redirect('/groupes');
    }

    public function update(int $id): void
    {
        [$name, $description] = $this->fields();
        if (Database::value('SELECT id FROM employee_groups WHERE name = ? AND id <> ?', [$name, $id])) {
            flash('error', 'Un groupe porte déjà ce nom.');
            $this->redirect('/groupes');
        }
        Database::run('UPDATE employee_groups SET name = ?, description = ? WHERE id = ?', [$name, $description, $id]);
        Audit::log('groupe_modifie', ['id' => $id, 'nom' => $name]);
        flash('success', 'Groupe mis à jour.');
        $this->redirect('/groupes');
    }

    public function destroy(int $id): void
    {
        Database::run("DELETE FROM schedule_assignments WHERE target_type = 'group' AND target_id = ?", [$id]);
        Database::run('DELETE FROM employee_groups WHERE id = ?', [$id]);
        Audit::log('groupe_supprime', ['id' => $id]);
        flash('success', 'Groupe supprimé. Ses agents n\'appartiennent plus à aucun groupe.');
        $this->redirect('/groupes');
    }

    private function fields(): array
    {
        $name = mb_substr((string) $this->input('name', ''), 0, 100);
        if ($name === '') {
            flash('error', 'Le nom du groupe est obligatoire.');
            $this->redirect('/groupes');
        }
        return [$name, mb_substr((string) $this->input('description', ''), 0, 255) ?: null];
    }
}
