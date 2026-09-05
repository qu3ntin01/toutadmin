require('dotenv').config();

const createApp = require('./app');
const db = require('./db');
const install = require('./install');
const deadlines = require('./deadlines');
const notifications = require('./notifications');
const audit = require('./audit');
const settings = require('./settings');

const PORT = process.env.PORT || 3000;
const EXPIRY_SWEEP_INTERVAL_MS = 60 * 60 * 1000;

/**
 * Balayage périodique : ce qui arrive à échéance devient une notification, les
 * contrats échus ferment les comptes, et les vieilles traces s'effacent. Chaque
 * opération est idempotente — la clé de déduplication des notifications garantit
 * qu'un même terme n'alerte qu'une fois, quel que soit le nombre de passages.
 */
function sweep() {
  try {
    db.deactivateExpiredContracts();
    const created = deadlines.notify();
    notifications.purgeRead();

    const retention = Number(settings.get('audit_retention_days')) || 365;
    const purged = audit.purgeOlderThan(retention);
    if (created || purged) {
      console.log(`Balayage : ${created} notification(s) créée(s), ${purged} entrée(s) de journal purgée(s).`);
    }
  } catch (err) {
    // Un balayage qui échoue ne doit pas emporter le serveur avec lui.
    console.error('Balayage périodique interrompu :', err.message);
  }
}

// Rattrape ce qui s'est passé pendant que le serveur était arrêté.
sweep();

const app = createApp();

app.listen(PORT, () => {
  const url = `http://localhost:${PORT}`;
  if (install.isInstalled()) {
    console.log(`Salarié Member — serveur démarré sur ${url}`);
  } else {
    // Première mise en route : on pointe directement vers l'assistant.
    console.log(`Salarié Member — instance non installée.`);
    console.log(`Ouvrez ${url}/installation pour lancer l'assistant d'installation.`);
    if (install.tokenRequired()) console.log("Un jeton d'installation (INSTALL_TOKEN) vous sera demandé.");
  }
});

setInterval(sweep, EXPIRY_SWEEP_INTERVAL_MS);
