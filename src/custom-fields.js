const db = require('./db');
const societes = require('./societes');

/** Le périmètre courant : les champs personnalisés sont ceux d'une société. */
const ici = () => societes.courant();

/**
 * Les champs personnalisés.
 *
 * ── Pourquoi ils existent ───────────────────────────────────────────────
 *
 * Deux entreprises ne suivent pas les mêmes choses. L'une veut un numéro de
 * badge sur la fiche salarié, l'autre la taille de vêtement de travail, la
 * troisième le nom du tuteur d'alternance. Les coder toutes mènerait à une
 * fiche de deux cents champs dont chacun n'en utilise que douze.
 *
 * Un champ personnalisé se pose depuis l'écran, par l'administration, et
 * apparaît sur la fiche concernée sans qu'on touche au code.
 *
 * ── Deux tables, et pas une colonne par champ ───────────────────────────
 *
 * On aurait pu ajouter une colonne à la table visée à chaque champ créé.
 * C'est plus rapide à lire et plus simple à joindre — et c'est une migration
 * de schéma déclenchée par un clic. Cela marche cent fois, puis casse une
 * base en service, un mardi, sans témoin, parce qu'un ALTER a croisé une
 * écriture. La définition et la valeur vivent donc à part.
 *
 * ── Ce que ce module ne fait pas ────────────────────────────────────────
 *
 * Il ne lit jamais les valeurs « de tous les enregistrements ». Un champ
 * posé sur la fiche salarié hérite du cloisonnement de cette fiche : qui ne
 * peut pas lire le salarié ne doit pas lire son numéro de badge. La seule
 * façon d'obtenir une valeur est de nommer l'enregistrement — que l'appelant
 * a donc déjà eu le droit de charger. Il n'y a pas de raccourci, et c'est
 * délibéré : un raccourci ici contournerait silencieusement tous les droits
 * du produit.
 */

/**
 * Les objets qu'on peut décorer.
 *
 * Une liste fermée, et non une chaîne libre. Une faute de frappe créerait un
 * objet fantôme dont les valeurs ne seraient jamais ni lues, ni effacées au
 * départ de l'enregistrement — elles s'accumuleraient sans que rien ne le
 * dise. Ajouter une ligne ici est le geste délibéré qui ouvre un objet.
 *
 * « table » sert au ménage : quand l'enregistrement s'en va, ses valeurs
 * aussi. « libelle » est ce que lit l'administration.
 */
const OBJETS = {
  employe: { table: 'users', libelle: 'champs.obj.employe' },
  ticket: { table: 'tickets', libelle: 'champs.obj.ticket' },
  projet: { table: 'projects', libelle: 'champs.obj.projet' },
  partenaire: { table: 'partners', libelle: 'champs.obj.partenaire' },
  facture: { table: 'invoices', libelle: 'champs.obj.facture' },
  equipement: { table: 'assets', libelle: 'champs.obj.equipement' },
  demande: { table: 'workflow_requests', libelle: 'champs.obj.demande' },
  evenement: { table: 'company_events', libelle: 'champs.obj.evenement' },
};

/**
 * Les types, et ce que chacun accepte.
 *
 * « valider » rend la valeur rangée, ou lève. Le rangement compte autant que
 * le refus : une date tapée « 3/4/26 » et une autre « 2026-04-03 » doivent se
 * comparer, et deux montants « 1 200,50 » et « 1200.5 » doivent s'additionner.
 * Sans normalisation, un champ personnalisé est du texte libre déguisé.
 */
const TYPES = {
  texte: {
    libelle: 'champs.type.texte',
    valider: (brut) => {
      const propre = String(brut ?? '').trim();
      if (propre.length > 500) throw new Error('champs.err.tropLong');
      return propre;
    },
  },
  texte_long: {
    libelle: 'champs.type.texteLong',
    valider: (brut) => {
      const propre = String(brut ?? '').trim();
      if (propre.length > 10000) throw new Error('champs.err.tropLong');
      return propre;
    },
  },
  nombre: {
    libelle: 'champs.type.nombre',
    valider: (brut) => {
      const propre = String(brut ?? '').trim().replace(',', '.');
      if (propre === '') return '';
      if (!/^-?\d+(\.\d+)?$/.test(propre)) throw new Error('champs.err.nombre');
      return String(Number(propre));
    },
  },
  montant: {
    libelle: 'champs.type.montant',
    valider: (brut) => {
      // Les espaces de milliers, fines ou insécables, viennent du copier-coller
      // d'un tableur : les refuser ferait passer l'outil pour cassé.
      const propre = String(brut ?? '').trim().replace(/[\s  ]/g, '').replace(',', '.');
      if (propre === '') return '';
      if (!/^-?\d+(\.\d{1,2})?$/.test(propre)) throw new Error('champs.err.montant');
      return Number(propre).toFixed(2);
    },
  },
  date: {
    libelle: 'champs.type.date',
    valider: (brut) => {
      const propre = String(brut ?? '').trim();
      if (propre === '') return '';
      if (!/^\d{4}-\d{2}-\d{2}$/.test(propre)) throw new Error('champs.err.date');
      // « 2026-02-31 » passe le motif et n'existe pas.
      const [a, m, j] = propre.split('-').map(Number);
      const d = new Date(Date.UTC(a, m - 1, j));
      if (d.getUTCFullYear() !== a || d.getUTCMonth() !== m - 1 || d.getUTCDate() !== j) {
        throw new Error('champs.err.date');
      }
      return propre;
    },
  },
  booleen: {
    libelle: 'champs.type.booleen',
    // Une case non cochée n'est pas envoyée par le navigateur : l'absence
    // vaut « non », et c'est la seule lecture qui ne perde pas de données.
    valider: (brut) => (brut === '1' || brut === 1 || brut === true || brut === 'on' ? '1' : '0'),
  },
  liste: {
    libelle: 'champs.type.liste',
    valider: (brut, champ) => {
      const propre = String(brut ?? '').trim();
      if (propre === '') return '';
      if (!choixDe(champ).includes(propre)) throw new Error('champs.err.horsListe');
      return propre;
    },
  },
  lien: {
    libelle: 'champs.type.lien',
    valider: (brut) => {
      const propre = String(brut ?? '').trim();
      if (propre === '') return '';
      // Seuls http et https. « javascript: » dans un champ qu'un écran rend
      // en lien est une porte ouverte, et elle serait posée par l'écran
      // d'administration lui-même.
      if (!/^https?:\/\/\S+$/i.test(propre) || propre.length > 2000) throw new Error('champs.err.lien');
      return propre;
    },
  },
};

const choixDe = (champ) => String(champ?.choix ?? '')
  .split('\n')
  .map((c) => c.trim())
  .filter((c) => c !== '');

/** La clé : stable, minuscule, sans surprise dans un export ni dans une URL. */
function normaliserCle(brut) {
  return String(brut ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 40);
}

/* ------------------------------------------------------------ définitions */

/** Les champs d'un objet. Par défaut les actifs seuls : c'est ce qu'un écran veut. */
function champs(objet, { tous = false } = {}) {
  if (!OBJETS[objet]) return [];
  const sql = tous
    ? 'SELECT * FROM custom_fields WHERE company_id = ? AND objet = ? ORDER BY rang, id'
    : 'SELECT * FROM custom_fields WHERE company_id = ? AND objet = ? AND actif = 1 ORDER BY rang, id';
  return db.prepare(sql).all(ici(), objet);
}

function champ(id) {
  return db.prepare('SELECT * FROM custom_fields WHERE id = ? AND company_id = ?')
    .get(Number(id) || 0, ici()) || null;
}

function creer({ objet, libelle, type, choix = '', obligatoire = false, aide = '', rang = 0 }) {
  if (!OBJETS[objet]) throw new Error('champs.err.objet');
  if (!TYPES[type]) throw new Error('champs.err.type');
  const nom = String(libelle ?? '').trim();
  if (nom === '') throw new Error('champs.err.libelle');

  const propres = type === 'liste' ? choixDe({ choix }) : [];
  if (type === 'liste' && propres.length === 0) throw new Error('champs.err.listeVide');

  /*
   * La clé vient du libellé, et se suffixe si elle est prise.
   *
   * Deux champs nommés « Référence » sur le même objet sont une étourderie,
   * pas une faute : on les accepte, et la clé du second devient
   * « reference_2 ». Refuser obligerait à trouver un autre nom à l'écran,
   * alors que la clé n'intéresse que les exports.
   */
  const base = normaliserCle(nom) || 'champ';
  let cle = base;
  let suffixe = 1;
  while (db.prepare('SELECT 1 FROM custom_fields WHERE company_id = ? AND objet = ? AND cle = ?')
    .get(ici(), objet, cle)) {
    suffixe += 1;
    cle = `${base}_${suffixe}`.slice(0, 40);
  }

  const info = db.prepare(`
    INSERT INTO custom_fields (company_id, objet, cle, libelle, type, choix, obligatoire, aide, rang)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(ici(), objet, cle, nom, type, propres.join('\n'), obligatoire ? 1 : 0, String(aide ?? '').trim(), Number(rang) || 0);
  return champ(info.lastInsertRowid);
}

function modifier(id, { libelle, choix, obligatoire, aide, rang }) {
  const existant = champ(id);
  if (!existant) return null;
  const nom = String(libelle ?? '').trim();
  if (nom === '') throw new Error('champs.err.libelle');

  /*
   * Le type ne se change pas, et la clé non plus.
   *
   * Changer un champ texte en nombre laisserait derrière des valeurs que le
   * nouveau type refuse — invisibles, puisque la validation ne joue qu'à
   * l'écriture, et qui ressortiraient au premier export. Pour changer de
   * type, on crée un champ et on éteint l'ancien : ses valeurs restent
   * lisibles, et personne n'a perdu de données sans le savoir.
   */
  const propres = existant.type === 'liste' ? choixDe({ choix }) : [];
  if (existant.type === 'liste' && propres.length === 0) throw new Error('champs.err.listeVide');

  db.prepare(`
    UPDATE custom_fields SET libelle = ?, choix = ?, obligatoire = ?, aide = ?, rang = ?
    WHERE id = ? AND company_id = ?
  `).run(nom, propres.join('\n'), obligatoire ? 1 : 0, String(aide ?? '').trim(),
    Number(rang) || 0, existant.id, ici());
  return champ(existant.id);
}

/** Éteindre : le champ quitte les écrans, ses valeurs restent. */
function basculer(id, actif) {
  const existant = champ(id);
  if (!existant) return null;
  db.prepare('UPDATE custom_fields SET actif = ? WHERE id = ? AND company_id = ?')
    .run(actif ? 1 : 0, existant.id, ici());
  return champ(existant.id);
}

/**
 * Supprimer : le champ et toutes ses valeurs.
 *
 * Irréversible, et donc séparé de « éteindre ». Le nombre de valeurs est
 * rendu pour que l'écran puisse le dire avant de demander confirmation —
 * « vous allez effacer 340 valeurs » n'est pas la même phrase que
 * « supprimer ce champ ? ».
 */
function supprimer(id) {
  const existant = champ(id);
  if (!existant) return null;
  const valeurs = db.prepare('SELECT COUNT(*) AS n FROM custom_values WHERE field_id = ?').get(existant.id).n;
  const effacer = db.transaction(() => {
    db.prepare('DELETE FROM custom_values WHERE field_id = ?').run(existant.id);
    db.prepare('DELETE FROM custom_fields WHERE id = ? AND company_id = ?').run(existant.id, ici());
  });
  effacer();
  return { champ: existant, valeurs };
}

function compterValeurs(id) {
  return db.prepare('SELECT COUNT(*) AS n FROM custom_values WHERE field_id = ?').get(Number(id) || 0).n;
}

/* ---------------------------------------------------------------- valeurs */

/**
 * Les valeurs d'un enregistrement, prêtes pour un écran.
 *
 * Rend une ligne par champ actif, valeur comprise — y compris les champs
 * jamais renseignés, qui rendent une valeur vide. Un écran qui ne recevrait
 * que les valeurs posées devrait recroiser lui-même avec les définitions, et
 * la moitié des écrans l'oublierait.
 */
function valeurs(objet, recordId) {
  const liste = champs(objet);
  if (liste.length === 0) return [];
  const posees = new Map(
    db.prepare(`
      SELECT v.field_id, v.valeur FROM custom_values v
      JOIN custom_fields f ON f.id = v.field_id
      WHERE f.objet = ? AND v.record_id = ?
    `).all(objet, Number(recordId) || 0).map((r) => [r.field_id, r.valeur]),
  );
  return liste.map((f) => ({ ...f, valeur: posees.get(f.id) ?? '', choixListe: choixDe(f) }));
}

/**
 * Les valeurs de plusieurs enregistrements, en une requête.
 *
 * Une liste qui appellerait « valeurs » ligne par ligne ferait une requête par
 * ligne — invisible sur dix factures, pesant sur deux cents. Rend un objet
 * indexé par identifiant, chaque entrée ayant la même forme que « valeurs ».
 *
 * Aucun champ posé sur l'objet, ou aucun identifiant : l'objet rendu est vide,
 * et l'écran n'affiche rien. C'est ce qui rend l'appel gratuit tant que
 * l'entreprise n'a rien ajouté.
 */
function valeursEnLot(objet, ids) {
  const liste = champs(objet);
  const propres = [...new Set((ids || []).map(Number).filter((n) => Number.isInteger(n) && n > 0))];
  if (liste.length === 0 || propres.length === 0) return {};

  const marques = propres.map(() => '?').join(', ');
  const lignes = db.prepare(`
    SELECT v.field_id, v.record_id, v.valeur FROM custom_values v
    WHERE v.record_id IN (${marques})
      AND v.field_id IN (SELECT id FROM custom_fields WHERE objet = ?)
  `).all(...propres, objet);

  const posees = new Map();
  for (const l of lignes) posees.set(`${l.record_id}:${l.field_id}`, String(l.valeur));

  const sortie = {};
  for (const id of propres) {
    sortie[id] = liste.map((c) => ({
      ...c,
      valeur: posees.get(`${id}:${c.id}`) ?? '',
      choixListe: choixDe(c),
    }));
  }
  return sortie;
}

/** La même chose, réduite à une table clé → valeur. Pour un export, un gabarit. */
function valeursParCle(objet, recordId) {
  const sortie = {};
  for (const ligne of valeurs(objet, recordId)) sortie[ligne.cle] = ligne.valeur;
  return sortie;
}

/**
 * Contrôler ce qu'un formulaire a envoyé, sans rien écrire.
 *
 * Séparé de l'écriture parce que deux moments en ont besoin : enregistrer, et
 * créer. Un écran de création ne peut pas écrire les valeurs avant que
 * l'enregistrement existe, et il ne peut pas non plus le créer avant de savoir
 * si les valeurs passent — sans quoi un champ refusé laisserait un
 * enregistrement nu derrière lui.
 *
 * @returns {{ aPoser: Array, erreurs: Array }}
 */
function controler(objet, soumis) {
  const erreurs = [];
  const aPoser = [];

  for (const f of champs(objet)) {
    const brut = soumis?.[`perso_${f.id}`];
    // Une case à cocher absente vaut « non » ; un autre champ absent veut
    // dire « ce formulaire ne le portait pas », et sa valeur ne bouge pas.
    if (brut === undefined && f.type !== 'booleen') continue;

    let valeur;
    try {
      valeur = TYPES[f.type].valider(brut, f);
    } catch (err) {
      erreurs.push({ champ: f, raison: err.message });
      continue;
    }
    if (f.obligatoire && valeur === '') {
      erreurs.push({ champ: f, raison: 'champs.err.obligatoire' });
      continue;
    }
    aPoser.push({ f, valeur });
  }

  return { aPoser, erreurs };
}

/**
 * Enregistrer ce qu'un formulaire a envoyé.
 *
 * « soumis » est le corps du formulaire : on y cherche « perso_<id> ». Les
 * clés inconnues sont ignorées — un formulaire n'a pas à porter autre chose,
 * et un champ d'un autre objet glissé là ne doit rien écrire. Le préfixe n'est
 * pas « champ_ » : les demandes internes s'en servent déjà pour leurs propres
 * champs, et deux inventaires sous le même nom finissent par se marcher dessus.
 *
 * Tout ou rien : une valeur refusée annule les autres. Un formulaire à moitié
 * enregistré est pire qu'un formulaire refusé, parce que l'utilisateur croit
 * avoir corrigé ce qu'il voit et ne voit pas ce qui n'est pas passé.
 */
function enregistrer(objet, recordId, soumis) {
  const liste = champs(objet);
  if (liste.length === 0) return { enregistres: 0, erreurs: [] };

  const id = Number(recordId) || 0;
  if (id <= 0) throw new Error('champs.err.enregistrement');

  const { aPoser, erreurs } = controler(objet, soumis);
  if (erreurs.length > 0) return { enregistres: 0, erreurs };

  const ecrire = db.transaction(() => {
    for (const { f, valeur } of aPoser) {
      if (valeur === '') {
        // Une valeur vidée s'efface plutôt que de garder une ligne vide :
        // sans cela, « jamais renseigné » et « vidé » se ressembleraient en
        // base sans se ressembler à l'écran.
        db.prepare('DELETE FROM custom_values WHERE field_id = ? AND record_id = ?').run(f.id, id);
      } else {
        db.prepare(`
          INSERT INTO custom_values (field_id, record_id, valeur, updated_at)
          VALUES (?, ?, ?, datetime('now'))
          ON CONFLICT(field_id, record_id) DO UPDATE SET valeur = excluded.valeur, updated_at = excluded.updated_at
        `).run(f.id, id, valeur);
      }
    }
  });
  ecrire();
  return { enregistres: aPoser.length, erreurs: [] };
}

/**
 * Le refus d'un formulaire de création, avant que l'enregistrement existe.
 *
 * Même message que « refus », mais sans écrire : l'écran contrôle, crée, puis
 * enregistre les valeurs sur l'identifiant tout neuf. Dans cet ordre, un champ
 * refusé ne crée rien du tout.
 */
function verifier(objet, soumis, t) {
  const { erreurs } = controler(objet, soumis);
  if (erreurs.length === 0) return null;
  const premier = erreurs[0];
  return `${premier.champ.libelle} : ${t(premier.raison)}`;
}

/**
 * Enregistrer et rendre le refus tout fait, pour les écrans.
 *
 * Huit fiches appellent « enregistrer » et doivent toutes dire la même chose
 * quand une valeur est refusée : le libellé du champ, puis la raison traduite.
 * Recopier ces trois lignes huit fois, c'est accepter que la huitième dise
 * autre chose — ou oublie de nommer le champ fautif.
 *
 * Rend null quand tout est passé, le message à afficher sinon. L'appelant n'a
 * donc qu'à écrire : « const refus = … ; if (refus) return échec(refus) ; ».
 */
function refus(objet, recordId, soumis, t) {
  const resultat = enregistrer(objet, recordId, soumis);
  if (resultat.erreurs.length === 0) return null;
  const premier = resultat.erreurs[0];
  return `${premier.champ.libelle} : ${t(premier.raison)}`;
}

/**
 * Le ménage, quand l'enregistrement décoré s'en va.
 *
 * Aucune clé étrangère ne peut le faire : la table visée dépend de la colonne
 * « objet », et une contrainte ne sait pas dire « selon la valeur d'une autre
 * colonne ». C'est donc au module de suppression de l'objet d'appeler ceci —
 * et au test « aucune valeur ne survit à son enregistrement » de le vérifier.
 */
function oublier(objet, recordId) {
  if (!OBJETS[objet]) return 0;
  const info = db.prepare(`
    DELETE FROM custom_values WHERE record_id = ?
      AND field_id IN (SELECT id FROM custom_fields WHERE objet = ?)
  `).run(Number(recordId) || 0, objet);
  return info.changes;
}

/**
 * Les valeurs orphelines : celles dont l'enregistrement n'existe plus.
 *
 * Un module qui supprime sans appeler « oublier » laisse des valeurs
 * derrière lui. Elles ne gênent personne — elles ne sont jamais lues, la
 * lecture passant par l'enregistrement — mais elles s'accumulent, et surtout
 * elles reviendraient à l'écran si l'identifiant était réattribué. Cette
 * fonction les compte et les efface ; l'écran d'administration la propose.
 */
function orphelines({ effacer = false } = {}) {
  let total = 0;
  for (const [objet, { table }] of Object.entries(OBJETS)) {
    const condition = `
      v.record_id NOT IN (SELECT id FROM ${table})
        AND v.field_id IN (SELECT id FROM custom_fields WHERE objet = ?)
    `;
    const n = db.prepare(`SELECT COUNT(*) AS n FROM custom_values v WHERE ${condition}`).get(objet).n;
    if (n > 0 && effacer) {
      db.prepare(`DELETE FROM custom_values WHERE rowid IN (
        SELECT v.rowid FROM custom_values v WHERE ${condition}
      )`).run(objet);
    }
    total += n;
  }
  return total;
}

module.exports = {
  OBJETS,
  TYPES,
  normaliserCle,
  choixDe,
  champs,
  champ,
  creer,
  modifier,
  basculer,
  supprimer,
  compterValeurs,
  valeurs,
  valeursEnLot,
  valeursParCle,
  enregistrer,
  verifier,
  refus,
  oublier,
  orphelines,
};
