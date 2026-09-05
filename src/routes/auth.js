const express = require('express');
const bcrypt = require('bcryptjs');

const db = require('../db');
const security = require('../security');
const audit = require('../audit');
const sessionStore = require('../session-store');
const i18n = require('../i18n');
const twoFactor = require('../two-factor');
const vault = require('../vault');
const settings = require('../settings');
const { setFlash, safeRedirect } = require('../utils');

const router = express.Router();

function homeFor(user) {
  return user.role === 'admin' ? '/admin' : '/mon-espace';
}

router.get('/', (req, res) => {
  if (!req.session.user) return res.redirect('/connexion');
  return res.redirect(homeFor(req.session.user));
});

router.get('/connexion', (req, res) => {
  if (req.session.user) return res.redirect(homeFor(req.session.user));
  res.render('login');
});

router.post('/connexion', security.loginLimiter, (req, res) => {
  const email = (req.body.email || '').toLowerCase().trim();
  const password = req.body.password || '';
  const genericError = () => {
    setFlash(req, 'error', 'Identifiants incorrects.');
    return res.redirect('/connexion');
  };

  const user = db.prepare('SELECT * FROM users WHERE email = ?').get(email);

  if (!user) {
    bcrypt.compareSync(password, security.DUMMY_HASH); // temps constant : ne révèle pas l'existence du compte
    req.auditHandled = true;
    audit.log(req, 'connexion.echec', 'users', null, { email, motif: 'compte inconnu' });
    return genericError();
  }

  if (security.isLocked(user)) {
    req.auditHandled = true;
    audit.log(req, 'connexion.refusee', 'users', user.id, { motif: 'compte verrouillé' });
    setFlash(req, 'error', `Compte temporairement verrouillé suite à plusieurs échecs. Réessayez dans ${security.LOCKOUT_MINUTES} minutes.`);
    return res.redirect('/connexion');
  }

  const today = new Date().toISOString().slice(0, 10);
  const contractOver = Boolean(user.contract_end_date && user.contract_end_date < today);
  if (contractOver && user.active) db.prepare('UPDATE users SET active = 0 WHERE id = ?').run(user.id);

  // Le mot de passe est vérifié avant toute chose, y compris pour un compte
  // fermé : sans cela, l'écran de connexion dirait qui est parti.
  const passwordOk = bcrypt.compareSync(password, user.password_hash);
  const closed = contractOver || !user.active;

  if (!passwordOk) {
    if (!closed) security.registerFailedAttempt(user);
    req.auditHandled = true;
    audit.log(req, 'connexion.echec', 'users', user.id, {
      motif: closed ? 'compte fermé' : 'mot de passe incorrect',
      tentative: user.failed_attempts + 1,
    });
    return genericError();
  }

  /**
   * Compte fermé mais coffre-fort garni : la personne entre, et n'atteint que
   * son coffre. C'est l'obligation de tenir ses bulletins à sa disposition
   * après le départ ; ce n'est pas une réouverture de compte.
   */
  if (closed) {
    if (!vault.hasDocuments(user.id)) {
      req.auditHandled = true;
      audit.log(req, 'connexion.refusee', 'users', user.id, { motif: contractOver ? 'contrat échu' : 'compte désactivé' });
      setFlash(req, 'error', "Ce compte est fermé. Si vous cherchez vos bulletins de paie, demandez un code d'accès à votre ancien employeur.");
      return res.redirect('/connexion');
    }
    security.resetFailedAttempts(user.id);
    return openVaultSession(req, res, user, 'mot de passe');
  }

  security.resetFailedAttempts(user.id);

  // Deuxième facteur : le mot de passe seul n'ouvre pas encore de session. On
  // retient seulement l'identité en attente, sans aucun droit attaché.
  if (user.totp_enabled) {
    return req.session.regenerate((err) => {
      if (err) return genericError();
      req.session.pendingTotp = { userId: user.id, since: Date.now() };
      req.auditHandled = true;
      audit.log(req, 'connexion.second_facteur_demande', 'users', user.id);
      res.redirect('/connexion/code');
    });
  }

  return openSession(req, res, user);
});

// Délai au-delà duquel une authentification restée en suspens est abandonnée.
const PENDING_TOTP_MS = 5 * 60 * 1000;

function openSession(req, res, user) {
  db.prepare("UPDATE users SET last_login_at = datetime('now') WHERE id = ?").run(user.id);

  req.session.regenerate((err) => {
    if (err) {
      setFlash(req, 'error', 'Identifiants incorrects.');
      return res.redirect('/connexion');
    }
    // Horodatage d'ouverture : sert de plafond absolu, que l'activité ne repousse pas.
    req.session.openedAt = Date.now();
    req.session.user = {
      id: user.id,
      role: user.role,
      email: user.email,
      firstName: user.first_name,
      lastName: user.last_name,
      grade: user.grade,
      isHr: Boolean(user.is_hr),
      locale: user.locale,
      avatarFile: user.avatar_file,
      mustChangePassword: Boolean(user.must_change_password),
    };
    req.auditHandled = true;
    audit.log(req, 'connexion.reussie', 'users', user.id);
    res.redirect(user.must_change_password ? '/mon-profil/premier-acces' : homeFor(user));
  });
  return undefined;
}

/**
 * Session restreinte au coffre-fort : aucun droit attaché, aucune autre page
 * atteignable (voir restrictToVault). C'est l'accès d'un ancien salarié.
 */
function openVaultSession(req, res, user, moyen) {
  req.session.regenerate((err) => {
    if (err) {
      setFlash(req, 'error', 'Identifiants incorrects.');
      return res.redirect('/connexion');
    }
    req.session.openedAt = Date.now();
    req.session.vaultOnly = true;
    req.session.user = {
      id: user.id,
      role: user.role,
      email: user.email,
      firstName: user.first_name,
      lastName: user.last_name,
      grade: user.grade,
      isHr: false,
      locale: user.locale,
      avatarFile: user.avatar_file,
      mustChangePassword: false,
    };
    req.auditHandled = true;
    audit.log(req, 'coffre.acces_ancien_salarie', 'users', user.id, { moyen });
    res.redirect('/coffre-fort');
  });
  return undefined;
}

/** Accès au coffre par code, pour qui a oublié son mot de passe. */
router.get('/coffre-fort/acces', (req, res) => {
  if (req.session.user) return res.redirect('/coffre-fort');
  res.render('vault-access');
});

router.post('/coffre-fort/acces', security.loginLimiter, (req, res) => {
  const user = vault.redeem(req.body.email, req.body.code);
  if (!user || !vault.hasDocuments(user.id)) {
    req.auditHandled = true;
    audit.log(req, 'coffre.code_refuse', 'users', user ? user.id : null, { email: (req.body.email || '').slice(0, 120) });
    setFlash(req, 'error', "Adresse ou code invalide, ou code expiré. Rapprochez-vous de votre ancien employeur.");
    return res.redirect('/coffre-fort/acces');
  }

  // Un code ouvre toujours une session restreinte, même pour un compte encore
  // actif : il ne sert qu'à retrouver ses documents.
  return openVaultSession(req, res, user, "code d'accès");
});

function pendingUser(req) {
  const pending = req.session.pendingTotp;
  if (!pending || Date.now() - pending.since > PENDING_TOTP_MS) return null;
  return db.prepare('SELECT * FROM users WHERE id = ?').get(pending.userId) || null;
}

router.get('/connexion/code', (req, res) => {
  if (req.session.user) return res.redirect(homeFor(req.session.user));
  if (!pendingUser(req)) {
    setFlash(req, 'error', 'Authentification expirée. Reprenez depuis le début.');
    return res.redirect('/connexion');
  }
  res.render('login-totp');
});

router.post('/connexion/code', security.loginLimiter, (req, res) => {
  const user = pendingUser(req);
  if (!user) {
    setFlash(req, 'error', 'Authentification expirée. Reprenez depuis le début.');
    return res.redirect('/connexion');
  }

  const verdict = twoFactor.verifyLogin(user, req.body.code || '');
  if (!verdict.ok) {
    // Un code faux compte comme un échec de connexion : sans cela, le second
    // facteur se force par répétition, à l'abri du verrouillage de compte.
    security.registerFailedAttempt(user);
    req.auditHandled = true;
    audit.log(req, 'connexion.second_facteur_echec', 'users', user.id);
    setFlash(req, 'error', 'Code incorrect.');
    return res.redirect('/connexion/code');
  }

  delete req.session.pendingTotp;
  req.auditHandled = true;
  audit.log(req, 'connexion.second_facteur_valide', 'users', user.id, verdict.usedRecovery ? { code_de_secours: true } : null);
  if (verdict.usedRecovery) setFlash(req, 'success', 'Code de secours utilisé : il ne resservira pas. Pensez à en régénérer.');
  return openSession(req, res, user);
});

router.post('/deconnexion', (req, res) => {
  req.auditHandled = true;
  audit.log(req, 'deconnexion', 'users', req.session.user ? req.session.user.id : null);
  req.session.destroy(() => res.redirect('/connexion'));
});

/** Ferme toutes les autres sessions du compte : utile après un doute ou un voyage. */
router.post('/sessions/fermer', (req, res) => {
  if (!req.session.user) return res.redirect('/connexion');
  const closed = sessionStore.store().revokeUser(req.session.user.id);
  req.auditHandled = true;
  audit.log(req, 'sessions.revoquees', 'users', req.session.user.id, { fermees: closed });

  // La session courante vient d'être fermée elle aussi : on la rouvre.
  req.session.regenerate((err) => {
    if (err) return res.redirect('/connexion');
    req.session.openedAt = Date.now();
    req.session.flash = { type: 'success', message: `${closed} session(s) fermée(s). Reconnectez-vous.` };
    res.redirect('/connexion');
  });
});

// Choix de la langue depuis l'écran de connexion : mémorisé en cookie tant qu'aucun
// compte n'est ouvert, puis repris par la préférence du compte une fois connecté.
router.post('/langue', (req, res) => {
  const locale = (req.body.locale || '').trim();
  const back = safeRedirect(req.body.retour, '/connexion');

  if (i18n.isSupported(locale)) {
    res.cookie('locale', locale, {
      httpOnly: true,
      sameSite: 'lax',
      secure: process.env.NODE_ENV === 'production',
      maxAge: 1000 * 60 * 60 * 24 * 365,
    });

    if (req.session.user) {
      db.prepare('UPDATE users SET locale = ? WHERE id = ?').run(locale, req.session.user.id);
      req.session.user.locale = locale;
    }
  }

  res.redirect(back);
});

module.exports = router;
