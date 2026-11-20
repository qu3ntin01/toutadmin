const settings = require('./settings');
const secrets = require('./secret-store');
const scan = require('./invoice-scan');
const confidentialite = require('./ai-confidentialite');
const logs = require('./logs');

/**
 * Analyse d'une facture par un modèle de langage — optionnelle, et éteinte par
 * défaut.
 *
 * La lecture par règles (voir invoice-scan.js) fonctionne toujours, hors ligne,
 * et ne fait sortir aucune donnée. Le modèle vient par-dessus, pour les cas que
 * les règles lisent mal : mises en page exotiques, factures étrangères,
 * documents où le libellé du fournisseur ne ressemble à rien de connu.
 *
 * Trois précautions, parce qu'envoyer une facture chez un tiers n'est pas
 * anodin :
 *
 *   — **c'est un choix explicite.** Rien n'est envoyé tant qu'un administrateur
 *     n'a pas activé la fonction, choisi le service et saisi sa clé. L'écran
 *     dit ce qui part et où ;
 *   — **la clé est chiffrée en base**, comme les autres secrets ;
 *   — **le modèle ne décide de rien.** Il propose des champs ; les règles
 *     gardent la main sur ce qu'elles savent vérifier (SIRET, IBAN, cohérence
 *     des montants), et le comptable valide avant toute écriture.
 *
 * Deux familles de services sont acceptées : l'API Claude d'Anthropic, et tout
 * service compatible OpenAI — Mistral, OVHcloud, Scaleway, un Ollama posé sur
 * le réseau interne. Le choix reste à l'entreprise, y compris celui de ne rien
 * envoyer du tout.
 */

const PROVIDERS = [
  {
    key: 'anthropic',
    label: 'API Claude (Anthropic)',
    defaultModel: 'claude-opus-5-5',
    // Les défauts d'avant. Une instance configurée a gardé en base le modèle que
    // le formulaire lui proposait ; voir « modeleRetenu ».
    anciensDefauts: ['claude-opus-5'],
    hint: 'Clé « sk-ant-… » créée depuis la console Anthropic.',
    needsBaseUrl: false,
  },
  {
    key: 'openai',
    label: 'Service compatible OpenAI (Mistral, OVHcloud, Scaleway, Ollama…)',
    defaultModel: 'mistral-small-latest',
    anciensDefauts: [],
    hint: "Indiquez l'adresse du service, par exemple https://api.mistral.ai/v1 — un modèle hébergé chez vous ne fait sortir aucune donnée.",
    needsBaseUrl: true,
  },
];

/**
 * Les fonctions d'assistance, une par une.
 *
 * Le service se configure une fois ; ce qu'on lui confie s'active fonction par
 * fonction. Une entreprise peut vouloir qu'un modèle résume un fil de support
 * sans vouloir qu'il lise ses factures, ou l'inverse. Un interrupteur global
 * cacherait ce choix, et le choix est tout l'objet de cet écran.
 *
 * « garder » liste ce que la porte de confidentialité laisse passer POUR cette
 * fonction, et rien d'autre. La lecture d'une facture a besoin de l'IBAN et du
 * SIRET puisque c'est ce qu'elle extrait ; le résumé d'un fil n'a besoin ni de
 * l'un ni de l'autre, et ne les verra jamais.
 *
 * « envoie » est la phrase que l'écran affiche. Elle n'est pas décorative :
 * c'est ce sur quoi un administrateur se prononce, et elle doit dire vrai.
 */
const FONCTIONS = [
  {
    cle: 'facture',
    label: 'Lecture des factures reçues',
    quoi: 'Extrait les champs d’une facture que les règles lisent mal.',
    envoie: 'Le texte du document déposé, IBAN et SIRET compris — ce sont les champs à extraire.',
    garder: ['iban', 'carte', 'nir'],
  },
  {
    cle: 'resume',
    label: 'Résumé d’un fil',
    quoi: 'Ramène un ticket de trente messages, ou des notes de réunion, à quelques lignes.',
    envoie: 'Le texte du fil, sans coordonnées bancaires, sans numéro de sécurité sociale, sans clé ni jeton.',
    garder: [],
  },
  {
    cle: 'elements',
    label: 'Décisions et actions depuis des notes',
    quoi: 'Propose les décisions et les actions que des notes de réunion contiennent, avec leur porteur et leur date.',
    envoie: 'Les notes saisies, et la liste des personnes à qui une action peut être attribuée — prénom, nom, fonction.',
    garder: [],
  },
  {
    cle: 'redaction',
    label: 'Brouillon de réponse',
    quoi: 'Propose une réponse à un ticket. Personne ne l’envoie à votre place : elle s’ouvre dans le champ de saisie.',
    envoie: 'Le fil de la demande, nettoyé comme le résumé.',
    garder: [],
  },
];

const fonctionParCle = (cle) => FONCTIONS.find((f) => f.cle === cle) || null;

const EFFORTS = ['low', 'medium', 'high'];
const MAX_CHARS = 20000;
const TIMEOUT_MS = 60000;
const MAX_TOKENS = 4000;

// Le schéma est envoyé au modèle et sert aussi de filtre au retour : un champ
// qui n'y figure pas est ignoré, quoi que le modèle ait décidé d'ajouter.
const SCHEMA = {
  type: 'object',
  properties: {
    fournisseur: { type: ['string', 'null'], description: "Raison sociale de l'émetteur de la facture" },
    reference: { type: ['string', 'null'], description: 'Numéro de la facture' },
    date_facture: { type: ['string', 'null'], description: "Date d'émission, au format AAAA-MM-JJ" },
    date_echeance: { type: ['string', 'null'], description: 'Date limite de paiement, au format AAAA-MM-JJ' },
    montant_ht: { type: ['number', 'null'], description: 'Total hors taxes' },
    montant_tva: { type: ['number', 'null'], description: 'Montant total de la TVA' },
    montant_ttc: { type: ['number', 'null'], description: 'Total toutes taxes comprises' },
    taux_tva: { type: ['number', 'null'], description: 'Taux de TVA en pourcentage' },
    devise: { type: ['string', 'null'], description: 'Code ISO de la devise, par exemple EUR' },
    siret: { type: ['string', 'null'], description: "SIRET ou SIREN de l'émetteur, chiffres uniquement" },
    numero_tva: { type: ['string', 'null'], description: "Numéro de TVA intracommunautaire de l'émetteur" },
    iban: { type: ['string', 'null'], description: 'IBAN de règlement' },
    objet: { type: ['string', 'null'], description: 'Objet de la facture en une ligne' },
  },
  required: ['fournisseur', 'reference', 'date_facture', 'montant_ht', 'montant_tva', 'montant_ttc', 'devise'],
  additionalProperties: false,
};

const PROMPT = [
  "Tu lis une facture reçue par une entreprise française et tu en extrais les champs demandés.",
  "L'émetteur est le fournisseur, pas le destinataire : ne confonds pas les deux blocs d'adresse.",
  'Les dates sont rendues au format AAAA-MM-JJ. Les montants sont des nombres, sans symbole ni espace, le point comme séparateur décimal.',
  "Un champ que le document ne donne pas vaut null. N'invente aucune valeur, ne complète pas par déduction commerciale.",
  'Réponds uniquement par le JSON demandé.',
].join('\n');

// ---------- Configuration ----------

const KEYS = {
  enabled: 'ai.enabled',
  provider: 'ai.provider',
  model: 'ai.model',
  baseUrl: 'ai.base_url',
  effort: 'ai.effort',
  key: 'ai.key',
  modeleChoisi: 'ai.modele_choisi',
};

/**
 * Les modèles pour lesquels un refus est rejoué par Anthropic sur un autre de
 * ses modèles, au lieu de revenir tel quel.
 *
 * Le repli reste chez le même service : la promesse de l'écran — ce qui part et
 * où — est inchangée. Il ne vaut que pour ces modèles ; un autre, ou un modèle
 * saisi à la main, part sans, plutôt que de risquer une requête refusée.
 */
const AVEC_REPLI = ['claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5', 'claude-fable-5-1'];
const ENTETE_REPLI = 'server-side-fallback-2026-07-01';

/**
 * L'interrupteur d'une fonction. Absent vaut ÉTEINT, et c'est l'inverse de la
 * convention des sections.
 *
 * La raison est la promesse faite à l'écran : rien ne part tant qu'un
 * administrateur n'a pas activé la fonction. Si l'absence valait « allumé », une
 * mise à jour qui ajoute une fonction se mettrait à envoyer du texte chez un
 * tiers sans que personne ne l'ait demandé.
 *
 * « facture » fait exception : elle existait avant les interrupteurs, sous le
 * seul « ai.enabled ». Une instance qui l'utilise déjà ne doit pas la voir
 * s'éteindre en se mettant à jour.
 */
const cleFonction = (cle) => `ai.fonction.${cle}`;

function fonctionActive(cle) {
  if (!fonctionParCle(cle)) return false;
  const pose = settings.get(cleFonction(cle));
  if (pose === '') return cle === 'facture';
  return pose === '1';
}

function activerFonction(cle, actif) {
  if (!fonctionParCle(cle)) return false;
  settings.set(cleFonction(cle), actif ? '1' : '0');
  return true;
}

/** La fonction est-elle utilisable : le service configuré, ET elle activée. */
const fonctionPrete = (cle) => isReady() && fonctionActive(cle);

/** Ce que l'écran montre : chaque fonction, son état, et ce qu'elle envoie. */
const fonctionsAffichees = () => FONCTIONS.map((f) => ({ ...f, active: fonctionActive(f.cle) }));

function providerByKey(key) {
  return PROVIDERS.find((p) => p.key === key) || null;
}

/**
 * Le modèle en service.
 *
 * Vide veut dire « le défaut du service », et c'est ce qui permet à une instance
 * de suivre quand ce défaut change. Mais le formulaire a longtemps enregistré le
 * modèle qu'il affichait, défaut compris : une instance configurée a donc en
 * base l'ancien défaut, sans que personne l'ait choisi. Une valeur égale à un
 * ancien défaut vaut le défaut d'aujourd'hui — sauf si un administrateur l'a
 * saisie lui-même depuis, ce que dit « ai.modele_choisi ».
 */
function modeleRetenu(provider) {
  const pose = settings.get(KEYS.model);
  if (!pose) return provider.defaultModel;
  if (provider.anciensDefauts.includes(pose) && settings.get(KEYS.modeleChoisi) !== '1') return provider.defaultModel;
  return pose;
}

function config() {
  const provider = providerByKey(settings.get(KEYS.provider)) || PROVIDERS[0];
  return {
    enabled: settings.get(KEYS.enabled) === '1',
    provider: provider.key,
    model: modeleRetenu(provider),
    baseUrl: settings.get(KEYS.baseUrl) || '',
    effort: EFFORTS.includes(settings.get(KEYS.effort)) ? settings.get(KEYS.effort) : 'medium',
    key: secrets.decrypt(settings.get(KEYS.key)),
  };
}

/** Ce qu'on affiche : la clé n'en sort jamais, seulement le fait qu'elle est posée. */
function displayConfig() {
  const current = config();
  return { ...current, key: secrets.mask(settings.get(KEYS.key)) };
}

function setConfig(values) {
  const provider = providerByKey(values.provider);
  if (!provider) return { ok: false, message: 'Service inconnu.' };

  const model = String(values.model || '').trim().slice(0, 120) || provider.defaultModel;
  const baseUrl = String(values.baseUrl || '').trim().slice(0, 300);
  if (provider.needsBaseUrl && baseUrl) {
    try {
      const url = new URL(baseUrl);
      if (!['http:', 'https:'].includes(url.protocol)) throw new Error('protocole');
    } catch {
      return { ok: false, message: 'Adresse du service invalide.' };
    }
  }
  if (provider.needsBaseUrl && !baseUrl) return { ok: false, message: "Ce service demande l'adresse de son API." };

  // Une clé laissée vide conserve la précédente : l'écran ne l'affiche pas,
  // il ne peut donc pas la renvoyer.
  const typed = String(values.key || '').trim();
  // Le défaut s'enregistre vide : l'instance suivra le prochain.
  const estDefaut = model === provider.defaultModel;
  settings.set(KEYS.provider, provider.key);
  settings.set(KEYS.model, estDefaut ? '' : model);
  settings.set(KEYS.modeleChoisi, estDefaut ? '' : '1');
  settings.set(KEYS.baseUrl, baseUrl);
  settings.set(KEYS.effort, EFFORTS.includes(values.effort) ? values.effort : 'medium');
  if (typed) settings.set(KEYS.key, secrets.encrypt(typed));

  const enabled = Boolean(values.enabled);
  if (enabled && !config().key) return { ok: false, message: 'Renseignez la clé avant d\'activer l\'analyse.' };
  settings.set(KEYS.enabled, enabled ? '1' : '0');

  return { ok: true };
}

function isReady() {
  const current = config();
  return Boolean(current.enabled && current.key && current.model);
}

function status() {
  const stored = settings.get('ai.status');
  if (!stored) return null;
  try {
    return JSON.parse(stored);
  } catch {
    return null;
  }
}

function recordStatus(result) {
  settings.set('ai.status', JSON.stringify({
    ok: Boolean(result.ok),
    message: String(result.message || '').slice(0, 300),
    at: new Date().toISOString(),
    model: result.model || '',
  }));
}

// ---------- Normalisation de la réponse ----------

const clean = (value, max = 200) => (value === null || value === undefined ? null : String(value).trim().slice(0, max) || null);

function number(value) {
  if (value === null || value === undefined || value === '') return null;
  const parsed = typeof value === 'number' ? value : scan.parseNumber(value);
  return Number.isFinite(parsed) ? Math.round(parsed * 100) / 100 : null;
}

/**
 * Ce que le modèle rend est traité comme une saisie d'utilisateur : filtré par
 * le schéma, normalisé, et jamais repris tel quel.
 */
function normalise(raw) {
  const data = raw && typeof raw === 'object' ? raw : {};
  return {
    supplierName: clean(data.fournisseur),
    reference: clean(data.reference, 60),
    issueDate: scan.parseDate(clean(data.date_facture, 40)),
    dueDate: scan.parseDate(clean(data.date_echeance, 40)),
    amountHt: number(data.montant_ht),
    amountVat: number(data.montant_tva),
    amountTtc: number(data.montant_ttc),
    vatRate: number(data.taux_tva),
    currency: (clean(data.devise, 8) || '').toUpperCase().slice(0, 3) || null,
    siret: (clean(data.siret, 20) || '').replace(/\D/g, '') || null,
    vatNumber: (clean(data.numero_tva, 20) || '').replace(/\s/g, '').toUpperCase() || null,
    iban: (clean(data.iban, 40) || '').replace(/\s/g, '').toUpperCase() || null,
    label: clean(data.objet, 160),
  };
}

// ---------- Appels ----------

function textOf(response) {
  return (response.content || [])
    .filter((block) => block.type === 'text')
    .map((block) => block.text)
    .join('\n');
}

/**
 * API Claude, par le SDK officiel.
 *
 * Avec un schéma, la sortie est contrainte et rendue telle quelle ; sans
 * schéma — un résumé, un brouillon — c'est le texte qui revient.
 */
async function callAnthropic({ consigne, contenu, schema, maxTokens }, current, deps = {}) {
  const Anthropic = require('@anthropic-ai/sdk');
  const client = deps.anthropic || new (Anthropic.default || Anthropic)({ apiKey: current.key, timeout: TIMEOUT_MS });

  const requete = {
    model: current.model,
    max_tokens: maxTokens,
    system: consigne,
    messages: [{ role: 'user', content: contenu }],
    output_config: schema
      ? { format: { type: 'json_schema', schema }, effort: current.effort }
      : { effort: current.effort },
  };

  // Le repli passe par l'espace « beta » du SDK, le seul qui connaît le paramètre.
  const repli = AVEC_REPLI.includes(current.model);
  const messages = repli ? client.beta.messages : client.messages;
  if (repli) Object.assign(requete, { betas: [ENTETE_REPLI], fallbacks: 'default' });

  let response;
  try {
    response = schema
      ? await messages.parse(requete)
      : await (messages.create ? messages.create(requete) : messages.parse(requete));
  } catch (err) {
    return { ok: false, message: `Service indisponible : ${err.message}` };
  }

  // Un refus est un cas normal, pas une panne : l'appelant garde son repli —
  // la lecture par règles pour une facture, l'écran vide pour un résumé.
  if (response.stop_reason === 'refusal') {
    return { ok: false, message: 'Le modèle a refusé de traiter ce document.' };
  }

  const model = response.model || current.model;
  if (!schema) {
    const texte = textOf(response).trim();
    if (!texte) return { ok: false, message: 'Réponse vide du modèle.' };
    return { ok: true, texte, model };
  }

  let parsed = response.parsed_output;
  if (!parsed) {
    try {
      parsed = JSON.parse(textOf(response));
    } catch {
      return { ok: false, message: 'Réponse illisible du modèle.' };
    }
  }
  return { ok: true, donnees: parsed, model };
}

/** Tout service compatible OpenAI, y compris auto-hébergé. */
async function callOpenAiCompatible({ consigne, contenu, schema, maxTokens }, current, deps = {}) {
  const fetchImpl = deps.fetchImpl || fetch;
  const url = `${current.baseUrl.replace(/\/+$/, '')}/chat/completions`;

  let response;
  try {
    response = await fetchImpl(url, {
      method: 'POST',
      headers: { 'content-type': 'application/json', authorization: `Bearer ${current.key}` },
      body: JSON.stringify({
        model: current.model,
        temperature: 0,
        max_tokens: maxTokens,
        ...(schema ? { response_format: { type: 'json_object' } } : {}),
        messages: [
          {
            role: 'system',
            content: schema ? `${consigne}\n\nSchéma attendu :\n${JSON.stringify(schema)}` : consigne,
          },
          { role: 'user', content: contenu },
        ],
      }),
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
  } catch (err) {
    return { ok: false, message: `Service indisponible : ${err.message}` };
  }

  const body = await response.text();
  if (!response.ok) return { ok: false, message: `Service en erreur (${response.status}) : ${body.slice(0, 200)}` };

  let payload;
  try {
    payload = JSON.parse(body);
  } catch {
    return { ok: false, message: 'Réponse illisible du modèle.' };
  }

  const content = payload.choices && payload.choices[0] ? String(payload.choices[0].message.content || '') : '';
  const model = payload.model || current.model;
  if (!schema) {
    return content.trim()
      ? { ok: true, texte: content.trim(), model }
      : { ok: false, message: 'Réponse vide du modèle.' };
  }

  try {
    return { ok: true, donnees: JSON.parse(content), model };
  } catch {
    return { ok: false, message: 'Réponse illisible du modèle.' };
  }
}

/**
 * Un appel d'assistance, quel qu'il soit.
 *
 * C'est le seul chemin vers un service de modèle : la porte de confidentialité
 * est ici, le journal est ici, et le socle ne sait pas envoyer autrement. Une
 * fonction qui voudrait contourner l'un ou l'autre aurait à réécrire ceci, ce
 * qui se voit dans une relecture — un appel direct au SDK ailleurs dans le
 * produit ne se verrait pas.
 *
 * Ne lève jamais : un service injoignable rend un échec, et l'appelant garde son
 * repli.
 */
async function demander({ fonction, consigne, contenu, schema = null, maxTokens = MAX_TOKENS }, deps = {}) {
  const definition = fonctionParCle(fonction);
  if (!definition) return { ok: false, message: 'Fonction d’assistance inconnue.' };
  if (!isReady()) return { ok: false, message: "L'assistance n'est pas configurée." };
  if (!fonctionActive(fonction)) return { ok: false, message: `« ${definition.label} » n’est pas activée.` };

  const brut = String(contenu || '');
  if (!brut.trim()) return { ok: false, message: 'Rien à envoyer.' };

  // La porte, puis la coupe : on nettoie avant de tronquer, pour qu'un secret
  // ne survive pas au ciseau en se retrouvant coupé en deux morceaux anodins.
  const { texte: propre, retires } = confidentialite.nettoyer(brut, { garder: definition.garder });
  const contenuEnvoye = propre.slice(0, MAX_CHARS);

  const current = config();
  const requete = { consigne, contenu: contenuEnvoye, schema, maxTokens };
  const result = current.provider === 'anthropic'
    ? await callAnthropic(requete, current, deps)
    : await callOpenAiCompatible(requete, current, deps);

  journaliser({
    fonction: definition,
    current,
    result,
    caracteres: contenuEnvoye.length,
    retires,
    req: deps.req || null,
  });

  return {
    ...result,
    provider: current.provider,
    retires,
    truncated: propre.length > MAX_CHARS,
  };
}

/**
 * Ce qu'un appel laisse derrière lui.
 *
 * Le contenu envoyé n'est PAS écrit au journal : ce serait recopier dans une
 * table ce qu'on vient de prendre soin de nettoyer. On garde ce qui permet de
 * rendre des comptes — qui, quelle fonction, quel service, quel modèle, combien
 * de caractères, et ce que la porte a retiré.
 */
function journaliser({ fonction, current, result, caracteres, retires, req }) {
  const retraits = confidentialite.combien(retires);
  const contexte = {
    fonction: fonction.cle,
    service: current.provider,
    modele: result.model || current.model,
    caracteres,
    retires: retraits ? retires : undefined,
  };

  const ecrire = result.ok ? logs.info : logs.warning;
  ecrire('integration', result.ok ? 'assistance.appel' : 'assistance.echec',
    result.ok
      ? `${fonction.label} — ${caracteres} caractères envoyés${retraits ? `, ${retraits} retirés avant l'envoi` : ''}`
      : `${fonction.label} — ${String(result.message || '').slice(0, 200)}`,
    { context: contexte, req });
}

/**
 * Analyse un texte de facture. L'enveloppe historique : la lecture par règles
 * reste le repli, et c'est elle qui garde la main sur ce qu'elle sait vérifier.
 */
async function analyse(text, deps = {}) {
  if (!isReady()) return { ok: false, message: "L'analyse par modèle n'est pas activée." };

  const result = await demander({
    fonction: 'facture', consigne: PROMPT, contenu: text, schema: SCHEMA,
  }, deps);

  if (!result.ok) return result;
  return { ...result, fields: normalise(result.donnees) };
}

/** Essai sur une facture d'exemple : le seul moyen de savoir que la clé passe. */
async function test(deps = {}) {
  const sample = [
    'PAPETERIE DU CENTRE SARL',
    'SIRET 732 829 320 00074',
    'FACTURE N° A-2026-88',
    'Date de facture : 04/02/2026',
    'Total HT 200,00 €',
    'TVA 20 % 40,00 €',
    'Net à payer 240,00 €',
  ].join('\n');

  const result = await analyse(sample, deps);
  if (!result.ok) {
    recordStatus(result);
    return result;
  }

  const found = result.fields.amountTtc === 240 && result.fields.reference === 'A-2026-88';
  const verdict = {
    ok: found,
    model: result.model,
    message: found
      ? `Lecture correcte de la facture d'essai (${result.model}).`
      : `Le service a répondu, mais la facture d'essai est mal lue : ${JSON.stringify(result.fields).slice(0, 200)}`,
  };
  recordStatus(verdict);
  return verdict;
}

module.exports = {
  PROVIDERS, FONCTIONS, EFFORTS, SCHEMA, PROMPT, MAX_CHARS, MAX_TOKENS, KEYS, AVEC_REPLI,
  providerByKey, fonctionParCle, fonctionActive, activerFonction, fonctionPrete, fonctionsAffichees,
  config, displayConfig, setConfig, isReady, status, recordStatus,
  normalise, demander, analyse, test,
};
