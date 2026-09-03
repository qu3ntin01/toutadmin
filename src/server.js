require('dotenv').config();

const createApp = require('./app');
const db = require('./db');
const install = require('./install');

const PORT = process.env.PORT || 3000;
const EXPIRY_SWEEP_INTERVAL_MS = 60 * 60 * 1000;

// Rattrape les contrats arrivés à échéance pendant que le serveur était arrêté.
db.deactivateExpiredContracts();

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

setInterval(() => {
  db.deactivateExpiredContracts();
}, EXPIRY_SWEEP_INTERVAL_MS);
