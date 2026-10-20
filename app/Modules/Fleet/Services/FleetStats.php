<?php
declare(strict_types=1);

namespace App\Modules\Fleet\Services;

use App\Core\Database;

/**
 * Indicateurs du tableau de bord du parc automobile : état des véhicules,
 * demandes en attente par étape, délais de traitement (création → validation
 * → affectation), missions du jour, alertes d'expiration, kilomètres du mois.
 */
final class FleetStats
{
    /** Période des délais moyens (jours). */
    public const DELAY_DAYS = 90;

    /** @return array<string, int> nombre de véhicules actifs par état */
    public static function vehicles(): array
    {
        $counts = array_fill_keys(array_keys(Fleet::VEHICLE_STATUSES), 0);
        foreach (Database::all('SELECT status, COUNT(*) n FROM fleet_vehicles WHERE active = 1 GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        return $counts;
    }

    /** @return array{n1:int, direction:int, affecter:int, urgences:int} demandes en attente par étape */
    public static function pending(): array
    {
        $r = Database::one(
            "SELECT SUM(status = 'en_validation' AND step = 'n1') n1,
                    SUM(status = 'en_validation' AND step = 'direction') direction,
                    SUM(status = 'approuvee' OR (status = 'en_validation' AND urgent = 1)) affecter,
                    SUM(assigned_before_validation = 1 AND validation = 'en_attente' AND status NOT IN ('annulee', 'refusee')) urgences
               FROM fleet_requests WHERE status IN ('en_validation', 'approuvee', 'affectee', 'en_cours', 'terminee')"
        );
        return array_map('intval', $r ?? []) + ['n1' => 0, 'direction' => 0, 'affecter' => 0, 'urgences' => 0];
    }

    /**
     * Délais moyens en minutes sur les DELAY_DAYS derniers jours (null : aucune donnée).
     * Les affectations d'urgence faites avant la validation ne comptent pas dans
     * « validation → affectation ».
     *
     * @return array{validation:?int, affectation:?int, total:?int, n_validation:int, n_affectation:int}
     */
    public static function delays(): array
    {
        $r = Database::one(
            'SELECT AVG(CASE WHEN validated_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, validated_at) END) validation,
                    COUNT(validated_at) n_validation,
                    AVG(CASE WHEN assigned_at IS NOT NULL AND validated_at IS NOT NULL AND assigned_before_validation = 0
                             THEN TIMESTAMPDIFF(MINUTE, validated_at, assigned_at) END) affectation,
                    SUM(assigned_at IS NOT NULL AND validated_at IS NOT NULL AND assigned_before_validation = 0) n_affectation,
                    AVG(CASE WHEN assigned_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, created_at, assigned_at) END) total
               FROM fleet_requests WHERE created_at >= NOW() - INTERVAL ? DAY',
            [self::DELAY_DAYS]
        );
        $round = static fn($v) => $v !== null ? (int) round((float) $v) : null;
        return [
            'validation'    => $round($r['validation']),
            'affectation'   => $round($r['affectation']),
            'total'         => $round($r['total']),
            'n_validation'  => (int) $r['n_validation'],
            'n_affectation' => (int) $r['n_affectation'],
        ];
    }

    /** Départs prévus aujourd'hui et missions en cours. */
    public static function today(): array
    {
        return Database::all(
            "SELECT r.id, r.reference, r.destination, r.depart_at, r.return_at, r.status, r.validation, r.step, r.urgent,
                    e.name requester_name, v.plate, de.name driver_name
               FROM fleet_requests r JOIN employees e ON e.id = r.requester_id
               LEFT JOIN fleet_vehicles v ON v.id = r.vehicle_id
               LEFT JOIN fleet_drivers dr ON dr.id = r.driver_id LEFT JOIN employees de ON de.id = dr.employee_id
              WHERE r.status = 'en_cours' OR (r.status IN ('affectee', 'approuvee') AND DATE(r.depart_at) = CURDATE())
              ORDER BY r.status = 'en_cours' DESC, r.depart_at LIMIT 20"
        );
    }

    /** Demandes à affecter, urgentes d'abord. */
    public static function toAssign(int $limit = 8): array
    {
        return Database::all(
            "SELECT r.id, r.reference, r.destination, r.depart_at, r.return_at, r.status, r.validation, r.step, r.urgent, e.name requester_name
               FROM fleet_requests r JOIN employees e ON e.id = r.requester_id
              WHERE r.status = 'approuvee' OR (r.status = 'en_validation' AND r.urgent = 1)
              ORDER BY r.urgent DESC, r.depart_at LIMIT " . max(1, $limit)
        );
    }

    /**
     * Documents (dernier de chaque type) et permis expirés ou expirant dans $days jours.
     * @return array<int, array{kind:string, label:string, expires:string, url:string}>
     */
    public static function expiring(int $days): array
    {
        $out = [];
        foreach (Database::all(
            "SELECT d.kind, d.expires_on, v.id, v.plate FROM fleet_vehicle_documents d
               JOIN (SELECT vehicle_id, kind, MAX(id) id FROM fleet_vehicle_documents
                      WHERE kind IN ('assurance', 'controle_technique') GROUP BY vehicle_id, kind) last ON last.id = d.id
               JOIN fleet_vehicles v ON v.id = d.vehicle_id AND v.active = 1
              WHERE d.expires_on <= CURDATE() + INTERVAL ? DAY ORDER BY d.expires_on", [$days]) as $d) {
            $out[] = ['kind' => $d['kind'], 'label' => Fleet::DOC_KINDS[$d['kind']] . ' · ' . $d['plate'], 'expires' => $d['expires_on'], 'url' => '/flotte/vehicules/' . (int) $d['id']];
        }
        foreach (Database::all(
            'SELECT dr.license_expires, e.name FROM fleet_drivers dr JOIN employees e ON e.id = dr.employee_id
              WHERE dr.active = 1 AND dr.license_expires <= CURDATE() + INTERVAL ? DAY ORDER BY dr.license_expires', [$days]) as $d) {
            $out[] = ['kind' => 'permis', 'label' => 'Permis · ' . $d['name'], 'expires' => $d['license_expires'], 'url' => '/flotte/chauffeurs'];
        }
        usort($out, static fn($a, $b) => $a['expires'] <=> $b['expires']);
        return $out;
    }

    /** @return array{missions:int, km:int, rating:?float} missions clôturées, kilomètres et note moyenne du mois */
    public static function month(): array
    {
        $r = Database::one(
            "SELECT COUNT(*) missions, COALESCE(SUM(m.end_km - m.start_km), 0) km
               FROM fleet_missions m WHERE m.ended_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
        );
        $rating = Database::value(
            "SELECT AVG((punctuality + driving) / 2) FROM fleet_ratings WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
        );
        return ['missions' => (int) $r['missions'], 'km' => (int) $r['km'], 'rating' => $rating !== null ? round((float) $rating, 1) : null];
    }

    /** « 45 min », « 3 h 20 », « 2 j 4 h » */
    public static function duration(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        return match (true) {
            $minutes < 60   => $minutes . ' min',
            $minutes < 1440 => intdiv($minutes, 60) . ' h ' . sprintf('%02d', $minutes % 60),
            default         => intdiv($minutes, 1440) . ' j ' . intdiv($minutes % 1440, 60) . ' h',
        };
    }
}
