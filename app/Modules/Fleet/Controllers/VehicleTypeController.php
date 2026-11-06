<?php
declare(strict_types=1);

namespace App\Modules\Fleet\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Database;

/**
 * Types de véhicule (droit fleet_manage : administrateur, profil Parc automobile,
 * gestionnaire de flotte) : proposés dans la fiche d'un véhicule et dans le champ
 * « Type de véhicule souhaité » d'une demande. Un type n'est jamais supprimé
 * (véhicules et demandes existants) : il est retiré de la liste.
 */
final class VehicleTypeController extends Controller
{
    public function index(): void
    {
        $this->render('Fleet:vehicle_types', [
            'title' => 'Types de véhicule',
            'types' => Database::all(
                'SELECT t.*, (SELECT COUNT(*) FROM fleet_vehicles v WHERE v.vehicle_type = t.code AND v.active = 1) vehicles
                   FROM fleet_vehicle_types t ORDER BY t.active DESC, t.label'),
        ]);
    }

    public function store(): void
    {
        $label = $this->label(null);
        $code = $this->code($label);
        Database::run('INSERT INTO fleet_vehicle_types (code, label) VALUES (?, ?)', [$code, $label]);
        Audit::log('flotte_type_cree', ['code' => $code, 'libelle' => $label]);
        flash('success', "Type « $label » créé.");
        $this->redirect('/flotte/types');
    }

    public function update(): void
    {
        $code = (string) $this->input('code', '');
        $old = Database::one('SELECT * FROM fleet_vehicle_types WHERE code = ?', [$code]) ?? $this->notFound();
        $label = $this->label($code);
        $active = isset($_POST['active']) ? 1 : 0;
        Database::run('UPDATE fleet_vehicle_types SET label = ?, active = ? WHERE code = ?', [$label, $active, $code]);
        Audit::log('flotte_type_modifie', ['code' => $code, 'avant' => $old['label'], 'libelle' => $label, 'actif' => $active]);
        flash('success', "Type « $label » enregistré.");
        $this->redirect('/flotte/types');
    }

    private function label(?string $code): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', mb_substr((string) $this->input('label', ''), 0, 60)) ?? '');
        $error = match (true) {
            $label === '' => 'Le nom du type est obligatoire.',
            (bool) Database::value('SELECT code FROM fleet_vehicle_types WHERE label = ? AND code <> ?', [$label, $code ?? '']) => "Le type « $label » existe déjà.",
            default => null,
        };
        if ($error) {
            flash('error', $error);
            $this->redirect('/flotte/types');
        }
        return $label;
    }

    /** Code technique tiré du libellé (« 4x4 Double cabine » → 4x4_double_cabine), unique. */
    private function code(string $label): string
    {
        $ascii = (function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) : false) ?: $label;
        $base = substr(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($ascii)) ?? '', '_'), 0, 16) ?: 'type';
        $code = $base;
        for ($i = 2; Database::value('SELECT 1 FROM fleet_vehicle_types WHERE code = ?', [$code]); $i++) {
            $code = $base . '_' . $i;
        }
        return $code;
    }
}
