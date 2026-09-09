const express = require('express');

const db = require('../db');
const audit = require('../audit');
const settings = require('../settings');
const twoFactor = require('../two-factor');
const sessionStore = require('../session-store');
const { requireAdmin } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();

router.use(requireAdmin);

const back = (anchor) => `/securite#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

/** Comptes qui méritent un regard : ce sont eux qu'on attaque en premier. */
function riskyAccounts() {
  const now = new Date().toISOString();
  const staleDate = new Date(Date.now() - 90 * 24 * 60 * 60 * 1000).toISOString();

  return {
    locked: db.prepare('SELECT id, email, first_name, last_name, locked_until FROM users WHERE locked_until > ? ORDER BY locked_until DESC').all(now),
    temporary: db.prepare('SELECT id, email, first_name, last_name, created_at FROM users WHERE must_change_password = 1 AND active = 1 ORDER BY created_at').all(),
    withoutTotp: db.prepare("SELECT id, email, first_name, last_name, role FROM users WHERE totp_enabled = 0 AND active = 1 AND role = 'admin' ORDER BY email").all(),
    dormant: db.prepare(`
      SELECT id, email, first_name, last_name, last_login_at FROM users
      WHERE active = 1 AND (last_login_at IS NULL OR last_login_at < ?)
      ORDER BY last_login_at IS NOT NULL, last_login_at
    `).all(staleDate.slice(0, 19).replace('T', ' ')),
  };
}

function openSessions() {
  return db.prepare(`
    SELECT s.sid, s.user_id, s.expires_at, u.email, u.first_name, u.last_name, u.role
    FROM sessions s LEFT JOIN users u ON u.id = s.user_id
    WHERE s.expires_at > ? AND s.user_id IS NOT NULL
    ORDER BY s.expires_at DESC
  `).all(Date.now());
}

router.get('/', (req, res) => {
  const filters = {
    page: Number(req.query.page) || 1,
    action: (req.query.action || '').trim(),
    actorId: Number(req.query.auteur) || null,
    entity: (req.query.objet || '').trim(),
    from: (req.query.du || '').trim(),
    to: (req.query.au || '').trim(),
  };

  res.render('securite', {
    journal: audit.list(filters),
    filters,
    knownActions: audit.knownActions(),
    admins: db.prepare("SELECT id, email, first_name, last_name, totp_enabled, last_login_at FROM users WHERE role = 'admin' ORDER BY email").all(),
    promotable: db.prepare("SELECT id, email, first_name, last_name FROM users WHERE role = 'employee' AND active = 1 ORDER BY last_name COLLATE NOCASE").all(),
    sessions: openSessions(),
    risky: riskyAccounts(),
    policy: settings.all(),
    // Le sceau du journal : vérifié à l'ouverture, pas sur demande. Un contrôle
    // qu'il faut penser à lancer est un contrôle qu'on ne lance pas.
    seal: audit.verifySeal(),
    counters: {
      entries: db.prepare('SELECT COUNT(*) AS n FROM audit_log').get().n,
      totpEnabled: db.prepare('SELECT COUNT(*) AS n FROM users WHERE totp_enabled = 1').get().n,
      accounts: db.prepare('SELECT COUNT(*) AS n FROM users WHERE active = 1').get().n,
    },
  });
});

/** Export du journal, filtres compris : une demande d'audit se répond par un fichier. */
router.get('/journal.csv', (req, res) => {
  const { rows } = audit.list({
    page: 1, action: (req.query.action || '').trim(), actorId: Number(req.query.auteur) || null,
    entity: (req.query.objet || '').trim(), from: (req.query.du || '').trim(), to: (req.query.au || '').trim(),
  });
  audit.log(req, 'journal.exporte', 'audit_log', null, { lignes: rows.length });
  res.setHeader('Content-Type', 'text/csv; charset=utf-8');
  res.setHeader('Content-Disposition', 'attachment; filename="journal-audit.csv"');
  res.send('﻿' + audit.toCsv(rows));
});

// ---------- Administrateurs ----------

function adminCount() {
  return db.prepare("SELECT COUNT(*) AS n FROM users WHERE role = 'admin' AND active = 1").get().n;
}

router.post('/administrateurs', (req, res) => {
  const id = Number(req.body.user_id);
  const user = db.prepare("SELECT * FROM users WHERE id = ? AND role = 'employee'").get(id);
  if (!user) return fail(req, res, 'administrateurs', 'Membre introuvable.');

  db.prepare("UPDATE users SET role = 'admin' WHERE id = ?").run(id);
  audit.log(req, 'administrateur.promu', 'users', id, { email: user.email });
  setFlash(req, 'success', `${user.email} est désormais administrateur.`);
  res.redirect(back('administrateurs'));
});

router.post('/administrateurs/:id/retirer', (req, res) => {
  const id = Number(req.params.id);
  const user = db.prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'").get(id);
  if (!user) return fail(req, res, 'administrateurs', 'Administrateur introuvable.');

  // Une instance sans administrateur ne se rattrape plus depuis l'interface.
  if (adminCount() <= 1) return fail(req, res, 'administrateurs', "Le dernier administrateur ne peut pas être rétrogradé : l'instance deviendrait ingérable.");
  if (id === req.session.user.id) return fail(req, res, 'administrateurs', 'Un administrateur ne se retire pas lui-même ses droits.');

  db.prepare("UPDATE users SET role = 'employee' WHERE id = ?").run(id);
  // La rétrogradation vaut tout de suite (revalidateSession), mais fermer ses
  // sessions évite qu'une page déjà ouverte laisse croire le contraire.
  sessionStore.store().revokeUser(id);
  audit.log(req, 'administrateur.retrograde', 'users', id, { email: user.email });
  setFlash(req, 'success', `${user.email} redevient membre.`);
  res.redirect(back('administrateurs'));
});

// ---------- Sessions ----------

router.post('/sessions/:id/fermer', (req, res) => {
  const id = Number(req.params.id);
  const closed = sessionStore.store().revokeUser(id);
  audit.log(req, 'sessions.revoquees', 'users', id, { fermees: closed });
  setFlash(req, 'success', `${closed} session(s) fermée(s).`);
  res.redirect(back('sessions'));
});

// ---------- Double authentification d'un membre ----------

router.post('/2fa/:id/reinitialiser', (req, res) => {
  const id = Number(req.params.id);
  const user = db.prepare('SELECT * FROM users WHERE id = ?').get(id);
  if (!user) return fail(req, res, 'comptes', 'Membre introuvable.');

  twoFactor.disable(id);
  sessionStore.store().revokeUser(id);
  audit.log(req, '2fa.reinitialisee_par_admin', 'users', id, { email: user.email });
  setFlash(req, 'success', `Double authentification remise à zéro pour ${user.email} : la personne devra la remettre en service.`);
  res.redirect(back('comptes'));
});

router.post('/comptes/:id/deverrouiller', (req, res) => {
  const id = Number(req.params.id);
  db.prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?').run(id);
  audit.log(req, 'compte.deverrouille', 'users', id);
  setFlash(req, 'success', 'Compte déverrouillé.');
  res.redirect(back('comptes'));
});

// ---------- Politique ----------

router.post('/politique', (req, res) => {
  const retention = Number(req.body.audit_retention_days);
  if (!Number.isInteger(retention) || retention < 30 || retention > 3650) {
    return fail(req, res, 'politique', 'La conservation du journal doit tenir entre 30 et 3650 jours.');
  }

  settings.setMany({
    require_2fa_admin: req.body.require_2fa_admin ? '1' : '0',
    require_2fa_all: req.body.require_2fa_all ? '1' : '0',
    audit_retention_days: String(retention),
  });
  audit.log(req, 'politique.modifiee', 'settings', null, {
    require_2fa_admin: Boolean(req.body.require_2fa_admin),
    require_2fa_all: Boolean(req.body.require_2fa_all),
    audit_retention_days: retention,
  });
  setFlash(req, 'success', 'Politique de sécurité enregistrée.');
  res.redirect(back('politique'));
});

router.post('/journal/purger', (req, res) => {
  const days = Number(settings.get('audit_retention_days')) || 365;
  const removed = audit.purgeOlderThan(days);
  audit.log(req, 'journal.purge', 'audit_log', null, { supprimees: removed, au_dela_de_jours: days });
  setFlash(req, 'success', `${removed} entrée(s) de plus de ${days} jours supprimée(s).`);
  res.redirect(back('journal'));
});

module.exports = router;
