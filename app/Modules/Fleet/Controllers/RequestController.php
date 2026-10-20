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
use App\Modules\Platform\Services\Departments;
use DomainException;

/**
 * Demandes de véhicule : création et suivi par le demandeur (tout compte relié
 * à une fiche employé), validation par le N+1 puis la direction si nécessaire
 * (règles dans FleetWorkflow), annulation, évaluation de la course, registre
 * de toutes les demandes (fleet_board). Chaque étape est tracée dans
 * fleet_request_events (historique de la demande) et dans le journal (Audit).
 */
final class RequestController extends Controller
{
    /** Filtres du registre : clé → [libellé, condition SQL]. */
    private const REGISTRY = [
        'actives'   => ['En cours de traitement', "r.status IN ('en_validation','approuvee','affectee','en_cours')"],
        'n1'        => ['Attente N+1', "r.status = 'en_validation' AND r.step = 'n1'"],
        'direction' => ['Attente direction', "r.status = 'en_validation' AND r.step = 'direction'"],
        'affecter'  => ['À affecter', "(r.status = 'approuvee' OR (r.status = 'en_validation' AND r.urgent = 1))"],
        'affectees' => ['Affectées', "r.status = 'affectee'"],
        'en_cours'  => ['En mission', "r.status = 'en_cours'"],
        'urgences'  => ['Urgences à régulariser', "r.assigned_before_validation = 1 AND r.validation = 'en_attente' AND r.status NOT IN ('annulee','refusee')"],
        'terminees' => ['Terminées', "r.status = 'terminee'"],
        'refusees'  => ['Refusées', "r.status = 'refusee'"],
        'annulees'  => ['Annulées', "r.status = 'annulee'"],
        'toutes'    => ['Toutes', '1'],
    ];

    /** Mes demandes. */
    public function index(): void
    {
        $employee = Auth::employeeId();
        $this->render('Fleet:requests', [
            'title'    => 'Mes demandes de véhicule',
            'employee' => $employee,
            'requests' => $employee === null ? [] : $this->rows('r.requester_id = ?', [$employee], 'r.created_at DESC', 100),
            'pending'  => $this->pendingCount(),
        ]);
    }

    public function create(): void
    {
        $employee = Auth::employeeId();
        $settings = Fleet::settings();
        $n1 = null;
        if ($employee !== null) {
            $emp = Database::one('SELECT department_id FROM employees WHERE id = ?', [$employee]);
            $head = FleetWorkflow::resolveN1($emp['department_id'] !== null ? (int) $emp['department_id'] : null, $employee, Departments::all());
            $n1 = $head !== null ? Database::one('SELECT e.name, u.id user_id FROM employees e LEFT JOIN users u ON u.employee_id = e.id AND u.active = 1 WHERE e.id = ?', [$head]) : null;
        }
        $this->render('Fleet:request_form', [
            'title'    => 'Nouvelle demande de véhicule',
            'employee' => $employee,
            'drivers'  => Fleet::drivers(),
            'settings' => $settings,
            'n1'       => $n1,
        ]);
    }

    public function store(): void
    {
        $employee = Auth::employeeId();
        if ($employee === null) {
            flash('error', 'Votre compte n\'est relié à aucune fiche employé : demandez-le à l\'administrateur.');
            $this->redirect('/flotte/demandes');
        }
        $settings = Fleet::settings();
        $req = [
            'purpose'             => mb_substr((string) $this->input('purpose', ''), 0, 255),
            'destination'         => mb_substr((string) $this->input('destination', ''), 0, 160),
            'out_of_town'         => isset($_POST['out_of_town']) ? 1 : 0,
            'depart_at'           => Fleet::datetime($this->input('depart_at')),
            'return_at'           => Fleet::datetime($this->input('return_at')),
            'passengers'          => (int) $this->input('passengers', 1),
            'with_driver'         => $this->input('with_driver', '1') === '0' ? 0 : 1,
            'preferred_driver_id' => (int) $this->input('preferred_driver_id', 0) ?: null,
            'vehicle_type'        => (string) $this->input('vehicle_type', '') ?: null,
            'urgent'              => isset($_POST['urgent']) ? 1 : 0,
            'urgent_reason'       => mb_substr((string) $this->input('urgent_reason', ''), 0, 255) ?: null,
        ];
        if (!$req['with_driver']) {
            $req['preferred_driver_id'] = null;
        }
        $error = match (true) {
            $req['purpose'] === '' || $req['destination'] === '' => 'Le motif et la destination sont obligatoires.',
            $req['depart_at'] === null || $req['return_at'] === null => 'Dates de départ et de retour invalides.',
            $req['depart_at'] < date('Y-m-d H:i:00', time() - 600) => 'Le départ ne peut pas être dans le passé.',
            $req['return_at'] <= $req['depart_at'] => 'Le retour doit être après le départ.',
            FleetWorkflow::days($req['depart_at'], $req['return_at']) > FleetWorkflow::MAX_DAYS => 'Mission trop longue (' . FleetWorkflow::MAX_DAYS . ' jours au plus).',
            $req['passengers'] < 1 || $req['passengers'] > 60 => 'Nombre de passagers invalide.',
            $req['vehicle_type'] !== null && !isset(FleetWorkflow::VEHICLE_TYPES[$req['vehicle_type']]) => 'Type de véhicule invalide.',
            $req['preferred_driver_id'] !== null && !Database::value('SELECT id FROM fleet_drivers WHERE id = ? AND active = 1', [$req['preferred_driver_id']]) => 'Chauffeur introuvable.',
            $req['urgent'] === 1 && $req['urgent_reason'] === null => 'Indiquez la raison de l\'urgence.',
            default => null,
        };
        $file = null;
        if ($error === null) {
            [$file, $error] = FleetFiles::upload('order_file', 'ordres');
            if ($error === null && $file === null && FleetWorkflow::orderRequired($req, $settings)) {
                $error = 'L\'ordre de mission est obligatoire pour cette demande (PDF ou image).';
            }
        }
        if ($error) {
            FleetFiles::delete('ordres', $file['stored_name'] ?? null);
            $this->withInput();
            flash('error', $error);
            $this->redirect('/flotte/demandes/nouvelle');
        }

        $emp = Database::one('SELECT department_id FROM employees WHERE id = ?', [$employee]);
        $department = $emp['department_id'] !== null ? (int) $emp['department_id'] : null;
        $head = FleetWorkflow::resolveN1($department, $employee, Departments::all());
        $n1User = Fleet::userOf($head);
        $start = FleetWorkflow::start($req, $n1User !== null, $settings);

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $id = Database::insert(
                'INSERT INTO fleet_requests (requester_id, created_by, department_id, purpose, destination, out_of_town, depart_at, return_at,
                        passengers, with_driver, preferred_driver_id, vehicle_type, urgent, urgent_reason,
                        order_file_name, order_stored_name, order_mime, order_size, status, validation, step, direction_reasons, n1_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$employee, Auth::id(), $department, $req['purpose'], $req['destination'], $req['out_of_town'], $req['depart_at'], $req['return_at'],
                 $req['passengers'], $req['with_driver'], $req['preferred_driver_id'], $req['vehicle_type'], $req['urgent'], $req['urgent_reason'],
                 $file['file_name'] ?? null, $file['stored_name'] ?? null, $file['file_mime'] ?? null, $file['file_size'] ?? null,
                 $start['status'], $start['validation'], $start['step'], $start['direction_reasons'], $n1User]
            );
            $reference = sprintf('DV-%s-%04d', date('Y'), $id);
            Database::run('UPDATE fleet_requests SET reference = ? WHERE id = ?', [$reference, $id]);
            Fleet::event($id, 'creee', implode(' · ', array_filter([
                $req['urgent'] ? 'URGENTE : ' . $req['urgent_reason'] : null,
                $start['step'] === 'n1' ? 'envoyée au N+1' : 'envoyée à la direction',
                $start['direction_reasons'] ? 'direction requise : ' . $start['direction_reasons'] : null,
            ])));
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            FleetFiles::delete('ordres', $file['stored_name'] ?? null);
            throw $e;
        }
        Audit::log('flotte_demande_creee', ['id' => $id, 'reference' => $reference, 'urgente' => $req['urgent'], 'etape' => $start['step']]);
        FleetNotify::received(Fleet::request($id));
        flash('success', 'Demande ' . $reference . ' envoyée ' . ($start['step'] === 'n1' ? 'à votre N+1' : 'à la direction') . '.');
        $this->redirect('/flotte/demandes/' . $id);
    }

    public function show(int $id): void
    {
        $req = Fleet::request($id) ?? $this->notFound();
        $access = $this->access($req);
        $this->render('Fleet:request', [
            'title'   => 'Demande ' . $req['reference'],
            'req'     => $req,
            'access'  => $access,
            'events'  => Database::all(
                'SELECT ev.*, u.full_name FROM fleet_request_events ev LEFT JOIN users u ON u.id = ev.user_id
                  WHERE ev.request_id = ? ORDER BY ev.id', [$id]),
            'mission' => Database::one('SELECT * FROM fleet_missions WHERE request_id = ?', [$id]),
            'rating'  => Database::one('SELECT * FROM fleet_ratings WHERE request_id = ?', [$id]),
            'decider' => static fn(?string $uid) => $uid ? Database::value('SELECT full_name FROM users WHERE id = ?', [$uid]) : null,
        ]);
    }

    /** Ordre de mission joint (mêmes droits que la demande). */
    public function order(int $id): void
    {
        $req = Fleet::request($id) ?? $this->notFound();
        $this->access($req);
        if (!$req['order_stored_name']) {
            $this->notFound();
        }
        FleetFiles::send(FleetFiles::path('ordres', $req['order_stored_name']), $req['order_file_name'] ?? 'ordre-de-mission', $req['order_mime']);
    }

    public function approve(int $id): void
    {
        $this->decide($id, true);
    }

    public function refuse(int $id): void
    {
        $this->decide($id, false);
    }

    /** Annulation par le demandeur (avant le départ). */
    public function cancel(int $id): void
    {
        $reason = mb_substr((string) $this->input('reason', ''), 0, 255) ?: null;
        $this->transition($id, function (array $req) use ($reason): array {
            if (!$this->access($req)['requester']) {
                throw new DomainException('Seul le demandeur peut annuler sa demande.');
            }
            $changes = FleetWorkflow::cancel($req);
            Database::run('UPDATE fleet_requests SET status = ?, step = ?, cancel_reason = ?, cancelled_at = NOW() WHERE id = ?',
                [$changes['status'], $changes['step'], $reason, $req['id']]);
            Fleet::event((int) $req['id'], 'annulee', trim(($reason ?? '') . ($changes['release'] ? ' · véhicule ' . $req['plate'] . ' libéré' : ''), ' ·'));
            Audit::log('flotte_demande_annulee', ['id' => $req['id'], 'reference' => $req['reference']]);
            return [fn() => FleetNotify::cancelled($req, $changes['release']), 'Demande ' . $req['reference'] . ' annulée.'];
        });
    }

    /** Évaluation de la course par le demandeur (une seule fois, après la mission). */
    public function rate(int $id): void
    {
        $punctuality = (int) $this->input('punctuality', 0);
        $driving = (int) $this->input('driving', 0);
        $comment = mb_substr((string) $this->input('comment', ''), 0, 500) ?: null;
        $this->transition($id, function (array $req) use ($punctuality, $driving, $comment): array {
            if (!$this->access($req)['requester'] || !FleetWorkflow::canRate($req)) {
                throw new DomainException('Évaluation possible par le demandeur, une fois la mission terminée.');
            }
            if ($punctuality < 1 || $punctuality > 5 || $driving < 1 || $driving > 5) {
                throw new DomainException('Donnez une note de 1 à 5 pour la ponctualité et pour la conduite.');
            }
            if (Database::value('SELECT 1 FROM fleet_ratings WHERE request_id = ?', [$req['id']])) {
                throw new DomainException('Cette course a déjà été évaluée.');
            }
            Database::run('INSERT INTO fleet_ratings (request_id, punctuality, driving, comment, created_by) VALUES (?, ?, ?, ?, ?)',
                [$req['id'], $punctuality, $driving, $comment, Auth::id()]);
            Fleet::event((int) $req['id'], 'evaluee', 'ponctualité ' . $punctuality . '/5, conduite ' . $driving . '/5');
            Audit::log('flotte_course_evaluee', ['id' => $req['id'], 'ponctualite' => $punctuality, 'conduite' => $driving]);
            return [null, 'Merci pour votre évaluation.'];
        });
    }

    /** Demandes à valider par l'utilisateur (N+1 ou direction), et ses dernières décisions. */
    public function validations(): void
    {
        $direction = (Auth::user()['fleet_role'] ?? '') === 'direction';
        $pending = $this->rows(
            "r.validation = 'en_attente' AND r.status NOT IN ('refusee','annulee')
             AND ((r.step = 'n1' AND r.n1_user_id = ?)" . ($direction ? " OR r.step = 'direction'" : '') . ')',
            [Auth::id()], 'r.urgent DESC, r.depart_at', 100
        );
        $this->render('Fleet:validations', [
            'title'   => 'Demandes à valider',
            'pending' => $pending,
            'decided' => $this->rows('(r.n1_decided_by = ? OR r.direction_decided_by = ?)', [Auth::id(), Auth::id()],
                'COALESCE(r.direction_decided_at, r.n1_decided_at) DESC', 20),
        ]);
    }

    /** Registre de toutes les demandes (gestionnaire, direction, administrateur). */
    public function registry(): void
    {
        $filter = (string) $this->input('filtre', 'actives');
        if (!isset(self::REGISTRY[$filter])) {
            $filter = 'actives';
        }
        $q = trim(mb_substr((string) $this->input('q', ''), 0, 60));
        $params = [];
        $where = self::REGISTRY[$filter][1];
        if ($q !== '') {
            $where .= ' AND (r.reference LIKE ? OR r.destination LIKE ? OR r.purpose LIKE ? OR e.name LIKE ? OR v.plate LIKE ?)';
            $params = array_fill(0, 5, '%' . addcslashes($q, '%_\\') . '%');
        }
        $counts = [];
        foreach (self::REGISTRY as $k => [, $cond]) {
            $counts[$k] = (int) Database::value("SELECT COUNT(*) FROM fleet_requests r WHERE $cond");
        }
        $this->render('Fleet:registry', [
            'title'    => 'Registre des demandes',
            'requests' => $this->rows($where, $params, $filter === 'actives' || $filter === 'affecter' ? 'r.urgent DESC, r.depart_at' : 'r.created_at DESC', 300),
            'filters'  => array_map(static fn(array $f) => $f[0], self::REGISTRY),
            'counts'   => $counts,
            'filter'   => $filter,
            'q'        => $q,
        ]);
    }

    /** Nombre de demandes en attente de la décision de l'utilisateur (menu, page Mes demandes). */
    private function pendingCount(): int
    {
        $direction = (Auth::user()['fleet_role'] ?? '') === 'direction';
        return (int) Database::value(
            "SELECT COUNT(*) FROM fleet_requests r WHERE r.validation = 'en_attente' AND r.status NOT IN ('refusee','annulee')
               AND ((r.step = 'n1' AND r.n1_user_id = ?)" . ($direction ? " OR r.step = 'direction'" : '') . ')',
            [Auth::id()]
        );
    }

    /** Approbation ou refus de l'étape en attente, par son validateur. */
    private function decide(int $id, bool $approve): void
    {
        $reason = trim(mb_substr((string) $this->input('reason', ''), 0, 500));
        $this->transition($id, function (array $req) use ($approve, $reason): array {
            $step = $this->access($req)['validate'];
            if ($step === null) {
                throw new DomainException('Vous n\'êtes pas le validateur de cette demande à cette étape.');
            }
            $who = $step === 'n1' ? 'n1' : 'direction';
            if ($approve) {
                $changes = FleetWorkflow::approve($req, $step);
                $final = ($changes['validation'] ?? null) === 'approuvee';
                Database::run(
                    "UPDATE fleet_requests SET step = ?, validation = ?, status = ?, {$who}_decided_by = ?, {$who}_decided_at = NOW(),
                            validated_at = IF(?, NOW(), validated_at) WHERE id = ?",
                    [$changes['step'], $changes['validation'] ?? $req['validation'], $changes['status'] ?? $req['status'], Auth::id(), $final ? 1 : 0, $req['id']]
                );
                $posteriori = $final && (int) $req['assigned_before_validation'] === 1;
                Fleet::event((int) $req['id'], $step === 'n1' ? 'validee_n1' : 'validee_direction',
                    trim(($reason !== '' ? $reason . ' · ' : '') . ($posteriori ? 'validation a posteriori (urgence)' : ($final ? 'demande validée' : 'transmise à la direction')), ' ·'));
                Audit::log('flotte_demande_validee', ['id' => $req['id'], 'reference' => $req['reference'], 'etape' => $step, 'finale' => $final]);
                $fresh = Fleet::request((int) $req['id']);
                return [fn() => $final ? FleetNotify::approved($fresh) : FleetNotify::toDirection($fresh),
                    'Demande ' . $req['reference'] . ($final ? ' validée.' : ' validée et transmise à la direction.')];
            }
            $changes = FleetWorkflow::refuse($req, $step, $reason);
            Database::run(
                "UPDATE fleet_requests SET step = NULL, validation = 'refusee', status = ?, refusal_reason = ?, refused_by = ?, refused_at = NOW(),
                        {$who}_decided_by = ?, {$who}_decided_at = NOW() WHERE id = ?",
                [$changes['status'], $changes['refusal_reason'], Auth::id(), Auth::id(), $req['id']]
            );
            Fleet::event((int) $req['id'], 'refusee', ($step === 'n1' ? 'par le N+1' : 'par la direction') . ' : ' . $changes['refusal_reason']
                . ($changes['release'] ? ' · véhicule ' . $req['plate'] . ' libéré' : '')
                . (in_array($req['status'], ['en_cours', 'terminee'], true) ? ' · refus a posteriori, mission déjà commencée' : ''));
            Audit::log('flotte_demande_refusee', ['id' => $req['id'], 'reference' => $req['reference'], 'etape' => $step, 'motif' => $changes['refusal_reason']]);
            return [fn() => FleetNotify::refused($req, $changes['refusal_reason'], $changes['release']), 'Demande ' . $req['reference'] . ' refusée.'];
        });
    }

    /**
     * Change une demande dans une transaction, la ligne verrouillée (deux clics
     * ou deux validateurs en même temps : le second voit l'état à jour).
     * $apply renvoie [notification à envoyer après la validation, message].
     */
    private function transition(int $id, callable $apply): never
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            Database::value('SELECT id FROM fleet_requests WHERE id = ? FOR UPDATE', [$id]) ?? $this->notFound();
            [$notify, $message] = $apply(Fleet::request($id));
            $pdo->commit();
        } catch (DomainException $e) {
            $pdo->rollBack();
            flash('error', $e->getMessage());
            $this->redirect('/flotte/demandes/' . $id);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        if ($notify) {
            $notify();
        }
        flash('success', $message);
        $this->redirect('/flotte/demandes/' . $id);
    }

    /** Droits sur la demande ; 403 si l'utilisateur ne peut pas la voir. */
    private function access(array $req): array
    {
        $access = Fleet::access($req);
        if (!$access['view']) {
            http_response_code(403);
            $this->render('errors/403', ['title' => 'Accès refusé']);
            exit;
        }
        return $access;
    }

    /** Demandes avec demandeur, véhicule et chauffeur. */
    private function rows(string $where, array $params, string $order, int $limit): array
    {
        return Database::all(
            "SELECT r.id, r.reference, r.purpose, r.destination, r.out_of_town, r.depart_at, r.return_at, r.passengers, r.with_driver,
                    r.urgent, r.status, r.validation, r.step, r.created_at, r.vehicle_id, r.assigned_before_validation,
                    e.name requester_name, d.name department_name, v.plate, de.name driver_name,
                    (SELECT 1 FROM fleet_ratings rt WHERE rt.request_id = r.id) rated
               FROM fleet_requests r
               JOIN employees e ON e.id = r.requester_id
               LEFT JOIN departments d ON d.id = r.department_id
               LEFT JOIN fleet_vehicles v ON v.id = r.vehicle_id
               LEFT JOIN fleet_drivers dr ON dr.id = r.driver_id
               LEFT JOIN employees de ON de.id = dr.employee_id
              WHERE $where ORDER BY $order LIMIT $limit",
            $params
        );
    }
}
