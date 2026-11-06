<?php
declare(strict_types=1);

namespace App\Modules\Platform\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Database;

/**
 * Provinces (administrateur) : liste, création, renommage, retrait. La province
 * de rattachement d'un employé (employees.province_id) est assignée par les RH,
 * le profil Parc automobile ou l'administrateur (droit « province », fiche
 * employé ou sélection multiple de la liste des employés). Parc automobile :
 * « Mission hors de <province du demandeur> ».
 */
final class ProvinceController extends Controller
{
    public function index(): void
    {
        $this->render('Platform:provinces', [
            'title'     => 'Provinces',
            'provinces' => Database::all(
                'SELECT p.*, (SELECT COUNT(*) FROM employees e WHERE e.province_id = p.id AND e.active = 1) agents
                   FROM provinces p ORDER BY p.active DESC, p.name'),
            'none'      => (int) Database::value('SELECT COUNT(*) FROM employees WHERE active = 1 AND province_id IS NULL'),
        ]);
    }

    public function store(): void
    {
        $name = $this->name(null);
        $id = Database::insert('INSERT INTO provinces (name) VALUES (?)', [$name]);
        Audit::log('province_creee', ['id' => $id, 'nom' => $name]);
        flash('success', "Province « $name » créée.");
        $this->redirect('/provinces');
    }

    public function update(int $id): void
    {
        $old = Database::one('SELECT * FROM provinces WHERE id = ?', [$id]) ?? $this->notFound();
        $name = $this->name($id);
        $active = isset($_POST['active']) ? 1 : 0;
        Database::run('UPDATE provinces SET name = ?, active = ? WHERE id = ?', [$name, $active, $id]);
        Audit::log('province_modifiee', ['id' => $id, 'avant' => $old['name'], 'nom' => $name, 'active' => $active]);
        flash('success', "Province « $name » enregistrée.");
        $this->redirect('/provinces');
    }

    /** Suppression : les employés rattachés n'ont plus de province (clé étrangère ON DELETE SET NULL). */
    public function destroy(int $id): void
    {
        $p = Database::one('SELECT * FROM provinces WHERE id = ?', [$id]) ?? $this->notFound();
        $n = (int) Database::value('SELECT COUNT(*) FROM employees WHERE province_id = ?', [$id]);
        Database::run('DELETE FROM provinces WHERE id = ?', [$id]);
        Audit::log('province_supprimee', ['id' => $id, 'nom' => $p['name'], 'employes_detaches' => $n]);
        flash('success', "Province « {$p['name']} » supprimée" . ($n ? " ($n employé(s) sans province)." : '.'));
        $this->redirect('/provinces');
    }

    private function name(?int $id): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', mb_substr((string) $this->input('name', ''), 0, 80)) ?? '');
        $error = match (true) {
            $name === '' => 'Le nom de la province est obligatoire.',
            (bool) Database::value('SELECT id FROM provinces WHERE name = ? AND id <> ?', [$name, $id ?? 0]) => "La province « $name » existe déjà.",
            default => null,
        };
        if ($error) {
            flash('error', $error);
            $this->redirect('/provinces');
        }
        return $name;
    }
}
