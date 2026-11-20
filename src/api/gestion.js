const db = require('../db');
const societes = require('../societes');
const finance = require('../finance');
const billing = require('../billing');
const partners = require('../partners');
const purchasing = require('../purchasing');
const treasury = require('../treasury');
const currency = require('../currency');
const { page, wrap, erreur, absent, texte, entier, date, compter } = require('./aide');

/**
 * Gestion : tiers, factures, abonnements, achats, trésorerie.
 *
 * Les montants sortent toujours accompagnés de leur devise **et** de leur
 * contre-valeur en devise de référence, au taux figé à l'émission. Un total
 * qui mêle des devises ne veut rien dire, et un appelant qui reconvertirait
 * lui-même au taux du jour donnerait un autre chiffre que l'écran.
 */

const facture = (i) => ({
  id: i.id,
  reference: i.reference,
  libelle: i.label,
  sens: i.direction,
  statut: i.status,
  emise_le: i.issue_date,
  echeance: i.due_date,
  en_retard: i.overdue,
  montant_ht: i.amount_ht,
  tva: i.vat_rate,
  montant_ttc: i.amount_ttc,
  devise: i.currency,
  taux: i.exchange_rate,
  montant_ttc_reference: i.amount_base_ttc,
  devise_reference: currency.base(),
  tiers: i.partner_name,
  tiers_id: i.partner_id,
});

module.exports = {
  cle: 'gestion',
  titre: 'Gestion et facturation',
  resume: 'Tiers, factures, abonnements, bons de commande, comptes bancaires.',
  fonctions: [
    {
      methode: 'GET',
      chemin: '/tiers',
      portee: 'gestion',
      resume: 'Les clients et fournisseurs.',
      parametres: [
        { nom: 'type', dans: 'requete', type: 'texte', description: 'Client, Fournisseur…' },
        { nom: 'limite', dans: 'requete', type: 'entier', defaut: 50 },
        { nom: 'depuis', dans: 'requete', type: 'entier', defaut: 0 },
      ],
      rend: ['id', 'type', 'nom', 'identifiant', 'email', 'telephone', 'actif'],
      faire: (req, res) => {
        const { limit, offset } = page(req);
        const type = texte(req.query, 'type', 40);
        const where = type ? 'AND kind = ?' : '';
        /* Le jeton n'a pas de session : hors contexte, « courant » rend la
           société qui mène. Voir societes.perimetreParDefaut(). */
        const args = type ? [societes.courant(), type] : [societes.courant()];

        const total = compter(`SELECT COUNT(*) AS n FROM partners WHERE company_id = ? ${where}`, ...args);
        const rows = db.prepare(`
          SELECT id, kind, name, registration, email, phone, active FROM partners
          WHERE company_id = ? ${where}
          ORDER BY name COLLATE NOCASE LIMIT ? OFFSET ?
        `).all(...args, limit, offset);

        res.json(wrap(rows.map((r) => ({
          id: r.id, type: r.kind, nom: r.name, identifiant: r.registration,
          email: r.email, telephone: r.phone, actif: Boolean(r.active),
        })), { limit, offset }, total));
      },
    },
    {
      methode: 'GET',
      chemin: '/tiers/:id',
      portee: 'gestion',
      resume: 'La fiche d’un tiers : contacts, pièces, évaluations.',
      parametres: [{ nom: 'id', dans: 'chemin', type: 'entier', requis: true }],
      rend: ['id', 'nom', 'type', 'contacts', 'documents', 'evaluations', 'note'],
      faire: (req, res) => {
        const id = entier(req.params.id);
        const tiers = finance.partnerById(id);
        if (!tiers) return absent(res, 'Tiers');

        return res.json({
          id: tiers.id, nom: tiers.name, type: tiers.kind,
          identifiant: tiers.registration, email: tiers.email, telephone: tiers.phone,
          adresse: tiers.address, actif: Boolean(tiers.active),
          contacts: partners.contacts(id),
          documents: partners.documents(id),
          evaluations: partners.reviews(id),
          note: partners.reviewScore(id),
        });
      },
    },
    {
      methode: 'GET',
      chemin: '/factures',
      portee: 'gestion',
      resume: 'Les factures, dans les deux sens.',
      parametres: [
        { nom: 'sens', dans: 'requete', type: 'texte', description: 'Client ou Fournisseur.' },
        { nom: 'limite', dans: 'requete', type: 'entier', defaut: 50 },
        { nom: 'depuis', dans: 'requete', type: 'entier', defaut: 0 },
      ],
      rend: ['id', 'reference', 'libelle', 'sens', 'statut', 'emise_le', 'echeance', 'en_retard', 'montant_ht', 'tva', 'montant_ttc', 'devise', 'taux', 'montant_ttc_reference', 'tiers'],
      faire: (req, res) => {
        const { limit, offset } = page(req);
        const sens = finance.INVOICE_DIRECTIONS.includes(req.query.sens) ? req.query.sens : null;

        const toutes = finance.invoices(sens ? { direction: sens } : {});
        res.json(wrap(toutes.slice(offset, offset + limit).map(facture), { limit, offset }, toutes.length));
      },
    },
    {
      methode: 'GET',
      chemin: '/factures/:id',
      portee: 'gestion',
      resume: 'Une facture, par son identifiant.',
      parametres: [{ nom: 'id', dans: 'chemin', type: 'entier', requis: true }],
      rend: ['id', 'reference', 'libelle', 'sens', 'statut', 'montant_ttc', 'devise', 'tiers'],
      faire: (req, res) => {
        const trouvee = finance.invoices().find((i) => i.id === entier(req.params.id));
        if (!trouvee) return absent(res, 'Facture');
        return res.json(facture(trouvee));
      },
    },
    {
      methode: 'GET',
      chemin: '/abonnements',
      portee: 'gestion',
      resume: 'Les facturations récurrentes et leur prochaine échéance.',
      rend: ['id', 'libelle', 'sens', 'tiers', 'montant_ht', 'devise', 'periodicite', 'prochaine_emission', 'fin', 'actif'],
      faire: (req, res) => res.json(wrap(billing.list().map((a) => ({
        id: a.id, libelle: a.label, sens: a.direction, tiers: a.partner_name,
        montant_ht: a.amount_ht, devise: a.currency, periodicite: a.period,
        prochaine_emission: a.next_issue, fin: a.end_date, actif: Boolean(a.active),
      })), { limit: 200, offset: 0 })),
    },
    {
      methode: 'GET',
      chemin: '/commandes',
      portee: 'gestion',
      resume: 'Les bons de commande fournisseurs.',
      parametres: [{ nom: 'ouvertes', dans: 'requete', type: 'booleen', description: 'À 1, masque les commandes reçues ou annulées.' }],
      rend: ['id', 'reference', 'tiers', 'statut', 'montant_ht', 'devise', 'passee_le', 'attendue_le'],
      faire: (req, res) => {
        const ouvertes = String(req.query.ouvertes || '') === '1';
        res.json(wrap(purchasing.orders({ includeClosed: !ouvertes }).map((o) => ({
          id: o.id, reference: o.reference, tiers: o.partner_name, statut: o.status,
          montant_ht: o.amount_ht, devise: o.currency, passee_le: o.ordered_on, attendue_le: o.expected_on,
        })), { limit: 200, offset: 0 }));
      },
    },
    {
      methode: 'GET',
      chemin: '/comptes',
      portee: 'gestion',
      resume: 'Les comptes bancaires et leur solde.',
      details: "Le solde est calculé depuis les mouvements enregistrés dans l'instance, non lu chez la banque : c'est le solde comptable, pas le solde bancaire.",
      rend: ['id', 'libelle', 'banque', 'iban_masque', 'devise', 'solde'],
      faire: (req, res) => res.json(wrap(treasury.accounts({ includeClosed: true }).map((c) => ({
        id: c.id, libelle: c.label, banque: c.bank, devise: c.currency, solde: c.balance,
        // L'IBAN complet ne sort pas : il suffit à initier un prélèvement.
        iban_masque: c.iban ? `…${String(c.iban).slice(-4)}` : '',
        actif: Boolean(c.active),
      })), { limit: 200, offset: 0 })),
    },

    // ---------- Écriture ----------

    {
      methode: 'POST',
      chemin: '/tiers',
      portee: 'gestion:ecriture',
      resume: 'Crée un client ou un fournisseur.',
      journal: 'api.tiers_cree',
      parametres: [
        { nom: 'type', dans: 'corps', type: 'texte', requis: true, description: 'Client, Fournisseur…' },
        { nom: 'nom', dans: 'corps', type: 'texte', requis: true },
        { nom: 'identifiant', dans: 'corps', type: 'texte', description: 'SIRET, numéro de TVA…' },
        { nom: 'email', dans: 'corps', type: 'texte' },
        { nom: 'telephone', dans: 'corps', type: 'texte' },
        { nom: 'adresse', dans: 'corps', type: 'texte' },
      ],
      rend: ['id', 'nom', 'type'],
      faire: (req, res) => {
        const type = texte(req.body, 'type', 40);
        const nom = texte(req.body, 'nom', 160);
        if (!finance.PARTNER_KINDS.includes(type)) {
          return erreur(res, 422, 'Type inconnu.', { attendus: finance.PARTNER_KINDS });
        }
        if (!nom) return erreur(res, 422, 'Un nom est requis.');

        const id = Number(finance.createPartner({
          kind: type, name: nom,
          registration: texte(req.body, 'identifiant', 60),
          email: texte(req.body, 'email', 160),
          phone: texte(req.body, 'telephone', 40),
          address: texte(req.body, 'adresse', 400),
        }));
        return res.status(201).json({ id, nom, type });
      },
    },
    {
      methode: 'POST',
      chemin: '/factures',
      portee: 'gestion:ecriture',
      resume: 'Émet une facture.',
      details: "Le taux de change est figé à l'émission, comme à l'écran : la contre-valeur ne bougera plus.",
      journal: 'api.facture_creee',
      parametres: [
        { nom: 'sens', dans: 'corps', type: 'texte', requis: true, description: 'Client ou Fournisseur.' },
        { nom: 'libelle', dans: 'corps', type: 'texte', requis: true },
        { nom: 'montant_ht', dans: 'corps', type: 'nombre', requis: true },
        { nom: 'tva', dans: 'corps', type: 'nombre', requis: true, description: 'Le taux, en pourcentage.' },
        { nom: 'emise_le', dans: 'corps', type: 'date', requis: true },
        { nom: 'echeance', dans: 'corps', type: 'date' },
        { nom: 'tiers', dans: 'corps', type: 'entier' },
        { nom: 'reference', dans: 'corps', type: 'texte' },
        { nom: 'devise', dans: 'corps', type: 'texte', description: 'Par défaut, la devise de référence.' },
      ],
      rend: ['id', 'reference', 'montant_ttc', 'devise'],
      faire: (req, res) => {
        const corps = req.body || {};
        const sens = texte(corps, 'sens', 20);
        const libelle = texte(corps, 'libelle', 200);
        const montant = Number(corps.montant_ht);
        const tva = Number(corps.tva);
        const emise = date(corps.emise_le);

        if (!finance.INVOICE_DIRECTIONS.includes(sens)) {
          return erreur(res, 422, 'Sens inconnu.', { attendus: finance.INVOICE_DIRECTIONS });
        }
        if (!libelle) return erreur(res, 422, 'Un libellé est requis.');
        if (!Number.isFinite(montant) || montant < 0) return erreur(res, 422, 'Un montant hors taxes positif est requis.');
        if (!Number.isFinite(tva) || tva < 0 || tva > 100) return erreur(res, 422, 'Un taux de TVA entre 0 et 100 est requis.');
        if (!emise) return erreur(res, 422, "Une date d'émission au format AAAA-MM-JJ est requise.");

        const tiers = entier(corps.tiers);
        if (tiers !== null && !finance.partnerById(tiers)) return erreur(res, 422, 'Ce tiers n’existe pas.');

        const id = finance.createInvoice({
          direction: sens, partnerId: tiers, label: libelle,
          reference: texte(corps, 'reference', 60),
          issueDate: emise, dueDate: date(corps.echeance) || null,
          amountHt: montant, vatRate: tva,
          currency: texte(corps, 'devise', 3).toUpperCase() || undefined,
          createdBy: null,
        });
        // createInvoice rend null quand la devise demandée n'a pas de taux connu.
        if (!id) return erreur(res, 422, 'Devise inconnue : aucun taux de change n’est enregistré pour elle.');

        const creee = finance.invoiceById(Number(id));
        return res.status(201).json({
          id: Number(id), reference: creee.reference,
          montant_ttc: creee.amount_ttc, devise: creee.currency,
        });
      },
    },
    {
      methode: 'PATCH',
      chemin: '/factures/:id',
      portee: 'gestion:ecriture',
      resume: 'Change le statut d’une facture — la marquer payée, par exemple.',
      journal: 'api.facture_statut',
      parametres: [
        { nom: 'id', dans: 'chemin', type: 'entier', requis: true },
        { nom: 'statut', dans: 'corps', type: 'texte', requis: true, description: 'Émise, Payée, Annulée…' },
      ],
      rend: ['id', 'statut'],
      faire: (req, res) => {
        const id = entier(req.params.id);
        const statut = texte(req.body, 'statut', 30);

        if (!finance.invoiceById(id)) return absent(res, 'Facture');
        if (!finance.INVOICE_STATUSES.includes(statut)) {
          return erreur(res, 422, 'Statut inconnu.', { attendus: finance.INVOICE_STATUSES });
        }
        if (!finance.setInvoiceStatus(id, statut)) return erreur(res, 409, 'Statut inchangé.');

        return res.json({ id, statut });
      },
    },
  ],
};
