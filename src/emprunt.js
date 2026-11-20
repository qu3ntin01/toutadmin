const db = require('./db');
const audit = require('./audit');

/**
 * « Se connecter en tant que » : voir le produit par les yeux d'un salarié.
 *
 * Il existe pour une raison précise : « le bouton ne marche pas chez moi » ne se
 * résout pas en relisant du code, mais en regardant l'écran que la personne
 * regarde — avec ses droits, ses rattachements, ses données. Sans cela,
 * l'administration demande des captures d'écran, ou se fait communiquer un mot
 * de passe, ce qui est pire que tout.
 *
 * C'est aussi la fonction la plus dangereuse du produit, et elle est écrite
 * comme telle. Cinq refus en tiennent les bords :
 *
 *   1. seule l'administration peut l'ouvrir ;
 *   2. jamais sur un autre administrateur — il n'y a rien à reproduire qu'un
 *      administrateur ne puisse déjà voir, et « qui a fait quoi » cesserait
 *      d'avoir une réponse simple ;
 *   3. jamais en cascade : on n'emprunte pas depuis un emprunt ;
 *   4. une durée courte, qui ne se repousse pas. Un emprunt oublié ne doit pas
 *      devenir une porte ouverte ;
 *   5. pendant l'emprunt, les secrets de la personne restent à elle : le
 *      coffre-fort, son mot de passe, sa double authentification et sa
 *      messagerie sont fermés. Voir son écran n'est pas devenir elle.
 *
 * Et le journal ne perd jamais le fil : pendant un emprunt, l'auteur consigné
 * est l'administrateur — c'est lui qui répond de l'acte — avec mention de la
 * personne au nom de qui il agissait.
 */

/** Durée d'un emprunt. Elle ne se repousse pas : c'est un plafond absolu. */
const DUREE_MINUTES = Number(process.env.EMPRUNT_MINUTES) || 30;

/*
 * Ce qu'un emprunt n'ouvre pas.
 *
 * Liste de préfixes, fermée et relue à chaque requête par l'intergiciel : ce
 * qui serait ajouté demain sous l'un de ces chemins est fermé sans qu'on y
 * pense. Ce sont les secrets de la personne, et non ses données de travail :
 * son coffre-fort, de quoi changer son mot de passe ou sa double
 * authentification, et sa messagerie, dont le mot de passe est en base.
 */
const FERME = [
  '/coffre-fort',
  '/mon-profil/mot-de-passe',
  '/mon-profil/double-authentification',
  '/mon-profil/premier-acces',
  '/mon-profil/messagerie',
  '/messages',
];

function cibleUtilisable(id) {
  const cible = db.prepare('SELECT * FROM users WHERE id = ?').get(Number(id) || 0);
  if (!cible) return { ok: false, raison: 'emprunt.err.introuvable' };
  if (!cible.active) return { ok: false, raison: 'emprunt.err.inactif' };
  // Un administrateur ne s'emprunte pas : voir sa page n'apprendrait rien que
  // l'on ne puisse déjà voir, et le journal ne saurait plus dire lequel des
  // deux a agi.
  if (cible.role === 'admin') return { ok: false, raison: 'emprunt.err.administrateur' };
  return { ok: true, cible };
}

/** L'emprunt en cours, tel que les écrans doivent le voir. */
function encours(req) {
  const e = req && req.session ? req.session.emprunt : null;
  if (!e) return null;
  const reste = Math.max(0, DUREE_MINUTES * 60 * 1000 - (Date.now() - Number(e.depuis || 0)));
  return {
    parId: e.parId,
    parLabel: e.parLabel,
    depuis: e.depuis,
    minutesRestantes: Math.ceil(reste / 60000),
    expire: reste === 0,
  };
}

/**
 * Ouvrir l'emprunt.
 *
 * La session est régénérée : un changement de droits change d'identifiant de
 * session, sinon un jeton volé avant l'emprunt vaudrait après. L'administrateur
 * n'est pas gardé en session autrement que par son identifiant — tout le reste
 * se relit en base au retour, et une session n'a pas à porter deux identités.
 */
function commencer(req, res, cibleId, apres) {
  const verdict = cibleUtilisable(cibleId);
  if (!verdict.ok) return apres(verdict);

  const admin = req.session.user;
  if (!admin || admin.role !== 'admin') return apres({ ok: false, raison: 'emprunt.err.reserve' });
  if (req.session.emprunt) return apres({ ok: false, raison: 'emprunt.err.encours' });

  const { cible } = verdict;
  const parLabel = `${admin.firstName || ''} ${admin.lastName || ''}`.trim() || admin.email;

  audit.log(req, 'emprunt.debut', 'users', cible.id, {
    pour: `${cible.first_name} ${cible.last_name}`,
    minutes: DUREE_MINUTES,
  });

  req.session.regenerate((err) => {
    if (err) return apres({ ok: false, raison: 'emprunt.err.session' });

    req.session.openedAt = Date.now();
    req.session.emprunt = { parId: admin.id, parLabel, depuis: Date.now() };
    req.session.user = {
      id: cible.id,
      role: cible.role,
      email: cible.email,
      firstName: cible.first_name,
      lastName: cible.last_name,
      grade: cible.grade,
      isHr: Boolean(cible.is_hr),
      locale: cible.locale,
      avatarFile: cible.avatar_file,
      // Un emprunt ne doit pas tomber sur l'écran « changez votre mot de
      // passe » : ce n'est pas le mot de passe de l'administrateur, et il n'a
      // rien à y faire.
      mustChangePassword: false,
    };
    return apres({ ok: true, cible });
  });
  return undefined;
}

/**
 * Revenir à soi.
 *
 * L'administrateur est relu en base, et non restitué depuis la session : entre
 * le départ et le retour, son compte a pu être désactivé ou rétrogradé, et
 * c'est l'état d'aujourd'hui qui décide. S'il n'est plus administrateur, le
 * retour échoue et la session repart vierge — mieux vaut une reconnexion qu'un
 * droit rendu par habitude.
 */
function revenir(req, res, apres) {
  const e = req.session ? req.session.emprunt : null;
  if (!e) return apres({ ok: false, raison: 'emprunt.err.aucun' });

  const emprunte = req.session.user;
  const admin = db.prepare('SELECT * FROM users WHERE id = ?').get(Number(e.parId) || 0);

  audit.log(req, 'emprunt.fin', 'users', emprunte ? emprunte.id : null, {
    minutes: Math.round((Date.now() - Number(e.depuis || 0)) / 60000),
  });

  if (!admin || !admin.active || admin.role !== 'admin') {
    return req.session.regenerate((err) => {
      if (err) return apres({ ok: false, raison: 'emprunt.err.session' });
      req.session.flash = { type: 'error', message: 'emprunt.err.retour' };
      return apres({ ok: false, raison: 'emprunt.err.retour', deconnecte: true });
    });
  }

  return req.session.regenerate((err) => {
    if (err) return apres({ ok: false, raison: 'emprunt.err.session' });
    req.session.openedAt = Date.now();
    req.session.user = {
      id: admin.id,
      role: admin.role,
      email: admin.email,
      firstName: admin.first_name,
      lastName: admin.last_name,
      grade: admin.grade,
      isHr: Boolean(admin.is_hr),
      locale: admin.locale,
      avatarFile: admin.avatar_file,
      mustChangePassword: Boolean(admin.must_change_password),
    };
    return apres({ ok: true, admin });
  });
}

/**
 * Le verrou de l'emprunt, posé en amont des routeurs.
 *
 * Deux choses : il ferme ce que l'emprunt n'ouvre pas, et il le termine quand
 * la durée est écoulée. Posé ici plutôt que route par route, il couvre aussi ce
 * qui sera écrit demain.
 */
function verrou(req, res, next) {
  const e = encours(req);
  if (!e) return next();

  if (e.expire) {
    return revenir(req, res, (verdict) => {
      req.session.flash = { type: 'success', message: 'Emprunt terminé : la durée était écoulée.' };
      res.redirect(verdict.ok ? '/admin' : '/connexion');
    });
  }

  // Le retour et la déconnexion restent ouverts : fermer la sortie serait
  // enfermer l'administrateur dans l'identité qu'il a emprunté.
  if (req.path === '/emprunt/retour' || req.path === '/deconnexion' || req.path === '/langue') return next();

  if (FERME.some((prefixe) => req.path === prefixe || req.path.startsWith(`${prefixe}/`))) {
    return res.status(403).render('error', {
      message: "Pendant un emprunt d'identité, les secrets de la personne restent à elle.",
    });
  }
  return next();
}

module.exports = { DUREE_MINUTES, FERME, encours, commencer, revenir, verrou, cibleUtilisable };
