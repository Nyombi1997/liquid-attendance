<?php
declare(strict_types=1);

namespace App\Modules\Fleet\Services;

use DomainException;

/**
 * Règles du circuit d'une demande de véhicule (sans base de données : testé par
 * tests/FleetWorkflowTest.php). Les contrôleurs lisent la demande, appellent une
 * de ces fonctions, puis enregistrent les champs renvoyés.
 *
 *   status     : en_validation → approuvee → affectee → en_cours → terminee ; refusee, annulee
 *   validation : en_attente / approuvee / refusee
 *   step       : étape de validation en attente (n1 puis direction si nécessaire)
 *
 * N+1 d'abord, puis direction si la mission est hors ville, dure plus de
 * fleet_direction_max_days jours ou touche un week-end (seuils dans settings).
 * Sans N+1 désigné, la demande va directement à la direction.
 * Demande urgente : le gestionnaire peut affecter avant la fin de la validation ;
 * la validation se fait alors a posteriori, et un refus a posteriori libère le
 * véhicule si la mission n'a pas commencé.
 */
final class FleetWorkflow
{
    /** Valeurs par défaut des paramètres du circuit (table settings). */
    public const DEFAULTS = [
        'fleet_city'                  => 'Kinshasa',
        'fleet_direction_out_of_town' => '1',
        'fleet_direction_max_days'    => '2',
        'fleet_direction_weekend'     => '1',
        'fleet_order_required'        => 'hors_ville', // toujours | hors_ville | jamais
        'fleet_reminder_minutes'      => '60',
        'fleet_doc_alert_days'        => '30',
    ];

    public const STATUSES = [
        'en_validation' => ['En validation', 'warn'],
        'approuvee'     => ['Validée, à affecter', 'info'],
        'affectee'      => ['Véhicule affecté', 'primary'],
        'en_cours'      => ['Mission en cours', 'ok'],
        'terminee'      => ['Terminée', 'muted'],
        'refusee'       => ['Refusée', 'danger'],
        'annulee'       => ['Annulée', 'muted'],
    ];

    public const STEPS = ['n1' => 'Validation du N+1', 'direction' => 'Validation de la direction'];

    public const VEHICLE_TYPES = [
        'berline' => 'Berline', 'suv' => '4x4 / SUV', 'pickup' => 'Pick-up',
        'minibus' => 'Minibus', 'camion' => 'Camion', 'moto' => 'Moto',
    ];

    /** Durée maximale d'une demande (jours). */
    public const MAX_DAYS = 31;

    /**
     * Raisons qui imposent la validation de la direction (liste vide : N+1 seul).
     *
     * @param array{out_of_town:int|bool|string, depart_at:string, return_at:string} $req
     * @return string[]
     */
    public static function directionReasons(array $req, array $settings): array
    {
        $s = $settings + self::DEFAULTS;
        $reasons = [];
        if ((string) $s['fleet_direction_out_of_town'] === '1' && (int) $req['out_of_town'] === 1) {
            $reasons[] = 'mission hors ville';
        }
        $max = (int) $s['fleet_direction_max_days'];
        $days = self::days($req['depart_at'], $req['return_at']);
        if ($max > 0 && $days > $max) {
            $reasons[] = 'durée de ' . $days . ' jours (plus de ' . $max . ')';
        }
        if ((string) $s['fleet_direction_weekend'] === '1' && self::touchesWeekend($req['depart_at'], $req['return_at'])) {
            $reasons[] = 'week-end';
        }
        return $reasons;
    }

    /** Nombre de jours du calendrier occupés par la mission (même jour = 1). */
    public static function days(string $from, string $to): int
    {
        $a = new \DateTimeImmutable(substr($from, 0, 10));
        $b = new \DateTimeImmutable(substr($to, 0, 10));
        return max(1, (int) $a->diff($b)->format('%r%a') + 1);
    }

    /** La mission touche-t-elle un samedi ou un dimanche ? */
    public static function touchesWeekend(string $from, string $to): bool
    {
        $d = new \DateTimeImmutable(substr($from, 0, 10));
        $end = substr($to, 0, 10);
        for ($i = 0; $i < self::MAX_DAYS + 1 && $d->format('Y-m-d') <= $end; $i++, $d = $d->modify('+1 day')) {
            if ((int) $d->format('N') >= 6) {
                return true;
            }
        }
        return false;
    }

    /**
     * N+1 du demandeur : responsable de son service, ou en remontant l'arbre
     * quand le demandeur est lui-même responsable (ou qu'aucun n'est désigné).
     *
     * @param array<int, array{parent_id:?int, head_employee_id:?int}> $departments
     * @return int|null employé responsable, ou null si personne
     */
    public static function resolveN1(?int $departmentId, int $requesterEmployeeId, array $departments): ?int
    {
        for ($i = 0; $departmentId !== null && isset($departments[$departmentId]) && $i < 20; $i++) {
            $head = $departments[$departmentId]['head_employee_id'] ?? null;
            if ($head !== null && (int) $head !== $requesterEmployeeId) {
                return (int) $head;
            }
            $departmentId = $departments[$departmentId]['parent_id'] ?? null;
        }
        return null;
    }

    /**
     * Champs d'une nouvelle demande : première étape de validation.
     *
     * @return array{status:string, validation:string, step:string, direction_reasons:?string}
     */
    public static function start(array $req, bool $hasN1, array $settings): array
    {
        $reasons = self::directionReasons($req, $settings);
        if (!$hasN1) {
            $reasons[] = 'aucun N+1 désigné';
        }
        return [
            'status'            => 'en_validation',
            'validation'        => 'en_attente',
            'step'              => $hasN1 ? 'n1' : 'direction',
            'direction_reasons' => $reasons ? implode(', ', $reasons) : null,
        ];
    }

    /** Ordre de mission obligatoire pour cette demande ? */
    public static function orderRequired(array $req, array $settings): bool
    {
        return match ((string) (($settings + self::DEFAULTS)['fleet_order_required'])) {
            'toujours'   => true,
            'hors_ville' => (int) $req['out_of_town'] === 1,
            default      => false,
        };
    }

    /**
     * Approbation de l'étape $step : passage à la direction si nécessaire,
     * sinon demande validée.
     */
    public static function approve(array $req, string $step): array
    {
        self::checkPending($req, $step);
        if ($step === 'n1' && !empty($req['direction_reasons'])) {
            return ['step' => 'direction'];
        }
        return [
            'validation' => 'approuvee',
            'step'       => null,
            'status'     => $req['status'] === 'en_validation' ? 'approuvee' : $req['status'],
        ];
    }

    /**
     * Refus (motif obligatoire). Avant le départ, le véhicule affecté (urgence)
     * est libéré ; mission déjà commencée : refus a posteriori, mission conservée.
     *
     * @return array champs à enregistrer ; 'release' => true si l'affectation est annulée
     */
    public static function refuse(array $req, string $step, string $reason): array
    {
        self::checkPending($req, $step);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new DomainException('Le motif du refus est obligatoire.');
        }
        $started = in_array($req['status'], ['en_cours', 'terminee'], true);
        return [
            'validation'     => 'refusee',
            'step'           => null,
            'status'         => $started ? $req['status'] : 'refusee',
            'refusal_reason' => mb_substr($reason, 0, 500),
            'release'        => !$started && $req['status'] === 'affectee',
        ];
    }

    /** Annulation par le demandeur : possible tant que la mission n'a pas commencé. */
    public static function cancel(array $req): array
    {
        if (!self::canCancel($req)) {
            throw new DomainException('Cette demande ne peut plus être annulée.');
        }
        return ['status' => 'annulee', 'step' => null, 'release' => $req['status'] === 'affectee'];
    }

    public static function canCancel(array $req): bool
    {
        return in_array($req['status'], ['en_validation', 'approuvee', 'affectee'], true);
    }

    /**
     * Affectation possible : demande validée (ou déjà affectée, pour changer de
     * véhicule avant le départ), ou demande urgente encore en validation.
     */
    public static function canAssign(array $req): bool
    {
        if (!in_array($req['status'], ['en_validation', 'approuvee', 'affectee'], true)) {
            return false;
        }
        return $req['validation'] === 'approuvee'
            || ($req['validation'] === 'en_attente' && (int) $req['urgent'] === 1);
    }

    /** Champs de l'affectation (urgence : drapeau de validation a posteriori). */
    public static function assign(array $req): array
    {
        if (!self::canAssign($req)) {
            throw new DomainException($req['validation'] === 'en_attente'
                ? 'Demande pas encore validée : seule une demande urgente peut être affectée avant validation.'
                : 'Cette demande ne peut plus être affectée.');
        }
        return [
            'status'                     => 'affectee',
            'assigned_before_validation' => $req['validation'] === 'en_attente' ? 1 : (int) ($req['assigned_before_validation'] ?? 0),
        ];
    }

    public static function canStart(array $req): bool
    {
        return $req['status'] === 'affectee' && $req['validation'] !== 'refusee' && !empty($req['vehicle_id']);
    }

    public static function canClose(array $req): bool
    {
        return $req['status'] === 'en_cours';
    }

    /** Contrôle de la clôture : kilométrage de retour supérieur à celui du départ. */
    public static function closeError(int $startKm, int $endKm): ?string
    {
        if ($endKm <= $startKm) {
            return 'Le kilométrage de retour (' . number_format($endKm, 0, ',', ' ') . ' km) doit être supérieur à celui du départ ('
                . number_format($startKm, 0, ',', ' ') . ' km).';
        }
        return null;
    }

    public static function canRate(array $req): bool
    {
        return $req['status'] === 'terminee';
    }

    /**
     * Libellé et classe de badge d'une demande, selon son statut et sa validation.
     *
     * @return array{0:string, 1:string}
     */
    public static function label(array $req): array
    {
        $status = $req['status'];
        if ($status === 'en_validation') {
            return [$req['step'] === 'direction' ? 'Attente direction' : 'Attente N+1', 'warn'];
        }
        [$label, $class] = self::STATUSES[$status] ?? [$status, 'muted'];
        if (in_array($status, ['affectee', 'en_cours', 'terminee'], true)) {
            if ($req['validation'] === 'en_attente') {
                $label .= ' · validation en attente';
                $class = 'accent';
            } elseif ($req['validation'] === 'refusee') {
                $label .= ' · refusée a posteriori';
                $class = 'danger';
            }
        }
        return [$label, $class];
    }

    /** @throws DomainException si l'étape demandée n'est pas celle en attente */
    private static function checkPending(array $req, string $step): void
    {
        if ($req['validation'] !== 'en_attente' || in_array($req['status'], ['refusee', 'annulee'], true)) {
            throw new DomainException('Cette demande n\'attend plus de validation.');
        }
        if ($req['step'] !== $step) {
            throw new DomainException('Cette demande est à l\'étape « ' . (self::STEPS[$req['step']] ?? '—') . ' ».');
        }
    }
}
