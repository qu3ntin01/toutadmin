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

use App\Core\Audit;
use App\Core\Db;
use App\Core\Settings;
use App\Modules\Backup;
use App\Modules\Billing;
use App\Modules\Deadlines;
use App\Modules\Mailbox;
use App\Modules\Notifications;
use App\Modules\Users;
use App\Modules\Webhooks;

Db::migrate();

// Un contrat arrivé à terme ferme le compte : c'est le même balayage.
$closed = Users::deactivateExpiredContracts();
if ($closed > 0) {
    echo "$closed compte(s) fermé(s) : contrat arrivé à terme.\n";
}

// Facturation récurrente : les échéances atteintes partent d'elles-mêmes.
// Une émission déjà faite est écartée par l'index unique, donc une tâche
// rejouée ne facture jamais deux fois.
$recurring = Billing::run();
if ($recurring['issued'] !== [] || $recurring['skipped'] !== []) {
    echo 'Abonnements : ' . count($recurring['issued']) . ' facture(s) émise(s)';
    echo $recurring['skipped'] === []
        ? "\n"
        : ', ' . count($recurring['skipped']) . ' écartée(s) ('
          . implode(', ', array_column($recurring['skipped'], 'reason')) . ")\n";
}

// Les échéances de l'entreprise deviennent des notifications. Rejouable :
// la clé de déduplication empêche qu'une même échéance alerte deux fois.
$notified = Deadlines::notify();
if ($notified > 0) {
    echo "$notified notification(s) d'échéance posée(s).\n";
}

// Ce qui a été lu depuis deux mois n'a plus à encombrer la file, et le
// journal d'audit s'efface au terme de conservation choisi par l'instance.
Notifications::purgeRead();
$retention = (int) Settings::get('audit_retention_days') ?: 365;
$purged = Audit::purgeOlderThan($retention);
if ($purged > 0) {
    echo "$purged entrée(s) de journal purgée(s) (conservation : $retention jours).\n";
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

// La file des webhooks est vidée ici, avec ses réessais : un envoi qui échoue
// n'interrompt pas les autres et repasse au balayage suivant.
$sent = Webhooks::flush();
if ($sent['delivered'] > 0 || $sent['failed'] > 0) {
    echo "Webhooks : {$sent['delivered']} livré(s), {$sent['failed']} en échec.\n";
}
Webhooks::purge();

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
