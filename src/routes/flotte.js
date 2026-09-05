const express = require('express');

const db = require('../db');
const audit = require('../audit');
const fleet = require('../fleet');
const { requireFinance } = require('../middleware/auth');
const { setFlash, isValidDateString, parseAmount } = require('../utils');

const router = express.Router();

// La flotte relève des moyens généraux, tenus par la gestion.
router.use(requireFinance);

const back = (anchor) => `/flotte#${anchor}`;

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

function readMileage(raw) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: true, value: 0 };
  const value = Number(trimmed);
  if (!Number.isInteger(value) || value < 0 || value > 5000000) return { ok: false };
  return { ok: true, value };
}

function employees() {
  return db.prepare('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE').all();
}

router.get('/', (req, res) => {
  res.render('flotte', {
    vehicleList: fleet.vehicles({ includeDisposed: req.query.cedes === '1' }),
    showDisposed: req.query.cedes === '1',
    deadlines: fleet.deadlines(),
    summary: fleet.summary(),
    kinds: fleet.KINDS,
    statuses: fleet.STATUSES,
    employees: employees(),
    today: new Date().toISOString().slice(0, 10),
  });
});

router.get('/:id', (req, res) => {
  const vehicle = fleet.byId(req.params.id);
  if (!vehicle) return res.status(404).render('error', { message: 'Véhicule introuvable.' });

  res.render('vehicule', {
    vehicle,
    eventList: fleet.events(vehicle.id),
    kinds: fleet.KINDS,
    statuses: fleet.STATUSES,
    eventKinds: fleet.EVENT_KINDS,
    employees: employees(),
    today: new Date().toISOString().slice(0, 10),
  });
});

router.post('/', (req, res) => {
  const registration = (req.body.registration || '').trim().toUpperCase();
  if (!registration || registration.length > 20) return fail(req, res, back('flotte'), "Immatriculation invalide.");
  if (db.prepare('SELECT 1 FROM vehicles WHERE registration = ?').get(registration)) {
    return fail(req, res, back('flotte'), 'Ce véhicule est déjà enregistré.');
  }
  if (!fleet.KINDS.includes(req.body.kind)) return fail(req, res, back('flotte'), 'Type invalide.');

  const acquired = readDate(req.body.acquired_on);
  const insurance = readDate(req.body.insurance_due);
  const inspection = readDate(req.body.inspection_due);
  const service = readDate(req.body.service_due);
  if (!acquired.ok || !insurance.ok || !inspection.ok || !service.ok) return fail(req, res, back('flotte'), 'Date invalide.');

  const mileage = readMileage(req.body.mileage);
  if (!mileage.ok) return fail(req, res, back('flotte'), 'Kilométrage invalide.');

  const id = fleet.create({
    registration,
    brand: (req.body.brand || '').trim().slice(0, 60),
    model: (req.body.model || '').trim().slice(0, 60),
    kind: req.body.kind,
    acquiredOn: acquired.value,
    mileage: mileage.value,
    assignedTo: Number(req.body.assigned_to) || null,
    insuranceDue: insurance.value,
    inspectionDue: inspection.value,
    serviceDue: service.value,
  });
  audit.log(req, 'flotte.vehicule_ajoute', 'vehicles', id, { immatriculation: registration });
  setFlash(req, 'success', 'Véhicule enregistré.');
  res.redirect(`/flotte/${id}`);
});

router.post('/:id/modifier', (req, res) => {
  const vehicle = fleet.byId(req.params.id);
  if (!vehicle) return fail(req, res, back('flotte'), 'Véhicule introuvable.');
  if (!fleet.KINDS.includes(req.body.kind)) return fail(req, res, `/flotte/${vehicle.id}`, 'Type invalide.');
  if (!fleet.STATUSES.includes(req.body.status)) return fail(req, res, `/flotte/${vehicle.id}`, 'Statut invalide.');

  const acquired = readDate(req.body.acquired_on);
  const insurance = readDate(req.body.insurance_due);
  const inspection = readDate(req.body.inspection_due);
  const service = readDate(req.body.service_due);
  if (!acquired.ok || !insurance.ok || !inspection.ok || !service.ok) return fail(req, res, `/flotte/${vehicle.id}`, 'Date invalide.');

  const mileage = readMileage(req.body.mileage);
  if (!mileage.ok) return fail(req, res, `/flotte/${vehicle.id}`, 'Kilométrage invalide.');
  // Un compteur ne recule pas : la saisie est refusée plutôt que silencieusement ignorée.
  if (mileage.value < vehicle.mileage) {
    return fail(req, res, `/flotte/${vehicle.id}`, `Le compteur ne recule pas : il est déjà à ${vehicle.mileage} km.`);
  }

  fleet.update(vehicle.id, {
    brand: (req.body.brand || '').trim().slice(0, 60),
    model: (req.body.model || '').trim().slice(0, 60),
    kind: req.body.kind,
    acquiredOn: acquired.value,
    mileage: mileage.value,
    assignedTo: Number(req.body.assigned_to) || null,
    insuranceDue: insurance.value,
    inspectionDue: inspection.value,
    serviceDue: service.value,
    status: req.body.status,
  });
  setFlash(req, 'success', 'Véhicule mis à jour.');
  res.redirect(`/flotte/${vehicle.id}`);
});

router.post('/:id/supprimer', (req, res) => {
  const vehicle = fleet.byId(req.params.id);
  if (!vehicle) return fail(req, res, back('flotte'), 'Véhicule introuvable.');
  fleet.remove(vehicle.id);
  audit.log(req, 'flotte.vehicule_supprime', 'vehicles', vehicle.id, { immatriculation: vehicle.registration });
  setFlash(req, 'success', 'Véhicule supprimé, avec son historique.');
  res.redirect(back('flotte'));
});

router.post('/:id/evenements', (req, res) => {
  const vehicle = fleet.byId(req.params.id);
  if (!vehicle) return fail(req, res, back('flotte'), 'Véhicule introuvable.');
  if (!fleet.EVENT_KINDS.includes(req.body.kind)) return fail(req, res, `/flotte/${vehicle.id}`, 'Nature invalide.');

  const on = readDate(req.body.occurred_on, { required: true });
  if (!on.ok) return fail(req, res, `/flotte/${vehicle.id}`, 'Date invalide.');

  const mileage = readMileage(req.body.mileage);
  if (!mileage.ok) return fail(req, res, `/flotte/${vehicle.id}`, 'Kilométrage invalide.');

  const rawCost = (req.body.cost || '').trim();
  const cost = rawCost ? parseAmount(rawCost) : null;
  if (rawCost && (!Number.isFinite(cost) || cost < 0 || cost > 1e7)) {
    return fail(req, res, `/flotte/${vehicle.id}`, 'Coût invalide.');
  }

  fleet.addEvent({
    vehicleId: vehicle.id,
    kind: req.body.kind,
    occurredOn: on.value,
    mileage: mileage.value || null,
    cost: cost == null ? null : Math.round(cost * 100) / 100,
    note: (req.body.note || '').trim().slice(0, 500),
  });
  setFlash(req, 'success', 'Événement consigné.');
  res.redirect(`/flotte/${vehicle.id}`);
});

router.post('/evenements/:id/supprimer', (req, res) => {
  const vehicleId = fleet.deleteEvent(Number(req.params.id));
  if (!vehicleId) return fail(req, res, back('flotte'), 'Événement introuvable.');
  setFlash(req, 'success', 'Événement retiré.');
  res.redirect(`/flotte/${vehicleId}`);
});

module.exports = router;
