const crypto = require('crypto');

const db = require('./db');
const settings = require('./settings');
const secrets = require('./secret-store');
const logs = require('./logs');
const audit = require('./audit');
const cle = require('./licence-cle');
const horloge = require('./licence-horloge');

/**
 * La licence de l'instance.
 *
 * Ce module ne fait qu'une chose : dire si cette instance a le droit d'écrire,
 * et jusqu'à quand. Il ne fabrique aucune licence — il n'a que la clé publique
 * de l'éditeur, qui vérifie sans jamais pouvoir signer.
 *
 * ── Les quatre états ─────────────────────────────────────────────────────
 *
 *   absente     aucun jeton : l'instance n'a jamais été activée
 *   valide      le jeton couvre aujourd'hui, et la dernière vérification est
 *               passée
 *   sursis      le jeton couvre encore, mais la vérification échoue depuis
 *               moins de quarante-huit heures : tout fonctionne, un bandeau
 *               prévient et dit combien de temps il reste
 *   grace       l'échéance du jeton est passée depuis moins de « grace »
 *               jours : tout fonctionne, un bandeau prévient
 *   restreint   au-delà de l'un ou de l'autre : plus aucune écriture, mais la
 *               lecture, l'export et la connexion restent ouverts
 *
 * Le palier de grâce n'est pas une faveur, c'est une nécessité : un réseau
 * coupé un week-end, un renouvellement oublié un vendredi soir, et une
 * entreprise entière se retrouverait à l'arrêt un lundi matin pour une raison
 * qui n'a rien à voir avec elle.
 *
 * ── Deux comptes à rebours, et ils ne mesurent pas la même chose ─────────
 *
 * « grace » part de l'échéance écrite dans le jeton : un abonnement qui se
 * termine. « sursis » part de la première vérification en échec : une licence
 * révoquée, une clé d'instance retirée, un service de licences injoignable. Le
 * premier se prévoit, le second tombe sans prévenir — d'où quarante-huit
 * heures, assez pour qu'un humain soit prévenu, rappelle et règle, et trop peu
 * pour qu'une instance révoquée continue des mois.
 *
 * Les deux mènent au même endroit et jamais à autre chose : la lecture seule.
 *
 * ── Ce que « restreint » ne fait jamais ──────────────────────────────────
 *
 * Ni effacer, ni chiffrer, ni cacher quoi que ce soit. On se connecte, on lit
 * tout, on exporte tout ; seules les écritures sont refusées. Les clients sont
 * responsables de traitement au sens du RGPD : leur couper l'accès aux dossiers
 * du personnel et aux bulletins de paie exposerait l'éditeur autant que ça les
 * gênerait. Prendre des données en otage n'est pas un levier commercial, c'est
 * une faute.
 */

const EDITION = 'node';

/**
 * Le prix, pour l'afficher — jamais pour le décider. Cinq euros par salarié et
 * par mois, au-delà des cinq premiers, offerts à tous : douze salariés, trente-
 * cinq euros. Le prix se décide et se facture chez l'éditeur, sur l'effectif
 * déclaré dans l'abonnement ; l'instance ne fait que le traduire depuis le
 * plafond que la licence lui donne, qui est cet effectif.
 *
 * La licence gratuite, elle, plafonne à cinq salariés : ce nombre-là est une
 * limite technique que l'instance applique, et le seuil du prix.
 *
 * Ce sont les nombres du site vitrine : s'ils divergeaient, une page publique
 * et une facture ne diraient pas la même chose.
 *
 * L'instance ne facture rien et n'encaisse rien.
 */
const PRIX_SALARIE = 5;
const GRATUITE_SIEGES = 5;

/**
 * Ce que coûte l'instance, par mois, d'après le plafond de sa licence.
 *
 * Sans plafond (zéro), le prix ne se déduit pas : c'est une licence donnée à
 * la main, ou d'avant le prix par salarié — rend null, et l'écran ne promet
 * pas un montant qu'il ne connaît pas.
 */
function prixMensuel(plafond) {
  if (!(plafond > 0)) return null;
  return PRIX_SALARIE * Math.max(0, plafond - GRATUITE_SIEGES);
}

/** Ce qui reste permis quand l'instance est restreinte. */
const PHASES = {
  ABSENTE: 'absente', VALIDE: 'valide', SURSIS: 'sursis',
  GRACE: 'grace', RESTREINT: 'restreint',
};

/**
 * Quarante-huit heures de sursis quand la vérification échoue, et une
 * vérification par heure — par quart d'heure une fois le sursis engagé, pour
 * que le retour à la normale soit rapide dès que le panneau répond de nouveau.
 */
const SURSIS_MS = 48 * 3600 * 1000;
const VERIF_INTERVALLE_MS = 3600 * 1000;
const VERIF_INTERVALLE_SURSIS_MS = 15 * 60 * 1000;

function config() {
  const jeton = settings.get('licence_jeton');
  return {
    jeton: jeton ? secrets.decrypt(jeton) : '',
    empreinte: settings.get('licence_empreinte'),
    url: settings.get('update_editor_url').replace(/\/+$/, ''),
    clef: settings.get('update_instance_key'),
    adresse: settings.get('licence_adresse').replace(/\/+$/, ''),
    dernierRefus: settings.get('licence_dernier_refus'),
    derniereVerif: settings.get('licence_derniere_verif'),
    echecDepuis: settings.get('licence_echec_depuis'),
  };
}

/**
 * L'empreinte de cette installation. Un nombre au hasard, posé une fois : pas
 * une empreinte matérielle, qui changerait au premier déplacement de machine
 * virtuelle et couperait un client qui n'a rien fait de mal.
 */
function empreinte() {
  let valeur = settings.get('licence_empreinte');
  if (!valeur) {
    valeur = crypto.randomBytes(16).toString('hex');
    settings.set('licence_empreinte', valeur);
  }
  return valeur;
}

// ------------------------------------------------------------------ lecture

function deB64url(texte) {
  return Buffer.from(String(texte).replace(/-/g, '+').replace(/_/g, '/'), 'base64');
}

/**
 * Relire un jeton, et ne rendre sa charge utile qu'après avoir vérifié la
 * signature. La vérification porte sur les octets reçus, pas sur un JSON
 * ré-encodé : c'est ce qui rend la canonicalisation sans objet.
 */
function relire(jeton, pem = cle.PEM) {
  const morceaux = String(jeton || '').trim().split('.');
  if (morceaux.length !== 2 || !morceaux[0] || !morceaux[1]) return null;

  let bon = false;
  try {
    bon = crypto.verify(
      null,
      Buffer.from(morceaux[0], 'utf8'),
      crypto.createPublicKey(pem),
      deB64url(morceaux[1]),
    );
  } catch {
    // Clé publique illisible, signature mal formée : c'est non, pas une panne.
    return null;
  }
  if (!bon) return null;

  try {
    const charge = JSON.parse(deB64url(morceaux[0]).toString('utf8'));
    return charge && charge.v === 1 ? charge : null;
  } catch {
    return null;
  }
}

const jour = 86400000;

/**
 * L'état d'aujourd'hui. Aucune écriture ici : cette fonction est appelée à
 * chaque requête, elle doit être prévisible et sans effet de bord.
 */
function etat(horodatage = Date.now()) {
  // Une instance dont le code ne porte pas de clé publique n'est pas une
  // instance vendue : développement, tests, démonstration. Rien à restreindre.
  if (!cle.distribue()) {
    return { phase: PHASES.VALIDE, distribue: false, plafondMembres: 0 };
  }

  const { jeton } = config();
  if (!jeton) {
    return { phase: PHASES.ABSENTE, distribue: true, plafondMembres: 0, motif: 'aucune-licence' };
  }

  const charge = relire(jeton);
  /*
   * À partir d'ici le temps n'est plus celui de la machine, mais celui dont
   * l'instance a la preuve : la date d'émission du jeton, qui est signée, et le
   * dernier horodatage du journal scellé. Reculer l'horloge d'un serveur prend
   * dix secondes et prolongerait un sursis indéfiniment — c'est le moyen le
   * plus simple, le plus silencieux et le plus reproductible de neutraliser une
   * licence, et chiffrer le code n'y changerait rien : le code chiffré lirait
   * la même horloge menteuse.
   */
  const maintenant = horloge.maintenant(charge, horodatage);
  if (charge === null) {
    // Un jeton illisible est un jeton faux. On ne distingue pas les deux : il
    // n'y a rien à faire de différent.
    return { phase: PHASES.ABSENTE, distribue: true, plafondMembres: 0, motif: 'jeton-invalide' };
  }

  // Trois vérifications que la signature seule ne fait pas : le jeton doit
  // avoir été émis pour cette édition, et pour cette installation-ci.
  if (charge.edition !== EDITION) {
    return { phase: PHASES.ABSENTE, distribue: true, plafondMembres: 0, motif: 'autre-edition' };
  }
  if (charge.instance && charge.instance !== empreinte()) {
    return { phase: PHASES.ABSENTE, distribue: true, plafondMembres: 0, motif: 'autre-instance' };
  }

  // Le jeton ne vaut jamais au-delà de l'abonnement qu'il annonce, même si sa
  // propre date d'expiration allait plus loin par erreur.
  const fin = Math.min(
    Date.parse(charge.expire) || 0,
    Date.parse(charge.abonnement) || Date.parse(charge.expire) || 0,
  );
  const graceJours = Number.isFinite(charge.grace) ? charge.grace : 7;
  const finGrace = fin + graceJours * jour;

  const commun = {
    distribue: true,
    licence: charge,
    plafondMembres: Number(charge.membres) || 0,
    expire: new Date(fin).toISOString(),
    abonnement: charge.abonnement,
    joursRestants: Math.ceil((fin - maintenant) / jour),
  };

  /*
   * Le sursis se calcule avant la grâce et l'emporte sur « valide » : un jeton
   * qui couvre encore trois mois ne dit rien de ce qui s'est passé depuis. Une
   * licence révoquée hier porte toujours son échéance de l'an prochain — c'est
   * la vérification qui l'apprend, et elle seule.
   */
  const echec = echecDepuis();
  const finSursis = echec ? echec + SURSIS_MS : 0;
  const enSursis = echec > 0 && maintenant <= finSursis;
  const sursisEpuise = echec > 0 && maintenant > finSursis;

  if (maintenant <= fin) {
    if (sursisEpuise) {
      return { ...commun, phase: PHASES.RESTREINT, motif: 'verification-impossible', echecDepuis: new Date(echec).toISOString() };
    }
    if (enSursis) {
      return {
        ...commun,
        phase: PHASES.SURSIS,
        motif: config().dernierRefus || 'verification-impossible',
        echecDepuis: new Date(echec).toISOString(),
        // Arrondi à l'heure supérieure : « il reste 2 heures » se comprend,
        // « il reste 1,37 heure » non.
        heuresDeSursis: Math.ceil((finSursis - maintenant) / 3600000),
      };
    }
    return { ...commun, phase: PHASES.VALIDE };
  }
  if (maintenant <= finGrace && !sursisEpuise) {
    return { ...commun, phase: PHASES.GRACE, joursDeGrace: Math.ceil((finGrace - maintenant) / jour) };
  }
  return { ...commun, phase: PHASES.RESTREINT };
}

/**
 * Depuis quand la vérification échoue, et qui le dit.
 *
 * Le réglage seul ne suffirait pas : il s'efface d'une requête SQL, et le
 * sursis repartirait à zéro toutes les quarante-huit heures sans que rien ne
 * paraisse. Le journal d'audit, lui, est **scellé par chaînage** — retirer une
 * ligne du milieu casse la chaîne à cet endroit précis, et la vérification du
 * sceau le dit.
 *
 * On prend donc la plus ancienne des deux dates : celle du réglage, et celle du
 * premier échec scellé depuis la dernière réussite scellée. Effacer le réglage
 * ne gagne rien ; effacer le journal se voit.
 */
function echecDepuis() {
  const duReglage = Date.parse(config().echecDepuis) || 0;
  const duJournal = premierEchecScelle();
  if (duReglage && duJournal) return Math.min(duReglage, duJournal);
  return duReglage || duJournal;
}

/**
 * Le premier échec scellé depuis la dernière réussite scellée. Zéro si la
 * dernière trace est une réussite — ou s'il n'y a pas de trace du tout, ce qui
 * est le cas d'une instance qui vient de s'installer.
 */
function premierEchecScelle() {
  let lignes;
  try {
    lignes = db.prepare(
      `SELECT occurred_at, action FROM audit_log
        WHERE action IN ('licence.verifiee', 'licence.non-verifiee')
          AND hash IS NOT NULL AND hash <> ''
        ORDER BY id DESC LIMIT 200`,
    ).all();
  } catch {
    return 0;
  }

  let premier = 0;
  for (const ligne of lignes) {
    // On remonte du plus récent au plus ancien : la première réussite
    // rencontrée clôt la série d'échecs qui la suivait dans le temps.
    if (ligne.action === 'licence.verifiee') break;
    premier = Date.parse(`${String(ligne.occurred_at).replace(' ', 'T')}Z`) || premier;
  }
  return premier;
}

/** L'instance affiche-t-elle un bandeau, et lequel ? */
function bandeau(maintenant = Date.now()) {
  const etatCourant = etat(maintenant);
  if (etatCourant.phase === PHASES.VALIDE) return null;
  return etatCourant;
}

/**
 * Faut-il revérifier maintenant ? Toutes les heures en temps normal, tous les
 * quarts d'heure une fois le sursis engagé : c'est là qu'une vérification qui
 * réussit rend le plus service, puisqu'elle éteint le bandeau.
 */
function verificationDue(maintenant = Date.now()) {
  if (!cle.distribue()) return false;
  const { derniereVerif, echecDepuis } = config();
  const derniere = Date.parse(derniereVerif) || 0;
  const ecart = echecDepuis ? VERIF_INTERVALLE_SURSIS_MS : VERIF_INTERVALLE_MS;
  return maintenant - derniere >= ecart;
}

/**
 * Noter qu'une vérification a réussi ou échoué.
 *
 * C'est le seul endroit qui arme et désarme le compte à rebours. Une réussite
 * l'efface entièrement : une instance qui a repris contact repart à zéro, elle
 * ne traîne pas la panne de la veille.
 */
function noterVerification(ok, refus = '', charge = null) {
  const quand = new Date().toISOString();

  // L'horloge avance d'abord : la date d'émission d'un jeton qu'on vient de
  // vérifier est signée, et c'est la meilleure preuve de « maintenant » qu'une
  // instance puisse tenir.
  horloge.avancer(charge);

  if (ok) {
    settings.setMany({
      licence_derniere_verif: quand,
      licence_dernier_refus: '',
      licence_echec_depuis: '',
    });
    // Scellé : c'est cette trace-là qui clôt une série d'échecs, et elle ne
    // s'écrit pas à la main.
    audit.logSystem('licence.verifiee', 'instance', null, { phase: 'ok' });
    return;
  }

  const dejaEnEchec = settings.get('licence_echec_depuis');
  settings.setMany({
    licence_derniere_verif: quand,
    licence_dernier_refus: String(refus || ''),
    // On ne repousse jamais le début de l'échec : sinon une vérification qui
    // échoue toutes les heures remettrait le compteur à zéro chaque heure, et
    // les quarante-huit heures ne viendraient jamais.
    licence_echec_depuis: dejaEnEchec || quand,
  });
  audit.logSystem('licence.non-verifiee', 'instance', null, { motif: String(refus || '') });
}

/** Raccourci : l'instance accepte-t-elle les écritures ? */
function ecritureAutorisee(maintenant = Date.now()) {
  const { phase } = etat(maintenant);
  return phase === PHASES.VALIDE || phase === PHASES.SURSIS || phase === PHASES.GRACE;
}

/**
 * Le plafond de membres. Rendu séparément parce que l'appliquer n'a de sens
 * qu'à la création : une instance qui repasse sous une offre plus petite garde
 * les gens qu'elle a déjà, on ne licencie personne par migration de contrat.
 *
 * L'effectif se compte comme la page de tarifs l'annonce — comptes actifs,
 * freelances et stagiaires compris, administrateurs exclus. Compter autrement
 * ferait mentir une page publique, et c'est la confiance qui part.
 */
function plafondAtteint(effectif, maintenant = Date.now()) {
  const { plafondMembres } = etat(maintenant);
  return plafondMembres > 0 && effectif >= plafondMembres;
}

// ---------------------------------------------------------------- le bail

/**
 * Demander un bail au service de licences. Appelée à l'installation, puis chaque
 * nuit au balayage. Ne jette pas : une panne de réseau n'est pas une faute de
 * l'instance, et la grâce est là pour ça.
 */
async function renouveler({ url, clef, adresse } = {}) {
  const courant = config();
  const cible = (url ?? courant.url).replace(/\/+$/, '');
  const secret = clef ?? (courant.clef ? secrets.decrypt(courant.clef) : '');
  if (!cible || !secret) return { ok: false, raison: 'non-configure' };

  let reponse;
  try {
    reponse = await fetch(`${cible}/api/v1/licence`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Cle-Instance': secret },
      body: JSON.stringify({
        edition: EDITION,
        instance: empreinte(),
        adresse: (adresse ?? courant.adresse ?? '').replace(/\/+$/, ''),
        /*
         * L'heure que cette machine croit être, et celle dont elle a la preuve.
         * Le panneau les compare à la sienne : une instance dont l'horloge est
         * en retard de trois semaines se voit de l'extérieur, et cette
         * observation-là, l'hôte ne peut pas l'effacer — elle n'est pas chez
         * lui. C'est la seule trace qui survive à un accès administrateur
         * complet sur le serveur du client.
         */
        horloge: new Date().toISOString(),
        horlogeSure: new Date(horloge.maintenant(relire(courant.jeton))).toISOString(),
      }),
      signal: AbortSignal.timeout(15000),
    });
  } catch (erreur) {
    return { ok: false, raison: 'reseau', detail: String(erreur.message || erreur) };
  }

  let corps = {};
  try {
    corps = await reponse.json();
  } catch {
    return { ok: false, raison: 'reponse-illisible' };
  }

  if (!reponse.ok) {
    // Le panneau nomme son refus : on le garde tel quel pour l'écran, plutôt
    // que de laisser l'administrateur deviner pourquoi son instance s'éteint.
    noterVerification(false, String(corps.erreur || reponse.status));
    logs.record({
      level: 4, channel: 'securite', event: 'licence.refusee',
      message: `Le service de licences refuse le bail : ${corps.explication || corps.erreur || reponse.status}`,
    });
    return { ok: false, raison: String(corps.erreur || 'refus'), explication: corps.explication || '' };
  }

  const charge = relire(String(corps.licence || ''));
  if (charge === null) {
    // Un panneau qui répond 200 avec un jeton qu'on ne sait pas vérifier :
    // soit la clé publique du code ne correspond pas, soit ce n'est pas lui.
    logs.record({
      level: 3, channel: 'securite', event: 'licence.jeton-refuse',
      message: 'Un jeton de licence a été servi mais sa signature n’est pas celle de l’éditeur.',
    });
    noterVerification(false, 'signature');
    return { ok: false, raison: 'signature' };
  }

  // Un jeton plus ancien que celui qu'on a déjà est un rejeu : le panneau
  // n'en sert jamais, mais un intermédiaire qui rejoue une vieille réponse,
  // lui, pourrait — et il gagnerait le plafond ou l'échéance d'hier.
  if (rejeu(charge)) {
    logs.record({
      level: 3, channel: 'securite', event: 'licence.rejeu',
      message: 'Un jeton de licence plus ancien que celui en place a été servi ; il est écarté.',
    });
    noterVerification(false, 'rejeu');
    return { ok: false, raison: 'rejeu' };
  }

  settings.set('licence_jeton', secrets.encrypt(String(corps.licence)));
  noterVerification(true, '', charge);
  return { ok: true, licence: charge };
}

/**
 * Ce jeton est-il plus ancien que celui déjà en place ?
 *
 * Chaque charge utile porte sa date d'émission. Un jeton correctement signé
 * n'est pas pour autant celui d'aujourd'hui : gardé, puis reposé plus tard, il
 * rendrait à l'instance le plafond de membres ou l'échéance qu'elle avait à
 * l'époque. La signature ne protège pas de cela — seule la date le fait.
 *
 * À émission égale on accepte : c'est le même jeton, reposé, et le refuser
 * ferait échouer une réinstallation légitime depuis une sauvegarde.
 */
function rejeu(charge) {
  const { jeton } = config();
  if (!jeton) return false;
  const enPlace = relire(jeton);
  if (enPlace === null) return false;
  const ancienne = Date.parse(enPlace.emise) || 0;
  const nouvelle = Date.parse(charge.emise) || 0;
  if (!ancienne || !nouvelle) return false;
  return nouvelle < ancienne;
}

/**
 * Vérifier maintenant, à la demande.
 *
 * C'est ce que fait le bouton de l'installeur et celui de l'écran « Licence ».
 * Contrairement au balayage, cette fonction rend un compte rendu fait pour être
 * montré : l'état qui en résulte, et pourquoi.
 */
async function verifier() {
  if (!cle.distribue()) {
    return { ok: true, distribue: false, phase: PHASES.VALIDE, raison: 'non-distribue' };
  }

  /*
   * Un recul d'horloge se signale avant tout le reste. Il ne rapporte rien —
   * les durées se mesurent contre l'instant sûr, pas contre l'heure système —
   * mais il laisse une trace scellée. Une machine mal synchronisée dérive de
   * quelques minutes ; un serveur reculé de trois semaines ne dérive pas.
   */
  const enRetard = horloge.recul(relire(config().jeton));
  if (enRetard > 0) {
    logs.record({
      level: 3, channel: 'securite', event: 'licence.horloge-reculee',
      message: `L'horloge de cette machine est en retard de ${Math.round(enRetard / 3600000)} h `
        + 'sur ce que l’instance sait déjà. Les durées de licence sont mesurées sur la date la '
        + 'plus tardive dont elle ait la preuve.',
    });
    audit.logSystem('licence.horloge-reculee', 'instance', null, { retardHeures: Math.round(enRetard / 3600000) });
  }

  const avant = etat();
  // Un jeton hors ligne ne se renouvelle pas en ligne : le vérifier, c'est
  // relire sa signature et ses dates, ce que « etat » fait déjà. On note quand
  // même le passage, sans quoi l'instance se croirait jamais vérifiée.
  if (avant.licence && avant.licence.mode === 'hors-ligne') {
    const bon = avant.phase === PHASES.VALIDE || avant.phase === PHASES.SURSIS
      || avant.phase === PHASES.GRACE;
    noterVerification(bon, bon ? '' : avant.motif || avant.phase);
    const apres = etat();
    return { ok: bon, distribue: true, mode: 'hors-ligne', phase: apres.phase,
             licence: apres.licence || null, raison: bon ? '' : apres.motif || apres.phase };
  }

  const bail = await renouveler();
  const apres = etat();
  return {
    ok: Boolean(bail.ok),
    distribue: true,
    phase: apres.phase,
    licence: apres.licence || null,
    plafondMembres: apres.plafondMembres || 0,
    expire: apres.expire || '',
    raison: bail.ok ? '' : String(bail.raison || 'refus'),
    explication: bail.explication || '',
  };
}

/**
 * Poser une licence hors ligne, collée à la main. Le jeton est vérifié avant
 * d'être rangé : une erreur de copier-coller se voit tout de suite, plutôt
 * qu'au prochain démarrage.
 */
function poser(jeton) {
  const charge = relire(String(jeton || '').trim());
  if (charge === null) return { ok: false, raison: 'signature' };
  if (charge.edition !== EDITION) return { ok: false, raison: 'autre-edition' };
  if (charge.instance && charge.instance !== empreinte()) return { ok: false, raison: 'autre-instance' };
  if ((Date.parse(charge.abonnement) || 0) <= Date.now()) return { ok: false, raison: 'echue' };
  if (rejeu(charge)) return { ok: false, raison: 'rejeu' };

  settings.set('licence_jeton', secrets.encrypt(String(jeton).trim()));
  noterVerification(true, '', charge);
  audit.logSystem('licence.posee', 'instance', null, { reference: charge.id, mode: charge.mode });
  return { ok: true, licence: charge };
}

/** Le balayage : renouveler si c'est un bail en ligne, et noter ce qui bascule. */
async function sweep(forcer = false) {
  if (!cle.distribue()) return { verifie: false };
  // Le balayage passe toutes les heures, mais une instance en sursis se
  // revérifie tous les quarts d'heure : c'est « verificationDue » qui décide,
  // et le balayage lui obéit plutôt que de vérifier à son propre rythme.
  if (!forcer && !verificationDue()) {
    const inchange = etat();
    return { verifie: false, phase: inchange.phase, raison: 'pas-encore' };
  }

  const avant = etat();
  const compte = await verifier();
  const apres = etat();

  if (apres.phase !== avant.phase) {
    logs.record({
      level: apres.phase === PHASES.RESTREINT ? 3 : 4,
      channel: 'systeme',
      event: `licence.${apres.phase}`,
      message: `La licence passe de « ${avant.phase} » à « ${apres.phase} »`
        + (apres.motif ? ` (${apres.motif})` : '') + '.',
    });
  }
  return {
    verifie: true,
    renouvele: compte.ok,
    phase: apres.phase,
    mode: compte.mode || 'en-ligne',
    raison: compte.raison,
  };
}

/** Cette instance porte-t-elle la clé publique d'un éditeur ? */
function distribuee() {
  return cle.distribue();
}

module.exports = {
  EDITION, PHASES, SURSIS_MS, VERIF_INTERVALLE_MS, VERIF_INTERVALLE_SURSIS_MS,
  bandeau, verificationDue, noterVerification, verifier, echecDepuis,
  PRIX_SALARIE, GRATUITE_SIEGES, prixMensuel,
  config, empreinte, relire, rejeu, etat, ecritureAutorisee, plafondAtteint, distribuee,
  renouveler, poser, sweep,
};
