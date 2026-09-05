const settings = require('../settings');
const secrets = require('../secret-store');
const audit = require('../audit');
const db = require('../db');
const notifications = require('../notifications');
const ftp = require('./ftp');
const drive = require('./drive');

/**
 * Externalisation des sauvegardes.
 *
 * Une sauvegarde qui reste sur le serveur qu'elle protège ne protège de rien :
 * le disque, l'incendie et le rançongiciel emportent les deux. Ce module envoie
 * chaque archive vers des destinations extérieures, et surveille qu'elles y
 * arrivent — une externalisation qui échoue en silence est pire que pas
 * d'externalisation, puisqu'elle rassure.
 */

const DESTINATIONS = [
  {
    key: 'ftp',
    label: 'Serveur FTP',
    hint: "Un NAS ou l'espace de sauvegarde d'un hébergeur. Préférez FTPS : en FTP simple, l'identifiant, le mot de passe et l'archive traversent le réseau en clair.",
    fields: [
      { name: 'host', label: 'Hôte', required: true },
      { name: 'port', label: 'Port', type: 'number', placeholder: '21' },
      { name: 'mode', label: 'Sécurité', type: 'select', options: ftp.MODES.map((m) => ({ value: m.key, label: m.label })) },
      { name: 'user', label: 'Identifiant', required: true },
      { name: 'password', label: 'Mot de passe', type: 'password', secret: true, required: true },
      { name: 'directory', label: 'Dossier distant', placeholder: '/sauvegardes' },
      { name: 'allowSelfSigned', label: 'Accepter un certificat auto-signé', type: 'checkbox' },
    ],
    driver: ftp,
  },
  {
    key: 'drive',
    label: 'Google Drive',
    hint: "Par compte de service. Créez-en un dans la console Google Cloud, activez l'API Drive, puis partagez le dossier de destination avec l'adresse du compte de service : son accès se limite alors à ce dossier.",
    fields: [
      { name: 'clientEmail', label: 'Adresse du compte de service', required: true, placeholder: 'sauvegarde@projet.iam.gserviceaccount.com' },
      { name: 'privateKey', label: 'Clé privée (champ private_key du fichier JSON)', type: 'textarea', secret: true, required: true },
      { name: 'folderId', label: 'Identifiant du dossier Drive', required: true, placeholder: '1AbC…' },
    ],
    driver: drive,
  },
];

const KEYS = DESTINATIONS.map((d) => d.key);

function byKey(key) {
  return DESTINATIONS.find((d) => d.key === key) || null;
}

const settingKey = (key, suffix) => `offsite.${key}.${suffix}`;

function rawConfig(key) {
  const stored = settings.get(settingKey(key, 'config'));
  if (!stored) return {};
  try {
    return JSON.parse(stored);
  } catch {
    return {};
  }
}

/** La configuration utilisable : secrets déchiffrés, prête pour le pilote. */
function config(key) {
  const destination = byKey(key);
  if (!destination) return {};

  const raw = rawConfig(key);
  const out = {};
  for (const field of destination.fields) {
    out[field.name] = field.secret ? secrets.decrypt(raw[field.name]) : (raw[field.name] ?? '');
  }
  return out;
}

/**
 * La configuration telle qu'on la montre : les secrets n'en sortent jamais,
 * seulement l'information qu'ils sont posés.
 */
function displayConfig(key) {
  const destination = byKey(key);
  if (!destination) return {};

  const raw = rawConfig(key);
  const out = {};
  for (const field of destination.fields) {
    out[field.name] = field.secret ? secrets.mask(raw[field.name]) : (raw[field.name] ?? '');
  }
  return out;
}

function isEnabled(key) {
  return settings.get(settingKey(key, 'enabled')) === '1';
}

function isConfigured(key) {
  const destination = byKey(key);
  if (!destination) return false;
  const values = config(key);
  return destination.fields.filter((f) => f.required).every((f) => String(values[f.name] || '').trim() !== '');
}

/**
 * Enregistre la configuration. Un champ secret laissé vide conserve la valeur
 * précédente : l'écran n'affiche jamais le secret, il ne peut donc pas le
 * renvoyer, et l'effacer par inadvertance couperait la sauvegarde.
 */
function setConfig(key, values) {
  const destination = byKey(key);
  if (!destination) return { ok: false, message: 'Destination inconnue.' };

  const previous = rawConfig(key);
  const next = {};
  for (const field of destination.fields) {
    const submitted = values[field.name];
    if (field.secret) {
      const typed = String(submitted || '').trim();
      next[field.name] = typed ? secrets.encrypt(typed) : (previous[field.name] || '');
    } else if (field.type === 'checkbox') {
      next[field.name] = submitted ? true : false;
    } else {
      next[field.name] = String(submitted == null ? '' : submitted).trim().slice(0, 4000);
    }
  }

  settings.set(settingKey(key, 'config'), JSON.stringify(next));
  drive.forgetTokens();
  return { ok: true };
}

function setEnabled(key, enabled) {
  if (!byKey(key)) return false;
  if (enabled && !isConfigured(key)) return false;
  settings.set(settingKey(key, 'enabled'), enabled ? '1' : '0');
  return true;
}

function status(key) {
  const stored = settings.get(settingKey(key, 'status'));
  if (!stored) return null;
  try {
    return JSON.parse(stored);
  } catch {
    return null;
  }
}

function recordStatus(key, result) {
  settings.set(settingKey(key, 'status'), JSON.stringify({
    ok: Boolean(result.ok),
    message: String(result.message || '').slice(0, 400),
    at: new Date().toISOString(),
    fileName: result.fileName || '',
  }));
}

function driverOf(key) {
  const destination = byKey(key);
  return destination ? destination.driver : null;
}

async function test(key) {
  const driver = driverOf(key);
  if (!driver) return { ok: false, message: 'Destination inconnue.' };
  if (!isConfigured(key)) return { ok: false, message: 'Configuration incomplète.' };

  const result = await driver.test(config(key));
  recordStatus(key, result);
  return result;
}

async function send(key, fileName, buffer) {
  const driver = driverOf(key);
  if (!driver) return { ok: false, message: 'Destination inconnue.' };
  if (!isConfigured(key)) return { ok: false, message: 'Configuration incomplète.' };

  let result;
  try {
    result = await driver.upload(config(key), fileName, buffer);
  } catch (err) {
    result = { ok: false, message: err.message };
  }
  recordStatus(key, { ...result, fileName });
  return result;
}

async function remoteList(key) {
  const driver = driverOf(key);
  if (!driver || !isConfigured(key)) return { ok: false, message: 'Configuration incomplète.' };
  return driver.list(config(key));
}

/**
 * Aligne la destination sur le nombre d'archives conservées : sans cela,
 * l'espace distant grossit indéfiniment et finit par refuser les dépôts.
 */
async function prune(key, keep) {
  const driver = driverOf(key);
  if (!driver || !isConfigured(key)) return { ok: false, message: 'Configuration incomplète.' };

  const listing = await driver.list(config(key));
  if (!listing.ok) return listing;

  const surplus = listing.files.slice(keep);
  const removed = [];
  for (const file of surplus) {
    const done = await driver.remove(config(key), key === 'drive' ? file.id : file.name);
    if (done.ok) removed.push(file.name);
  }
  return { ok: true, removed };
}

const enabled = () => KEYS.filter(isEnabled);

/**
 * Envoie une archive à toutes les destinations actives. N'échoue jamais en
 * bloc : chaque destination est indépendante, et le résultat de chacune est
 * rendu pour être tracé et signalé.
 */
async function dispatch(fileName, buffer, { keep = 24, req = null } = {}) {
  const results = [];
  for (const key of enabled()) {
    const result = await send(key, fileName, buffer);
    results.push({ key, ...result });

    if (result.ok) {
      await prune(key, keep);
      audit.log(req, 'sauvegarde.externalisee', 'backups', null, { destination: key, fichier: fileName });
    } else {
      audit.log(req, 'sauvegarde.externalisation_echec', 'backups', null, { destination: key, fichier: fileName, motif: result.message });
    }
  }
  return results;
}

/**
 * Après chaque sauvegarde : envoi vers les destinations actives, et alerte des
 * administrateurs si l'une d'elles refuse. Une externalisation muette qui
 * échoue depuis trois semaines est le pire des cas — on croit être couvert.
 */
async function afterBackup(fileName, buffer, { keep = 24, req = null } = {}) {
  const results = await dispatch(fileName, buffer, { keep, req });

  for (const result of results.filter((r) => !r.ok)) {
    const destination = byKey(result.key);
    for (const admin of db.prepare("SELECT id FROM users WHERE role = 'admin' AND active = 1").all()) {
      notifications.push({
        userId: admin.id,
        kind: 'sauvegarde',
        title: `Externalisation en échec — ${destination ? destination.label : result.key}`,
        body: `${fileName} n'a pas pu être déposé : ${result.message}`,
        link: '/sauvegardes#externalisation',
        // Une même destination en échec n'alerte qu'une fois par jour.
        dedupeKey: `offsite:${result.key}:${new Date().toISOString().slice(0, 10)}`,
      });
    }
  }
  return results;
}

/** Les destinations actives dont le dernier envoi a échoué. */
function failing() {
  return enabled().map((key) => ({ key, status: status(key) })).filter((d) => d.status && d.status.ok === false);
}

function list() {
  return DESTINATIONS.map((destination) => ({
    key: destination.key,
    label: destination.label,
    hint: destination.hint,
    fields: destination.fields,
    enabled: isEnabled(destination.key),
    configured: isConfigured(destination.key),
    values: displayConfig(destination.key),
    status: status(destination.key),
  }));
}

module.exports = {
  DESTINATIONS, KEYS, byKey, config, displayConfig, isEnabled, isConfigured,
  setConfig, setEnabled, status, recordStatus, test, send, remoteList, prune,
  enabled, dispatch, afterBackup, failing, list,
};
