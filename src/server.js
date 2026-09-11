require('dotenv').config();

const fs = require('fs');

const createApp = require('./app');
const db = require('./db');
const install = require('./install');
const deadlines = require('./deadlines');
const notifications = require('./notifications');
const audit = require('./audit');
const settings = require('./settings');
const backup = require('./backup');
const billing = require('./billing');
const webhooks = require('./webhooks');
const mailbox = require('./mailbox');
const offsite = require('./offsite');

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

    // Facturation récurrente : les échéances atteintes partent d'elles-mêmes.
    // Une émission déjà faite est écartée par l'index unique, donc un balayage
    // rejoué ne facture jamais deux fois.
    const recurring = billing.run();
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

// Rattrape ce qui s'est passé pendant que le serveur était arrêté.
sweep();

const app = createApp();

app.listen(PORT, () => {
  const url = `http://localhost:${PORT}`;
  if (install.isInstalled()) {
    console.log(`Toutadmin — serveur démarré sur ${url}`);
  } else {
    // Première mise en route : on pointe directement vers l'assistant.
    console.log(`Toutadmin — instance non installée.`);
    console.log(`Ouvrez ${url}/installation pour lancer l'assistant d'installation.`);
    if (install.tokenRequired()) console.log("Un jeton d'installation (INSTALL_TOKEN) vous sera demandé.");
  }
});

setInterval(sweep, EXPIRY_SWEEP_INTERVAL_MS);
