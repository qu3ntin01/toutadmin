const bcrypt = require('bcryptjs');

const db = require('./db');
const csv = require('./csv');
const org = require('./org');
const settings = require('./settings');
const hr = require('./hr');
const grades = require('./grades');
const contractTypes = require('./contract-types');
const modules = require('./modules');
const { isValidEmail, isValidDateString, generatePassword, parseAmount } = require('./utils');

/**
 * Import de données en masse.
 *
 * Reprendre un tableur de deux cents lignes à la main est le moment où l'on
 * renonce à changer d'outil. Trois partis pris rendent l'opération sûre :
 *
 * 1. **Rien n'est écrit avant d'avoir tout vérifié.** L'aperçu contrôle chaque
 *    ligne et nomme le problème avec son numéro de ligne.
 * 2. **Tout ou rien.** Un fichier contenant une seule ligne fautive n'est pas
 *    importé du tout : un import à moitié fait est plus long à rattraper qu'un
 *    fichier à corriger.
 * 3. **Aucune ligne existante n'est modifiée.** L'import crée ; il ne met pas à
 *    jour et n'écrase pas. Un doublon est signalé, jamais silencieusement fondu
 *    dans l'existant.
 */

const MAX_BYTES = 2 * 1024 * 1024;

const text = (value, max) => String(value || '').trim().slice(0, max);

function annualLeaveDays() {
  const configured = Number(settings.get('annual_leave_days'));
  return Number.isFinite(configured) ? configured : hr.DEFAULT_ANNUAL_LEAVE;
}

/** Retrouve un service ou une équipe par son nom, la casse et les espaces en moins. */
function findByName(list, name) {
  const wanted = String(name || '').trim().toLowerCase();
  return list.find((row) => row.name.trim().toLowerCase() === wanted) || null;
}

const ENTITIES = [
  {
    key: 'membres',
    label: 'Membres du personnel',
    right: 'admin',
    hint: "Un compte est créé pour chaque ligne, avec un mot de passe temporaire à remettre à l'intéressé. Les colonnes service et équipe désignent des rattachements existants, par leur nom.",
    columns: [
      { name: 'prenom', label: 'Prénom', required: true },
      { name: 'nom', label: 'Nom', required: true },
      { name: 'email', label: 'Adresse email', required: true },
      { name: 'grade', label: `Grade (${grades.join(', ')})`, required: true },
      { name: 'type_contrat', label: `Type de contrat (${contractTypes.join(', ')})`, required: true },
      { name: 'service', label: 'Service (nom exact)' },
      { name: 'equipe', label: 'Équipe (nom exact)' },
      { name: 'fin_contrat', label: 'Fin de contrat (AAAA-MM-JJ)' },
      { name: 'tjm', label: 'Taux journalier (freelance)' },
    ],
    prepare() {
      return {
        departments: org.departments(),
        teams: org.teams(),
        emails: new Set(db.prepare('SELECT email FROM users').all().map((r) => r.email)),
      };
    },
    validate(row, context) {
      const email = text(row.email, 254).toLowerCase();
      const grade = text(row.grade, 60);
      const contractType = text(row.type_contrat, 40);

      if (!text(row.prenom, 100) || !text(row.nom, 100)) return { ok: false, message: 'Prénom et nom sont requis.' };
      if (!isValidEmail(email)) return { ok: false, message: `Adresse email invalide : ${email || '(vide)'}` };
      if (context.emails.has(email)) return { ok: false, message: `Un compte existe déjà avec ${email}` };
      if (!grades.includes(grade)) return { ok: false, message: `Grade inconnu : ${grade}` };
      if (!contractTypes.includes(contractType)) return { ok: false, message: `Type de contrat inconnu : ${contractType}` };

      const department = row.service ? findByName(context.departments, row.service) : null;
      if (row.service && !department) return { ok: false, message: `Service introuvable : ${row.service}` };

      const team = row.equipe ? findByName(context.teams, row.equipe) : null;
      if (row.equipe && !team) return { ok: false, message: `Équipe introuvable : ${row.equipe}` };

      const endDate = text(row.fin_contrat, 10);
      if (endDate && !isValidDateString(endDate)) return { ok: false, message: `Date de fin invalide : ${endDate}` };

      const rate = text(row.tjm, 20);
      const dailyRate = rate ? parseAmount(rate) : null;
      if (rate && (!Number.isFinite(dailyRate) || dailyRate < 0)) return { ok: false, message: `Taux journalier invalide : ${rate}` };

      // Le fichier est vérifié contre lui-même autant que contre la base :
      // deux lignes portant la même adresse doivent se voir avant l'écriture.
      context.emails.add(email);

      return {
        ok: true,
        summary: `${text(row.prenom, 100)} ${text(row.nom, 100)} · ${email} · ${grade}`,
        values: {
          firstName: text(row.prenom, 100),
          lastName: text(row.nom, 100),
          email,
          grade,
          contractType,
          departmentId: department ? department.id : null,
          teamId: team ? team.id : null,
          endDate: endDate || null,
          dailyRate: dailyRate === null || Number.isNaN(dailyRate) ? null : dailyRate,
        },
      };
    },
    insert(values) {
      const password = generatePassword();
      const created = db.prepare(`
        INSERT INTO users (role, email, password_hash, first_name, last_name, grade, contract_type,
                           contract_end_date, daily_rate, leave_balance, active, must_change_password)
        VALUES ('employee', ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)
      `).run(values.email, bcrypt.hashSync(password, 12), values.firstName, values.lastName, values.grade,
        values.contractType, values.endDate, values.dailyRate,
        values.contractType === 'Freelance' ? 0 : annualLeaveDays()).lastInsertRowid;

      org.assignMembership(Number(created), { departmentId: values.departmentId, teamId: values.teamId });
      // Le mot de passe temporaire est rendu à l'écran une seule fois : il n'est
      // stocké nulle part en clair, pas même dans un journal.
      return { id: created, credential: { email: values.email, password } };
    },
  },
  {
    key: 'tiers',
    label: 'Clients et fournisseurs',
    right: 'finance',
    hint: 'Les tiers de la gestion : clients, fournisseurs, ou les deux.',
    columns: [
      { name: 'nom', label: 'Raison sociale', required: true },
      { name: 'type', label: 'Type (Client, Fournisseur, Client et fournisseur)', required: true },
      { name: 'identifiant', label: 'SIRET ou numéro de TVA' },
      { name: 'contact', label: 'Personne à contacter' },
      { name: 'email', label: 'Adresse email' },
      { name: 'telephone', label: 'Téléphone' },
      { name: 'adresse', label: 'Adresse' },
    ],
    prepare() {
      return { names: new Set(db.prepare('SELECT name FROM partners').all().map((r) => r.name.toLowerCase())) };
    },
    validate(row, context) {
      const name = text(row.nom, 160);
      const kind = text(row.type, 40);
      const kinds = ['Client', 'Fournisseur', 'Client et fournisseur'];

      if (!name) return { ok: false, message: 'La raison sociale est requise.' };
      if (context.names.has(name.toLowerCase())) return { ok: false, message: `Ce tiers existe déjà : ${name}` };
      if (!kinds.includes(kind)) return { ok: false, message: `Type inconnu : ${kind || '(vide)'}` };

      const email = text(row.email, 254);
      if (email && !isValidEmail(email)) return { ok: false, message: `Adresse email invalide : ${email}` };

      context.names.add(name.toLowerCase());
      return {
        ok: true,
        summary: `${name} · ${kind}`,
        values: {
          name, kind,
          registration: text(row.identifiant, 40),
          contactName: text(row.contact, 120),
          email,
          phone: text(row.telephone, 40),
          address: text(row.adresse, 300),
        },
      };
    },
    insert(values) {
      const id = db.prepare(`
        INSERT INTO partners (kind, name, registration, contact_name, email, phone, address)
        VALUES (?, ?, ?, ?, ?, ?, ?)
      `).run(values.kind, values.name, values.registration, values.contactName, values.email, values.phone, values.address).lastInsertRowid;
      return { id };
    },
  },
  {
    key: 'articles',
    label: 'Articles de stock',
    right: 'finance',
    module: 'stock',
    hint: "Le catalogue d'articles. Les mouvements d'entrée et de sortie se saisissent ensuite depuis l'espace Stock.",
    columns: [
      { name: 'libelle', label: 'Libellé', required: true },
      { name: 'reference', label: 'Référence' },
      { name: 'unite', label: 'Unité (unité, kg, litre…)' },
      { name: 'categorie', label: 'Catégorie' },
      { name: 'stock_min', label: "Seuil d'alerte" },
      { name: 'prix_unitaire', label: 'Prix unitaire' },
    ],
    prepare() {
      return { references: new Set(db.prepare("SELECT reference FROM items WHERE reference != ''").all().map((r) => r.reference.toLowerCase())) };
    },
    validate(row, context) {
      const label = text(row.libelle, 160);
      const reference = text(row.reference, 60);
      if (!label) return { ok: false, message: 'Le libellé est requis.' };
      if (reference && context.references.has(reference.toLowerCase())) {
        return { ok: false, message: `Référence déjà utilisée : ${reference}` };
      }

      const stockMin = row.stock_min ? parseAmount(row.stock_min) : 0;
      if (row.stock_min && (!Number.isFinite(stockMin) || stockMin < 0)) return { ok: false, message: `Seuil invalide : ${row.stock_min}` };

      const price = row.prix_unitaire ? parseAmount(row.prix_unitaire) : null;
      if (row.prix_unitaire && (!Number.isFinite(price) || price < 0)) return { ok: false, message: `Prix invalide : ${row.prix_unitaire}` };

      if (reference) context.references.add(reference.toLowerCase());
      return {
        ok: true,
        summary: `${label}${reference ? ` (${reference})` : ''}`,
        values: {
          label, reference,
          unit: text(row.unite, 20) || 'unité',
          category: text(row.categorie, 60),
          stockMin: stockMin || 0,
          unitPrice: price === null || Number.isNaN(price) ? null : price,
        },
      };
    },
    insert(values) {
      const id = db.prepare(`
        INSERT INTO items (reference, label, unit, category, stock_min, unit_price)
        VALUES (?, ?, ?, ?, ?, ?)
      `).run(values.reference, values.label, values.unit, values.category, values.stockMin, values.unitPrice).lastInsertRowid;
      return { id };
    },
  },
  {
    key: 'contacts',
    label: 'Contacts commerciaux',
    right: 'finance',
    module: 'crm',
    hint: 'Chaque contact est rattaché à un tiers existant, désigné par sa raison sociale.',
    columns: [
      { name: 'tiers', label: 'Tiers (raison sociale exacte)', required: true },
      { name: 'prenom', label: 'Prénom', required: true },
      { name: 'nom', label: 'Nom', required: true },
      { name: 'fonction', label: 'Fonction' },
      { name: 'email', label: 'Adresse email' },
      { name: 'telephone', label: 'Téléphone' },
    ],
    prepare() {
      return { partners: db.prepare('SELECT id, name FROM partners').all() };
    },
    validate(row, context) {
      const partner = findByName(context.partners, row.tiers);
      if (!partner) return { ok: false, message: `Tiers introuvable : ${row.tiers || '(vide)'}` };
      if (!text(row.prenom, 100) || !text(row.nom, 100)) return { ok: false, message: 'Prénom et nom sont requis.' };

      const email = text(row.email, 254);
      if (email && !isValidEmail(email)) return { ok: false, message: `Adresse email invalide : ${email}` };

      return {
        ok: true,
        summary: `${text(row.prenom, 100)} ${text(row.nom, 100)} · ${partner.name}`,
        values: {
          partnerId: partner.id,
          firstName: text(row.prenom, 100),
          lastName: text(row.nom, 100),
          role: text(row.fonction, 120),
          email,
          phone: text(row.telephone, 40),
        },
      };
    },
    insert(values) {
      const id = db.prepare(`
        INSERT INTO crm_contacts (partner_id, first_name, last_name, role, email, phone)
        VALUES (?, ?, ?, ?, ?, ?)
      `).run(values.partnerId, values.firstName, values.lastName, values.role, values.email, values.phone).lastInsertRowid;
      return { id };
    },
  },
];

function byKey(key) {
  return ENTITIES.find((e) => e.key === key) || null;
}

/** Les imports ouverts à cette personne : ses droits, et les modules activés. */
function availableFor(user) {
  return ENTITIES.filter((entity) => {
    if (entity.module && !modules.isEnabled(entity.module)) return false;
    if (user.role === 'admin') return true;
    if (entity.right === 'admin') return false;
    if (entity.right === 'finance') return Boolean(user.is_finance);
    return false;
  });
}

/** Le modèle de fichier : les en-têtes attendus, dans l'ordre. */
function template(entity) {
  return entity.columns.map((c) => c.name).join(';');
}

/**
 * Contrôle le fichier sans rien écrire. Rend chaque ligne avec son verdict,
 * son numéro de ligne d'origine, et le compte des erreurs.
 */
function preview(entityKey, content) {
  const entity = byKey(entityKey);
  if (!entity) return { ok: false, message: 'Type de données inconnu.' };

  const parsed = csv.parse(content);
  if (!parsed.ok) return { ok: false, message: parsed.message };

  const missing = entity.columns.filter((c) => c.required && !parsed.headers.includes(c.name));
  if (missing.length) {
    return { ok: false, message: `Colonne(s) obligatoire(s) absente(s) : ${missing.map((c) => c.name).join(', ')}.` };
  }

  const context = entity.prepare();
  const rows = parsed.rows.map((row) => {
    const verdict = entity.validate(row, context);
    // Une ligne refusée doit rester reconnaissable : sans son contenu brut, on
    // ne sait pas laquelle corriger dans le tableur.
    const raw = Object.keys(row).filter((key) => key !== '__line' && row[key] !== '')
      .map((key) => row[key]).join(' · ').slice(0, 160);
    return {
      line: row.__line,
      ok: verdict.ok,
      message: verdict.message || '',
      summary: verdict.summary || raw,
      values: verdict.values,
    };
  });

  return {
    ok: true,
    entity: { key: entity.key, label: entity.label },
    delimiter: parsed.delimiter,
    headers: parsed.headers,
    rows,
    valid: rows.filter((r) => r.ok).length,
    errors: rows.filter((r) => !r.ok),
  };
}

/**
 * Écrit, une fois seulement si tout est bon. Le contrôle est refait ici : entre
 * l'aperçu et la confirmation, la base a pu changer — un compte créé entre-temps
 * ne doit pas passer.
 */
function commit(entityKey, content) {
  const checked = preview(entityKey, content);
  if (!checked.ok) return checked;
  if (checked.errors.length) {
    return { ok: false, message: `${checked.errors.length} ligne(s) en erreur : rien n'a été importé.`, preview: checked };
  }
  if (!checked.rows.length) return { ok: false, message: 'Aucune ligne à importer.' };

  const entity = byKey(entityKey);
  const results = [];
  const write = db.transaction(() => {
    for (const row of checked.rows) results.push({ line: row.line, ...entity.insert(row.values) });
  });
  write();

  return {
    ok: true,
    imported: results.length,
    entity: { key: entity.key, label: entity.label },
    credentials: results.filter((r) => r.credential).map((r) => r.credential),
  };
}

module.exports = { ENTITIES, MAX_BYTES, byKey, availableFor, template, preview, commit };
