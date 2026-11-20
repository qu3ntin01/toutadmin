const ai = require('./ai');

/**
 * Ce que l'assistance sait faire, et ce qu'elle ne fait pas.
 *
 * Trois verbes, pas plus : résumer un fil, en proposer un brouillon de réponse,
 * et relever dans des notes ce qui ressemble à une décision ou à une action.
 * Chacun compose son texte, passe par « ai.demander » — qui tient la porte de
 * confidentialité et le journal — et rend une proposition.
 *
 * Une proposition, jamais une écriture. Rien ici ne touche à la base : le résumé
 * s'affiche, le brouillon s'ouvre dans le champ de saisie, les décisions et les
 * actions relevées attendent qu'une personne les accepte une par une. Ce n'est
 * pas de la prudence d'ingénieur, c'est la règle : sur une personne — qui porte
 * quoi, qui répond quoi —, une machine ne décide pas.
 *
 * Aucune fonction ne lève. Le service peut être éteint, injoignable, en panne :
 * l'écran continue de marcher sans elle, comme il marchait avant qu'elle existe.
 */

const MAX_ECHANGES = 60;

/** Un échange, rendu comme on le lirait. Les notes internes restent dedans : l'agent les voit déjà. */
const ligneEchange = (m) => {
  const auteur = [m.first_name, m.last_name].filter(Boolean).join(' ') || 'Compte supprimé';
  const quand = String(m.created_at || '').slice(0, 16);
  return `${quand} — ${auteur}${m.internal ? ' (note interne)' : ''} :\n${String(m.body || '').trim()}`;
};

/**
 * Un fil sans un mot n'est pas un fil : il n'y a rien à résumer, et rien à envoyer.
 *
 * « demande » compte autant que les échanges, et c'est un test qui l'a rappelé :
 * la demande d'origine est portée par le ticket lui-même, pas par un premier
 * message. Un ticket qu'on vient d'ouvrir a donc un texte et zéro échange — et
 * c'est précisément celui qu'on veut résumer.
 */
const filVide = ({ demande, echanges = [] }) => !String(demande || '').trim()
  && !echanges.some((m) => String(m && m.body || '').trim());

const filEnTexte = ({ titre, categorie, priorite, demande, echanges = [] }) => [
  `Demande : ${titre || ''}`,
  categorie ? `Catégorie : ${categorie}` : '',
  priorite ? `Priorité : ${priorite}` : '',
  '',
  String(demande || '').trim(),
  '',
  ...echanges.slice(-MAX_ECHANGES).map(ligneEchange),
].filter(Boolean).join('\n');

// ---------- Résumer ----------

const SCHEMA_RESUME = {
  type: 'object',
  properties: {
    points: {
      type: 'array',
      items: { type: 'string' },
      description: 'Trois à six phrases courtes : ce qui a été demandé, ce qui a été fait, où cela en est.',
    },
    attente: {
      type: ['string', 'null'],
      description: 'Ce que le fil attend maintenant, en une phrase. null si rien n’est en attente.',
    },
    manquant: {
      type: 'array',
      items: { type: 'string' },
      description: 'Les informations qui manquent pour avancer. Tableau vide s’il ne manque rien.',
    },
  },
  required: ['points', 'attente', 'manquant'],
  additionalProperties: false,
};

const CONSIGNE_RESUME = [
  'Tu résumes un fil de discussion interne à une entreprise, pour la personne qui va le reprendre.',
  'Tu t’en tiens à ce que le fil dit. Tu ne supposes rien, tu ne conseilles rien, tu ne juges personne.',
  'Chaque point est une phrase courte, en français, sans formule d’introduction.',
  'Si le fil ne dit pas où il en est, « attente » vaut null plutôt qu’une hypothèse.',
  'Réponds uniquement par le JSON demandé.',
].join('\n');

/** Le résumé d'un fil : ticket de support, ou compte rendu déjà saisi. */
async function resumer(fil, deps = {}) {
  if (filVide(fil)) return { ok: false, message: 'Ce fil ne contient aucun message : rien à résumer.' };

  const resultat = await ai.demander({
    fonction: 'resume',
    consigne: CONSIGNE_RESUME,
    contenu: filEnTexte(fil),
    schema: SCHEMA_RESUME,
    maxTokens: 1500,
  }, deps);

  if (!resultat.ok) return resultat;

  const d = resultat.donnees && typeof resultat.donnees === 'object' ? resultat.donnees : {};
  return {
    ...resultat,
    resume: {
      points: texteListe(d.points, 6, 300),
      attente: texte(d.attente, 300),
      manquant: texteListe(d.manquant, 6, 200),
    },
  };
}

// ---------- Proposer un brouillon ----------

const CONSIGNE_BROUILLON = [
  'Tu proposes un brouillon de réponse à une demande d’assistance interne, en français.',
  'Tu écris ce que la personne qui traite la demande pourrait envoyer : direct, courtois, sans emphase.',
  'Tu ne promets aucun délai, aucun budget, aucune décision : ce n’est pas à toi de les prendre.',
  'Ce que le fil ne dit pas, tu le demandes plutôt que de l’inventer.',
  'Réponds par le texte du message seul, sans objet, sans signature, sans commentaire sur ton propre travail.',
].join('\n');

/** Un brouillon de réponse. Rien n'est envoyé : le texte revient à l'écran. */
async function brouillon(fil, deps = {}) {
  if (filVide(fil)) return { ok: false, message: 'Ce fil ne contient aucun message : rien à quoi répondre.' };

  const resultat = await ai.demander({
    fonction: 'redaction',
    consigne: CONSIGNE_BROUILLON,
    contenu: filEnTexte(fil),
    maxTokens: 1200,
  }, deps);

  if (!resultat.ok) return resultat;
  return { ...resultat, brouillon: texte(resultat.texte, 4000) };
}

// ---------- Relever des décisions et des actions ----------

const SCHEMA_ELEMENTS = {
  type: 'object',
  properties: {
    decisions: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          libelle: { type: 'string', description: 'La décision, telle qu’elle a été prise, en une phrase' },
        },
        required: ['libelle'],
        additionalProperties: false,
      },
    },
    actions: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          libelle: { type: 'string', description: 'Ce qui est à faire, en une phrase' },
          porteur: {
            type: ['string', 'null'],
            description: 'Le prénom et le nom de la personne désignée, repris EXACTEMENT de la liste fournie. null si les notes ne désignent personne.',
          },
          echeance: { type: ['string', 'null'], description: 'Date au format AAAA-MM-JJ, si les notes en donnent une. Sinon null.' },
        },
        required: ['libelle', 'porteur', 'echeance'],
        additionalProperties: false,
      },
    },
  },
  required: ['decisions', 'actions'],
  additionalProperties: false,
};

const CONSIGNE_ELEMENTS = [
  'Tu relèves, dans des notes de réunion, ce qui a été DÉCIDÉ et ce qui est À FAIRE.',
  'Une décision est une chose tranchée. Une action est une chose à faire, avec si possible qui la porte et pour quand.',
  'Tu ne relèves que ce que les notes contiennent. Une piste évoquée n’est pas une décision ; une intention n’est pas une action.',
  'Le porteur est repris exactement de la liste des personnes fournie. Si les notes désignent quelqu’un qui n’y figure pas, le porteur vaut null.',
  'Si les notes ne contiennent ni décision ni action, rends deux tableaux vides. C’est une réponse, pas un échec.',
  'Réponds uniquement par le JSON demandé.',
].join('\n');

/**
 * Relève les décisions et les actions de notes de réunion.
 *
 * « personnes » sert à deux choses : le modèle y prend les porteurs possibles, et
 * le retour est recoupé avec elle. Un nom qui n'y figure pas est écarté — une
 * action ne s'attribue pas à quelqu'un qui n'existe pas dans l'entreprise.
 */
async function relever({ notes, personnes = [] }, deps = {}) {
  const annuaire = personnes
    .map((p) => ({ id: p.id, nom: [p.first_name, p.last_name].filter(Boolean).join(' '), grade: p.grade || '' }))
    .filter((p) => p.nom);

  const contenu = [
    'Personnes à qui une action peut être attribuée :',
    ...annuaire.map((p) => `- ${p.nom}${p.grade ? ` (${p.grade})` : ''}`),
    '',
    'Notes de la réunion :',
    String(notes || ''),
  ].join('\n');

  const resultat = await ai.demander({
    fonction: 'elements',
    consigne: CONSIGNE_ELEMENTS,
    contenu,
    schema: SCHEMA_ELEMENTS,
    maxTokens: 2500,
  }, deps);

  if (!resultat.ok) return resultat;

  const d = resultat.donnees && typeof resultat.donnees === 'object' ? resultat.donnees : {};
  const parNom = new Map(annuaire.map((p) => [p.nom.toLowerCase(), p.id]));

  return {
    ...resultat,
    decisions: (Array.isArray(d.decisions) ? d.decisions : [])
      .slice(0, 20)
      .map((x) => ({ libelle: texte(x && x.libelle, 300) }))
      .filter((x) => x.libelle),
    actions: (Array.isArray(d.actions) ? d.actions : [])
      .slice(0, 30)
      .map((x) => {
        const nom = texte(x && x.porteur, 120);
        return {
          libelle: texte(x && x.libelle, 300),
          // Un porteur qui n'est pas dans l'annuaire n'est pas un porteur.
          porteurId: nom ? (parNom.get(nom.toLowerCase()) || null) : null,
          porteurNom: nom && parNom.has(nom.toLowerCase()) ? nom : null,
          echeance: dateSeule(x && x.echeance),
        };
      })
      .filter((x) => x.libelle),
  };
}

// ---------- Nettoyage du retour ----------

/** Ce que le modèle rend est traité comme une saisie : borné, jamais repris tel quel. */
const texte = (valeur, max) => {
  if (valeur === null || valeur === undefined) return null;
  const propre = String(valeur).replace(/\s+/g, ' ').trim();
  return propre ? propre.slice(0, max) : null;
};

const texteListe = (valeur, combien, max) => (Array.isArray(valeur) ? valeur : [])
  .slice(0, combien)
  .map((x) => texte(x, max))
  .filter(Boolean);

const dateSeule = (valeur) => {
  const propre = texte(valeur, 10);
  return propre && /^\d{4}-\d{2}-\d{2}$/.test(propre) ? propre : null;
};

module.exports = {
  MAX_ECHANGES,
  SCHEMA_RESUME, SCHEMA_ELEMENTS,
  CONSIGNE_RESUME, CONSIGNE_BROUILLON, CONSIGNE_ELEMENTS,
  filVide, filEnTexte, resumer, brouillon, relever,
};
