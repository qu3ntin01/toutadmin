require('dotenv').config();

const createApp = require('./app');
const db = require('./db');

const PORT = process.env.PORT || 3000;
const EXPIRY_SWEEP_INTERVAL_MS = 60 * 60 * 1000;

// Rattrape les contrats arrivés à échéance pendant que le serveur était arrêté.
db.deactivateExpiredContracts();

const app = createApp();

app.listen(PORT, () => {
  console.log(`Private Member — serveur démarré sur http://localhost:${PORT}`);
});

setInterval(() => {
  db.deactivateExpiredContracts();
}, EXPIRY_SWEEP_INTERVAL_MS);
