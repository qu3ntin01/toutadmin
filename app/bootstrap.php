<?php

/**
 * Amorçage de l'édition PHP de Toutadmin.
 *
 * Aucune dépendance, aucun gestionnaire de paquets : le dossier se dépose tel
 * quel sur un hébergement mutualisé. Les classes se chargent par convention —
 * App\Core\Db vit dans app/Core/Db.php — ce qui évite un autoloader engendré
 * qu'il faudrait régénérer à chaque ajout de fichier.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_DIR', __DIR__);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, 4));
    $file = APP_DIR . '/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once APP_DIR . '/Core/helpers.php';

\App\Core\Config::load();
