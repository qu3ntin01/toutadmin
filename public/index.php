<?php

/**
 * Point d'entrée unique.
 *
 * Seul ce dossier est exposé par le serveur web : le code, la base et les
 * fichiers déposés vivent au-dessus, hors d'atteinte d'une requête. C'est la
 * différence entre « on ne peut pas télécharger la base » et « on espère que
 * personne n'essaiera ».
 */

declare(strict_types=1);

// Serveur intégré de PHP (php -S), utilisé pour essayer le site en local :
// il appelle ce script pour toute requête, y compris les fichiers qui
// existent. On les lui rend, sinon la feuille de style ne serait jamais
// servie. Apache et Nginx, eux, ne passent ici que pour les vraies routes.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    if (is_file($file)) {
        return false;
    }
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Db;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;

try {
    Db::migrate();
    $kernel = new Kernel();
    $response = $kernel->handle(Request::fromGlobals());
} catch (\Throwable $error) {
    error_log('toutadmin: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine());
    // Le détail d'une erreur ne sort jamais vers le visiteur : il renseigne
    // autant l'attaquant que l'administrateur.
    $response = Response::html('<!doctype html><meta charset="utf-8"><title>Erreur</title>'
        . '<p style="font:16px system-ui;margin:3rem">Une erreur est survenue. Merci de réessayer.</p>', 500);
}

$response->send();
