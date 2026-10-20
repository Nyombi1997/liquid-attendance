<?php
declare(strict_types=1);

namespace App\Modules\Fleet\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Modules\Fleet\Services\Fleet;
use App\Modules\Fleet\Services\FleetFiles;
use App\Modules\Fleet\Services\FleetNotify;
use App\Modules\Fleet\Services\FleetWorkflow;
use DomainException;

/**
 * Missions du chauffeur (écran pour téléphone) : démarrage et clôture avec
 * kilométrage, niveau de carburant, photo du compteur et état du véhicule.
 * Le demandeur qui conduit lui-même (sans chauffeur) a le même écran.
 *
 * Saisie hors réseau : app.js garde la saisie dans IndexedDB et l'envoie au
 * retour du réseau, avec l'heure de la saisie (recorded_at) et un identifiant
 * (client_id). Un envoi en double (même client_id) est accepté sans rien
 * refaire. Réponse JSON quand le navigateur la demande (Accept), sinon
 * redirection avec message.
 */
final class MissionController extends Controller
{
    /** Saisie hors réseau acceptée jusqu'à 7 jours après coup. */
    private const MAX_DELAY_SEC = 7 * 86400;

    public function index(): void
    {
        $employee = Auth::employeeId();
        $where = "(de.id = ? OR (r.with_driver = 0 AND r.requester_id = ?)) AND r.vehicle_id IS NOT NULL";
        $select = "SELECT r.id, r.reference, r.destination, r.purpose, r.depart_at, r.return_at, r.passengers, r.status, r.validation, r.step,
                          r.urgent, r.with_driver, e.name requester_name, e.phone requester_phone, v.plate, v.brand, v.model
                     FROM fleet_requests r JOIN employees e ON e.id = r.requester_id
                     JOIN fleet_vehicles v ON v.id = r.vehicle_id
                     LEFT JOIN fleet_drivers dr ON dr.id = r.driver_id LEFT JOIN employees de ON de.id = dr.employee_id
                    WHERE $where";
        $this->render('Fleet:missions', [
            'title'    => 'Mes missions',
            'employee' => $employee,
            'current'  => $employee === null ? [] : Database::all("$select AND r.status IN ('affectee', 'en_cours') ORDER BY r.status = 'en_cours' DESC, r.depart_at", [$employee, $employee]),
            'done'     => $employee === null ? [] : Database::all("$select AND r.status = 'terminee' ORDER BY r.depart_at DESC LIMIT 10", [$employee, $employee]),
        ]);
    }

    public function show(int $id): void
    {
        $req = Fleet::request($id) ?? $this->notFound();
        $access = Fleet::access($req);
        if (!$access['view'] || $req['vehicle_id'] === null) {
            $this->forbidden();
        }
        $this->render('Fleet:mission', [
            'title'   => 'Mission ' . $req['reference'],
            'req'     => $req,
            'access'  => $access,
            'mission' => Database::one('SELECT * FROM fleet_missions WHERE request_id = ?', [$id]),
        ]);
    }

    public function start(int $id): void
    {
        $this->record($id, 'depart');
    }

    public function close(int $id): void
    {
        $this->record($id, 'retour');
    }

    public function photoStart(int $id): void
    {
        $this->photo($id, 'depart');
    }

    public function photoEnd(int $id): void
    {
        $this->photo($id, 'retour');
    }

    /** Photo du compteur (mêmes droits que la demande). */
    private function photo(int $id, string $moment): void
    {
        $req = Fleet::request($id) ?? $this->notFound();
        if (!Fleet::access($req)['view']) {
            $this->forbidden();
        }
        FleetFiles::send(FleetFiles::photoPath($id, $moment), $req['reference'] . '-compteur-' . $moment . '.jpg', 'image/jpeg');
    }

    /** Démarrage (depart) ou clôture (retour) d'une mission. */
    private function record(int $id, string $moment): void
    {
        $clientId = preg_match('/^[A-Za-z0-9-]{8,40}$/', (string) $this->input('client_id', '')) ? (string) $this->input('client_id') : null;
        $km = filter_var($this->input('km'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 5_000_000]]);
        $fuel = (int) $this->input('fuel', -1);
        $condition = (string) $this->input('condition', '');
        $notes = mb_substr((string) $this->input('notes', ''), 0, 500);
        $at = $this->recordedAt((string) $this->input('recorded_at', ''));
        $prefix = $moment === 'depart' ? 'start' : 'end';

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            Database::value('SELECT id FROM fleet_requests WHERE id = ? FOR UPDATE', [$id]) ?? throw new DomainException('Mission introuvable.', 404);
            $req = Fleet::request($id);
            if (!Fleet::access($req)['driver']) {
                throw new DomainException('Seul le chauffeur affecté peut ' . ($moment === 'depart' ? 'démarrer' : 'clôturer') . ' cette mission.', 403);
            }
            $mission = Database::one('SELECT * FROM fleet_missions WHERE request_id = ?', [$id]);
            // Envoi en double (file hors réseau, double clic) : déjà fait, rien à refaire
            if ($clientId !== null && $mission !== null && $mission[$prefix . '_client_id'] === $clientId) {
                $pdo->rollBack();
                $this->respond(true, $moment === 'depart' ? 'Mission déjà démarrée.' : 'Mission déjà clôturée.', $id, 200);
            }
            if ($moment === 'depart' ? !FleetWorkflow::canStart($req) : !FleetWorkflow::canClose($req)) {
                throw new DomainException($moment === 'depart'
                    ? ($req['status'] === 'en_cours' || $req['status'] === 'terminee' ? 'Mission déjà démarrée.' : 'Cette mission ne peut pas être démarrée (' . FleetWorkflow::label($req)[0] . ').')
                    : ($req['status'] === 'terminee' ? 'Mission déjà clôturée.' : 'Cette mission n\'est pas en cours.'), 409);
            }
            [$photo, $photoError] = FleetFiles::decodePhoto((string) ($_POST['photo_data'] ?? ''));
            $error = match (true) {
                $km === false => 'Kilométrage invalide.',
                !isset(Fleet::FUEL_LEVELS[$fuel]) => 'Choisissez le niveau de carburant.',
                !isset(Fleet::CONDITIONS[$condition]) => 'Choisissez l\'état du véhicule.',
                $photoError !== null => $photoError,
                $photo === null => 'La photo du compteur est obligatoire.',
                $condition !== 'bon' && trim($notes) === '' => 'Décrivez le problème constaté.',
                $moment === 'retour' => FleetWorkflow::closeError((int) $mission['start_km'], (int) $km),
                default => null,
            };
            if ($moment === 'retour' && $error === null && $at < $mission['started_at']) {
                $at = date('Y-m-d H:i:s');
            }
            if ($error !== null) {
                throw new DomainException($error, 422);
            }

            FleetFiles::savePhoto($id, $moment, $photo);
            $fuelLabel = Fleet::FUEL_LEVELS[$fuel];
            if ($moment === 'depart') {
                Database::run(
                    'INSERT INTO fleet_missions (request_id, vehicle_id, driver_id, start_km, start_fuel, start_condition, start_notes, start_photo,
                                                 started_at, started_by, start_client_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)',
                    [$id, $req['vehicle_id'], $req['driver_id'], $km, $fuel, $condition, $notes ?: null, $at, Auth::id(), $clientId]
                );
                Database::run("UPDATE fleet_requests SET status = 'en_cours' WHERE id = ?", [$id]);
                Database::run("UPDATE fleet_vehicles SET status = 'en_mission' WHERE id = ?", [$req['vehicle_id']]);
                $below = $req['odometer_km'] !== null && $km < (int) $req['odometer_km'];
                Fleet::event($id, 'demarree', number_format($km, 0, ',', ' ') . ' km · carburant ' . $fuelLabel . ' · ' . Fleet::CONDITIONS[$condition]
                    . ($notes !== '' ? ' : ' . $notes : '') . ($below ? ' · inférieur au dernier relevé (' . number_format((int) $req['odometer_km'], 0, ',', ' ') . ' km)' : '')
                    . ($at < date('Y-m-d H:i:s', time() - 120) ? ' · saisi hors réseau le ' . date('d/m à H:i', strtotime($at)) : ''));
            } else {
                Database::run(
                    'UPDATE fleet_missions SET end_km = ?, end_fuel = ?, end_condition = ?, end_notes = ?, end_photo = 1, ended_at = ?,
                            end_synced_at = NOW(), ended_by = ?, end_client_id = ? WHERE request_id = ?',
                    [$km, $fuel, $condition, $notes ?: null, $at, Auth::id(), $clientId, $id]
                );
                Database::run("UPDATE fleet_requests SET status = 'terminee' WHERE id = ?", [$id]);
                Database::run('UPDATE fleet_vehicles SET status = ?, odometer_km = GREATEST(COALESCE(odometer_km, 0), ?) WHERE id = ?',
                    [$condition === 'en_panne' ? 'en_panne' : 'disponible', $km, $req['vehicle_id']]);
                Fleet::event($id, 'cloturee', number_format($km, 0, ',', ' ') . ' km (' . number_format($km - (int) $mission['start_km'], 0, ',', ' ') . ' km parcourus)'
                    . ' · carburant ' . $fuelLabel . ' · ' . Fleet::CONDITIONS[$condition] . ($notes !== '' ? ' : ' . $notes : '')
                    . ($at < date('Y-m-d H:i:s', time() - 120) ? ' · saisi hors réseau le ' . date('d/m à H:i', strtotime($at)) : ''));
            }
            Audit::log($moment === 'depart' ? 'flotte_mission_demarree' : 'flotte_mission_cloturee',
                ['id' => $id, 'reference' => $req['reference'], 'km' => $km, 'carburant' => $fuel, 'etat' => $condition, 'saisie' => $at]);
            $pdo->commit();
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->respond(false, $e->getMessage(), $id, $e->getCode() ?: 422);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        if ($moment === 'retour') {
            FleetNotify::closed($req);
            if ($condition === 'en_panne') {
                FleetNotify::breakdown($req, $notes);
            }
        }
        $this->respond(true, $moment === 'depart' ? 'Mission démarrée. Bonne route !' : 'Mission clôturée. Merci !', $id, 200);
    }

    /** Heure de la saisie sur le téléphone, bornée (pas dans le futur, 7 jours au plus). */
    private function recordedAt(string $value): string
    {
        $now = time();
        $ts = Fleet::datetime($value) !== null ? strtotime(str_replace('T', ' ', substr($value, 0, 19))) : false;
        return $ts !== false && $ts <= $now + 300 && $ts >= $now - self::MAX_DELAY_SEC ? date('Y-m-d H:i:s', min($ts, $now)) : date('Y-m-d H:i:s', $now);
    }

    /** Réponse JSON (app.js, file hors réseau) ou redirection avec message. */
    private function respond(bool $ok, string $message, int $id, int $status): never
    {
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['ok' => $ok, 'message' => $message, 'redirect' => '/flotte/missions/' . $id], JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash($ok ? 'success' : 'error', $message);
        $this->redirect('/flotte/missions/' . $id);
    }

    private function forbidden(): never
    {
        http_response_code(403);
        $this->render('errors/403', ['title' => 'Accès refusé']);
        exit;
    }
}
