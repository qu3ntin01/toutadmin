const settings = require('./settings');
const secrets = require('./secret-store');
const scan = require('./invoice-scan');

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
    defaultModel: 'claude-opus-5',
    hint: 'Clé « sk-ant-… » créée depuis la console Anthropic.',
    needsBaseUrl: false,
  },
  {
    key: 'openai',
    label: 'Service compatible OpenAI (Mistral, OVHcloud, Scaleway, Ollama…)',
    defaultModel: 'mistral-small-latest',
    hint: "Indiquez l'adresse du service, par exemple https://api.mistral.ai/v1 — un modèle hébergé chez vous ne fait sortir aucune donnée.",
    needsBaseUrl: true,
  },
];

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
};

function providerByKey(key) {
  return PROVIDERS.find((p) => p.key === key) || null;
}

function config() {
  const provider = providerByKey(settings.get(KEYS.provider)) || PROVIDERS[0];
  return {
    enabled: settings.get(KEYS.enabled) === '1',
    provider: provider.key,
    model: settings.get(KEYS.model) || provider.defaultModel,
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
  settings.set(KEYS.provider, provider.key);
  settings.set(KEYS.model, model);
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

/** API Claude, par le SDK officiel : sortie contrainte par le schéma. */
async function callAnthropic(text, current, deps = {}) {
  const Anthropic = require('@anthropic-ai/sdk');
  const client = deps.anthropic || new (Anthropic.default || Anthropic)({ apiKey: current.key, timeout: TIMEOUT_MS });

  let response;
  try {
    response = await client.messages.parse({
      model: current.model,
      max_tokens: MAX_TOKENS,
      system: PROMPT,
      messages: [{ role: 'user', content: text }],
      output_config: { format: { type: 'json_schema', schema: SCHEMA }, effort: current.effort },
    });
  } catch (err) {
    return { ok: false, message: `Service indisponible : ${err.message}` };
  }

  // Un refus est un cas normal, pas une panne : la lecture par règles reste
  // acquise, et c'est elle qui sert de repli.
  if (response.stop_reason === 'refusal') {
    return { ok: false, message: 'Le modèle a refusé de traiter ce document.' };
  }

  let parsed = response.parsed_output;
  if (!parsed) {
    try {
      parsed = JSON.parse(textOf(response));
    } catch {
      return { ok: false, message: 'Réponse illisible du modèle.' };
    }
  }
  return { ok: true, fields: normalise(parsed), model: response.model || current.model };
}

/** Tout service compatible OpenAI, y compris auto-hébergé. */
async function callOpenAiCompatible(text, current, deps = {}) {
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
        max_tokens: MAX_TOKENS,
        response_format: { type: 'json_object' },
        messages: [
          { role: 'system', content: `${PROMPT}\n\nSchéma attendu :\n${JSON.stringify(SCHEMA)}` },
          { role: 'user', content: text },
        ],
      }),
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
  } catch (err) {
    return { ok: false, message: `Service indisponible : ${err.message}` };
  }

  const body = await response.text();
  if (!response.ok) return { ok: false, message: `Service en erreur (${response.status}) : ${body.slice(0, 200)}` };

  try {
    const payload = JSON.parse(body);
    const content = payload.choices && payload.choices[0] ? payload.choices[0].message.content : '';
    return { ok: true, fields: normalise(JSON.parse(content)), model: payload.model || current.model };
  } catch {
    return { ok: false, message: 'Réponse illisible du modèle.' };
  }
}

/**
 * Analyse un texte de facture. Ne lève jamais : un service injoignable rend un
 * échec, et l'appelant garde la lecture par règles.
 */
async function analyse(text, deps = {}) {
  if (!isReady()) return { ok: false, message: "L'analyse par modèle n'est pas activée." };

  const current = config();
  const content = String(text || '').slice(0, MAX_CHARS);
  if (!content.trim()) return { ok: false, message: 'Document sans texte lisible : rien à envoyer.' };

  const result = current.provider === 'anthropic'
    ? await callAnthropic(content, current, deps)
    : await callOpenAiCompatible(content, current, deps);

  return { ...result, provider: current.provider, truncated: String(text || '').length > MAX_CHARS };
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
  PROVIDERS, EFFORTS, SCHEMA, PROMPT, MAX_CHARS, KEYS,
  providerByKey, config, displayConfig, setConfig, isReady, status, recordStatus,
  normalise, analyse, test,
};
