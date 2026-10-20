<?php
declare(strict_types=1);

namespace App\Modules\Fleet\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Modules\Fleet\Services\Availability;
use App\Modules\Fleet\Services\Fleet;
use App\Modules\Fleet\Services\FleetNotify;
use App\Modules\Fleet\Services\FleetWorkflow;
use App\Modules\Fleet\Services\Timeline;
use DomainException;

/**
 * Affectation d'un véhicule et d'un chauffeur (gestionnaire de flotte) et
 * planning du parc. La page d'affectation montre la frise des véhicules et des
 * chauffeurs sur la période de la demande, avec leurs indisponibilités
 * (Availability). L'enregistrement verrouille la demande, le véhicule et le
 * chauffeur (SELECT … FOR UPDATE) et refait tous les contrôles dans la
 * transaction : deux affectations simultanées du même véhicule ne peuvent pas
 * réussir toutes les deux.
 */
final class AssignmentController extends Controller
{
    public function form(int $id): void
    {
        $req = Fleet::request($id) ?? $this->notFound();
        [$from, $to] = $this->window($req['depart_at'], $req['return_at']);
        $vehicleBookings = Fleet::bookings($from, $to, 'vehicle_id');
        $driverBookings = Fleet::bookings($from, $to, 'driver_id');
        $docs = Fleet::documents();
        $authorized = Fleet::authorizedVehicles();
        $slot = Timeline::span($req['depart_at'], $req['return_at'], $from, $to);

        $vehicles = [];
        foreach (Database::all('SELECT * FROM fleet_vehicles WHERE active = 1 ORDER BY plate') as $v) {
            $v['issues'] = Availability::vehicleIssues($v, $req, $docs[(int) $v['id']] ?? [], $vehicleBookings[(int) $v['id']] ?? []);
            $v['blocked'] = Availability::blocking($v['issues']);
            $v['match'] = $req['vehicle_type'] === null || $req['vehicle_type'] === $v['vehicle_type'];
            $v['bars'] = $this->bars($vehicleBookings[(int) $v['id']] ?? [], $from, $to, $id, 'driver');
            $vehicles[] = $v;
        }
        usort($vehicles, static fn($a, $b) => [$a['blocked'], !$a['match'], count($a['issues']), $a['plate']]
            <=> [$b['blocked'], !$b['match'], count($b['issues']), $b['plate']]);

        $drivers = [];
        if ((int) $req['with_driver'] === 1) {
            $current = $req['vehicle_id'] !== null ? Database::one('SELECT * FROM fleet_vehicles WHERE id = ?', [$req['vehicle_id']]) : null;
            foreach (Fleet::drivers() as $d) {
                $d['issues'] = Availability::driverIssues($d, $req, $current, $authorized[(int) $d['id']] ?? [], $driverBookings[(int) $d['id']] ?? []);
                $d['blocked'] = Availability::blocking($d['issues']);
                $d['preferred'] = (int) $req['preferred_driver_id'] === (int) $d['id'];
                $d['authorized'] = $authorized[(int) $d['id']] ?? [];
                $d['bars'] = $this->bars($driverBookings[(int) $d['id']] ?? [], $from, $to, $id, 'vehicle');
                $drivers[] = $d;
            }
            usort($drivers, static fn($a, $b) => [$a['blocked'], !$a['preferred'], $a['name']] <=> [$b['blocked'], !$b['preferred'], $b['name']]);
        }

        $this->render('Fleet:assign', [
            'title'    => 'Affecter ' . $req['reference'],
            'req'      => $req,
            'can'      => FleetWorkflow::canAssign($req),
            'vehicles' => $vehicles,
            'drivers'  => $drivers,
            'ticks'    => Timeline::ticks($from, $to),
            'slot'     => $slot,
        ]);
    }

    public function store(int $id): void
    {
        $vehicleId = (int) $this->input('vehicle_id', 0);
        $driverId = (int) $this->input('driver_id', 0) ?: null;
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // Verrous, toujours dans le même ordre : demande, véhicule, chauffeur
            Database::value('SELECT id FROM fleet_requests WHERE id = ? FOR UPDATE', [$id]) ?? $this->notFound();
            $req = Fleet::request($id);
            $changes = FleetWorkflow::assign($req);
            $vehicle = Database::one('SELECT * FROM fleet_vehicles WHERE id = ? FOR UPDATE', [$vehicleId]);
            if ($vehicle === null) {
                throw new DomainException('Choisissez un véhicule.');
            }
            $driver = null;
            if ((int) $req['with_driver'] === 1) {
                $driver = $driverId !== null ? Database::one(
                    'SELECT dr.*, e.name FROM fleet_drivers dr JOIN employees e ON e.id = dr.employee_id WHERE dr.id = ? FOR UPDATE', [$driverId]) : null;
                if ($driver === null) {
                    throw new DomainException('Choisissez un chauffeur.');
                }
            }
            $issues = Availability::vehicleIssues($vehicle, $req, Fleet::documents()[$vehicleId] ?? [],
                Fleet::bookings($req['depart_at'], $req['return_at'], 'vehicle_id', true, $vehicleId)[$vehicleId] ?? []);
            if ($driver !== null) {
                $issues = array_merge($issues, Availability::driverIssues($driver, $req, $vehicle, Fleet::authorizedVehicles()[(int) $driver['id']] ?? [],
                    Fleet::bookings($req['depart_at'], $req['return_at'], 'driver_id', true, (int) $driver['id'])[(int) $driver['id']] ?? []));
            }
            $blocking = array_filter($issues, static fn($i) => $i['level'] === 'block');
            if ($blocking) {
                throw new DomainException('Affectation impossible : ' . implode(' ; ', array_column($blocking, 'text')) . '.');
            }

            $changed = $req['vehicle_id'] !== null;
            Database::run(
                'UPDATE fleet_requests SET vehicle_id = ?, driver_id = ?, status = ?, assigned_before_validation = ?,
                        assigned_by = ?, assigned_at = NOW(), reminder_sent_at = IF(depart_at > NOW(), NULL, reminder_sent_at) WHERE id = ?',
                [$vehicleId, $driver['id'] ?? null, $changes['status'], $changes['assigned_before_validation'], Auth::id(), $id]
            );
            $what = $vehicle['plate'] . ($driver ? ' · chauffeur ' . $driver['name'] : ' · le demandeur conduit');
            $warnings = array_column(array_filter($issues, static fn($i) => $i['level'] === 'warn'), 'text');
            Fleet::event($id, $changed ? 'reaffectee' : ($req['validation'] === 'en_attente' ? 'affectee_urgence' : 'affectee'),
                $what . ($changed ? ' (avant : ' . $req['plate'] . ($req['driver_name'] ? ', ' . $req['driver_name'] : '') . ')' : '')
                . ($warnings ? ' · à noter : ' . implode(' ; ', $warnings) : ''));
            Audit::log('flotte_affectation', ['id' => $id, 'reference' => $req['reference'], 'vehicule' => $vehicleId,
                'chauffeur' => $driver['id'] ?? null, 'avant_validation' => $req['validation'] === 'en_attente']);
            $pdo->commit();
        } catch (DomainException $e) {
            $pdo->rollBack();
            flash('error', $e->getMessage());
            $this->redirect('/flotte/demandes/' . $id . '/affecter');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        FleetNotify::assigned(Fleet::request($id), $changed);
        flash('success', 'Demande ' . $req['reference'] . ' affectée : ' . $what . '.');
        $this->redirect('/flotte/demandes/' . $id);
    }

    /** Planning des véhicules et des chauffeurs (jour ou semaine). */
    public function planning(): void
    {
        $date = (string) $this->input('date', date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
            $date = date('Y-m-d');
        }
        $view = $this->input('vue') === 'jour' ? 'jour' : 'semaine';
        $start = $view === 'jour' ? $date : date('Y-m-d', strtotime($date . ' -' . (date('N', strtotime($date)) - 1) . ' days'));
        $days = $view === 'jour' ? 1 : 7;
        $from = $start . ' 00:00:00';
        $to = date('Y-m-d H:i:s', strtotime($from . ' +' . $days . ' days'));
        $vehicleBookings = Fleet::bookings($from, $to, 'vehicle_id');
        $driverBookings = Fleet::bookings($from, $to, 'driver_id');

        $vehicleRows = [];
        foreach (Database::all('SELECT * FROM fleet_vehicles WHERE active = 1 ORDER BY plate') as $v) {
            $vehicleRows[] = [
                'label'   => '<span class="list-main"><strong>' . e($v['plate']) . '</strong><small>' . e($v['brand'] . ' ' . $v['model'])
                    . ' · ' . (int) $v['seats'] . ' pl.</small></span> ' . ($v['status'] !== 'disponible' ? Fleet::vehicleBadge($v['status']) : ''),
                'blocked' => in_array($v['status'], ['en_panne', 'en_entretien'], true),
                'slot'    => null,
                'bars'    => $this->bars($vehicleBookings[(int) $v['id']] ?? [], $from, $to, null, 'driver'),
            ];
        }
        $driverRows = [];
        foreach (Fleet::drivers() as $d) {
            $driverRows[] = [
                'label'   => avatar($d['name'], photo_url(['id' => $d['employee_id'], 'photo_updated_at' => $d['photo_updated_at']]))
                    . '<span class="list-main"><strong>' . e($d['name']) . '</strong><small>' . e($d['phone'] ?? '') . '</small></span>',
                'blocked' => $d['license_expires'] !== null && $d['license_expires'] < substr($from, 0, 10),
                'slot'    => null,
                'bars'    => $this->bars($driverBookings[(int) $d['id']] ?? [], $from, $to, null, 'vehicle'),
            ];
        }
        $waiting = Database::all(
            "SELECT r.id, r.reference, r.destination, r.depart_at, r.return_at, r.urgent, r.status, r.validation, r.step, e.name requester_name
               FROM fleet_requests r JOIN employees e ON e.id = r.requester_id
              WHERE (r.status = 'approuvee' OR (r.status = 'en_validation' AND r.urgent = 1)) AND r.depart_at < ? AND r.return_at > ?
              ORDER BY r.urgent DESC, r.depart_at",
            [$to, $from]
        );
        $this->render('Fleet:planning', [
            'title'    => 'Planning du parc',
            'view'     => $view,
            'date'     => $date,
            'from'     => $from,
            'to'       => $to,
            'prev'     => date('Y-m-d', strtotime($start . ' -' . $days . ' days')),
            'next'     => date('Y-m-d', strtotime($start . ' +' . $days . ' days')),
            'ticks'    => Timeline::ticks($from, $to),
            'vehicles' => $vehicleRows,
            'drivers'  => $driverRows,
            'waiting'  => $waiting,
        ]);
    }

    /**
     * Période affichée : du début du jour de départ à la fin du jour de retour.
     * @return array{0:string, 1:string}
     */
    private function window(string $depart, string $return): array
    {
        return [substr($depart, 0, 10) . ' 00:00:00', date('Y-m-d', strtotime(substr($return, 0, 10) . ' +1 day')) . ' 00:00:00'];
    }

    /**
     * Barres d'une ligne de la frise.
     * @param 'driver'|'vehicle' $other ce qu'on affiche dans la bulle (chauffeur d'un véhicule, véhicule d'un chauffeur)
     */
    private function bars(array $bookings, string $from, string $to, ?int $current, string $other): array
    {
        static $names = null;
        $names ??= [
            'vehicle' => array_column(Database::all('SELECT id, plate FROM fleet_vehicles'), 'plate', 'id'),
            'driver'  => array_column(Database::all('SELECT dr.id, e.name FROM fleet_drivers dr JOIN employees e ON e.id = dr.employee_id'), 'name', 'id'),
        ];
        $bars = [];
        foreach ($bookings as $b) {
            $span = Timeline::span($b['from'], $b['to'], $from, $to);
            if ($span === null) {
                continue;
            }
            $with = $b[$other . '_id'] !== null ? ($names[$other][$b[$other . '_id']] ?? '') : '';
            $bars[] = [$span[0], $span[1], $b['reference'] . ' · ' . $b['destination'],
                can('fleet_board') || can('fleet_manage') ? '/flotte/demandes/' . $b['request_id'] : null,
                $b['request_id'] === $current ? 'is-current' : ($b['status'] === 'en_cours' ? 'is-ongoing' : ''),
                $b['reference'] . ' · ' . $b['requester'] . ' · ' . $b['destination'] . ' · ' . date('d/m H:i', strtotime($b['from']))
                    . ' → ' . date('d/m H:i', strtotime($b['to'])) . ($with !== '' ? ' · ' . $with : '')];
        }
        return $bars;
    }
}
