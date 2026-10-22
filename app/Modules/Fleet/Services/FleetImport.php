<?php
declare(strict_types=1);

namespace App\Modules\Fleet\Services;

/**
 * Import CSV du parc automobile (organisation, véhicules, chauffeurs). Service
 * pur, sans base (testé par php tests/FleetImportTest.php) :
 * - read() : décodage (UTF-8, UTF-16 ou Windows-1252, détection automatique),
 *   séparateur « ; », « , » ou tabulation détecté sur la ligne d'en-tête ;
 * - analyze() : rapproche chaque ligne des données existantes (contexte fourni
 *   par le contrôleur) et rend, ligne par ligne, l'action prévue (création,
 *   mise à jour, inchangée, erreur), le détail des changements et un plan
 *   d'opérations que ImportController applique dans une transaction.
 * Une cellule vide ne change rien sur une fiche existante.
 */
final class FleetImport
{
    public const MAX_BYTES = 1_048_576;
    public const MAX_ROWS = 2000;

    /**
     * Listes importables : libellé, colonnes (clé => [en-tête du modèle, autres noms acceptés]),
     * colonnes obligatoires, lignes d'exemple du modèle.
     */
    public const KINDS = [
        'organisation' => [
            'label'    => 'Organisation',
            'help'     => 'Directions, services et responsables (N+1) qui valident les demandes de véhicule.',
            'columns'  => [
                'direction' => ['Direction', []],
                'service'   => ['Service', ['departement', 'sous direction']],
                'head'      => ['N+1', ['n 1', 'n1', 'responsable', 'chef', 'valideur', 'validateur']],
            ],
            'required' => ['direction'],
            'example'  => [
                ['Direction technique', 'Réseau', 'MUKENDI Jean'],
                ['Direction technique', 'Support informatique', '173'],
                ['Direction générale', '', 'LUNDA Patrick'],
            ],
        ],
        'vehicules' => [
            'label'    => 'Véhicules',
            'help'     => 'Parc automobile, avec les échéances de l\'assurance et du contrôle technique.',
            'columns'  => [
                'plate'           => ['Immatriculation', ['plaque', 'immat']],
                'brand'           => ['Marque', []],
                'model'           => ['Modèle', []],
                'year'            => ['Année', ['annee de mise en circulation']],
                'fuel_type'       => ['Carburant', ['energie']],
                'tank_liters'     => ['Réservoir (L)', ['reservoir', 'capacite du reservoir']],
                'ref_consumption' => ['Consommation de référence (L/100 km)', ['consommation', 'conso']],
                'status'          => ['Statut', ['etat']],
                'assurance'       => ['Échéance assurance', ['assurance', 'fin assurance', 'expiration assurance']],
                'controle_technique' => ['Échéance contrôle technique', ['controle technique', 'fin controle technique', 'ct', 'echeance ct']],
            ],
            'required' => ['plate'],
            'example'  => [
                ['1234AB01', 'Toyota', 'Hilux', '2021', 'Diesel', '80', '9,5', 'Disponible', '31/12/2026', '2026-11-30'],
                ['5678CD01', 'Nissan', 'Patrol', '2019', 'Essence', '140', '14', 'En entretien', '15/03/2027', '15/01/2027'],
            ],
        ],
        'chauffeurs' => [
            'label'    => 'Chauffeurs',
            'help'     => 'Employés qui conduisent, avec leur permis.',
            'columns'  => [
                'employee'         => ['Employé', ['nom', 'chauffeur', 'agent', 'n de pointeuse', 'numero de pointeuse']],
                'license_number'   => ['N° de permis', ['n permis', 'numero de permis', 'permis']],
                'license_category' => ['Catégorie', ['categories', 'categorie du permis']],
                'license_expires'  => ['Date d\'expiration', ['expiration', 'expiration du permis', 'validite']],
            ],
            'required' => ['employee'],
            'example'  => [
                ['KASONGO Didier', 'KIN-123456', 'B,C', '30/06/2028'],
                ['42', 'KIN-654321', 'B', '2027-01-15'],
            ],
        ],
    ];

    public const FUELS = [
        'diesel' => 'diesel', 'gasoil' => 'diesel', 'gazole' => 'diesel', 'essence' => 'essence', 'super' => 'essence',
        'hybride' => 'hybride', 'electrique' => 'electrique',
    ];
    public const STATUSES = [
        'disponible' => 'disponible', 'en panne' => 'en_panne', 'panne' => 'en_panne',
        'en entretien' => 'en_entretien', 'entretien' => 'en_entretien', 'en mission' => 'en_mission',
    ];
    /** Libellés affichés dans l'aperçu (mêmes que Fleet::VEHICLE_STATUSES et Fleet::FUEL_TYPES). */
    public const LABELS = [
        'disponible' => 'disponible', 'en_mission' => 'en mission', 'en_panne' => 'en panne', 'en_entretien' => 'en entretien',
        'diesel' => 'diesel', 'essence' => 'essence', 'hybride' => 'hybride', 'electrique' => 'électrique',
    ];
    public const LICENSE_CATEGORIES = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

    // -- Lecture du fichier --------------------------------------------------

    /**
     * Texte du fichier en UTF-8 : BOM UTF-8 ou UTF-16 reconnu, UTF-8 valide gardé
     * tel quel, sinon Windows-1252 (Excel « CSV (séparateur : point-virgule) »).
     *
     * @return array{0:string, 1:string} texte, encodage détecté
     */
    public static function decode(string $raw): array
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            return [substr($raw, 3), 'UTF-8'];
        }
        if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
            $enc = $raw[0] === "\xFF" ? 'UTF-16LE' : 'UTF-16BE';
            return [mb_convert_encoding(substr($raw, 2), 'UTF-8', $enc), $enc];
        }
        if (mb_check_encoding($raw, 'UTF-8')) {
            return [$raw, 'UTF-8'];
        }
        return [mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'), 'Windows-1252'];
    }

    /** Séparateur le plus fréquent, hors guillemets, sur la première ligne non vide (« ; » à égalité). */
    public static function separator(string $text): string
    {
        $line = '';
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $l) {
            if (trim($l) !== '') {
                $line = $l;
                break;
            }
        }
        $line = preg_replace('/"[^"]*"/', '', $line) ?? $line;
        $best = ';';
        $max = substr_count($line, ';');
        foreach ([',', "\t"] as $sep) {
            if (substr_count($line, $sep) > $max) {
                $best = $sep;
                $max = substr_count($line, $sep);
            }
        }
        return $best;
    }

    /**
     * Lignes du fichier (cellules nettoyées), lignes vides retirées.
     *
     * @return array{rows: list<array{line:int, cells:list<string>}>, separator:string, encoding:string}
     */
    public static function read(string $raw): array
    {
        [$text, $encoding] = self::decode($raw);
        $sep = self::separator($text);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $text);
        rewind($stream);
        $rows = [];
        $line = 0;
        while (($cells = fgetcsv($stream, null, $sep, '"', '')) !== false) {
            $line++;
            $cells = array_map(static fn($c) => trim(str_replace("\u{A0}", ' ', (string) $c)), $cells);
            if (implode('', $cells) === '') {
                continue;
            }
            $rows[] = ['line' => $line, 'cells' => $cells];
        }
        fclose($stream);
        return ['rows' => $rows, 'separator' => $sep, 'encoding' => $encoding];
    }

    // -- Valeurs ---------------------------------------------------------------

    /** Minuscules sans accents ni ponctuation, espaces réduits (comparaison des noms et en-têtes). */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae', '°' => ' ', 'º' => ' ',
        ]);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '');
    }

    /**
     * Date JJ/MM/AAAA (ou JJ-MM-AAAA, JJ.MM.AAAA) ou AAAA-MM-JJ, heure éventuelle ignorée.
     *
     * @return string|false|null AAAA-MM-JJ, null si vide, false si invalide
     */
    public static function date(string $value): string|false|null
    {
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        $v = preg_replace('/[ T]\d{1,2}:\d{2}(:\d{2})?$/', '', $v) ?? $v;
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $v, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return false;
        }
        return $y >= 1900 && checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : false;
    }

    /** Nombre positif (« 9,5 », « 1 234.5 ») ; null si vide, false si invalide ou hors bornes. */
    public static function number(string $value, float $max): float|false|null
    {
        $v = str_replace([' ', "\u{202F}"], '', trim($value));
        if ($v === '') {
            return null;
        }
        $v = str_replace(',', '.', $v);
        return is_numeric($v) && (float) $v >= 0 && (float) $v <= $max ? (float) $v : false;
    }

    /** Catégories de permis « B,C », « B C », « B/C » → « B,C » ; false si une lettre est inconnue. */
    public static function categories(string $value): string|false|null
    {
        $cats = array_values(array_unique(array_filter(preg_split('/[\s,;\/+-]+/', mb_strtoupper(trim($value))) ?: [])));
        if (!$cats) {
            return null;
        }
        return array_diff($cats, self::LICENSE_CATEGORIES) ? false : implode(',', $cats);
    }

    public static function plate(string $value): string
    {
        return mb_substr(mb_strtoupper(preg_replace('/\s+/', ' ', trim($value)) ?? ''), 0, 20);
    }

    /** Clé de comparaison d'une immatriculation : « 1234 ab-01 » = « 1234AB01 » (index ctx['vehicles']). */
    public static function plateKey(string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(self::normalize($value))) ?? '';
    }

    // -- En-têtes --------------------------------------------------------------

    /**
     * Colonne de chaque champ d'après la ligne d'en-tête.
     *
     * @return array<string, int>|string champ => index, ou message d'erreur
     */
    public static function headers(string $kind, array $cells): array|string
    {
        $def = self::KINDS[$kind];
        $map = [];
        foreach ($cells as $i => $cell) {
            $h = self::normalize((string) $cell);
            if ($h === '') {
                continue;
            }
            foreach ($def['columns'] as $field => [$title, $aliases]) {
                if (isset($map[$field])) {
                    continue;
                }
                foreach ([self::normalize($title), ...$aliases] as $alias) {
                    if ($h === $alias || str_starts_with($h, $alias . ' ')) {
                        $map[$field] = $i;
                        continue 3;
                    }
                }
            }
        }
        $missing = array_diff($def['required'], array_keys($map));
        if ($missing) {
            $titles = array_map(static fn($f) => '« ' . $def['columns'][$f][0] . ' »', $missing);
            return 'Première ligne : colonne ' . implode(', ', $titles) . ' introuvable. Utilisez le modèle CSV (en-têtes : '
                . implode(' ; ', array_map(static fn($c) => $c[0], $def['columns'])) . ').';
        }
        return $map;
    }

    /** Modèle CSV (UTF-8 avec BOM, séparateur « ; », lu tel quel par Excel). */
    public static function template(string $kind): string
    {
        $def = self::KINDS[$kind];
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_map(static fn($c) => $c[0], $def['columns']), ';', '"', '');
        foreach ($def['example'] as $row) {
            fputcsv($out, $row, ';', '"', '');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return "\xEF\xBB\xBF" . str_replace("\n", "\r\n", (string) $csv);
    }

    // -- Employés --------------------------------------------------------------

    /**
     * Index des employés pour resolveEmployee() : par n° de pointeuse, par nom
     * et par nom aux mots dans le désordre (« Jean Mukendi » = « MUKENDI Jean »).
     *
     * @param list<array{id:int|string, name:string, device_user_id:string, active:int|string}> $employees
     */
    public static function employeeIndex(array $employees): array
    {
        $idx = ['device' => [], 'name' => [], 'words' => [], 'rows' => []];
        foreach ($employees as $e) {
            $id = (int) $e['id'];
            $idx['rows'][$id] = $e;
            $idx['device'][self::deviceKey((string) $e['device_user_id'])] = $id;
            $n = self::normalize((string) $e['name']);
            $idx['name'][$n][] = $id;
            $idx['words'][self::words($n)][] = $id;
        }
        return $idx;
    }

    /** N° de pointeuse comparé sans casse ni zéros devant (« 0042 » = « 42 »). */
    private static function deviceKey(string $value): string
    {
        $v = mb_strtolower(trim($value));
        return ctype_digit($v) ? (ltrim($v, '0') ?: '0') : $v;
    }

    private static function words(string $normalized): string
    {
        $w = explode(' ', $normalized);
        sort($w);
        return implode(' ', $w);
    }

    /** @return array{0:?int, 1:?string} id de l'employé, ou message d'erreur */
    public static function resolveEmployee(string $value, array $idx): array
    {
        $v = trim($value);
        if ($v === '') {
            return [null, 'Employé manquant.'];
        }
        $id = $idx['device'][self::deviceKey($v)] ?? null;
        if ($id === null && ctype_digit($v)) {
            return [null, "Employé introuvable : aucun n° de pointeuse « $v »."];
        }
        if ($id === null) {
            $n = self::normalize($v);
            $ids = array_values(array_unique([...($idx['name'][$n] ?? []), ...($idx['words'][self::words($n)] ?? [])]));
            if (!$ids) {
                return [null, "Employé introuvable : « $v »."];
            }
            if (count($ids) > 1) {
                return [null, "Plusieurs employés s'appellent « $v » : indiquez son n° de pointeuse."];
            }
            $id = $ids[0];
        }
        $row = $idx['rows'][$id];
        if (!(int) $row['active']) {
            return [null, "Employé désactivé : {$row['name']}."];
        }
        return [$id, null];
    }

    private static function employeeName(int $id, array $idx): string
    {
        return (string) ($idx['rows'][$id]['name'] ?? "#$id");
    }

    // -- Analyse -----------------------------------------------------------------

    /**
     * @param list<array{line:int, cells:list<string>}> $rows lignes lues par read()
     * @param array $ctx données existantes : employees, departments, vehicles (par plateKey()),
     *                   documents (par véhicule), drivers (par employé), year (année en cours)
     * @return array{fatal:?string, lines:list<array>, counts:array<string,int>}
     */
    public static function analyze(string $kind, array $rows, array $ctx): array
    {
        $counts = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'error' => 0];
        if (!isset(self::KINDS[$kind])) {
            return ['fatal' => 'Liste inconnue.', 'lines' => [], 'counts' => $counts];
        }
        if (!$rows) {
            return ['fatal' => 'Le fichier est vide.', 'lines' => [], 'counts' => $counts];
        }
        if (count($rows) - 1 > self::MAX_ROWS) {
            return ['fatal' => 'Fichier trop long : ' . self::MAX_ROWS . ' lignes au plus.', 'lines' => [], 'counts' => $counts];
        }
        $map = self::headers($kind, $rows[0]['cells']);
        if (is_string($map)) {
            return ['fatal' => $map, 'lines' => [], 'counts' => $counts];
        }
        if (count($rows) === 1) {
            return ['fatal' => 'Le fichier ne contient que la ligne d\'en-tête.', 'lines' => [], 'counts' => $counts];
        }
        $idx = self::employeeIndex($ctx['employees'] ?? []);
        $state = [];
        $lines = [];
        foreach (array_slice($rows, 1) as $row) {
            $cells = [];
            foreach (self::KINDS[$kind]['columns'] as $field => $_) {
                $cells[$field] = isset($map[$field]) ? (string) ($row['cells'][$map[$field]] ?? '') : '';
            }
            $line = match ($kind) {
                'organisation' => self::organisationLine($cells, $ctx, $idx, $state, $row['line']),
                'vehicules'    => self::vehicleLine($cells, $ctx, $state, $row['line']),
                'chauffeurs'   => self::driverLine($cells, $ctx, $idx, $state, $row['line']),
            };
            $line['line'] = $row['line'];
            if ($line['errors']) {
                $line['status'] = 'error';
                $line['plan'] = [];
                $line['changes'] = [];
            }
            $counts[$line['status']]++;
            $lines[] = $line;
        }
        return ['fatal' => null, 'lines' => $lines, 'counts' => $counts];
    }

    /** Empreinte du plan : l'import n'est confirmé que si les données n'ont pas changé depuis l'aperçu. */
    public static function fingerprint(array $analysis): string
    {
        return sha1(json_encode(array_map(static fn($l) => [$l['line'], $l['status'], $l['plan']], $analysis['lines'])) ?: '');
    }

    private static function result(string $label, array $errors, array $changes, array $plan, bool $creates): array
    {
        return ['label' => $label, 'errors' => $errors, 'changes' => $changes, 'plan' => $plan,
                'status' => $creates ? 'create' : ($changes ? 'update' : 'unchanged')];
    }

    /**
     * Organisation : direction (créée à la racine si absente), service (créé ou
     * rattaché sous la direction), N+1 du service, ou de la direction si la
     * colonne Service est vide. Références aux départements créés plus haut
     * dans le fichier : clés « new:… » résolues à l'application.
     */
    private static function organisationLine(array $c, array $ctx, array $idx, array &$state, int $n): array
    {
        $deps = $ctx['departments'] ?? [];
        $byName = $state['byName'] ??= self::departmentsByName($deps);
        $dirName = mb_substr(preg_replace('/\s+/', ' ', $c['direction']) ?? '', 0, 100);
        $svcName = mb_substr(preg_replace('/\s+/', ' ', $c['service']) ?? '', 0, 100);
        $label = $dirName . ($svcName !== '' ? ' › ' . $svcName : '');
        $errors = [];
        $head = null;
        if ($dirName === '') {
            $errors[] = 'Direction manquante.';
        }
        if ($c['head'] !== '') {
            [$head, $err] = self::resolveEmployee($c['head'], $idx);
            if ($err) {
                $errors[] = 'N+1 : ' . $err;
            }
        }
        $dirKey = self::normalize($dirName);
        $svcKey = self::normalize($svcName);
        if ($svcName !== '' && $svcKey === $dirKey) {
            $errors[] = 'Le service porte le même nom que la direction.';
        }
        if ($svcName !== '' && isset($state['svc'][$svcKey])) {
            $errors[] = "Doublon : service « $svcName » déjà présent ligne {$state['svc'][$svcKey]}.";
        }
        if ($svcName === '' && $head !== null && isset($state['dirHead'][$dirKey]) && $state['dirHead'][$dirKey][1] !== $head) {
            $errors[] = "N+1 de la direction déjà indiqué ligne {$state['dirHead'][$dirKey][0]}.";
        }

        // Direction
        $dir = null;     // id existant ou clé « new:d:… »
        $plan = [];
        $changes = [];
        $creates = false;
        if (!$errors) {
            if (isset($state['dir'][$dirKey])) {
                $dir = $state['dir'][$dirKey];
            } else {
                $found = $byName[$dirKey] ?? [];
                if (count($found) > 1) {
                    $roots = array_values(array_filter($found, static fn($id) => $deps[$id]['parent_id'] === null));
                    $found = count($roots) === 1 ? $roots : $found;
                }
                if (count($found) > 1) {
                    $errors[] = "Plusieurs départements s'appellent « $dirName » : renommez-les dans Départements.";
                } elseif ($found) {
                    $dir = $found[0];
                    if ($deps[$dir]['kind'] !== 'direction') {
                        $plan[] = ['op' => 'update', 'ref' => $dir, 'set' => ['kind' => 'direction']];
                        $changes[] = "« {$deps[$dir]['name']} » devient une direction";
                    }
                } else {
                    $dir = 'new:d:' . $dirKey;
                    $plan[] = ['op' => 'create', 'key' => $dir, 'name' => $dirName, 'kind' => 'direction', 'parent' => null, 'head' => null];
                    $changes[] = "Direction « $dirName » créée";
                    $creates = true;
                }
                if (!$errors) {
                    $state['dir'][$dirKey] = $dir;
                }
            }
        }

        // Service et N+1
        if (!$errors && $svcName !== '') {
            $found = array_values(array_filter($byName[$svcKey] ?? [], static fn($id) => $id !== $dir));
            if (count($found) > 1) {
                $under = array_values(array_filter($found, static fn($id) => $deps[$id]['parent_id'] === $dir));
                $found = count($under) === 1 ? $under : $found;
            }
            if (count($found) > 1) {
                $errors[] = "Plusieurs départements s'appellent « $svcName » : renommez-les dans Départements.";
            } elseif ($found) {
                $svc = $found[0];
                $set = [];
                if ($deps[$svc]['parent_id'] !== $dir) {
                    if (is_int($dir) && in_array($dir, self::descendants($svc, $deps), true)) {
                        $errors[] = "« $dirName » est déjà sous « $svcName » : le service ne peut pas passer sous elle.";
                    }
                    $set['parent'] = $dir;
                    $changes[] = "« {$deps[$svc]['name']} » rattaché à « $dirName »";
                }
                if ($deps[$svc]['kind'] !== 'service') {
                    $set['kind'] = 'service';
                    $changes[] = "« {$deps[$svc]['name']} » devient un service";
                }
                if ($head !== null && $deps[$svc]['head_employee_id'] !== $head) {
                    $set['head'] = $head;
                    $changes[] = 'N+1 : ' . self::employeeName($head, $idx);
                }
                if ($set) {
                    $plan[] = ['op' => 'update', 'ref' => $svc, 'set' => $set];
                }
            } else {
                $plan[] = ['op' => 'create', 'key' => 'new:s:' . $svcKey, 'name' => $svcName, 'kind' => 'service', 'parent' => $dir, 'head' => $head];
                $changes[] = "Service « $svcName » créé" . ($head !== null ? ', N+1 : ' . self::employeeName($head, $idx) : '');
                $creates = true;
            }
            if (!$errors) {
                $state['svc'][$svcKey] = $n;
            }
        } elseif (!$errors && $head !== null) {
            $current = is_int($dir) ? $deps[$dir]['head_employee_id'] : null;
            if (!isset($state['dirHead'][$dirKey]) && $current !== $head) {
                $created = $plan && $plan[array_key_last($plan)]['op'] === 'create';
                if ($created) {
                    $plan[array_key_last($plan)]['head'] = $head;
                } else {
                    $plan[] = ['op' => 'update', 'ref' => $dir, 'set' => ['head' => $head]];
                }
                $changes[] = 'N+1 de la direction : ' . self::employeeName($head, $idx);
            }
            $state['dirHead'][$dirKey] = [$n, $head];
        }
        return self::result($label, $errors, $changes, $plan, $creates);
    }

    /** @return array<string, list<int>> nom normalisé => ids */
    private static function departmentsByName(array $deps): array
    {
        $out = [];
        foreach ($deps as $id => $d) {
            $out[self::normalize((string) $d['name'])][] = (int) $id;
        }
        return $out;
    }

    /** @return list<int> le département et ses sous-départements */
    private static function descendants(int $id, array $deps): array
    {
        $ids = [$id];
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($deps as $d) {
                if ($d['parent_id'] === $ids[$i] && !in_array((int) $d['id'], $ids, true)) {
                    $ids[] = (int) $d['id'];
                }
            }
        }
        return $ids;
    }

    /**
     * Véhicule : créé si l'immatriculation est nouvelle (marque et modèle
     * obligatoires), sinon mis à jour. Une échéance différente de la dernière
     * connue ajoute un document (assurance, contrôle technique).
     */
    private static function vehicleLine(array $c, array $ctx, array &$state, int $n): array
    {
        $key = self::plateKey($c['plate']);
        $existing = $key !== '' ? ($ctx['vehicles'][$key] ?? null) : null;
        // Fiche existante : son immatriculation garde son écriture
        $plate = $existing ? (string) $existing['plate'] : self::plate($c['plate']);
        $errors = [];
        if ($key === '') {
            $errors[] = 'Immatriculation manquante.';
        } elseif (isset($state['plates'][$key])) {
            $errors[] = "Doublon d'immatriculation : « $plate » déjà ligne {$state['plates'][$key]}.";
        } else {
            $state['plates'][$key] = $n;
        }
        $set = [];
        foreach (['brand' => 'Marque', 'model' => 'Modèle'] as $f => $title) {
            $v = mb_substr(preg_replace('/\s+/', ' ', $c[$f]) ?? '', 0, 60);
            if ($v !== '') {
                $set[$f] = $v;
            } elseif (!$existing) {
                $errors[] = "$title manquant(e) pour un nouveau véhicule.";
            }
        }
        if ($c['year'] !== '') {
            $year = ctype_digit($c['year']) ? (int) $c['year'] : 0;
            $max = (int) ($ctx['year'] ?? date('Y')) + 1;
            if ($year < 1950 || $year > $max) {
                $errors[] = "Année invalide : « {$c['year']} » (1950 à $max).";
            } else {
                $set['year'] = $year;
            }
        }
        if ($c['fuel_type'] !== '') {
            $fuel = self::FUELS[self::normalize($c['fuel_type'])] ?? null;
            if ($fuel === null) {
                $errors[] = "Carburant inconnu : « {$c['fuel_type']} » (diesel, essence, hybride, électrique).";
            } else {
                $set['fuel_type'] = $fuel;
            }
        }
        foreach (['tank_liters' => ['Réservoir', 2000], 'ref_consumption' => ['Consommation de référence', 100]] as $f => [$title, $max]) {
            $v = self::number($c[$f], $max);
            if ($v === false) {
                $errors[] = "$title invalide : « {$c[$f]} » (nombre de 0 à $max).";
            } elseif ($v !== null) {
                $set[$f] = $v;
            }
        }
        if ($c['status'] !== '') {
            $status = self::STATUSES[self::normalize($c['status'])] ?? null;
            if ($status === null) {
                $errors[] = "Statut inconnu : « {$c['status']} » (disponible, en panne, en entretien).";
            } elseif ($status === 'en_mission' && ($existing['status'] ?? '') !== 'en_mission') {
                $errors[] = 'Statut « En mission » : il est donné par le démarrage d\'une mission, pas par l\'import.';
            } elseif ($existing && $existing['status'] === 'en_mission' && $status !== 'en_mission' && !empty($existing['busy'])) {
                $errors[] = 'Ce véhicule est en mission : son état changera à la clôture de la mission.';
            } else {
                $set['status'] = $status;
            }
        }
        $docs = [];
        foreach (['assurance' => 'assurance', 'controle_technique' => 'contrôle technique'] as $kind => $title) {
            $d = self::date($c[$kind]);
            if ($d === false) {
                $errors[] = "Date invalide ($title) : « {$c[$kind]} » (JJ/MM/AAAA ou AAAA-MM-JJ).";
            } elseif ($d !== null) {
                $docs[$kind] = $d;
            }
        }
        if ($errors) {
            return self::result($plate, $errors, [], [], false);
        }

        $labels = ['brand' => 'marque', 'model' => 'modèle', 'year' => 'année', 'fuel_type' => 'carburant',
                   'tank_liters' => 'réservoir', 'ref_consumption' => 'consommation', 'status' => 'statut'];
        $current = $existing ? ($ctx['documents'][(int) $existing['id']] ?? []) : [];
        $changes = [];
        if ($existing) {
            $set = array_filter($set, static fn($v, $f) => $existing[$f] === null
                || (is_float($v) ? abs((float) $existing[$f] - $v) > 0.001 : (string) $existing[$f] !== (string) $v), ARRAY_FILTER_USE_BOTH);
            foreach ($set as $f => $v) {
                $label = static fn($x) => is_string($x) ? (self::LABELS[$x] ?? $x) : $x;
                $changes[] = $labels[$f] . ' : ' . self::shown($label($existing[$f])) . ' → ' . self::shown($label($v));
            }
            if ($changes && !(int) ($existing['active'] ?? 1)) {
                $changes[] = 'véhicule retiré du parc (reste retiré)';
            }
        } else {
            $changes[] = 'Nouveau véhicule : ' . $set['brand'] . ' ' . $set['model'];
        }
        $docs = array_filter($docs, static fn($d, $k) => ($current[$k] ?? null) !== $d, ARRAY_FILTER_USE_BOTH);
        foreach ($docs as $k => $d) {
            $changes[] = ($k === 'assurance' ? 'assurance' : 'contrôle technique') . ' jusqu\'au ' . self::dateFr($d)
                . (isset($current[$k]) ? ' (avant : ' . self::dateFr($current[$k]) . ')' : '');
        }
        $plan = ($set || $docs || !$existing)
            ? [['op' => $existing ? 'update' : 'create', 'id' => $existing ? (int) $existing['id'] : null, 'plate' => $plate, 'set' => $set, 'docs' => $docs]]
            : [];
        return self::result($plate, [], $changes, $plan, !$existing);
    }

    /** Chauffeur : créé pour l'employé, ou permis mis à jour s'il est déjà chauffeur. */
    private static function driverLine(array $c, array $ctx, array $idx, array &$state, int $n): array
    {
        $errors = [];
        [$emp, $err] = self::resolveEmployee($c['employee'], $idx);
        $label = $emp !== null ? self::employeeName($emp, $idx) : $c['employee'];
        if ($err) {
            $errors[] = $err;
        } elseif (isset($state['employees'][$emp])) {
            $errors[] = "Doublon : {$label} déjà ligne {$state['employees'][$emp]}.";
        } else {
            $state['employees'][$emp] = $n;
        }
        $set = [];
        if ($c['license_number'] !== '') {
            $set['license_number'] = mb_substr($c['license_number'], 0, 40);
        }
        $cats = self::categories($c['license_category']);
        if ($cats === false) {
            $errors[] = "Catégorie de permis invalide : « {$c['license_category']} » (lettres A à G, ex. B,C).";
        } elseif ($cats !== null) {
            $set['license_category'] = $cats;
        }
        $exp = self::date($c['license_expires']);
        if ($exp === false) {
            $errors[] = "Date invalide (expiration du permis) : « {$c['license_expires']} » (JJ/MM/AAAA ou AAAA-MM-JJ).";
        } elseif ($exp !== null) {
            $set['license_expires'] = $exp;
        }
        if ($errors) {
            return self::result($label, $errors, [], [], false);
        }
        $existing = $ctx['drivers'][$emp] ?? null;
        $labels = ['license_number' => 'n° de permis', 'license_category' => 'catégorie', 'license_expires' => 'expiration'];
        $changes = [];
        if ($existing) {
            $set = array_filter($set, static fn($v, $f) => (string) $existing[$f] !== (string) $v, ARRAY_FILTER_USE_BOTH);
            foreach ($set as $f => $v) {
                $changes[] = $labels[$f] . ' : ' . self::shown($existing[$f], $f === 'license_expires') . ' → ' . self::shown($v, $f === 'license_expires');
            }
        } else {
            $changes[] = 'Nouveau chauffeur' . (isset($set['license_category']) ? ', permis ' . $set['license_category'] : '')
                . (isset($set['license_expires']) ? ' jusqu\'au ' . self::dateFr($set['license_expires']) : '');
        }
        $plan = ($set || !$existing)
            ? [['op' => $existing ? 'update' : 'create', 'id' => $existing ? (int) $existing['id'] : null, 'employee_id' => $emp, 'set' => $set]]
            : [];
        return self::result($label, [], $changes, $plan, !$existing);
    }

    private static function shown(mixed $v, bool $date = false): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        if ($date) {
            return self::dateFr((string) $v);
        }
        return is_float($v) || (is_string($v) && is_numeric($v) && str_contains($v, '.'))
            ? rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',') : (string) $v;
    }

    private static function dateFr(string $ymd): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) ? "$m[3]/$m[2]/$m[1]" : $ymd;
    }
}
