<?php

/**
 * Tâche planifiée de l'hébergeur.
 *
 * Un site PHP ne tourne qu'au moment d'une requête : ce que l'édition Node fait
 * dans son balayage horaire se fait ici depuis le cron de l'hébergeur.
 *
 *     * * * * * /usr/bin/php /chemin/vers/tools/cron.php >> /chemin/cron.log 2>&1
 *
 * Rien ne se déclenche avant l'échéance : appeler le script plus souvent que
 * l'intervalle configuré ne sauvegarde pas plus souvent.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Db;
use App\Modules\Backup;
use App\Modules\Mailbox;
use App\Modules\Users;

Db::migrate();

// Un contrat arrivé à terme ferme le compte : c'est le même balayage.
$closed = Users::deactivateExpiredContracts();
if ($closed > 0) {
    echo "$closed compte(s) fermé(s) : contrat arrivé à terme.\n";
}

// Relève de la boîte aux lettres comptable : les factures reçues par courriel
// arrivent dans la corbeille du comptable sans qu'on y pense. Une boîte
// injoignable n'emporte pas le reste du balayage avec elle.
if (Mailbox::config()['enabled'] && Mailbox::isReady()) {
    try {
        $fetched = Mailbox::fetchOnce();
        if (!empty($fetched['received']) || empty($fetched['ok'])) {
            echo 'Pièces reçues : ' . $fetched['message'] . "\n";
        }
    } catch (\Throwable $error) {
        echo 'Relève de la boîte aux lettres interrompue : ' . $error->getMessage() . "\n";
    }
}

$created = Backup::runScheduled();
if ($created === null) {
    echo "Rien à faire : l'intervalle de sauvegarde n'est pas écoulé.\n";
    exit(0);
}

echo 'Sauvegarde ' . $created['fileName'] . ' créée (' . $created['files'] . " fichier(s)).\n";
if ($created['removed'] !== []) {
    echo count($created['removed']) . " archive(s) au-delà du nombre conservé supprimée(s).\n";
}
foreach ($created['offsite'] as $sent) {
    echo $sent['ok']
        ? 'Externalisée vers ' . $sent['key'] . ".\n"
        : 'Externalisation vers ' . $sent['key'] . ' en échec : ' . $sent['message'] . "\n";
}
