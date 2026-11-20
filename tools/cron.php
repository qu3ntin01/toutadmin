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
use App\Modules\LogForward;
use App\Modules\Licence;
use App\Modules\Updates;
use App\Modules\Logs;
use App\Modules\Mailbox;
use App\Modules\MemberMail;
use App\Modules\Notifications;
use App\Modules\Users;
use App\Modules\Webhooks;

Db::migrate();

// Un contrat arrivé à terme ferme le compte : c'est le même balayage.
$closed = Users::deactivateExpiredContracts();
if ($closed > 0) {
    echo "$closed compte(s) fermé(s) : contrat arrivé à terme.\n";
}

/*
 * Facturation récurrente : les échéances atteintes partent d'elles-mêmes,
 * société par société. Une émission déjà faite est écartée par l'index unique,
 * donc une tâche rejouée ne facture jamais deux fois ; et comme la tâche n'a pas
 * de session, c'est elle qui doit passer dans chaque société plutôt que de s'en
 * tenir à celle qui mène.
 */
$recurring = Billing::runToutes();
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
/*
 * La corbeille se vide d'elle-même. Une corbeille qu'on ne vide jamais garde
 * indéfiniment ce qu'on a voulu supprimer, ce qu'un droit à l'effacement
 * n'admet pas. Zéro jour veut dire « garder sans limite », et c'est alors un
 * choix que l'écran a fait écrire exprès.
 */
$jetees = \App\Modules\Corbeille::purger((int) Settings::get('trash_retention_days'));
if ($jetees > 0) {
    echo "$jetees enregistrement(s) définitivement retiré(s) de la corbeille.\n";
}
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

// Messagerie externe : la boîte de chaque membre qui l'a fait brancher. La
// relève est bornée par passage ; les boîtes non servies ce tour-ci passent en
// tête du suivant, l'ordre étant celui du dernier passage.
try {
    $releve = MemberMail::fetchAll();
    if ($releve['added'] > 0 || $releve['failed'] > 0) {
        echo 'Messagerie externe : ' . $releve['added'] . ' message(s) relevé(s) sur '
            . $releve['boxes'] . ' boîte(s)'
            . ($releve['failed'] > 0 ? ', ' . $releve['failed'] . " en échec.\n" : ".\n");
    }
    MemberMail::purge();
} catch (\Throwable $error) {
    echo 'Relève de la messagerie externe interrompue : ' . $error->getMessage() . "\n";
}

// La file des webhooks est vidée ici, avec ses réessais : un envoi qui échoue
// n'interrompt pas les autres et repasse au balayage suivant.
$sent = Webhooks::flush();
if ($sent['delivered'] > 0 || $sent['failed'] > 0) {
    echo "Webhooks : {$sent['delivered']} livré(s), {$sent['failed']} en échec.\n";
}
Webhooks::purge();

// Centre de journaux : ce qui a dépassé sa durée de conservation part, et ce
// qu'aucune cible n'a encore reçu reste — la purge ne double jamais l'export.
$journal = Logs::purge();
if ($journal['total'] > 0) {
    echo "Journaux : {$journal['total']} ligne(s) purgée(s).\n";
}

// Puis la recopie vers les collecteurs : syslog, Wazuh. Une cible en panne
// n'emporte pas les autres, et son filigrane n'avance pas — ce qu'elle n'a pas
// reçu repart au passage suivant.
$exported = LogForward::flush();
if ($exported['sent'] > 0 || $exported['failed'] > 0) {
    echo "Journaux exportés : {$exported['sent']} ligne(s) vers {$exported['targets']} cible(s), "
        . "{$exported['failed']} en échec.\n";
}

// Mises à jour : on demande s'il y a du nouveau, et on n'applique que si
// l'instance l'a demandé. Un site PHP ne tourne qu'au moment d'une requête —
// c'est donc ici, et nulle part ailleurs, que la mise à jour automatique peut
// se faire.
// La licence, avant les mises à jour : c'est elle qui dit si l'instance a
// encore le droit d'écrire, et un bail expiré doit se renouveler avant qu'un
// bandeau n'apparaisse pour rien. Un échec n'interrompt rien — la grâce est là
// pour couvrir un réseau coupé.
$licence = Licence::balayer();
if (!empty($licence['verifie']) && empty($licence['renouvele'])) {
    echo 'La licence n’a pas été renouvelée : ' . ($licence['raison'] ?? 'raison inconnue') . ".\n";
}

$update = Updates::sweep();
if (!empty($update['available'])) {
    echo empty($update['applied'])
        ? "Une mise à jour est disponible (mode « prévenir seulement »).\n"
        : 'Mise à jour appliquée : ' . $update['result']['version'] . ".\n";
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
