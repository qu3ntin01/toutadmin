const licence = require('./licence');
const secrets = require('./secret-store');
const settings = require('./settings');

/**
 * Écrire à l'éditeur, depuis l'instance.
 *
 * Un administrateur qui a un problème avec le logiciel écrit ici, sans quitter
 * son écran et sans compte supplémentaire. L'instance porte déjà la clé qui lui
 * sert à obtenir son bail de licence et ses mises à jour : c'est elle qui
 * l'authentifie. Aucun mot de passe de l'espace client ne transite par le CMS —
 * il n'en connaît aucun, et il n'a pas à en connaître.
 *
 * Deux verbes, et une lecture. Rien n'est conservé ici : le fil vit chez
 * l'éditeur, et cette instance n'en garde pas de copie. Une copie locale
 * divergerait le jour où quelqu'un répondrait depuis l'espace client, et deux
 * versions d'une même conversation valent moins qu'une.
 */

const SUJETS = ['question', 'technique', 'facturation', 'licence', 'autre'];
const DELAI = 15000;

/*
 * Le seul chemin vers le dehors, et son point d'injection.
 *
 * Un test qui remplacerait « global.fetch » intercepterait aussi les requêtes
 * de son propre client HTTP, et n'éprouverait plus rien. Le transport se pose
 * donc ici, nommément : ce qui passe par cette variable est exactement ce qui
 * sort de l'instance, et un appel qui la contournerait se verrait dans un test
 * qui devrait intercepter deux choses au lieu d'une.
 */
let transport = null;

/** Poser un transport de remplacement. Rendre la main : « poserTransport(null) ». */
function poserTransport(remplacant) {
  transport = typeof remplacant === 'function' ? remplacant : null;
}

/** L'éditeur est-il joignable, de principe ? Adresse et clé, rien de plus. */
function configure() {
  const courant = licence.config();
  return Boolean(courant.url && courant.clef);
}

/**
 * Les éléments de l'appel, ou null.
 *
 * La clé sort du coffre ici et nulle part ailleurs : elle est chiffrée en base,
 * et la déchiffrer dans une route l'exposerait à être enregistrée par
 * inadvertance dans un journal de requête.
 */
function acces() {
  const courant = licence.config();
  if (!courant.url || !courant.clef) return null;
  return { url: courant.url, clef: secrets.decrypt(courant.clef) };
}

/** Les refus que l'API nomme, traduits en clés d'écran. */
const RAISONS = {
  'cle-refusee': 'cleRefusee',
  'instance-sans-client': 'sansClient',
  'message-trop-court': 'tropCourt',
  'sujet-vide': 'sujetVide',
  'demande-introuvable': 'introuvable',
  plafond: 'plafond',
};

async function appeler(methode, corps, deps = {}) {
  const porte = acces();
  if (!porte) return { ok: false, raison: 'nonConfigure' };

  const requete = deps.fetch || transport || fetch;
  let reponse;
  try {
    reponse = await requete(`${porte.url}/api/v1/assistance`, {
      method: methode,
      headers: {
        'Content-Type': 'application/json',
        'X-Cle-Instance': porte.clef,
      },
      body: corps === null ? undefined : JSON.stringify(corps),
      signal: AbortSignal.timeout(DELAI),
    });
  } catch (erreur) {
    // Une panne de réseau n'est pas une faute de l'instance : on le dit, et
    // l'administrateur réessaiera.
    return { ok: false, raison: 'reseau', detail: String(erreur.message || erreur) };
  }

  let donnees = {};
  try {
    donnees = await reponse.json();
  } catch {
    donnees = {};
  }

  if (reponse.status >= 200 && reponse.status < 300) return { ok: true, donnees };
  return { ok: false, raison: RAISONS[donnees.erreur] || 'refus', statut: reponse.status, detail: donnees.detail || '' };
}

/** Ouvrir une demande. */
async function ouvrir({ sujet, type, corps }, deps = {}) {
  const objet = String(sujet || '').trim();
  const texte = String(corps || '').trim();
  if (!objet) return { ok: false, raison: 'sujetVide' };
  // Le même plancher qu'à l'autre bout : mieux vaut le dire ici que de faire
  // un aller-retour pour se faire refuser.
  if (texte.length < 10) return { ok: false, raison: 'tropCourt' };

  return appeler('POST', {
    sujet: objet.slice(0, 200),
    type: SUJETS.includes(type) ? type : 'technique',
    corps: texte.slice(0, 5000),
    edition: 'node',
    // L'adresse et la version aident au diagnostic, et l'éditeur les voit déjà
    // par le registre des instances : les redire ne révèle rien de plus.
    adresse: licence.config().adresse,
  }, deps);
}

/** Relancer dans un fil déjà ouvert par cette instance. */
async function repondre({ reference, corps }, deps = {}) {
  const texte = String(corps || '').trim();
  if (texte.length < 10) return { ok: false, raison: 'tropCourt' };
  return appeler('POST', { demande: String(reference || ''), corps: texte.slice(0, 5000) }, deps);
}

/** Relever les demandes de cette instance, et les réponses. */
async function relever(deps = {}) {
  const vu = await appeler('GET', null, deps);
  if (!vu.ok) return vu;
  const demandes = Array.isArray(vu.donnees.demandes) ? vu.donnees.demandes : [];
  return { ok: true, demandes, sujets: vu.donnees.sujets || {} };
}

/**
 * Le dernier relevé, et quand il a été fait.
 *
 * Gardé en réglages et non en session : l'administrateur qui se reconnecte
 * demain doit retrouver l'état sous les yeux, et non un écran vide qui laisse
 * croire qu'il n'a jamais écrit. La fraîcheur est affichée, parce qu'une liste
 * sans date laisse croire qu'elle est d'aujourd'hui.
 */
function memoriser(demandes) {
  settings.set('assistance_editeur_releve', JSON.stringify({
    at: new Date().toISOString(),
    demandes,
  }));
}

function memoire() {
  const brut = settings.get('assistance_editeur_releve');
  if (!brut) return null;
  try {
    const lu = JSON.parse(brut);
    return { at: lu.at || null, demandes: Array.isArray(lu.demandes) ? lu.demandes : [] };
  } catch {
    return null;
  }
}

module.exports = { SUJETS, configure, ouvrir, repondre, relever, memoriser, memoire, poserTransport };
