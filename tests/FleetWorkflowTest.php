<?php
declare(strict_types=1);
// Tests du circuit des demandes de véhicule et des conflits d'affectation : php tests/FleetWorkflowTest.php
require dirname(__DIR__) . '/app/Modules/Fleet/Services/FleetWorkflow.php';
require dirname(__DIR__) . '/app/Modules/Fleet/Services/Availability.php';
use App\Modules\Fleet\Services\Availability as A;
use App\Modules\Fleet\Services\FleetWorkflow as W;

$fail = 0;
function check(string $name, mixed $r, mixed $exp): void {
    global $fail;
    if (is_array($exp) && is_array($r) && !array_is_list($exp)) {
        foreach ($exp as $k => $v) if (!array_key_exists($k, $r) || $r[$k] !== $v) { $fail++; echo "ÉCHEC $name : $k = ".var_export($r[$k] ?? null,true)." attendu ".var_export($v,true)."\n"; return; }
    } elseif ($r !== $exp) { $fail++; echo "ÉCHEC $name : ".var_export($r,true)." attendu ".var_export($exp,true)."\n"; return; }
    echo "ok   $name\n";
}
function throws(string $name, callable $fn, string $contains): void {
    global $fail;
    try { $fn(); } catch (DomainException $e) {
        if (str_contains($e->getMessage(), $contains)) { echo "ok   $name\n"; return; }
        $fail++; echo "ÉCHEC $name : message « {$e->getMessage()} »\n"; return;
    }
    $fail++; echo "ÉCHEC $name : aucune exception\n";
}
$settings = W::DEFAULTS;
// 2026-10-19 = lundi, 2026-10-24 = samedi
$req = fn(array $o = []) => $o + ['id'=>10,'out_of_town'=>0,'depart_at'=>'2026-10-20 08:00:00','return_at'=>'2026-10-20 17:00:00',
    'passengers'=>2,'with_driver'=>1,'urgent'=>0,'status'=>'en_validation','validation'=>'en_attente','step'=>'n1',
    'direction_reasons'=>null,'vehicle_id'=>null,'assigned_before_validation'=>0];

// --- Seuils de la direction ---------------------------------------------------
check('mission en ville d\'un jour : N+1 seul', W::directionReasons($req(), $settings), []);
check('hors ville : direction', W::directionReasons($req(['out_of_town'=>1]), $settings), ['mission hors ville']);
check('2 jours : N+1 seul', W::directionReasons($req(['return_at'=>'2026-10-21 17:00:00']), $settings), []);
check('3 jours : direction', W::directionReasons($req(['return_at'=>'2026-10-22 10:00:00']), $settings), ['durée de 3 jours (plus de 2)']);
check('touche un samedi : direction', W::directionReasons($req(['depart_at'=>'2026-10-23 20:00:00','return_at'=>'2026-10-24 02:00:00']), $settings), ['week-end']);
check('vendredi seul : pas de week-end', W::touchesWeekend('2026-10-23 08:00:00','2026-10-23 18:00:00'), false);
check('cumul des raisons', W::directionReasons($req(['out_of_town'=>1,'depart_at'=>'2026-10-22 08:00:00','return_at'=>'2026-10-26 18:00:00']), $settings),
  ['mission hors ville','durée de 5 jours (plus de 2)','week-end']);
check('seuils désactivés', W::directionReasons($req(['out_of_town'=>1,'depart_at'=>'2026-10-22 08:00:00','return_at'=>'2026-10-26 18:00:00']),
  ['fleet_direction_out_of_town'=>'0','fleet_direction_max_days'=>'0','fleet_direction_weekend'=>'0'] + $settings), []);
check('seuil porté à 5 jours', W::directionReasons($req(['return_at'=>'2026-10-22 10:00:00']), ['fleet_direction_max_days'=>'5'] + $settings), []);
check('jours du calendrier', [W::days('2026-10-20 23:00:00','2026-10-21 01:00:00'), W::days('2026-10-20 08:00:00','2026-10-20 09:00:00')], [2, 1]);

// --- Première étape -----------------------------------------------------------
check('départ chez le N+1', W::start($req(['out_of_town'=>1]), true, $settings), ['step'=>'n1','status'=>'en_validation','direction_reasons'=>'mission hors ville']);
check('sans N+1 : direction', W::start($req(), false, $settings), ['step'=>'direction','direction_reasons'=>'aucun N+1 désigné']);
$deps = [1=>['parent_id'=>null,'head_employee_id'=>100], 2=>['parent_id'=>1,'head_employee_id'=>200], 3=>['parent_id'=>2,'head_employee_id'=>null]];
check('N+1 = responsable du service', W::resolveN1(2, 5, $deps), 200);
check('service sans responsable : on remonte', W::resolveN1(3, 5, $deps), 200);
check('responsable qui demande : son propre N+1', W::resolveN1(2, 200, $deps), 100);
check('directeur sans supérieur : personne', W::resolveN1(1, 100, $deps), null);
check('sans service : personne', W::resolveN1(null, 5, $deps), null);
check('ordre de mission obligatoire hors ville', [W::orderRequired($req(['out_of_town'=>1]), $settings), W::orderRequired($req(), $settings)], [true, false]);

// --- Validation ---------------------------------------------------------------
check('N+1 approuve, pas de direction : validée', W::approve($req(), 'n1'), ['validation'=>'approuvee','status'=>'approuvee','step'=>null]);
check('N+1 approuve, direction requise', W::approve($req(['direction_reasons'=>'week-end']), 'n1'), ['step'=>'direction']);
check('direction approuve : validée', W::approve($req(['step'=>'direction','direction_reasons'=>'week-end']), 'direction'), ['validation'=>'approuvee','status'=>'approuvee']);
throws('la direction ne valide pas avant le N+1', fn() => W::approve($req(), 'direction'), 'Validation du N+1');
throws('pas de double validation', fn() => W::approve($req(['validation'=>'approuvee','status'=>'approuvee','step'=>null]), 'n1'), 'n\'attend plus');

// --- Refus --------------------------------------------------------------------
throws('refus sans motif', fn() => W::refuse($req(), 'n1', '  '), 'motif du refus est obligatoire');
check('refus motivé', W::refuse($req(), 'n1', 'Budget épuisé'), ['status'=>'refusee','validation'=>'refusee','refusal_reason'=>'Budget épuisé','release'=>false]);
check('refus de la direction', W::refuse($req(['step'=>'direction']), 'direction', 'Pas de mission le week-end'), ['status'=>'refusee']);
throws('refus d\'une demande annulée', fn() => W::refuse($req(['status'=>'annulee']), 'n1', 'Motif'), 'n\'attend plus');

// --- Urgence : affectation avant validation, validation a posteriori ------------
throws('demande normale non validée : pas d\'affectation', fn() => W::assign($req()), 'seule une demande urgente');
check('urgence affectée avant validation', W::assign($req(['urgent'=>1])), ['status'=>'affectee','assigned_before_validation'=>1]);
$urgent = $req(['urgent'=>1,'status'=>'affectee','assigned_before_validation'=>1,'vehicle_id'=>3]);
check('urgence : démarrage possible avant validation', W::canStart($urgent), true);
check('urgence : validation a posteriori, statut conservé', W::approve($urgent, 'n1'), ['validation'=>'approuvee','status'=>'affectee']);
check('urgence refusée avant départ : véhicule libéré', W::refuse($urgent, 'n1', 'Pas urgent'), ['status'=>'refusee','release'=>true]);
check('urgence refusée après départ : mission conservée', W::refuse(['status'=>'en_cours'] + $urgent, 'n1', 'Pas justifiée'), ['status'=>'en_cours','validation'=>'refusee','release'=>false]);
check('refus a posteriori affiché', W::label(['status'=>'terminee','validation'=>'refusee'] + $urgent), ['Terminée · refusée a posteriori', 'danger']);
check('validation en attente affichée', W::label($urgent), ['Véhicule affecté · validation en attente', 'accent']);
check('demande validée affectée normalement', W::assign($req(['status'=>'approuvee','validation'=>'approuvee','step'=>null])), ['assigned_before_validation'=>0]);
check('pas d\'affectation après refus', W::canAssign($req(['status'=>'refusee','validation'=>'refusee'])), false);

// --- Annulation, clôture, évaluation ------------------------------------------
check('annulation avant départ', W::cancel($req(['status'=>'affectee','validation'=>'approuvee'])), ['status'=>'annulee','release'=>true]);
throws('pas d\'annulation en mission', fn() => W::cancel($req(['status'=>'en_cours'])), 'ne peut plus');
check('kilométrage de retour inférieur refusé', W::closeError(12000, 11990) !== null, true);
check('kilométrage de retour égal refusé', W::closeError(12000, 12000) !== null, true);
check('kilométrage de retour supérieur accepté', W::closeError(12000, 12085), null);
check('évaluation après la mission seulement', [W::canRate($req(['status'=>'terminee'])), W::canRate($req(['status'=>'en_cours']))], [true, false]);

// --- Conflits d'affectation ---------------------------------------------------
$bookings = [
    ['request_id'=>1,'reference'=>'DV-2026-0001','from'=>'2026-10-20 08:00:00','to'=>'2026-10-20 12:00:00'],
    ['request_id'=>2,'reference'=>'DV-2026-0002','from'=>'2026-10-20 14:00:00','to'=>'2026-10-20 18:00:00'],
];
check('chevauchement au début', count(A::conflicts('2026-10-20 11:00:00','2026-10-20 13:00:00',$bookings)), 1);
check('créneau englobant deux réservations', count(A::conflicts('2026-10-20 07:00:00','2026-10-20 19:00:00',$bookings)), 2);
check('créneau contenu dans une réservation', count(A::conflicts('2026-10-20 09:00:00','2026-10-20 10:00:00',$bookings)), 1);
check('bout à bout : pas de conflit', A::conflicts('2026-10-20 12:00:00','2026-10-20 14:00:00',$bookings), []);
check('la demande elle-même est ignorée', A::conflicts('2026-10-20 08:00:00','2026-10-20 12:00:00',$bookings, 1), []);
check('mission en retard : véhicule occupé jusqu\'à maintenant', A::window('en_cours','2026-10-20 08:00:00','2026-10-20 12:00:00','2026-10-20 15:00:00'), ['2026-10-20 08:00:00','2026-10-20 15:00:00']);
check('réservation à venir : créneau prévu', A::window('affectee','2026-10-21 08:00:00','2026-10-21 12:00:00','2026-10-20 15:00:00'), ['2026-10-21 08:00:00','2026-10-21 12:00:00']);

$veh = ['id'=>3,'active'=>1,'status'=>'disponible','seats'=>5,'vehicle_type'=>'suv'];
$docs = ['assurance'=>'2027-01-01','controle_technique'=>'2027-01-01'];
$r = $req(['depart_at'=>'2026-10-20 11:00:00','return_at'=>'2026-10-20 13:00:00']);
check('véhicule déjà réservé : bloquant', A::blocking(A::vehicleIssues($veh, $r, $docs, $bookings)), true);
check('véhicule libre : disponible', A::vehicleIssues($veh, $req(['depart_at'=>'2026-10-21 08:00:00','return_at'=>'2026-10-21 10:00:00']), $docs, $bookings), []);
check('véhicule en panne : bloquant', A::blocking(A::vehicleIssues(['status'=>'en_panne'] + $veh, $req(['depart_at'=>'2026-10-21 08:00:00','return_at'=>'2026-10-21 10:00:00']), $docs, [])), true);
check('véhicule en entretien : bloquant', A::vehicleIssues(['status'=>'en_entretien'] + $veh, $req(['depart_at'=>'2026-10-21 08:00:00']), $docs, [])[0]['text'], 'En entretien');
check('assurance expirée : bloquant', A::blocking(A::vehicleIssues($veh, $req(), ['assurance'=>'2026-10-01'] + $docs, [])), true);
check('assurance expirant pendant la mission : avertissement', A::vehicleIssues($veh, $req(['return_at'=>'2026-10-22 10:00:00']), ['assurance'=>'2026-10-21'] + $docs, []),
  [['level'=>'warn','text'=>'Assurance expire le 21/10/2026, pendant la mission']]);
check('places insuffisantes : avertissement', A::vehicleIssues(['seats'=>2] + $veh, $req(['passengers'=>3]), $docs, [])[0]['level'], 'warn');

$driver = ['id'=>7,'active'=>1,'license_expires'=>'2028-01-01','license_category'=>'B'];
check('chauffeur en mission sur le créneau : bloquant', A::blocking(A::driverIssues($driver, $r, $veh, [], $bookings)), true);
check('chauffeur libre', A::driverIssues($driver, $req(['depart_at'=>'2026-10-21 08:00:00','return_at'=>'2026-10-21 10:00:00']), $veh, [], $bookings), []);
check('permis expiré : bloquant', A::blocking(A::driverIssues(['license_expires'=>'2026-09-30'] + $driver, $req(), $veh, [], [])), true);
check('véhicule non autorisé : bloquant', A::driverIssues($driver, $req(), $veh, [4, 5], [])[0]['text'], 'Non autorisé sur ce véhicule');
check('véhicule autorisé', A::driverIssues($driver, $req(), $veh, [3], []), []);
check('catégorie D pour un minibus : avertissement', A::driverIssues($driver, $req(), ['vehicle_type'=>'minibus','seats'=>15] + $veh, [], [])[0],
  ['level'=>'warn','text'=>'Permis D requis (catégories : B)']);

echo $fail ? "\n$fail échec(s)\n" : "\nTous les tests passent.\n";
exit($fail ? 1 : 0);
