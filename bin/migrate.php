<?php
declare(strict_types=1);

/*
 * Migrations de la base : applique dans l'ordre les fichiers
 * database/migrations/NNN_nom.sql qui ne sont pas encore enregistrés dans la
 * table schema_migrations (nom, date, empreinte sha256), puis les enregistre.
 * À lancer dans le conteneur worker après chaque mise à jour du code :
 *
 *   sudo docker compose exec worker php bin/migrate.php              appliquer ce qui manque
 *   sudo docker compose exec worker php bin/migrate.php --dry-run    lister sans rien changer
 *   sudo docker compose exec worker php bin/migrate.php --check      vérifier (code 3 si en retard)
 *   sudo docker compose exec worker php bin/migrate.php --baseline=006
 *        marquer comme déjà appliquées les migrations jusqu'à 006 incluse
 *        (--baseline seul : toutes celles présentes), sans les exécuter.
 *        Une seule fois, sur une base existante créée avant le suivi des migrations.
 *
 * Chaque migration est appliquée dans une transaction si elle ne contient que
 * des données (INSERT, UPDATE…). Une migration qui modifie la structure
 * (CREATE, ALTER…) ne peut pas l'être (MariaDB valide chaque instruction de
 * structure) : elle est appliquée instruction par instruction, et doit donc
 * pouvoir être rejouée sans risque (IF NOT EXISTS…) si elle échoue en route.
 * Arrêt à la première erreur. Base vide : charge database/schema.sql et seed.sql.
 *
 * Codes de sortie : 0 succès (ou à jour), 1 erreur, 2 option invalide, 3 migrations en attente (--check).
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Migrations;
use App\Core\Database;

$opts = ['dry-run' => false, 'check' => false, 'baseline' => null];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $opts['dry-run'] = true;
    } elseif ($arg === '--check') {
        $opts['check'] = true;
    } elseif ($arg === '--baseline') {
        $opts['baseline'] = '';
    } elseif (preg_match('/^--baseline=(\d{3})$/', $arg, $m)) {
        $opts['baseline'] = $m[1];
    } else {
        fwrite(STDERR, "Option inconnue : $arg\nUsage : php bin/migrate.php [--dry-run] [--check] [--baseline[=NNN]]\n");
        exit(2);
    }
}

$say = static fn(string $line) => print('[migrations] ' . $line . "\n");
$fail = static function (string $line): never {
    fwrite(STDERR, '[migrations] ERREUR : ' . $line . "\n");
    exit(1);
};

try {
    $pdo = Database::pdo();
} catch (\Throwable $e) {
    $fail('connexion à la base impossible (' . $e->getMessage() . ').');
}

// Vérification seule (worker au démarrage) : rien n'est modifié
if ($opts['check']) {
    $warning = Migrations::warning($pdo);
    if ($warning === null) {
        $say('base à jour (' . count(Migrations::files()) . ' migrations appliquées).');
        exit(0);
    }
    fwrite(STDERR, '[migrations] ATTENTION : ' . $warning . "\n");
    exit(3);
}

// Une seule exécution à la fois (deux déploiements lancés en même temps)
if ((int) $pdo->query("SELECT GET_LOCK('liquid_attendance_migrate', 30)")->fetchColumn() !== 1) {
    $fail('une autre exécution de bin/migrate.php est en cours.');
}

/** Exécute une liste d'instructions ; renvoie [n° de l'instruction en échec, message] ou null. */
$run = static function (PDO $pdo, array $statements): ?array {
    foreach ($statements as $i => $sql) {
        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            return [$i + 1, $e->getMessage()];
        }
    }
    return null;
};
$excerpt = static fn(string $sql): string => mb_substr(preg_replace('/\s+/', ' ', $sql) ?? '', 0, 110);

// Base vide : installation neuve (comme le premier démarrage du conteneur db)
$tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
if ($tables === 0) {
    $files = ['schema.sql', 'seed.sql'];
    if ($opts['dry-run']) {
        $say('base vide : database/' . implode(' puis database/', $files) . ' seraient chargés.');
        exit(0);
    }
    foreach ($files as $file) {
        $statements = Migrations::split((string) file_get_contents(BASE_PATH . '/database/' . $file));
        if ($error = $run($pdo, $statements)) {
            $fail("database/$file, instruction n° {$error[0]} (« {$excerpt($statements[$error[0] - 1])} ») : {$error[1]}");
        }
        $say("base vide : database/$file chargé (" . count($statements) . ' instructions).');
    }
}

$files = Migrations::files();
if (!$opts['dry-run']) {
    $pdo->exec(Migrations::TABLE_SQL);
}
$applied = Migrations::applied($pdo);

// Fichiers modifiés ou disparus depuis leur application : signalés, sans bloquer
foreach ($applied as $name => $row) {
    if (!isset($files[$name])) {
        $say("attention : $name est enregistrée mais le fichier n'existe plus.");
    } elseif ($row['checksum'] !== null && $row['checksum'] !== Migrations::checksum($files[$name])) {
        $say("attention : $name a été modifiée depuis son application le {$row['applied_at']} (empreinte différente) ; elle n'est pas rejouée.");
    }
}

$pending = array_values(array_diff(array_keys($files), array_keys($applied)));

// --baseline : marquer comme appliquées, sans les exécuter
if ($opts['baseline'] !== null) {
    $upto = $opts['baseline'];
    $mark = array_values(array_filter($pending, static fn(string $n) => $upto === '' || substr($n, 0, 3) <= $upto));
    if ($upto !== '' && !array_filter(array_keys($files), static fn(string $n) => substr($n, 0, 3) === $upto)) {
        $fail("aucune migration $upto dans database/migrations.");
    }
    if (!$mark) {
        $say('rien à marquer : ' . ($upto === '' ? 'toutes les migrations' : "les migrations jusqu'à $upto") . ' sont déjà enregistrées.');
        exit(0);
    }
    foreach ($mark as $name) {
        if ($opts['dry-run']) {
            $say("serait marquée comme déjà appliquée : $name");
            continue;
        }
        $st = $pdo->prepare("INSERT INTO schema_migrations (name, checksum, mode) VALUES (?, ?, 'baseline')");
        $st->execute([$name, Migrations::checksum($files[$name])]);
        $say("marquée comme déjà appliquée (baseline) : $name");
    }
    $rest = array_values(array_diff($pending, $mark));
    if ($rest) {
        $say('restent à appliquer : ' . implode(', ', $rest) . ' → lancez php bin/migrate.php');
    }
    exit(0);
}

if (!$pending) {
    $say('base à jour : aucune migration à appliquer (' . count($files) . ' enregistrées).');
    exit(0);
}

foreach ($pending as $name) {
    $statements = Migrations::split((string) file_get_contents($files[$name]));
    $ddl = Migrations::hasDdl($statements);
    $how = count($statements) . ' instruction(s), ' . ($ddl ? 'sans transaction (modification de structure)' : 'dans une transaction');
    if ($opts['dry-run']) {
        $say("à appliquer : $name ($how)");
        continue;
    }
    $start = microtime(true);
    if (!$ddl) {
        $pdo->beginTransaction();
    }
    $error = $run($pdo, $statements);
    if ($error === null) {
        try {
            $st = $pdo->prepare("INSERT INTO schema_migrations (name, checksum, mode) VALUES (?, ?, 'applique')");
            $st->execute([$name, Migrations::checksum($files[$name])]);
        } catch (\PDOException $e) {
            $error = [0, 'enregistrement dans schema_migrations impossible : ' . $e->getMessage()];
        }
    }
    if ($error !== null) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $where = $error[0] > 0 ? "instruction n° {$error[0]} (« {$excerpt($statements[$error[0] - 1])} ») : " : '';
        $fail("échec de $name, $where{$error[1]}\n"
            . ($ddl ? "[migrations] Les instructions précédentes de $name sont déjà appliquées (structure). Corrigez la cause puis relancez php bin/migrate.php.\n"
                    : "[migrations] Rien n'a été modifié par $name (transaction annulée). Corrigez la cause puis relancez php bin/migrate.php.\n")
            . '[migrations] Les migrations suivantes n\'ont pas été appliquées.');
    }
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
    $say(sprintf('appliquée : %s (%s, %.1f s)', $name, $how, microtime(true) - $start));
}
if (!$opts['dry-run']) {
    $say('terminé : ' . count($pending) . ' migration(s) appliquée(s).');
}
