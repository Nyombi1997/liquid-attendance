<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Suivi des migrations de la base (table schema_migrations) : fichiers
 * database/migrations/NNN_nom.sql appliqués une seule fois, dans l'ordre, par
 * bin/migrate.php. Lu aussi par bin/fleet-tasks.php, le worker (au démarrage)
 * et Paramètres › État du service, qui signalent une migration en retard au
 * lieu de planter.
 *
 * schema_migrations.mode :
 *   applique : appliquée par bin/migrate.php (empreinte sha256 du fichier)
 *   baseline : marquée comme déjà appliquée (bin/migrate.php --baseline)
 *   schema   : incluse dans database/schema.sql (installation neuve, sans empreinte)
 */
final class Migrations
{
    public const TABLE_SQL = "CREATE TABLE IF NOT EXISTS schema_migrations (
  name       VARCHAR(190) NOT NULL PRIMARY KEY COMMENT 'fichier de database/migrations',
  applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  checksum   CHAR(64)     NULL COMMENT 'sha256 du fichier au moment de l''application',
  mode       VARCHAR(10)  NOT NULL DEFAULT 'applique' COMMENT 'applique, baseline ou schema'
) ENGINE=InnoDB";

    /** Instructions qui valident implicitement la transaction en cours (MariaDB). */
    private const DDL = '/^\s*(CREATE|ALTER|DROP|RENAME|TRUNCATE|GRANT|REVOKE|LOCK|UNLOCK)\b/i';

    public static function dir(): string
    {
        return BASE_PATH . '/database/migrations';
    }

    /** @return array<string, string> nom du fichier → chemin, dans l'ordre d'application */
    public static function files(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.sql') ?: [] as $path) {
            $name = basename($path);
            if (preg_match('/^\d{3}_[A-Za-z0-9_-]+\.sql$/', $name)) {
                $out[$name] = $path;
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    public static function tracked(PDO $pdo): bool
    {
        return (bool) $pdo->query("SELECT COUNT(*) FROM information_schema.tables
                                    WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'")->fetchColumn();
    }

    /** @return array<string, array{name:string, applied_at:string, checksum:?string, mode:string}> */
    public static function applied(PDO $pdo): array
    {
        if (!self::tracked($pdo)) {
            return [];
        }
        $out = [];
        foreach ($pdo->query('SELECT name, applied_at, checksum, mode FROM schema_migrations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['name']] = $r;
        }
        return $out;
    }

    /** @return string[] migrations présentes mais pas encore appliquées */
    public static function pending(PDO $pdo): array
    {
        return array_values(array_diff(array_keys(self::files()), array_keys(self::applied($pdo))));
    }

    /**
     * Message d'alerte si la base est en retard, sinon null :
     * « migration 007_roles_texte.sql non appliquée : lancez php bin/migrate.php ».
     * Ne lève jamais d'exception (base injoignable : message d'erreur).
     */
    public static function warning(?PDO $pdo = null): ?string
    {
        try {
            $pdo ??= Database::pdo();
            if (!self::tracked($pdo)) {
                return 'suivi des migrations absent (table schema_migrations) : lancez php bin/migrate.php'
                    . ' (première fois sur une base existante : php bin/migrate.php --baseline, voir le README)';
            }
            $pending = self::pending($pdo);
        } catch (\Throwable $e) {
            return 'vérification des migrations impossible : ' . $e->getMessage();
        }
        if (!$pending) {
            return null;
        }
        return (count($pending) > 1 ? 'migrations ' . implode(', ', $pending) . ' non appliquées' : 'migration ' . $pending[0] . ' non appliquée')
            . ' : lancez php bin/migrate.php';
    }

    public static function checksum(string $path): string
    {
        return hash_file('sha256', $path);
    }

    /** La migration contient-elle du DDL (pas de transaction possible) ? */
    public static function hasDdl(array $statements): bool
    {
        foreach ($statements as $s) {
            if (preg_match(self::DDL, $s)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Découpe un fichier SQL en instructions (séparateur « ; » hors chaînes et
     * commentaires). Commentaires « -- », « # » et « /* … *\/ » retirés.
     * Pas de DELIMITER ni de procédures stockées dans les migrations.
     *
     * @return string[]
     */
    public static function split(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($c === '-' && $next === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) {
                $i = ($p = strpos($sql, "\n", $i)) === false ? $len : $p;
                $buf .= "\n";
                continue;
            }
            if ($c === '#') {
                $i = ($p = strpos($sql, "\n", $i)) === false ? $len : $p;
                $buf .= "\n";
                continue;
            }
            if ($c === '/' && $next === '*') {
                $i = ($p = strpos($sql, '*/', $i + 2)) === false ? $len : $p + 1;
                $buf .= ' ';
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $buf .= $c;
                for ($i++; $i < $len; $i++) {
                    $buf .= $sql[$i];
                    if ($sql[$i] === '\\' && $c !== '`') {
                        $buf .= $sql[++$i] ?? '';
                    } elseif ($sql[$i] === $c) {
                        if (($sql[$i + 1] ?? '') === $c) {   // '' ou "" doublé
                            $buf .= $sql[++$i];
                        } else {
                            break;
                        }
                    }
                }
                continue;
            }
            if ($c === ';') {
                if (trim($buf) !== '') {
                    $out[] = trim($buf);
                }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }
        return $out;
    }
}
