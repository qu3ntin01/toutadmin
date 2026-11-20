require('dotenv').config();

const fs = require('fs');

const createApp = require('./app');
const db = require('./db');
const install = require('./install');
const deadlines = require('./deadlines');
const notifications = require('./notifications');
const audit = require('./audit');
const corbeille = require('./corbeille');
const settings = require('./settings');
const backup = require('./backup');
const billing = require('./billing');
const webhooks = require('./webhooks');
const mailbox = require('./mailbox');
const memberMail = require('./member-mail');
const logs = require('./logs');
const logForward = require('./log-forward');
const offsite = require('./offsite');
const updates = require('./updates');
const licence = require('./licence');

const PORT = process.env.PORT || 3000;
const EXPIRY_SWEEP_INTERVAL_MS = 60 * 60 * 1000;

/**
 * Balayage périodique : ce qui arrive à échéance devient une notification, les
 * contrats échus ferment les comptes, et les vieilles traces s'effacent. Chaque
 * opération est idempotente — la clé de déduplication des notifications garantit
 * qu'un même terme n'alerte qu'une fois, quel que soit le nombre de passages.
 */
async function sweep() {
  try {
    db.deactivateExpiredContracts();

    /*
     * Facturation récurrente : les échéances atteintes partent d'elles-mêmes,
     * société par société. Une émission déjà faite est écartée par l'index
     * unique, donc un balayage rejoué ne facture jamais deux fois ; et comme le
     * balayage n'a pas de session, c'est lui qui doit passer dans chaque société
     * plutôt que de s'en tenir à celle qui mène.
     */
    const recurring = billing.runToutes();
    if (recurring.issued.length || recurring.skipped.length) {
      console.log(`Abonnements : ${recurring.issued.length} facture(s) émise(s)`
        + (recurring.skipped.length ? `, ${recurring.skipped.length} écartée(s) (${recurring.skipped.map((s) => s.reason).join(', ')})` : ''));
    }

    const created = deadlines.notify();
    notifications.purgeRead();
    webhooks.purge();

    const retention = Number(settings.get('audit_retention_days')) || 365;
    const purged = audit.purgeOlderThan(retention);
    if (created || purged) {
      console.log(`Balayage : ${created} notification(s) créée(s), ${purged} entrée(s) de journal purgée(s).`);
    }

    /*
     * La corbeille se vide d'elle-même. Une corbeille qu'on ne vide jamais
     * garde indéfiniment ce qu'on a voulu supprimer, ce qu'un droit à
     * l'effacement n'admet pas. Zéro jour veut dire « garder sans limite », et
     * c'est alors un choix que l'écran a fait écrire exprès.
     */
    const jetees = corbeille.purger({ jours: Number(settings.get('trash_retention_days')) });
    if (jetees) console.log(`Corbeille : ${jetees} enregistrement(s) définitivement retiré(s).`);

    // Centre de journaux : ce qui a dépassé sa durée de conservation part, et
    // ce qu'aucune cible n'a encore reçu reste — la purge ne double jamais
    // l'export.
    const journal = logs.purge();
    if (journal.total) console.log(`Journaux : ${journal.total} ligne(s) purgée(s).`);

    // La licence, avant les mises à jour : c'est elle qui dit si l'instance a
    // encore le droit d'écrire, et un bail expiré doit se renouveler avant
    // qu'un bandeau n'apparaisse pour rien. Un échec n'interrompt rien — la
    // grâce est là pour couvrir un réseau coupé.
    const bail = await licence.sweep();
    if (bail.verifie && !bail.renouvele) {
      console.log(`La licence n'a pas été renouvelée : ${bail.raison || 'raison inconnue'}.`);
    }

    // Mises à jour : on demande s'il y a du nouveau, et on n'applique que si
    // l'instance l'a demandé. Le mode « avertir » se contente d'une ligne au
    // journal — c'est à un humain de cliquer.
    const update = await updates.sweep();
    if (update.available) {
      console.log(update.applied
        ? `Mise à jour appliquée : ${update.result.version}. Redémarrage attendu.`
        : 'Une mise à jour est disponible (mode « prévenir seulement »).');
    }
  } catch (err) {
    // Un balayage qui échoue ne doit pas emporter le serveur avec lui.
    console.error('Balayage périodique interrompu :', err.message);
  }

  // Relève de la boîte aux lettres comptable : les factures reçues par
  // courriel arrivent dans la corbeille du comptable sans qu'on y pense.
  try {
    if (mailbox.config().enabled && mailbox.isReady()) {
      const relevé = await mailbox.fetchOnce();
      if (relevé.received || !relevé.ok) console.log(`Pièces reçues : ${relevé.message}`);
    }
  } catch (err) {
    console.error('Relève de la boîte aux lettres interrompue :', err.message);
  }

  // Messagerie externe : la boîte de chaque membre qui l'a fait brancher. La
  // relève est bornée par passage ; les boîtes non servies ce tour-ci passent
  // en tête du suivant, l'ordre étant celui du dernier passage.
  try {
    const relevé = await memberMail.fetchAll();
    if (relevé.added || relevé.failed) {
      console.log(`Messagerie externe : ${relevé.added} message(s) relevé(s) sur ${relevé.boxes} boîte(s)`
        + (relevé.failed ? `, ${relevé.failed} en échec.` : '.'));
    }
    memberMail.purge();
  } catch (err) {
    console.error('Relève de la messagerie externe interrompue :', err.message);
  }

  // Journaux : ce qui n'est pas encore parti chez les collecteurs part
  // maintenant. Une cible injoignable garde son filigrane et repart au
  // balayage suivant, sans emporter les autres.
  try {
    const shipped = await logForward.flush();
    if (shipped.sent || shipped.failed) {
      console.log(`Journaux exportés : ${shipped.sent} ligne(s) vers ${shipped.targets} cible(s)`
        + (shipped.failed ? `, ${shipped.failed} en échec.` : '.'));
    }
  } catch (err) {
    console.error('Export des journaux interrompu :', err.message);
  }

  // Webhooks : la file est vidée ici, avec ses réessais. Un envoi qui échoue
  // n'interrompt pas les autres et repasse au balayage suivant.
  try {
    const sent = await webhooks.flush();
    if (sent.delivered || sent.failed) {
      console.log(`Webhooks : ${sent.delivered} livré(s), ${sent.failed} en échec.`);
    }
  } catch (err) {
    console.error('Envoi des webhooks interrompu :', err.message);
  }

  // La sauvegarde est à part : c'est la seule opération dont l'échec doit se
  // voir en clair, puisqu'elle est ce qui rattrape toutes les autres.
  try {
    const done = await backup.runScheduled();
    if (done) {
      console.log(`Sauvegarde automatique : ${done.fileName} (${done.files} fichier(s), ${Math.round(done.bytes / 1024)} Ko)`
        + (done.removed.length ? `, ${done.removed.length} archive(s) purgée(s)` : ''));

      // Externalisation : une archive restée sur le serveur qu'elle protège ne
      // protège de rien.
      const target = backup.pathOf(done.fileName);
      if (target && offsite.enabled().length) {
        const sent = await offsite.afterBackup(done.fileName, fs.readFileSync(target), { keep: backup.config().keep });
        for (const result of sent) {
          console.log(`  → ${result.key} : ${result.ok ? 'déposé' : 'ÉCHEC — ' + result.message}`);
        }
      }
    }
  } catch (err) {
    console.error('Sauvegarde automatique en échec :', err.message);
    audit.logSystem('sauvegarde.echec', 'backups', null, { erreur: err.message });
  }
}

/**
 * Sort-on d'une mise à jour ?
 *
 * Si oui, ce démarrage est le test de la nouvelle version. Trois démarrages
 * ratés et l'ancienne revient d'elle-même : à trois heures du matin, personne
 * ne surveille. La confirmation, elle, n'arrive qu'une fois le serveur en
 * écoute depuis une minute — un processus qui meurt à la première requête ne
 * doit pas passer pour un succès.
 */
const boot = updates.onBoot();
if (boot.state === 'rolled-back') {
  console.error(`Mise à jour abandonnée (${boot.reason}). Les fichiers précédents ont été remis ;`);
  console.error('redémarrez le service pour repartir sur la version d’avant.');
} else if (boot.state === 'trying') {
  console.log(`Démarrage sur la version ${boot.version} (essai ${boot.tries}/${updates.MAX_TRIES}).`);
}

// Rattrape ce qui s'est passé pendant que le serveur était arrêté.
sweep();

const app = createApp();

app.listen(PORT, () => {
  const url = `http://localhost:${PORT}`;

  // Une minute debout : la version posée tient la route.
  setTimeout(() => {
    const settled = updates.confirmBoot();
    if (settled.state === 'confirmed') console.log(`Version ${settled.version} confirmée.`);
  }, 60 * 1000).unref();

  if (install.isInstalled()) {
    console.log(`Toutadmin — serveur démarré sur ${url}`);
  } else {
    // Première mise en route : on pointe directement vers l'assistant.
    console.log(`Toutadmin — instance non installée.`);
    console.log(`Ouvrez ${url}/install pour lancer l'assistant d'installation.`);
    if (install.tokenRequired()) console.log("Un jeton d'installation (INSTALL_TOKEN) vous sera demandé.");
  }
});

setInterval(sweep, EXPIRY_SWEEP_INTERVAL_MS);
