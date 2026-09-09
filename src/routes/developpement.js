const express = require('express');

const db = require('../db');
const audit = require('../audit');
const dev = require('../dev');
const it = require('../it');
const { requireIt } = require('../middleware/auth');
const { setFlash, isValidDateString, isValidUrl } = require('../utils');

const router = express.Router();

// Même périmètre que le service informatique : ceux qui livrent et ceux qui
// exploitent regardent le même référentiel, sinon il en existe deux.
router.use(requireIt);

const back = (anchor) => `/developpement#${anchor}`;

function fail(req, res, target, message) {
  setFlash(req, 'error', message);
  return res.redirect(target);
}

function readDate(raw, { required = false } = {}) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: !required, value: null };
  if (!isValidDateString(trimmed)) return { ok: false };
  return { ok: true, value: trimmed };
}

// Une adresse de dépôt ou de documentation est facultative, mais si elle est
// donnée elle doit être cliquable sans risque : ni javascript:, ni data:.
function readLink(raw) {
  const trimmed = (raw || '').trim().slice(0, 300);
  if (!trimmed) return { ok: true, value: '' };
  if (!isValidUrl(trimmed)) return { ok: false };
  return { ok: true, value: trimmed };
}

function employees() {
  return db.prepare('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE').all();
}

function projects() {
  return db.prepare('SELECT id, name FROM projects WHERE archived = 0 ORDER BY name COLLATE NOCASE').all();
}

function serviceFields(body) {
  const name = (body.name || '').trim().slice(0, 120);
  if (!name) return { ok: false, message: 'Nom du service obligatoire.' };
  if (!dev.CRITICALITIES.includes(body.criticality)) return { ok: false, message: 'Criticité invalide.' };

  const repository = readLink(body.repository);
  const documentation = readLink(body.documentation);
  if (!repository.ok || !documentation.ok) return { ok: false, message: 'Adresse invalide : elle doit commencer par http:// ou https://.' };

  return {
    ok: true,
    fields: {
      name,
      code: (body.code || '').trim().slice(0, 30),
      description: (body.description || '').trim().slice(0, 1000),
      repository: repository.value,
      documentation: documentation.value,
      stack: (body.stack || '').trim().slice(0, 120),
      criticality: body.criticality,
      leadId: Number(body.lead_id) || null,
      projectId: Number(body.project_id) || null,
    },
  };
}

// ---------------------------------------------------------------- écrans

router.get('/', (req, res) => {
  res.render('developpement', {
    serviceList: dev.services({ includeRetired: req.query.retires === '1' }),
    showRetired: req.query.retires === '1',
    releaseList: dev.releases({ limit: 60 }),
    summary: dev.summary(),
    restore: it.incidentStats(),
    criticalities: dev.CRITICALITIES,
    employees: employees(),
    projectList: projects(),
    today: new Date().toISOString().slice(0, 10),
  });
});

router.get('/services/:id', (req, res) => {
  const service = dev.serviceById(req.params.id);
  if (!service) return res.status(404).render('error', { message: 'Service applicatif introuvable.' });

  res.render('service-applicatif', {
    service,
    releaseList: dev.releases({ serviceId: service.id, limit: 100 }),
    incidentList: it.incidents({ limit: 200 }).filter((i) => i.service_id === service.id),
    criticalities: dev.CRITICALITIES,
    statuses: dev.SERVICE_STATUSES,
    environments: dev.ENVIRONMENTS,
    releaseStatuses: dev.RELEASE_STATUSES,
    employees: employees(),
    projectList: projects(),
    downtime: it.downtimeMinutes,
    today: new Date().toISOString().slice(0, 10),
  });
});

// ---------------------------------------------------------------- services

router.post('/services', (req, res) => {
  const parsed = serviceFields(req.body);
  if (!parsed.ok) return fail(req, res, back('nouveau'), parsed.message);

  const id = dev.createService({ ...parsed.fields, status: 'En service' });
  audit.log(req, 'developpement.service_ajoute', 'app_services', id, { nom: parsed.fields.name });
  setFlash(req, 'success', 'Service applicatif enregistré.');
  res.redirect(`/developpement/services/${id}`);
});

router.post('/services/:id/modifier', (req, res) => {
  const service = dev.serviceById(req.params.id);
  if (!service) return fail(req, res, back('services'), 'Service applicatif introuvable.');

  const parsed = serviceFields(req.body);
  if (!parsed.ok) return fail(req, res, `/developpement/services/${service.id}`, parsed.message);
  if (!dev.SERVICE_STATUSES.includes(req.body.status)) {
    return fail(req, res, `/developpement/services/${service.id}`, 'Statut invalide.');
  }

  dev.updateService(service.id, { ...parsed.fields, status: req.body.status });
  setFlash(req, 'success', 'Service applicatif mis à jour.');
  res.redirect(`/developpement/services/${service.id}`);
});

router.post('/services/:id/supprimer', (req, res) => {
  const service = dev.serviceById(req.params.id);
  if (!service) return fail(req, res, back('services'), 'Service applicatif introuvable.');

  dev.removeService(service.id);
  audit.log(req, 'developpement.service_supprime', 'app_services', service.id, { nom: service.name });
  setFlash(req, 'success', 'Service applicatif supprimé, avec ses livraisons.');
  res.redirect(back('services'));
});

// ---------------------------------------------------------------- livraisons

router.post('/services/:id/livraisons', (req, res) => {
  const service = dev.serviceById(req.params.id);
  if (!service) return fail(req, res, back('services'), 'Service applicatif introuvable.');

  const target = `/developpement/services/${service.id}`;
  const version = (req.body.version || '').trim().slice(0, 40);
  if (!version) return fail(req, res, target, 'Numéro de version obligatoire.');
  if (!dev.ENVIRONMENTS.includes(req.body.environment)) return fail(req, res, target, 'Environnement invalide.');
  if (!dev.RELEASE_STATUSES.includes(req.body.status)) return fail(req, res, target, 'Statut invalide.');

  const planned = readDate(req.body.planned_on);
  const released = readDate(req.body.released_on);
  if (!planned.ok || !released.ok) return fail(req, res, target, 'Date invalide.');
  // Une livraison sortie du champ « prévue » sans date de mise en production ne
  // compte dans aucun indicateur : elle disparaîtrait des statistiques.
  if (req.body.status !== 'Planifiée' && !released.value) {
    return fail(req, res, target, 'Renseignez la date de mise en production.');
  }

  const id = dev.createRelease({
    serviceId: service.id,
    version,
    environment: req.body.environment,
    plannedOn: planned.value,
    releasedOn: released.value,
    status: req.body.status,
    changelog: (req.body.changelog || '').trim().slice(0, 2000),
    authorId: req.session.user.id,
  });
  audit.log(req, 'developpement.livraison_ajoutee', 'releases', id, {
    service: service.name, version, environnement: req.body.environment,
  });
  setFlash(req, 'success', 'Livraison consignée.');
  res.redirect(target);
});

router.post('/livraisons/:id/modifier', (req, res) => {
  const release = dev.releaseById(req.params.id);
  if (!release) return fail(req, res, back('livraisons'), 'Livraison introuvable.');

  const target = `/developpement/services/${release.service_id}`;
  if (!dev.ENVIRONMENTS.includes(req.body.environment)) return fail(req, res, target, 'Environnement invalide.');
  if (!dev.RELEASE_STATUSES.includes(req.body.status)) return fail(req, res, target, 'Statut invalide.');

  const planned = readDate(req.body.planned_on);
  const released = readDate(req.body.released_on);
  if (!planned.ok || !released.ok) return fail(req, res, target, 'Date invalide.');
  if (req.body.status !== 'Planifiée' && !released.value) {
    return fail(req, res, target, 'Renseignez la date de mise en production.');
  }

  const incidentId = Number(req.body.incident_id) || null;
  if (incidentId && !it.incidentById(incidentId)) return fail(req, res, target, 'Incident introuvable.');

  dev.updateRelease(release.id, {
    version: (req.body.version || '').trim().slice(0, 40) || release.version,
    environment: req.body.environment,
    plannedOn: planned.value,
    releasedOn: released.value,
    status: req.body.status,
    changelog: (req.body.changelog || '').trim().slice(0, 2000),
    incidentId,
  });
  setFlash(req, 'success', 'Livraison mise à jour.');
  res.redirect(target);
});

router.post('/livraisons/:id/supprimer', (req, res) => {
  const release = dev.releaseById(req.params.id);
  if (!release) return fail(req, res, back('livraisons'), 'Livraison introuvable.');

  dev.removeRelease(release.id);
  audit.log(req, 'developpement.livraison_supprimee', 'releases', release.id, { version: release.version });
  setFlash(req, 'success', 'Livraison retirée du registre.');
  res.redirect(`/developpement/services/${release.service_id}`);
});

module.exports = router;
