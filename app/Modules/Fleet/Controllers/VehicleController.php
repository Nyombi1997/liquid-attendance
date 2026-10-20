<?php
declare(strict_types=1);

namespace App\Modules\Fleet\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Database;
use App\Modules\Fleet\Services\Fleet;
use App\Modules\Fleet\Services\FleetFiles;
use App\Modules\Fleet\Services\FleetWorkflow;

/**
 * Référentiel des véhicules : liste, fiche, documents (assurance, contrôle
 * technique, avec alerte avant expiration). Consultation : fleet_board ;
 * modification : fleet_manage (gestionnaire de flotte, administrateur).
 */
final class VehicleController extends Controller
{
    public function index(): void
    {
        $status = (string) $this->input('statut', '');
        $status = isset(Fleet::VEHICLE_STATUSES[$status]) ? $status : '';
        $vehicles = Database::all(
            "SELECT v.*, (SELECT r.reference FROM fleet_requests r WHERE r.vehicle_id = v.id AND r.status = 'en_cours' LIMIT 1) mission_ref
               FROM fleet_vehicles v WHERE v.active = 1 AND (? = '' OR v.status = ?) ORDER BY v.plate",
            [$status, $status]
        );
        $counts = array_fill_keys(array_keys(Fleet::VEHICLE_STATUSES), 0);
        foreach (Database::all('SELECT status, COUNT(*) n FROM fleet_vehicles WHERE active = 1 GROUP BY status') as $c) {
            $counts[$c['status']] = (int) $c['n'];
        }
        $this->render('Fleet:vehicles', [
            'title'     => 'Véhicules',
            'vehicles'  => $vehicles,
            'retired'   => Database::all('SELECT * FROM fleet_vehicles WHERE active = 0 ORDER BY plate'),
            'docs'      => Fleet::documents(),
            'counts'    => $counts,
            'status'    => $status,
            'alertDays' => (int) Fleet::settings()['fleet_doc_alert_days'],
        ]);
    }

    public function show(int $id): void
    {
        $vehicle = Database::one('SELECT * FROM fleet_vehicles WHERE id = ?', [$id]) ?? $this->notFound();
        $this->render('Fleet:vehicle', [
            'title'     => $vehicle['plate'],
            'vehicle'   => $vehicle,
            'documents' => Database::all(
                'SELECT d.*, u.full_name author FROM fleet_vehicle_documents d LEFT JOIN users u ON u.id = d.created_by
                  WHERE d.vehicle_id = ? ORDER BY d.id DESC', [$id]),
            'current'   => Fleet::documents()[$id] ?? [],
            'upcoming'  => Database::all(
                "SELECT r.id, r.reference, r.depart_at, r.return_at, r.destination, r.status, r.validation, r.step, e.name requester, de.name driver
                   FROM fleet_requests r JOIN employees e ON e.id = r.requester_id
                   LEFT JOIN fleet_drivers dr ON dr.id = r.driver_id LEFT JOIN employees de ON de.id = dr.employee_id
                  WHERE r.vehicle_id = ? AND r.status IN ('affectee', 'en_cours') ORDER BY r.depart_at", [$id]),
            'missions'  => Database::all(
                "SELECT r.id, r.reference, r.destination, m.started_at, m.ended_at, m.start_km, m.end_km, m.end_condition, de.name driver
                   FROM fleet_missions m JOIN fleet_requests r ON r.id = m.request_id
                   LEFT JOIN fleet_drivers dr ON dr.id = m.driver_id LEFT JOIN employees de ON de.id = dr.employee_id
                  WHERE m.vehicle_id = ? ORDER BY m.started_at DESC LIMIT 20", [$id]),
            'alertDays' => (int) Fleet::settings()['fleet_doc_alert_days'],
        ]);
    }

    public function store(): void
    {
        $data = $this->validated(null);
        $id = Database::insert(
            'INSERT INTO fleet_vehicles (plate, brand, model, year, vehicle_type, seats, fuel_type, tank_liters, ref_consumption,
                                         odometer_km, status, tracker_id, notes)
             VALUES (:plate, :brand, :model, :year, :vehicle_type, :seats, :fuel_type, :tank_liters, :ref_consumption,
                     :odometer_km, :status, :tracker_id, :notes)',
            array_diff_key($data, ['active' => 1])
        );
        Audit::log('vehicule_cree', ['id' => $id, 'immatriculation' => $data['plate']]);
        flash('success', 'Véhicule ' . $data['plate'] . ' ajouté. Ajoutez maintenant son assurance et son contrôle technique.');
        $this->redirect('/flotte/vehicules/' . $id);
    }

    public function update(int $id): void
    {
        $old = Database::one('SELECT * FROM fleet_vehicles WHERE id = ?', [$id]) ?? $this->notFound();
        $data = $this->validated($id);
        if ($data['status'] !== $old['status'] && $old['status'] === 'en_mission'
            && Database::value("SELECT 1 FROM fleet_requests WHERE vehicle_id = ? AND status = 'en_cours'", [$id])) {
            flash('error', 'Ce véhicule est en mission : son état changera à la clôture de la mission.');
            $this->redirect('/flotte/vehicules/' . $id);
        }
        Database::run(
            'UPDATE fleet_vehicles SET plate = :plate, brand = :brand, model = :model, year = :year, vehicle_type = :vehicle_type,
                    seats = :seats, fuel_type = :fuel_type, tank_liters = :tank_liters, ref_consumption = :ref_consumption,
                    odometer_km = :odometer_km, status = :status, tracker_id = :tracker_id, notes = :notes, active = :active
              WHERE id = :id',
            $data + ['id' => $id]
        );
        $changes = array_filter($data, static fn($v, $k) => (string) $v !== (string) $old[$k], ARRAY_FILTER_USE_BOTH);
        Audit::log('vehicule_modifie', ['id' => $id, 'immatriculation' => $data['plate']] + $changes);
        flash('success', 'Véhicule ' . $data['plate'] . ' enregistré.');
        $this->redirect('/flotte/vehicules/' . $id);
    }

    /** Nouveau document (assurance, contrôle technique…) : le plus récent de chaque type fait foi. */
    public function storeDocument(int $id): void
    {
        $vehicle = Database::one('SELECT id, plate FROM fleet_vehicles WHERE id = ?', [$id]) ?? $this->notFound();
        $kind = (string) $this->input('kind', '');
        $expires = (string) $this->input('expires_on', '');
        $reference = mb_substr((string) $this->input('reference', ''), 0, 80) ?: null;
        $error = match (true) {
            !isset(Fleet::DOC_KINDS[$kind]) => 'Type de document invalide.',
            $expires !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) => 'Date d\'expiration invalide.',
            $kind !== 'autre' && $expires === '' => 'La date d\'expiration est obligatoire pour ce document.',
            default => null,
        };
        [$file, $uploadError] = $error ? [null, null] : FleetFiles::upload('file', 'documents');
        $error ??= $uploadError;
        if ($error === null && $file === null && $reference === null && $expires === '') {
            $error = 'Indiquez au moins une référence, une date ou un fichier.';
        }
        if ($error) {
            FleetFiles::delete('documents', $file['stored_name'] ?? null);
            flash('error', $error);
            $this->redirect('/flotte/vehicules/' . $id);
        }
        $docId = Database::insert(
            'INSERT INTO fleet_vehicle_documents (vehicle_id, kind, reference, expires_on, file_name, stored_name, file_mime, file_size, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $kind, $reference, $expires ?: null, $file['file_name'] ?? null, $file['stored_name'] ?? null,
             $file['file_mime'] ?? null, $file['file_size'] ?? null, $_SESSION['user_id'] ?? null]
        );
        Audit::log('vehicule_document', ['vehicule' => $id, 'document' => $docId, 'type' => $kind, 'expire' => $expires ?: null]);
        flash('success', 'Document « ' . Fleet::DOC_KINDS[$kind] . ' » enregistré pour ' . $vehicle['plate'] . '.');
        $this->redirect('/flotte/vehicules/' . $id);
    }

    public function document(int $id): void
    {
        $doc = Database::one('SELECT * FROM fleet_vehicle_documents WHERE id = ?', [$id]);
        if (!$doc || !$doc['stored_name']) {
            $this->notFound();
        }
        FleetFiles::send(FleetFiles::path('documents', $doc['stored_name']), $doc['file_name'] ?? 'document', $doc['file_mime']);
    }

    /** @return array<string, mixed> champs de la table fleet_vehicles */
    private function validated(?int $id): array
    {
        $num = static fn($v, float $max) => $v === '' || $v === null ? null
            : (is_numeric(str_replace(',', '.', (string) $v)) && (float) str_replace(',', '.', (string) $v) >= 0
               && (float) str_replace(',', '.', (string) $v) <= $max ? (float) str_replace(',', '.', (string) $v) : false);
        $plate = mb_strtoupper(preg_replace('/\s+/', ' ', (string) $this->input('plate', '')));
        $data = [
            'plate'           => mb_substr($plate, 0, 20),
            'brand'           => mb_substr((string) $this->input('brand', ''), 0, 60),
            'model'           => mb_substr((string) $this->input('model', ''), 0, 60),
            'year'            => (int) $this->input('year', 0) ?: null,
            'vehicle_type'    => (string) $this->input('vehicle_type', 'berline'),
            'seats'           => (int) $this->input('seats', 5),
            'fuel_type'       => (string) $this->input('fuel_type', 'diesel'),
            'tank_liters'     => $num($this->input('tank_liters'), 2000),
            'ref_consumption' => $num($this->input('ref_consumption'), 100),
            'odometer_km'     => $num($this->input('odometer_km'), 5_000_000),
            'status'          => (string) $this->input('status', 'disponible'),
            'tracker_id'      => mb_substr((string) $this->input('tracker_id', ''), 0, 60) ?: null,
            'notes'           => mb_substr((string) $this->input('notes', ''), 0, 255) ?: null,
            'active'          => $id === null || isset($_POST['active']) ? 1 : 0,
        ];
        $error = match (true) {
            $data['plate'] === '' || $data['brand'] === '' || $data['model'] === '' => 'Immatriculation, marque et modèle sont obligatoires.',
            $data['year'] !== null && ($data['year'] < 1950 || $data['year'] > (int) date('Y') + 1) => 'Année invalide.',
            !isset(FleetWorkflow::VEHICLE_TYPES[$data['vehicle_type']]) => 'Type de véhicule invalide.',
            $data['seats'] < 1 || $data['seats'] > 80 => 'Nombre de places invalide.',
            !isset(Fleet::FUEL_TYPES[$data['fuel_type']]) => 'Carburant invalide.',
            in_array(false, [$data['tank_liters'], $data['ref_consumption'], $data['odometer_km']], true) => 'Réservoir, consommation ou kilométrage invalide.',
            !isset(Fleet::VEHICLE_STATUSES[$data['status']]) => 'État invalide.',
            (bool) Database::value('SELECT id FROM fleet_vehicles WHERE plate = ? AND id <> ?', [$data['plate'], $id ?? 0]) => 'Cette immatriculation existe déjà.',
            default => null,
        };
        if ($error) {
            $this->withInput();
            flash('error', $error);
            $this->redirect($id ? '/flotte/vehicules/' . $id : '/flotte/vehicules');
        }
        $data['odometer_km'] = $data['odometer_km'] !== null ? (int) $data['odometer_km'] : null;
        return $data;
    }
}
