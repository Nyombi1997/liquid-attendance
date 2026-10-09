<?php
declare(strict_types=1);

/*
 * Chargement commun (web et ligne de commande) : autoload, configuration, helpers.
 */

define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', __DIR__);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require APP_PATH . '/Core/helpers.php';

$config = require BASE_PATH . '/config/config.php';
App\Core\Config::load($config);
date_default_timezone_set(App\Core\Config::get('timezone'));
