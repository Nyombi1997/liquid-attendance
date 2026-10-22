<?php
declare(strict_types=1);
// Tests de l'import CSV du parc automobile (lecture, dates, analyse), sans base : php tests/FleetImportTest.php
require dirname(__DIR__) . '/app/Modules/Fleet/Services/FleetImport.php';
use App\Modules\Fleet\Services\FleetImport as I;

$fail = 0;
function check(string $name, mixed $r, mixed $exp): void {
    global $fail;
    if ($r === $exp) { echo "ok   $name\n"; return; }
    $fail++;
    echo "ÉCHEC $name : " . var_export($r, true) . ' attendu ' . var_export($exp, true) . "\n";
}
$cells = fn(string $raw) => array_map(fn($r) => $r['cells'], I::read($raw)['rows']);

// --- Dates --------------------------------------------------------------------
check('date JJ/MM/AAAA', I::date('31/12/2026'), '2026-12-31');
check('date AAAA-MM-JJ', I::date('2026-11-30'), '2026-11-30');
check('date J/M/AAAA (Excel)', I::date('5/3/2027'), '2027-03-05');
check('date JJ-MM-AAAA et JJ.MM.AAAA', [I::date('15-03-2027'), I::date('15.03.2027')], ['2027-03-15', '2027-03-15']);
check('date avec heure (Excel)', [I::date('31/12/2026 00:00'), I::date('2026-12-31 00:00:00')], ['2026-12-31', '2026-12-31']);
check('date vide', I::date('  '), null);
check('date impossible (31/02, 13e mois)', [I::date('31/02/2026'), I::date('2026-13-01'), I::date('29/02/2027')], [false, false, false]);
check('29 février d\'une année bissextile', I::date('29/02/2028'), '2028-02-29');
check('date au format américain ou texte refusée', [I::date('12/31/2026'), I::date('demain'), I::date('31/12/26')], [false, false, false]);

// --- Nombres, catégories, immatriculations -------------------------------------
check('nombre décimal à virgule ou point', [I::number('9,5', 100), I::number('9.5', 100), I::number('1 234,5', 2000)], [9.5, 9.5, 1234.5]);
check('nombre invalide ou hors bornes', [I::number('abc', 100), I::number('-1', 100), I::number('101', 100), I::number('', 100)], [false, false, false, null]);
check('catégories de permis', [I::categories('b,c'), I::categories('B C'), I::categories('B/C/B'), I::categories('B, Z'), I::categories('')], ['B,C', 'B,C', 'B,C', false, null]);
check('immatriculation normalisée', I::plate('  1234 ab   01 '), '1234 AB 01');

// --- Séparateurs, encodages, accents -------------------------------------------
check('séparateur ;', I::read("Immatriculation;Marque\n1234AB01;Toyota")['separator'], ';');
check('séparateur ,', I::read("Immatriculation,Marque\n1234AB01,Toyota")['separator'], ',');
check('séparateur tabulation', I::read("Immatriculation\tMarque\n1234AB01\tToyota")['separator'], "\t");
check('virgule entre guillemets ignorée pour la détection', I::read("\"Nom, prénom\";Permis\nX;Y")['separator'], ';');
check('cellules avec « , » décimale et séparateur « ; »', $cells("A;B\n9,5;\"x;y\"")[1], ['9,5', 'x;y']);
check('guillemets et virgules avec séparateur « , »', $cells("Employé,Catégorie\n\"KASONGO Didier\",\"B,C\"")[1], ['KASONGO Didier', 'B,C']);
check('lignes vides ignorées, fin de ligne Windows', $cells("A;B\r\n\r\n1;2\r\n;\r\n"), [['A', 'B'], ['1', '2']]);
check('UTF-8 avec BOM', I::read("\xEF\xBB\xBFModèle;Année\nCitroën;2020"), ['rows' => [['line' => 1, 'cells' => ['Modèle', 'Année']], ['line' => 2, 'cells' => ['Citroën', '2020']]], 'separator' => ';', 'encoding' => 'UTF-8']);
$win = mb_convert_encoding("Direction;Service;N+1\nDirection générale;Comptabilité;Hélène Kaçé", 'Windows-1252', 'UTF-8');
$read = I::read($win);
check('Windows-1252 détecté et converti (accents)', [$read['encoding'], $read['rows'][1]['cells']], ['Windows-1252', ['Direction générale', 'Comptabilité', 'Hélène Kaçé']]);
$utf16 = "\xFF\xFE" . mb_convert_encoding("Employé;Catégorie\nÉlodie;B", 'UTF-16LE', 'UTF-8');
check('UTF-16 (Excel « texte Unicode »)', I::read($utf16)['rows'][1]['cells'], ['Élodie', 'B']);
check('UTF-8 sans BOM', I::read("Employé;Catégorie\nÉlodie;B")['encoding'], 'UTF-8');
check('normalisation des accents et de la casse', I::normalize('  Échéance  Contrôle-Technique '), 'echeance controle technique');

// --- En-têtes et modèles -------------------------------------------------------
check('en-têtes dans le désordre, avec accents et unités', I::headers('vehicules', ['Marque', 'IMMATRICULATION', 'Consommation (L/100km)', 'Echeance assurance']),
    ['brand' => 0, 'plate' => 1, 'ref_consumption' => 2, 'assurance' => 3]);
check('colonne obligatoire absente', is_string(I::headers('chauffeurs', ['Permis', 'Catégorie'])), true);
foreach (array_keys(I::KINDS) as $kind) {
    $t = I::read(I::template($kind));
    $h = I::headers($kind, $t['rows'][0]['cells']);
    check("modèle $kind : relu avec toutes ses colonnes", is_array($h) && count($h) === count(I::KINDS[$kind]['columns']), true);
}

// --- Contexte commun -------------------------------------------------------------
$employees = [
    ['id' => 1, 'name' => 'MUKENDI Jean', 'device_user_id' => '12', 'active' => 1],
    ['id' => 2, 'name' => 'KASONGO Didier', 'device_user_id' => '42', 'active' => 1],
    ['id' => 3, 'name' => 'ILUNGA Sarah', 'device_user_id' => '7', 'active' => 1],
    ['id' => 4, 'name' => 'ILUNGA Sarah', 'device_user_id' => '8', 'active' => 1],
    ['id' => 5, 'name' => 'LUNDA Patrick', 'device_user_id' => '9', 'active' => 0],
    ['id' => 6, 'name' => 'Hélène Kaçé', 'device_user_id' => '10', 'active' => 1],
];
$idx = I::employeeIndex($employees);
check('employé par n° de pointeuse', I::resolveEmployee('42', $idx), [2, null]);
check('employé par n° avec zéros devant', I::resolveEmployee('0042', $idx), [2, null]);
check('employé par n° de pointeuse non numérique', I::resolveEmployee('DEMO-07', I::employeeIndex([['id' => 9, 'name' => 'X Y', 'device_user_id' => 'demo-07', 'active' => 1]])), [9, null]);
check('employé par nom, casse et ordre des mots libres', [I::resolveEmployee('jean mukendi', $idx)[0], I::resolveEmployee('Mukendi  Jean', $idx)[0]], [1, 1]);
check('employé par nom sans accents', I::resolveEmployee('HELENE KACE', $idx), [6, null]);
check('employé introuvable', I::resolveEmployee('Inconnu Total', $idx)[1], 'Employé introuvable : « Inconnu Total ».');
check('n° de pointeuse introuvable', I::resolveEmployee('999', $idx)[1], 'Employé introuvable : aucun n° de pointeuse « 999 ».');
check('homonymes : n° de pointeuse demandé', str_contains((string) I::resolveEmployee('Sarah Ilunga', $idx)[1], 'n° de pointeuse'), true);
check('employé désactivé refusé', I::resolveEmployee('9', $idx)[1], 'Employé désactivé : LUNDA Patrick.');

$rows = fn(string $csv) => I::read($csv)['rows'];
$statuses = fn(array $a) => array_map(fn($l) => $l['status'], $a['lines']);

// --- Véhicules -----------------------------------------------------------------
$ctx = [
    'employees' => $employees, 'year' => 2026,
    'vehicles' => [
        '1234AB01' => ['id' => 1, 'plate' => '1234AB01', 'brand' => 'Toyota', 'model' => 'Hilux', 'year' => 2021, 'fuel_type' => 'diesel',
                       'tank_liters' => '80.0', 'ref_consumption' => '9.50', 'status' => 'disponible', 'active' => 1, 'busy' => false],
        'MISSION01' => ['id' => 2, 'plate' => 'MISSION01', 'brand' => 'Nissan', 'model' => 'Patrol', 'year' => null, 'fuel_type' => 'diesel',
                        'tank_liters' => null, 'ref_consumption' => null, 'status' => 'en_mission', 'active' => 1, 'busy' => true],
    ],
    'documents' => [1 => ['assurance' => '2026-12-31']],
];
$csv = "Immatriculation;Marque;Modèle;Année;Carburant;Réservoir (L);Consommation de référence (L/100 km);Statut;Échéance assurance;Échéance contrôle technique\n"
     . "1234 ab01;Toyota;Hilux;2021;Gasoil;80;9,5;Disponible;31/12/2026;\n"        // 2 : inchangé
     . "1234AB01;Toyota;Hilux;;;;;;;\n"                                              // 3 : doublon
     . "5678CD01;Nissan;Patrol;2019;Essence;140;14;En entretien;15/03/2027;2027-01-15\n" // 4 : création
     . "9999ZZ01;;;;;;;;;\n"                                                          // 5 : marque, modèle manquants
     . "8888YY01;Ford;Ranger;2030;Fioul;-5;x;Volé;31/02/2027;demain\n"                 // 6 : erreurs multiples
     . "MISSION01;;;;;;;Disponible;;\n"                                               // 7 : en mission
     . "1234AB02;Toyota;Hilux;;;;;En mission;;\n";                                    // 8 : statut interdit
$a = I::analyze('vehicules', $rows($csv), $ctx);
check('véhicules : statut de chaque ligne', $statuses($a), ['unchanged', 'error', 'create', 'error', 'error', 'error', 'error']);
check('véhicules : doublon d\'immatriculation (même plaque écrite autrement)', $a['lines'][1]['errors'], ["Doublon d'immatriculation : « 1234AB01 » déjà ligne 2."]);
check('véhicules : création avec deux documents', $a['lines'][2]['plan'][0], ['op' => 'create', 'id' => null, 'plate' => '5678CD01',
    'set' => ['brand' => 'Nissan', 'model' => 'Patrol', 'year' => 2019, 'fuel_type' => 'essence', 'tank_liters' => 140.0, 'ref_consumption' => 14.0, 'status' => 'en_entretien'],
    'docs' => ['assurance' => '2027-03-15', 'controle_technique' => '2027-01-15']]);
check('véhicules : marque et modèle obligatoires pour un nouveau', count($a['lines'][3]['errors']), 2);
check('véhicules : toutes les erreurs de la ligne', count($a['lines'][4]['errors']), 7);
check('véhicules : date invalide signalée', in_array('Date invalide (assurance) : « 31/02/2027 » (JJ/MM/AAAA ou AAAA-MM-JJ).', $a['lines'][4]['errors'], true), true);
check('véhicules : véhicule en mission non modifiable', $a['lines'][5]['errors'], ['Ce véhicule est en mission : son état changera à la clôture de la mission.']);
check('véhicules : « En mission » refusé', str_contains($a['lines'][6]['errors'][0], 'En mission'), true);
check('véhicules : décompte', $a['counts'], ['create' => 1, 'update' => 0, 'unchanged' => 1, 'error' => 5]);
$u = I::analyze('vehicules', $rows("Immatriculation,Consommation,Échéance assurance,Échéance contrôle technique\n1234AB01,\"10,2\",2027-06-30,01/02/2027"), $ctx);
check('véhicules : mise à jour d\'une fiche (cellules vides gardées)', [$u['lines'][0]['status'], $u['lines'][0]['plan'][0]['set'], $u['lines'][0]['plan'][0]['docs']],
    ['update', ['ref_consumption' => 10.2], ['assurance' => '2027-06-30', 'controle_technique' => '2027-02-01']]);
check('véhicules : détail des changements', $u['lines'][0]['changes'], ['consommation : 9,5 → 10,2', 'assurance jusqu\'au 30/06/2027 (avant : 31/12/2026)', 'contrôle technique jusqu\'au 01/02/2027']);
$u = I::analyze('vehicules', $rows("Immatriculation;Statut;Carburant\n1234AB01;En panne;Électrique"), $ctx);
check('véhicules : libellés du statut et du carburant', $u['lines'][0]['changes'], ['carburant : diesel → électrique', 'statut : disponible → en panne']);
check('véhicules : en-tête seul', I::analyze('vehicules', $rows("Immatriculation;Marque\n"), $ctx)['fatal'] !== null, true);
check('véhicules : en-tête inconnu', str_contains((string) I::analyze('vehicules', $rows("Numéro;Marque\nX;Y"), $ctx)['fatal'], 'Immatriculation'), true);
check('véhicules : autre nom d\'en-tête accepté', I::headers('vehicules', ["Plaque d'immat", 'Marque']), ['plate' => 0, 'brand' => 1]);
check('clé d\'immatriculation', [I::plateKey('1234 ab-01'), I::plateKey('1234AB01')], ['1234AB01', '1234AB01']);

// --- Chauffeurs ------------------------------------------------------------------
$ctx['drivers'] = [2 => ['id' => 7, 'employee_id' => 2, 'license_number' => 'KIN-1', 'license_category' => 'B', 'license_expires' => '2027-01-01', 'active' => 1]];
$csv = "Employé;N° de permis;Catégorie;Date d'expiration\n"
     . "42;KIN-1;b,c;01/01/2027\n"          // 2 : mise à jour de la catégorie
     . "Jean Mukendi;KIN-2;B;2028-06-30\n"  // 3 : nouveau
     . "MUKENDI Jean;;;\n"                  // 4 : doublon
     . "Personne Inconnue;X;B;01/01/2028\n" // 5 : introuvable
     . "Hélène Kaçé;X;Z;2028-02-30\n";      // 6 : catégorie et date invalides
$a = I::analyze('chauffeurs', $rows($csv), $ctx);
check('chauffeurs : statut de chaque ligne', $statuses($a), ['update', 'create', 'error', 'error', 'error']);
check('chauffeurs : mise à jour du permis', [$a['lines'][0]['plan'][0]['set'], $a['lines'][0]['changes']], [['license_category' => 'B,C'], ['catégorie : B → B,C']]);
check('chauffeurs : création', $a['lines'][1]['plan'][0], ['op' => 'create', 'id' => null, 'employee_id' => 1,
    'set' => ['license_number' => 'KIN-2', 'license_category' => 'B', 'license_expires' => '2028-06-30']]);
check('chauffeurs : doublon d\'employé', $a['lines'][2]['errors'], ['Doublon : MUKENDI Jean déjà ligne 3.']);
check('chauffeurs : employé introuvable', $a['lines'][3]['errors'], ['Employé introuvable : « Personne Inconnue ».']);
check('chauffeurs : catégorie et date invalides', count($a['lines'][4]['errors']), 2);

// --- Organisation ----------------------------------------------------------------
$ctx['departments'] = [
    1 => ['id' => 1, 'name' => 'Direction technique', 'parent_id' => null, 'kind' => 'direction', 'head_employee_id' => null],
    2 => ['id' => 2, 'name' => 'Réseau', 'parent_id' => 1, 'kind' => 'service', 'head_employee_id' => 1],
    3 => ['id' => 3, 'name' => 'Comptabilité', 'parent_id' => null, 'kind' => 'service', 'head_employee_id' => null],
];
$csv = "Direction;Service;N+1\n"
     . "direction technique;RESEAU;12\n"            // 2 : inchangé (même N+1, accents et casse libres)
     . "Direction technique;Support;Didier Kasongo\n" // 3 : service créé
     . "Direction financière;Comptabilité;\n"        // 4 : direction créée, service existant rattaché
     . "Direction financière;;Hélène Kaçé\n"         // 5 : N+1 de la direction créée plus haut
     . "Direction financière;Trésorerie;\n"          // 6 : service créé sous la direction du fichier
     . "Direction technique;Support;\n"              // 7 : doublon de service
     . ";Achats;\n"                                  // 8 : direction manquante
     . "Direction technique;Logistique;Sarah Ilunga\n" // 9 : homonymes
     . "Réseau;Direction technique;\n";               // 10 : boucle
$a = I::analyze('organisation', $rows($csv), $ctx);
check('organisation : statut de chaque ligne', $statuses($a), ['unchanged', 'create', 'create', 'update', 'create', 'error', 'error', 'error', 'error']);
check('organisation : service créé avec son N+1', $a['lines'][1]['plan'], [['op' => 'create', 'key' => 'new:s:support', 'name' => 'Support', 'kind' => 'service', 'parent' => 1, 'head' => 2]]);
check('organisation : direction créée, service existant rattaché', $a['lines'][2]['plan'], [
    ['op' => 'create', 'key' => 'new:d:direction financiere', 'name' => 'Direction financière', 'kind' => 'direction', 'parent' => null, 'head' => null],
    ['op' => 'update', 'ref' => 3, 'set' => ['parent' => 'new:d:direction financiere']],
]);
check('organisation : N+1 d\'une direction créée plus haut', $a['lines'][3]['plan'], [['op' => 'update', 'ref' => 'new:d:direction financiere', 'set' => ['head' => 6]]]);
check('organisation : service sous une direction du fichier', $a['lines'][4]['plan'][0]['parent'], 'new:d:direction financiere');
check('organisation : doublon de service', $a['lines'][5]['errors'], ['Doublon : service « Support » déjà présent ligne 3.']);
check('organisation : direction manquante', $a['lines'][6]['errors'], ['Direction manquante.']);
check('organisation : N+1 homonyme', str_contains($a['lines'][7]['errors'][0], 'n° de pointeuse'), true);
check('organisation : boucle refusée', str_contains($a['lines'][8]['errors'][0] ?? '', 'ne peut pas passer sous'), true);
$f1 = I::fingerprint($a);
$ctx['departments'][2]['head_employee_id'] = 3;
check('empreinte : change si les données ont changé depuis l\'aperçu', I::fingerprint(I::analyze('organisation', $rows($csv), $ctx)) !== $f1, true);

echo $fail ? "\n$fail échec(s)\n" : "\nTous les tests passent.\n";
exit($fail ? 1 : 0);
