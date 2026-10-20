<?php
declare(strict_types=1);

namespace App\Modules\Fleet\Services;

/**
 * Disponibilité des véhicules et des chauffeurs sur un créneau (sans base de
 * données : testé par tests/FleetWorkflowTest.php). Les réservations viennent
 * de Fleet::bookings().
 *
 * Problème « block » : affectation refusée (conflit de réservation, véhicule en
 * panne ou en entretien, assurance ou contrôle technique expiré, permis expiré,
 * chauffeur non autorisé sur le véhicule).
 * Problème « warn » : affichée, affectation possible (places, catégorie de permis,
 * document non renseigné ou expirant pendant la mission).
 * Dates au format « AAAA-MM-JJ HH:MM:SS » (comparaison de chaînes).
 */
final class Availability
{
    public const DOC_KINDS = ['assurance' => 'Assurance', 'controle_technique' => 'Contrôle technique'];

    /** Deux créneaux se chevauchent (bornes exclues : un retour à 12:00 libère pour un départ à 12:00). */
    public static function overlaps(string $aFrom, string $aTo, string $bFrom, string $bTo): bool
    {
        return $aFrom < $bTo && $bFrom < $aTo;
    }

    /**
     * Période occupée par une réservation : une mission en cours dont le retour
     * est dépassé occupe le véhicule jusqu'à maintenant (pas encore rendu).
     *
     * @return array{0:string, 1:string}
     */
    public static function window(string $status, string $from, string $to, string $now): array
    {
        return [$from, $status === 'en_cours' && $to < $now ? $now : $to];
    }

    /**
     * Réservations qui chevauchent le créneau (hors la demande elle-même).
     *
     * @param array<int, array{request_id:int, reference:?string, from:string, to:string}> $bookings
     * @return array<int, array>
     */
    public static function conflicts(string $from, string $to, array $bookings, ?int $exceptRequestId = null): array
    {
        return array_values(array_filter($bookings, static fn(array $b) =>
            (int) $b['request_id'] !== $exceptRequestId && self::overlaps($from, $to, $b['from'], $b['to'])));
    }

    /**
     * Problèmes d'un véhicule pour une demande.
     *
     * @param array $vehicle  ligne fleet_vehicles (active, status, seats)
     * @param array<string, ?string> $docs date d'expiration du dernier document de chaque type
     * @param array $bookings réservations de ce véhicule
     * @param array $req      demande (id, depart_at, return_at, passengers, with_driver)
     * @return array<int, array{level:string, text:string}>
     */
    public static function vehicleIssues(array $vehicle, array $req, array $docs, array $bookings): array
    {
        $issues = [];
        $from = $req['depart_at'];
        $to = $req['return_at'];
        if (!(int) $vehicle['active']) {
            $issues[] = self::block('Véhicule retiré du parc');
        }
        if ($vehicle['status'] === 'en_panne') {
            $issues[] = self::block('En panne');
        } elseif ($vehicle['status'] === 'en_entretien') {
            $issues[] = self::block('En entretien');
        }
        foreach (self::DOC_KINDS as $kind => $label) {
            $issues = array_merge($issues, self::expiry($label, $docs[$kind] ?? null, $from, $to));
        }
        $needed = (int) $req['passengers'] + ((int) $req['with_driver'] === 1 ? 1 : 0);
        if ((int) $vehicle['seats'] > 0 && $needed > (int) $vehicle['seats']) {
            $issues[] = self::warn($vehicle['seats'] . ' places pour ' . $needed . ' personnes');
        }
        foreach (self::conflicts($from, $to, $bookings, isset($req['id']) ? (int) $req['id'] : null) as $c) {
            $issues[] = self::block('Déjà réservé : ' . self::bookingLabel($c));
        }
        return $issues;
    }

    /**
     * Problèmes d'un chauffeur pour une demande et un véhicule.
     *
     * @param array $driver     ligne fleet_drivers (active, license_expires, license_category)
     * @param int[] $authorized véhicules autorisés (vide : tous)
     * @param array|null $vehicle véhicule choisi (id, vehicle_type, seats)
     */
    public static function driverIssues(array $driver, array $req, ?array $vehicle, array $authorized, array $bookings): array
    {
        $issues = [];
        if (!(int) $driver['active']) {
            $issues[] = self::block('Chauffeur inactif');
        }
        $issues = array_merge($issues, self::expiry('Permis', $driver['license_expires'] ?? null, $req['depart_at'], $req['return_at']));
        if ($vehicle !== null) {
            if ($authorized && !in_array((int) $vehicle['id'], array_map('intval', $authorized), true)) {
                $issues[] = self::block('Non autorisé sur ce véhicule');
            }
            $needed = self::requiredCategory($vehicle['vehicle_type'], (int) $vehicle['seats']);
            $held = array_filter(array_map('trim', explode(',', strtoupper((string) ($driver['license_category'] ?? '')))));
            if ($held && !in_array($needed, $held, true)) {
                $issues[] = self::warn('Permis ' . $needed . ' requis (catégories : ' . implode(', ', $held) . ')');
            }
        }
        foreach (self::conflicts($req['depart_at'], $req['return_at'], $bookings, isset($req['id']) ? (int) $req['id'] : null) as $c) {
            $issues[] = self::block('Déjà en mission : ' . self::bookingLabel($c));
        }
        return $issues;
    }

    /** Au moins un problème bloquant ? */
    public static function blocking(array $issues): bool
    {
        foreach ($issues as $i) {
            if ($i['level'] === 'block') {
                return true;
            }
        }
        return false;
    }

    /** Catégorie de permis nécessaire (A moto, C camion, D au-delà de 9 places, B sinon). */
    public static function requiredCategory(string $type, int $seats): string
    {
        return match (true) {
            $type === 'moto'   => 'A',
            $type === 'camion' => 'C',
            $type === 'minibus' || $seats > 9 => 'D',
            default            => 'B',
        };
    }

    /** Document ou permis : absent, expiré avant le départ (bloquant) ou pendant la mission. */
    private static function expiry(string $label, ?string $expires, string $from, string $to): array
    {
        if ($expires === null || $expires === '') {
            return [self::warn($label . ' non renseigné')];
        }
        if ($expires < substr($from, 0, 10)) {
            return [self::block($label . ' expiré le ' . self::d($expires))];
        }
        if ($expires < substr($to, 0, 10)) {
            return [self::warn($label . ' expire le ' . self::d($expires) . ', pendant la mission')];
        }
        return [];
    }

    private static function bookingLabel(array $b): string
    {
        return ($b['reference'] ?? ('n° ' . $b['request_id'])) . ' (' . date('d/m H:i', strtotime($b['from']))
            . ' → ' . date('d/m H:i', strtotime($b['to'])) . ')';
    }

    private static function d(string $date): string
    {
        return date('d/m/Y', strtotime($date));
    }

    private static function block(string $text): array
    {
        return ['level' => 'block', 'text' => $text];
    }

    private static function warn(string $text): array
    {
        return ['level' => 'warn', 'text' => $text];
    }
}
