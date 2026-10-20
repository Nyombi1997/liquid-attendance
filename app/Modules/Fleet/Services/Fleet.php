<?php
declare(strict_types=1);

namespace App\Modules\Fleet\Services;

use App\Core\Auth;
use App\Core\Database;

/**
 * Accès aux données du parc automobile partagé par les contrôleurs :
 * paramètres, demandes, réservations (pour Availability), documents,
 * historique des demandes et destinataires des notifications.
 */
final class Fleet
{
    public const VEHICLE_STATUSES = [
        'disponible'   => ['Disponible', 'ok'],
        'en_mission'   => ['En mission', 'info'],
        'en_panne'     => ['En panne', 'danger'],
        'en_entretien' => ['En entretien', 'warn'],
    ];
    public const FUEL_TYPES = ['diesel' => 'Diesel', 'essence' => 'Essence', 'hybride' => 'Hybride', 'electrique' => 'Électrique'];
    public const DOC_KINDS = ['assurance' => 'Assurance', 'controle_technique' => 'Contrôle technique', 'autre' => 'Autre document'];
    public const CONDITIONS = ['bon' => 'Bon état', 'a_signaler' => 'Défaut à signaler', 'en_panne' => 'En panne / immobilisé'];
    public const FUEL_LEVELS = [5 => 'Réserve', 25 => '1/4', 50 => '1/2', 75 => '3/4', 100 => 'Plein'];

    /** Libellés de l'historique d'une demande (fleet_request_events.action). */
    public const EVENTS = [
        'creee'            => 'Demande créée',
        'validee_n1'       => 'Validée par le N+1',
        'validee_direction'=> 'Validée par la direction',
        'refusee'          => 'Refusée',
        'annulee'          => 'Annulée par le demandeur',
        'affectee'         => 'Véhicule et chauffeur affectés',
        'reaffectee'       => 'Affectation modifiée',
        'affectee_urgence' => 'Affectée avant validation (urgence)',
        'liberee'          => 'Affectation annulée, véhicule libéré',
        'demarree'         => 'Mission démarrée',
        'cloturee'         => 'Mission clôturée',
        'evaluee'          => 'Course évaluée',
        'rappel'           => 'Rappel avant le départ envoyé',
    ];

    /** Statuts qui occupent un véhicule et un chauffeur. */
    public const BOOKED = ['affectee', 'en_cours'];

    /** @return array<string, string> paramètres du circuit (valeurs par défaut comprises) */
    public static function settings(): array
    {
        $values = FleetWorkflow::DEFAULTS;
        foreach (Database::all("SELECT name, value FROM settings WHERE name LIKE 'fleet\\_%'") as $s) {
            $values[$s['name']] = $s['value'];
        }
        return $values;
    }

    /** Demande avec le demandeur, son service, le véhicule et le chauffeur. */
    public static function request(int $id): ?array
    {
        return Database::one(
            "SELECT r.*, e.name requester_name, e.email requester_email, e.phone requester_phone, d.name department_name,
                    v.plate, v.brand, v.model, v.vehicle_type v_type, v.seats v_seats, v.odometer_km,
                    dr.employee_id driver_employee_id, de.name driver_name, de.phone driver_phone,
                    pe.name preferred_driver_name, nu.full_name n1_name
               FROM fleet_requests r
               JOIN employees e ON e.id = r.requester_id
               LEFT JOIN departments d ON d.id = r.department_id
               LEFT JOIN fleet_vehicles v ON v.id = r.vehicle_id
               LEFT JOIN fleet_drivers dr ON dr.id = r.driver_id
               LEFT JOIN employees de ON de.id = dr.employee_id
               LEFT JOIN fleet_drivers pd ON pd.id = r.preferred_driver_id
               LEFT JOIN employees pe ON pe.id = pd.employee_id
               LEFT JOIN users nu ON nu.id = r.n1_user_id
              WHERE r.id = ?",
            [$id]
        );
    }

    /**
     * Droits de l'utilisateur connecté sur une demande.
     *
     * @return array{view:bool, requester:bool, validate:?string, driver:bool, manage:bool}
     */
    public static function access(array $req): array
    {
        $employee = Auth::employeeId();
        $requester = $employee !== null && (int) $req['requester_id'] === $employee;
        $driver = $employee !== null && (
            ($req['driver_employee_id'] !== null && (int) $req['driver_employee_id'] === $employee)
            || ((int) $req['with_driver'] === 0 && $requester && $req['vehicle_id'] !== null));
        $validate = null;
        if ($req['validation'] === 'en_attente' && !in_array($req['status'], ['refusee', 'annulee'], true)) {
            if ($req['step'] === 'n1' && (int) $req['n1_user_id'] === Auth::id()) {
                $validate = 'n1';
            } elseif ($req['step'] === 'direction' && (Auth::user()['fleet_role'] ?? '') === 'direction') {
                $validate = 'direction';
            }
        }
        // Validateurs déjà intervenus : gardent l'accès en consultation
        $acted = in_array(Auth::id(), array_map('intval', array_filter([$req['n1_user_id'], $req['n1_decided_by'],
            $req['direction_decided_by'], $req['refused_by']])), true);
        $board = Auth::can('fleet_board');
        return [
            'view'      => $requester || $driver || $validate !== null || $acted || $board || Auth::can('fleet_manage'),
            'requester' => $requester,
            'validate'  => $validate,
            'driver'    => $driver,
            'manage'    => Auth::can('fleet_manage'),
        ];
    }

    /** Ajoute une ligne à l'historique de la demande. */
    public static function event(int $requestId, string $action, ?string $details = null): void
    {
        Database::run('INSERT INTO fleet_request_events (request_id, user_id, action, details) VALUES (?, ?, ?, ?)',
            [$requestId, $_SESSION['user_id'] ?? null, $action, $details !== null ? mb_substr($details, 0, 500) : null]);
    }

    /**
     * Réservations (demandes affectées ou en cours) qui touchent la période,
     * regroupées par véhicule ou par chauffeur.
     *
     * @param 'vehicle_id'|'driver_id' $by
     * @return array<int, array<int, array{request_id:int, reference:?string, from:string, to:string, status:string, destination:string, requester:string, vehicle_id:?int, driver_id:?int}>>
     */
    public static function bookings(string $from, string $to, string $by, bool $lock = false, ?int $only = null): array
    {
        $by = $by === 'driver_id' ? 'driver_id' : 'vehicle_id';
        $now = date('Y-m-d H:i:s');
        $rows = Database::all(
            "SELECT r.id, r.reference, r.depart_at, r.return_at, r.status, r.destination, r.vehicle_id, r.driver_id, e.name requester
               FROM fleet_requests r JOIN employees e ON e.id = r.requester_id
              WHERE r.status IN ('affectee', 'en_cours') AND r.$by IS NOT NULL
                AND r.depart_at < ? AND (r.return_at > ? OR r.status = 'en_cours')"
                . ($only !== null ? " AND r.$by = " . (int) $only : '') . ($lock ? ' FOR UPDATE' : ''),
            [$to, $from]
        );
        $out = [];
        foreach ($rows as $r) {
            [$f, $t] = Availability::window($r['status'], $r['depart_at'], $r['return_at'], $now);
            if (!Availability::overlaps($from, $to, $f, $t)) {
                continue;
            }
            $out[(int) $r[$by]][] = [
                'request_id' => (int) $r['id'], 'reference' => $r['reference'], 'from' => $f, 'to' => $t,
                'status' => $r['status'], 'destination' => $r['destination'], 'requester' => $r['requester'],
                'vehicle_id' => $r['vehicle_id'] !== null ? (int) $r['vehicle_id'] : null,
                'driver_id' => $r['driver_id'] !== null ? (int) $r['driver_id'] : null,
            ];
        }
        return $out;
    }

    /**
     * Date d'expiration du dernier document de chaque type, par véhicule.
     *
     * @return array<int, array<string, ?string>>
     */
    public static function documents(): array
    {
        $out = [];
        foreach (Database::all(
            "SELECT d.vehicle_id, d.kind, d.expires_on FROM fleet_vehicle_documents d
               JOIN (SELECT vehicle_id, kind, MAX(id) id FROM fleet_vehicle_documents
                      WHERE kind IN ('assurance', 'controle_technique') GROUP BY vehicle_id, kind) last ON last.id = d.id"
        ) as $d) {
            $out[(int) $d['vehicle_id']][$d['kind']] = $d['expires_on'];
        }
        return $out;
    }

    /** @return array<int, int[]> véhicules autorisés par chauffeur (absent : tous) */
    public static function authorizedVehicles(): array
    {
        $out = [];
        foreach (Database::all('SELECT driver_id, vehicle_id FROM fleet_driver_vehicles') as $r) {
            $out[(int) $r['driver_id']][] = (int) $r['vehicle_id'];
        }
        return $out;
    }

    /** Chauffeurs avec leur nom (actifs seulement par défaut). */
    public static function drivers(bool $activeOnly = true): array
    {
        return Database::all(
            'SELECT dr.*, e.name, e.phone, e.email, e.photo_updated_at, e.job_title
               FROM fleet_drivers dr JOIN employees e ON e.id = dr.employee_id'
            . ($activeOnly ? ' WHERE dr.active = 1 AND e.active = 1' : '') . ' ORDER BY e.name'
        );
    }

    /** Comptes actifs ayant un rôle Parc automobile donné. @return int[] */
    public static function usersWithRole(string $fleetRole): array
    {
        return array_map('intval', array_column(
            Database::all('SELECT id FROM users WHERE active = 1 AND fleet_role = ?', [$fleetRole]), 'id'));
    }

    /** Compte actif relié à une fiche employé, ou null. */
    public static function userOf(?int $employeeId): ?int
    {
        if ($employeeId === null) {
            return null;
        }
        $id = Database::value('SELECT id FROM users WHERE employee_id = ? AND active = 1', [$employeeId]);
        return $id !== null ? (int) $id : null;
    }

    /** Valide une date et heure saisie (« AAAA-MM-JJTHH:MM ») ; null si invalide. */
    public static function datetime(?string $value): ?string
    {
        $value = str_replace('T', ' ', trim((string) $value));
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $value)) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i' . (strlen($value) > 16 ? ':s' : ''), $value);
        return $d && $d->format('Y') >= 2000 ? $d->format('Y-m-d H:i:00') : null;
    }

    /** « lun. 20/10 08:00 » */
    public static function when(?string $datetime): string
    {
        if (!$datetime) {
            return '—';
        }
        $jours = ['', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];
        $ts = strtotime($datetime);
        return $jours[(int) date('N', $ts)] . ' ' . date('d/m H:i', $ts);
    }

    /** Badge HTML du statut d'une demande. */
    public static function badge(array $req): string
    {
        [$label, $class] = FleetWorkflow::label($req);
        return '<span class="badge badge-' . $class . '">' . e($label) . '</span>';
    }

    /**
     * Badge d'expiration d'un document ou d'un permis : expiré, expire bientôt
     * (moins de $alertDays jours), valide ou non renseigné.
     */
    public static function expiryBadge(?string $date, int $alertDays): string
    {
        if (!$date) {
            return '<span class="badge badge-muted">Non renseigné</span>';
        }
        $days = (int) floor((strtotime($date) - strtotime(date('Y-m-d'))) / 86400);
        [$label, $class] = match (true) {
            $days < 0           => ['Expiré le ' . date('d/m/Y', strtotime($date)), 'danger'],
            $days <= $alertDays => ['Expire le ' . date('d/m/Y', strtotime($date)) . ' (' . $days . ' j)', 'warn'],
            default             => ['Valide jusqu\'au ' . date('d/m/Y', strtotime($date)), 'ok'],
        };
        return '<span class="badge badge-' . $class . '">' . e($label) . '</span>';
    }

    /** Badge HTML du statut d'un véhicule. */
    public static function vehicleBadge(string $status): string
    {
        [$label, $class] = self::VEHICLE_STATUSES[$status] ?? [$status, 'muted'];
        return '<span class="badge badge-' . $class . '">' . e($label) . '</span>';
    }
}
