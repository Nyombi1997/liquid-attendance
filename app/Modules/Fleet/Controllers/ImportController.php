<?php
declare(strict_types=1);

namespace App\Modules\Fleet\Controllers;

use App\Core\Audit;
use App\Core\Controller;
use App\Core\Database;
use App\Modules\Fleet\Services\Fleet;
use App\Modules\Fleet\Services\FleetImport;
use App\Modules\Platform\Services\Departments;

/**
 * Import CSV de l'organisation, des véhicules et des chauffeurs (droit
 * fleet_manage : administrateur, gestionnaire de flotte). Trois temps :
 * envoi du fichier (lu et gardé en session, rien n'est enregistré), aperçu
 * ligne par ligne, confirmation. À la confirmation, l'analyse est refaite sous
 * verrou dans une transaction : si le résultat diffère de l'aperçu (données
 * modifiées entre-temps), rien n'est enregistré et un nouvel aperçu s'affiche.
 * Seules les lignes valides sont enregistrées ; les lignes en erreur sont ignorées.
 */
final class ImportController extends Controller
{
    public function index(): void
    {
        $this->render('Fleet:import', [
            'title'   => 'Import CSV',
            'kinds'   => FleetImport::KINDS,
            'pending' => array_map(static fn($p) => $p['file'], $_SESSION['fleet_import'] ?? []),
        ]);
    }

    public function template(): void
    {
        $kind = $this->kind();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="modele_' . $kind . '.csv"');
        header('Cache-Control: private, no-store');
        echo FleetImport::template($kind);
    }

    public function upload(): void
    {
        $kind = $this->kind();
        $f = $_FILES['fichier'] ?? null;
        $error = match (true) {
            !$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE => 'Choisissez un fichier CSV.',
            $f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > FleetImport::MAX_BYTES
                => 'Fichier trop volumineux (1 Mo au plus).',
            $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) => 'Le fichier n\'a pas pu être reçu, réessayez.',
            !preg_match('/\.(csv|txt)$/i', (string) $f['name']) => 'Fichier .csv attendu (Excel : Enregistrer sous › CSV).',
            default => null,
        };
        $raw = $error ? '' : (string) file_get_contents($f['tmp_name']);
        // Classeur Excel (zip) ou fichier binaire renommé en .csv
        if (!$error && (str_starts_with($raw, 'PK') || (str_contains($raw, "\0") && !preg_match('/^\xFF\xFE|^\xFE\xFF/', $raw)))) {
            $error = 'Ce fichier n\'est pas un CSV : dans Excel, utilisez Enregistrer sous › CSV (séparateur : point-virgule).';
        }
        if ($error) {
            flash('error', $error);
            $this->redirect('/flotte/import');
        }
        $read = FleetImport::read($raw);
        $_SESSION['fleet_import'][$kind] = [
            'file'      => mb_substr(basename((string) $f['name']), 0, 120),
            'rows'      => $read['rows'],
            'separator' => $read['separator'],
            'encoding'  => $read['encoding'],
        ];
        $this->redirect('/flotte/import/apercu?liste=' . $kind);
    }

    public function preview(): void
    {
        $kind = $this->kind();
        $pending = $_SESSION['fleet_import'][$kind] ?? null;
        if (!$pending) {
            flash('error', 'Aucun fichier en attente : envoyez d\'abord le fichier CSV.');
            $this->redirect('/flotte/import');
        }
        $analysis = FleetImport::analyze($kind, $pending['rows'], $this->context(false));
        $this->render('Fleet:import_preview', [
            'title'       => 'Aperçu de l\'import',
            'kind'        => $kind,
            'def'         => FleetImport::KINDS[$kind],
            'pending'     => $pending,
            'analysis'    => $analysis,
            'fingerprint' => FleetImport::fingerprint($analysis),
        ]);
    }

    public function cancel(): void
    {
        $kind = $this->kind();
        unset($_SESSION['fleet_import'][$kind]);
        flash('success', 'Import annulé : rien n\'a été enregistré.');
        $this->redirect('/flotte/import');
    }

    public function confirm(): void
    {
        $kind = $this->kind();
        $pending = $_SESSION['fleet_import'][$kind] ?? null;
        if (!$pending) {
            flash('error', 'Aucun fichier en attente : envoyez d\'abord le fichier CSV.');
            $this->redirect('/flotte/import');
        }
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $analysis = FleetImport::analyze($kind, $pending['rows'], $this->context(true));
            if ($analysis['fatal'] !== null || !hash_equals(FleetImport::fingerprint($analysis), (string) $this->input('empreinte', ''))) {
                $pdo->rollBack();
                flash('error', 'Les données ont changé depuis l\'aperçu : rien n\'a été enregistré. Vérifiez ce nouvel aperçu puis confirmez.');
                $this->redirect('/flotte/import/apercu?liste=' . $kind);
            }
            $done = match ($kind) {
                'organisation' => $this->applyOrganisation($analysis['lines']),
                'vehicules'    => $this->applyVehicles($analysis['lines']),
                'chauffeurs'   => $this->applyDrivers($analysis['lines']),
            };
            Audit::log('import_' . $kind, [
                'fichier'     => $pending['file'],
                'encodage'    => $pending['encoding'],
                'creations'   => $analysis['counts']['create'],
                'mises_a_jour'=> $analysis['counts']['update'],
                'inchangees'  => $analysis['counts']['unchanged'],
                'erreurs_ignorees' => $analysis['counts']['error'],
                'elements'    => array_slice($done, 0, 300),
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        Departments::forget();
        unset($_SESSION['fleet_import'][$kind]);
        $c = $analysis['counts'];
        flash('success', FleetImport::KINDS[$kind]['label'] . ' importés : ' . $c['create'] . ' création(s), ' . $c['update'] . ' mise(s) à jour'
            . ($c['unchanged'] ? ', ' . $c['unchanged'] . ' ligne(s) inchangée(s)' : '')
            . ($c['error'] ? ', ' . $c['error'] . ' ligne(s) en erreur ignorée(s)' : '') . '.');
        $this->redirect(match ($kind) {
            'vehicules'  => '/flotte/vehicules',
            'chauffeurs' => '/flotte/chauffeurs',
            default      => '/flotte/import',
        });
    }

    private function kind(): string
    {
        $kind = (string) $this->input('liste', '');
        if (!isset(FleetImport::KINDS[$kind])) {
            $this->notFound();
        }
        return $kind;
    }

    /**
     * Données existantes pour l'analyse ; sous verrou ($lock) à la confirmation,
     * pour qu'elles ne changent pas entre l'analyse et l'enregistrement.
     */
    private function context(bool $lock): array
    {
        $for = $lock ? ' FOR UPDATE' : '';
        $deps = [];
        foreach (Database::all('SELECT id, name, parent_id, kind, head_employee_id FROM departments' . $for) as $d) {
            $deps[(int) $d['id']] = ['id' => (int) $d['id'], 'name' => $d['name'],
                'parent_id' => $d['parent_id'] !== null ? (int) $d['parent_id'] : null, 'kind' => $d['kind'],
                'head_employee_id' => $d['head_employee_id'] !== null ? (int) $d['head_employee_id'] : null];
        }
        $vehicles = [];
        foreach (Database::all(
            "SELECT v.id, v.plate, v.brand, v.model, v.year, v.fuel_type, v.tank_liters, v.ref_consumption, v.status, v.active,
                    EXISTS(SELECT 1 FROM fleet_requests r WHERE r.vehicle_id = v.id AND r.status = 'en_cours') busy
               FROM fleet_vehicles v ORDER BY v.id" . $for
        ) as $v) {
            $vehicles[FleetImport::plateKey($v['plate'])] ??= $v;
        }
        $drivers = [];
        foreach (Database::all('SELECT id, employee_id, license_number, license_category, license_expires, active FROM fleet_drivers' . $for) as $d) {
            $drivers[(int) $d['employee_id']] = $d;
        }
        return [
            'employees'   => Database::all('SELECT id, name, device_user_id, active FROM employees'),
            'departments' => $deps,
            'vehicles'    => $vehicles,
            'documents'   => Fleet::documents(),
            'drivers'     => $drivers,
            'year'        => (int) date('Y'),
        ];
    }

    /** @return list<string> éléments créés ou modifiés (journal) */
    private function applyOrganisation(array $lines): array
    {
        $ids = [];   // clé « new:… » => id créé
        $ref = static function (int|string|null $r) use (&$ids): ?int {
            return $r === null || is_int($r) ? $r : ($ids[$r] ?? throw new \LogicException("Référence $r inconnue"));
        };
        $done = [];
        foreach ($lines as $l) {
            foreach ($l['plan'] as $op) {
                if ($op['op'] === 'create') {
                    $id = Database::insert('INSERT INTO departments (name, parent_id, kind, head_employee_id) VALUES (?, ?, ?, ?)',
                        [$op['name'], $ref($op['parent']), $op['kind'], $op['head']]);
                    $ids[$op['key']] = $id;
                    $done[] = "créé : {$op['name']} (#$id)";
                    continue;
                }
                $id = $ref($op['ref']);
                $cols = ['kind' => 'kind', 'parent' => 'parent_id', 'head' => 'head_employee_id'];
                $set = [];
                $params = [];
                foreach ($op['set'] as $k => $v) {
                    $set[] = $cols[$k] . ' = ?';
                    $params[] = $k === 'parent' ? $ref($v) : $v;
                }
                Database::run('UPDATE departments SET ' . implode(', ', $set) . ' WHERE id = ?', [...$params, $id]);
                $done[] = "modifié : #$id (" . implode(', ', array_keys($op['set'])) . ')';
            }
        }
        return $done;
    }

    private function applyVehicles(array $lines): array
    {
        $cols = ['brand', 'model', 'year', 'fuel_type', 'tank_liters', 'ref_consumption', 'status'];
        $done = [];
        foreach ($lines as $l) {
            foreach ($l['plan'] as $op) {
                $set = array_intersect_key($op['set'], array_flip($cols));
                if ($op['op'] === 'create') {
                    $id = Database::insert(
                        'INSERT INTO fleet_vehicles (plate, ' . implode(', ', array_keys($set)) . ') VALUES (?' . str_repeat(', ?', count($set)) . ')',
                        [$op['plate'], ...array_values($set)]
                    );
                    $done[] = "créé : {$op['plate']} (#$id)";
                } else {
                    $id = $op['id'];
                    if ($set) {
                        Database::run('UPDATE fleet_vehicles SET ' . implode(', ', array_map(static fn($c) => "$c = ?", array_keys($set))) . ' WHERE id = ?',
                            [...array_values($set), $id]);
                    }
                    $done[] = "modifié : {$op['plate']} (" . implode(', ', [...array_keys($set), ...array_keys($op['docs'])]) . ')';
                }
                foreach ($op['docs'] as $kind => $date) {
                    Database::run('INSERT INTO fleet_vehicle_documents (vehicle_id, kind, reference, expires_on, created_by) VALUES (?, ?, ?, ?, ?)',
                        [$id, $kind, 'Import CSV', $date, $_SESSION['user_id'] ?? null]);
                }
            }
        }
        return $done;
    }

    private function applyDrivers(array $lines): array
    {
        $cols = ['license_number', 'license_category', 'license_expires'];
        $done = [];
        foreach ($lines as $l) {
            foreach ($l['plan'] as $op) {
                $set = array_intersect_key($op['set'], array_flip($cols));
                if ($op['op'] === 'create') {
                    $id = Database::insert(
                        'INSERT INTO fleet_drivers (employee_id' . ($set ? ', ' . implode(', ', array_keys($set)) : '') . ') VALUES (?' . str_repeat(', ?', count($set)) . ')',
                        [$op['employee_id'], ...array_values($set)]
                    );
                    $done[] = "créé : {$l['label']} (chauffeur #$id)";
                } else {
                    // Nouvelle date d'expiration : l'alerte pourra repartir
                    $extra = isset($set['license_expires']) ? ', license_alerted_at = NULL' : '';
                    Database::run('UPDATE fleet_drivers SET ' . implode(', ', array_map(static fn($c) => "$c = ?", array_keys($set))) . $extra . ' WHERE id = ?',
                        [...array_values($set), $op['id']]);
                    $done[] = "modifié : {$l['label']} (" . implode(', ', array_keys($set)) . ')';
                }
            }
        }
        return $done;
    }
}
