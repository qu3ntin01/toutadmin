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

// La version de PHP ne regarde personne : « expose_php » l'annonce dans un
// en-tête sur chaque réponse, et c'est la première chose qu'on cherche pour
// choisir une faille. On le retire ici, quel que soit le réglage de l'hébergeur.
header_remove('X-Powered-By');

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

    // Une erreur qui n'existe que dans le journal du serveur n'existe pas pour
    // qui administre l'instance depuis un navigateur : elle entre aussi au
    // centre de journaux, d'où elle part vers le collecteur.
    try {
        \App\Modules\Logs::error('systeme', 'erreur.non_rattrapee', mb_substr($error->getMessage(), 0, 300), [
            'context' => [
                'chemin' => mb_substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 200),
                'methode' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
                'pile' => mb_substr(implode(' | ', array_slice(explode("\n", $error->getTraceAsString()), 0, 6)), 0, 1200),
            ],
        ]);
    } catch (\Throwable) {
        // La base est peut-être justement ce qui vient de tomber : on ne
        // transforme pas une erreur en deux.
    }
    // Le détail d'une erreur ne sort jamais vers le visiteur : il renseigne
    // autant l'attaquant que l'administrateur.
    $response = Response::html(
        '<!doctype html><html lang="fr"><meta charset="utf-8">'
        . '<title>Erreur</title><h1>Une erreur est survenue</h1>'
        . '<p>Merci de réessayer dans un instant.</p></html>',
        500,
    )->withHeaders([
            'Content-Type' => 'text/html; charset=UTF-8',
            /*
             * La page de dernier recours porte les mêmes en-têtes que les
             * autres, et c'est le cas où cela compte le plus.
             *
             * Elle est servie hors du noyau — c'est tout l'intérêt : elle
             * répond même quand le noyau est ce qui vient de tomber. Mais elle
             * sortait de ce fait sans politique de contenu, sans « nosniff »,
             * sans rien : la seule réponse servie quand quelque chose a mal
             * tourné était la seule sans protection.
             *
             * « default-src 'none' » et rien d'autre : cette page ne charge ni
             * script, ni feuille, ni image. Elle ne portait un style en ligne
             * que pour faire joli, et une page qui ne dépend de rien est
             * exactement ce qu'on veut le jour où plus rien ne marche.
             *
             * « no-store » : un 500 gardé par un cache continuerait de
             * s'afficher une fois la panne réparée.
             */
            'Content-Security-Policy' =>
                "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
        ]);
}

$response->send();
