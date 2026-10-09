<?php
declare(strict_types=1);

/*
 * Création (ou réinitialisation) d'un compte administrateur.
 *   docker compose exec app php bin/create-admin.php admin "Nom Complet"
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;

$username = $argv[1] ?? null;
$fullName = $argv[2] ?? 'Administrateur';
if (!$username || !preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username)) {
    fwrite(STDERR, "Usage : php bin/create-admin.php <identifiant> [\"Nom complet\"]\n");
    exit(1);
}

function ask(string $prompt): string
{
    echo $prompt;
    if (function_exists('posix_isatty') ? posix_isatty(STDIN) : true) {
        @shell_exec('stty -echo');
    }
    $value = trim((string) fgets(STDIN));
    @shell_exec('stty echo');
    echo "\n";
    return $value;
}

$password = ask('Mot de passe (12 caractères minimum) : ');
if (mb_strlen($password) < 12) {
    fwrite(STDERR, "Mot de passe trop court.\n");
    exit(1);
}
if (ask('Confirmation : ') !== $password) {
    fwrite(STDERR, "Les deux saisies ne correspondent pas.\n");
    exit(1);
}

Database::run(
    "INSERT INTO users (username, full_name, password_hash, role, active) VALUES (?, ?, ?, 'admin', 1)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = 'admin', active = 1",
    [$username, $fullName, password_hash($password, PASSWORD_DEFAULT)]
);
echo "Compte administrateur « $username » prêt.\n";
