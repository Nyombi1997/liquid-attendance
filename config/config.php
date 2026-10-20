<?php
declare(strict_types=1);

// Configuration lue depuis les variables d'environnement (.env via docker compose)
$env = static fn(string $k, ?string $d = null) => ($v = getenv($k)) !== false && $v !== '' ? $v : $d;

return [
    'app_name' => $env('APP_NAME', 'Liquid Présence'),
    'app_url'  => $env('APP_URL', 'https://41.174.155.178:8443'),
    'timezone' => $env('APP_TIMEZONE', 'Africa/Kinshasa'),
    'db' => [
        'host'     => $env('DB_HOST', 'db'),
        'port'     => (int) $env('DB_PORT', '3306'),
        'name'     => $env('DB_NAME', 'liquid_attendance'),
        'user'     => $env('DB_USER', 'liquid'),
        'password' => $env('DB_PASSWORD', ''),
    ],
    // Fichiers déposés dans la plateforme (photos des agents : <storage>/photos/<id>.jpg).
    // Monté sur ./data/storage par docker compose : conservé lors des mises à jour.
    'storage_path' => $env('STORAGE_PATH', dirname(__DIR__) . '/storage'),
    // Cookie de session réservé au HTTPS (désactivable pour un test local en HTTP)
    'session_secure' => $env('SESSION_SECURE', '1') !== '0',
    // E-mails des notifications (Parc automobile), envoyés par le worker.
    // Sans SMTP_HOST ni SMTP_FROM : aucun envoi, notifications dans l'application seulement.
    'mail' => [
        'host'      => $env('SMTP_HOST', ''),
        'port'      => (int) $env('SMTP_PORT', '587'),
        'secure'    => $env('SMTP_SECURE', 'tls'),   // tls (STARTTLS), ssl (port 465) ou none
        'user'      => $env('SMTP_USER', ''),
        'password'  => $env('SMTP_PASSWORD', ''),
        'from'      => $env('SMTP_FROM', ''),
        'from_name' => $env('SMTP_FROM_NAME', $env('APP_NAME', 'Liquid Présence')),
    ],
    // Connexion : 5 échecs en 15 minutes bloquent le couple identifiant/IP
    'login_max_attempts'  => 5,
    'login_window_minutes'=> 15,
];
