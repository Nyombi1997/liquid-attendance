<?php
declare(strict_types=1);

namespace App\Modules\Fleet\Services;

use App\Core\Auth;
use App\Modules\Platform\Services\Notifications;

/**
 * Notifications du circuit d'une demande (cloche et e-mail) : qui prévenir à
 * chaque étape. $req : ligne de Fleet::request(). Celui qui fait l'action
 * n'est pas prévenu de sa propre action.
 */
final class FleetNotify
{
    /** Demande reçue : le validateur de l'étape en cours (et les gestionnaires si urgente). */
    public static function received(array $req): void
    {
        $what = self::summary($req);
        if ($req['step'] === 'n1') {
            self::send([$req['n1_user_id']], 'demande_recue', 'Demande de véhicule à valider : ' . $req['reference'], $what, $req);
        } else {
            self::send(Fleet::usersWithRole('direction'), 'demande_recue', 'Demande à valider (direction) : ' . $req['reference'],
                $what . ($req['direction_reasons'] ? ' · Motif : ' . $req['direction_reasons'] : ''), $req);
        }
        if ((int) $req['urgent'] === 1) {
            self::send(Fleet::usersWithRole('gestionnaire'), 'demande_urgente', 'Demande URGENTE : ' . $req['reference'],
                $what . ' · Affectation possible avant validation.', $req);
        }
    }

    /** Le N+1 a approuvé, la direction doit valider. */
    public static function toDirection(array $req): void
    {
        self::send(Fleet::usersWithRole('direction'), 'demande_recue', 'Demande à valider (direction) : ' . $req['reference'],
            self::summary($req) . ' · Validée par le N+1. Motif : ' . $req['direction_reasons'], $req);
        self::send([self::requester($req)], 'demande_etape', 'Demande ' . $req['reference'] . ' validée par votre N+1',
            'En attente de la direction (' . $req['direction_reasons'] . ').', $req);
    }

    /** Validation terminée : le demandeur, et les gestionnaires pour l'affectation. */
    public static function approved(array $req): void
    {
        $assigned = $req['status'] !== 'approuvee';
        self::send([self::requester($req)], 'demande_approuvee', 'Demande ' . $req['reference'] . ' approuvée',
            $assigned ? 'Validation confirmée.' : 'Un véhicule va vous être affecté.', $req);
        if (!$assigned) {
            self::send(Fleet::usersWithRole('gestionnaire'), 'demande_a_affecter', 'Demande à affecter : ' . $req['reference'],
                self::summary($req), $req, '/flotte/demandes/' . (int) $req['id'] . '/affecter');
        } else {
            self::send(Fleet::usersWithRole('gestionnaire'), 'demande_approuvee', 'Urgence validée a posteriori : ' . $req['reference'],
                self::summary($req), $req);
        }
    }

    /** Refus : le demandeur (avec le motif) ; véhicule libéré ou mission en cours : gestionnaires et chauffeur. */
    public static function refused(array $req, string $reason, bool $released): void
    {
        self::send([self::requester($req)], 'demande_refusee', 'Demande ' . $req['reference'] . ' refusée', 'Motif : ' . $reason, $req);
        if ($released || in_array($req['status'], ['en_cours', 'terminee'], true)) {
            $text = $released ? 'Affectation annulée, véhicule libéré. Motif : ' : 'Refus a posteriori. Motif : ';
            self::send(array_merge(Fleet::usersWithRole('gestionnaire'), [$released ? Fleet::userOf(self::int($req['driver_employee_id'])) : null]),
                'demande_refusee', 'Demande ' . $req['reference'] . ' refusée', $text . $reason, $req);
        }
    }

    /** Annulation par le demandeur. */
    public static function cancelled(array $req, bool $released): void
    {
        $to = $released
            ? array_merge(Fleet::usersWithRole('gestionnaire'), [Fleet::userOf(self::int($req['driver_employee_id']))])
            : ($req['step'] === 'n1' ? [$req['n1_user_id']] : ($req['step'] === 'direction' ? Fleet::usersWithRole('direction') : Fleet::usersWithRole('gestionnaire')));
        self::send($to, 'demande_annulee', 'Demande ' . $req['reference'] . ' annulée par le demandeur',
            self::summary($req) . ($released ? ' · Véhicule libéré.' : ''), $req);
    }

    /** Véhicule affecté : le demandeur et le chauffeur. */
    public static function assigned(array $req, bool $changed): void
    {
        $vehicle = $req['plate'] . ' (' . $req['brand'] . ' ' . $req['model'] . ')';
        $driver = $req['driver_name'] ? ', chauffeur ' . $req['driver_name'] . ($req['driver_phone'] ? ' (' . $req['driver_phone'] . ')' : '') : ', vous conduisez';
        self::send([self::requester($req)], 'vehicule_affecte',
            ($changed ? 'Affectation modifiée : ' : 'Véhicule affecté : ') . $req['reference'],
            $vehicle . $driver . ' · Départ ' . Fleet::when($req['depart_at']) . '.', $req);
        $driverUser = Fleet::userOf(self::int($req['driver_employee_id']));
        if ($driverUser !== null) {
            self::send([$driverUser], 'mission_affectee', 'Nouvelle mission : ' . $req['reference'],
                $vehicle . ' · ' . self::summary($req), $req, '/flotte/missions/' . (int) $req['id']);
        }
    }

    /** Rappel avant le départ (worker) : le demandeur et le chauffeur. */
    public static function reminder(array $req, int $minutes): void
    {
        $text = 'Départ ' . Fleet::when($req['depart_at']) . ' (dans ' . $minutes . ' min environ) · ' . $req['plate']
            . ($req['driver_name'] ? ' · chauffeur ' . $req['driver_name'] : '') . ' · ' . $req['destination'];
        self::send([self::requester($req)], 'rappel_depart', 'Rappel : départ de ' . $req['reference'], $text, $req, null, null);
        $driverUser = Fleet::userOf(self::int($req['driver_employee_id']));
        if ($driverUser !== null) {
            self::send([$driverUser], 'rappel_depart', 'Rappel : mission ' . $req['reference'], $text, $req, '/flotte/missions/' . (int) $req['id'], null);
        }
    }

    /** Mission terminée : le demandeur est invité à évaluer la course. */
    public static function closed(array $req): void
    {
        self::send([self::requester($req)], 'mission_terminee', 'Mission ' . $req['reference'] . ' terminée',
            'Merci d\'évaluer la course (ponctualité, conduite).', $req);
    }

    /** Véhicule déclaré en panne à la clôture d'une mission. */
    public static function breakdown(array $req, string $notes): void
    {
        self::send(Fleet::usersWithRole('gestionnaire'), 'vehicule_panne', 'Véhicule en panne : ' . $req['plate'],
            'Signalé à la clôture de ' . $req['reference'] . ($notes !== '' ? ' : ' . $notes : '.'), $req, '/flotte/vehicules/' . (int) $req['vehicle_id']);
    }

    /** « Jean Dupont · Matadi · lun. 20/10 08:00 → mar. 21/10 17:00 » */
    public static function summary(array $req): string
    {
        return $req['requester_name'] . ' · ' . $req['destination'] . ' · ' . Fleet::when($req['depart_at']) . ' → ' . Fleet::when($req['return_at']);
    }

    private static function requester(array $req): ?int
    {
        return Fleet::userOf((int) $req['requester_id']);
    }

    private static function int(mixed $v): ?int
    {
        return $v !== null ? (int) $v : null;
    }

    /** @param string|null $url adresse de la notification (par défaut : la demande) */
    private static function send(array $users, string $kind, string $title, string $body, array $req, ?string $url = null, ?int $except = -1): void
    {
        Notifications::send($users, $kind, $title, $body, $url ?? '/flotte/demandes/' . (int) $req['id'], $except === -1 ? Auth::id() : $except);
    }
}
