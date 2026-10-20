<?php
declare(strict_types=1);

namespace App\Modules\Fleet\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Database;
use App\Modules\Fleet\Services\Fleet;

/**
 * Référentiel des chauffeurs : un chauffeur est un employé (table employees),
 * avec son permis (numéro, catégories, expiration avec alerte) et ses
 * véhicules autorisés (aucun coché : tous). Pour voir ses missions sur son
 * téléphone, il lui faut un compte relié à sa fiche, avec le rôle « Chauffeur ».
 */
final class DriverController extends Controller
{
    public function index(): void
    {
        $drivers = Database::all(
            "SELECT dr.*, e.name, e.phone, e.photo_updated_at, e.id employee_id, e.active employee_active,
                    u.id user_id, u.fleet_role,
                    (SELECT COUNT(*) FROM fleet_missions m WHERE m.driver_id = dr.id AND m.ended_at IS NOT NULL) missions,
                    (SELECT ROUND(AVG((rt.punctuality + rt.driving) / 2), 1) FROM fleet_ratings rt
                       JOIN fleet_requests r ON r.id = rt.request_id WHERE r.driver_id = dr.id) rating
               FROM fleet_drivers dr JOIN employees e ON e.id = dr.employee_id
               LEFT JOIN users u ON u.employee_id = e.id AND u.active = 1
              ORDER BY dr.active DESC, e.name"
        );
        $this->render('Fleet:drivers', [
            'title'      => 'Chauffeurs',
            'drivers'    => $drivers,
            'authorized' => Fleet::authorizedVehicles(),
            'vehicles'   => Database::all('SELECT id, plate, brand, model FROM fleet_vehicles WHERE active = 1 ORDER BY plate'),
            'employees'  => Database::all('SELECT e.id, e.name FROM employees e LEFT JOIN fleet_drivers dr ON dr.employee_id = e.id
                                            WHERE e.active = 1 AND dr.id IS NULL ORDER BY e.name'),
            'alertDays'  => (int) Fleet::settings()['fleet_doc_alert_days'],
        ]);
    }

    public function store(): void
    {
        $employee = (int) $this->input('employee_id', 0);
        $name = Database::value('SELECT name FROM employees WHERE id = ? AND active = 1', [$employee]);
        if (!$name || Database::value('SELECT id FROM fleet_drivers WHERE employee_id = ?', [$employee])) {
            flash('error', $name ? 'Cet employé est déjà chauffeur.' : 'Choisissez un employé.');
            $this->redirect('/flotte/chauffeurs');
        }
        $data = $this->validated();
        $id = Database::insert(
            'INSERT INTO fleet_drivers (employee_id, license_number, license_category, license_expires, notes) VALUES (?, ?, ?, ?, ?)',
            [$employee, $data['license_number'], $data['license_category'], $data['license_expires'], $data['notes']]
        );
        $this->saveVehicles($id);
        Audit::log('chauffeur_cree', ['id' => $id, 'employe' => $employee, 'permis_expire' => $data['license_expires']]);
        flash('success', $name . ' ajouté aux chauffeurs.');
        $this->redirect('/flotte/chauffeurs');
    }

    public function update(int $id): void
    {
        $driver = Database::one('SELECT dr.*, e.name FROM fleet_drivers dr JOIN employees e ON e.id = dr.employee_id WHERE dr.id = ?', [$id])
            ?? $this->notFound();
        $data = $this->validated();
        $active = isset($_POST['active']) ? 1 : 0;
        Database::run(
            'UPDATE fleet_drivers SET license_number = ?, license_category = ?, license_expires = ?, notes = ?, active = ?,
                    license_alerted_at = IF(license_expires <=> ?, license_alerted_at, NULL) WHERE id = ?',
            [$data['license_number'], $data['license_category'], $data['license_expires'], $data['notes'], $active, $data['license_expires'], $id]
        );
        $this->saveVehicles($id);
        Audit::log('chauffeur_modifie', ['id' => $id, 'permis_expire' => $data['license_expires'], 'actif' => $active]);
        flash('success', 'Chauffeur ' . $driver['name'] . ' enregistré.');
        $this->redirect('/flotte/chauffeurs');
    }

    /** Véhicules autorisés (cases cochées ; aucune : tous les véhicules). */
    private function saveVehicles(int $driverId): void
    {
        $ids = array_unique(array_filter(array_map('intval', (array) ($_POST['vehicle_ids'] ?? []))));
        Database::run('DELETE FROM fleet_driver_vehicles WHERE driver_id = ?', [$driverId]);
        foreach ($ids as $vid) {
            Database::run('INSERT IGNORE INTO fleet_driver_vehicles (driver_id, vehicle_id)
                           SELECT ?, id FROM fleet_vehicles WHERE id = ?', [$driverId, $vid]);
        }
    }

    /** @return array{license_number:?string, license_category:?string, license_expires:?string, notes:?string} */
    private function validated(): array
    {
        $expires = (string) $this->input('license_expires', '');
        $categories = array_unique(array_filter(array_map('trim', explode(',', mb_strtoupper((string) $this->input('license_category', ''))))));
        if ($expires !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) {
            flash('error', 'Date d\'expiration du permis invalide.');
            $this->redirect('/flotte/chauffeurs');
        }
        if (array_diff($categories, ['A', 'B', 'C', 'D', 'E', 'F', 'G'])) {
            flash('error', 'Catégories de permis : lettres A à G séparées par des virgules (ex. B,C).');
            $this->redirect('/flotte/chauffeurs');
        }
        return [
            'license_number'   => mb_substr((string) $this->input('license_number', ''), 0, 40) ?: null,
            'license_category' => $categories ? implode(',', $categories) : null,
            'license_expires'  => $expires ?: null,
            'notes'            => mb_substr((string) $this->input('notes', ''), 0, 255) ?: null,
        ];
    }
}
