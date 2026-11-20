const db = require('./db');
const customFields = require('./custom-fields');
const societes = require('./societes');
const cloison = require('./cloison');

/**
 * La corbeille : une suppression qui se défait.
 *
 * Deux dessins étaient possibles, et le choix compte plus que le code.
 *
 * Le premier — la « suppression douce » — pose une colonne « deleted_at » sur
 * chaque table et demande à toutes les lectures de l'écarter. Il restaure
 * parfaitement, puisque rien ne part. Mais il faut alors reprendre chaque
 * requête de lecture : cent quarante-quatre endroits dans cette édition pour
 * les seuls sept objets concernés, et autant dans l'autre. Un seul oubli, et un
 * projet supprimé réapparaît dans un rapport.
 *
 * Le second — celui-ci — met de côté ce que la suppression va emporter, puis
 * laisse la suppression se faire pour de bon. Aucune lecture ne change : ce qui
 * est supprimé est supprimé, et la corbeille est un dépôt à part. Le prix à
 * payer est qu'il faut savoir exactement ce qu'une suppression emporte — les
 * enfants en cascade, les liens mis à nul, les champs personnalisés — et le
 * déclarer. C'est ce que fait OBJETS ci-dessous, et un test vérifie que cette
 * déclaration colle au schéma : une table ajoutée demain avec une cascade vers
 * un objet de la corbeille fera échouer ce test, et non perdre des données en
 * silence.
 *
 * Ce que la corbeille ne couvre pas, et pourquoi : la fiche d'un salarié. Son
 * empreinte traverse tout le produit — quarante-sept tables en cascade,
 * soixante-quatre liens — et la ressusciter à moitié serait pire que la
 * suppression : des sessions, des jetons, des identifiants de messagerie, des
 * documents de coffre-fort reviendraient sans que personne ait décidé lesquels.
 * Le produit offre déjà le bon remède pour « je n'aurais pas dû » : désactiver
 * le compte, qui ne perd rien.
 */

/*
 * Ce qu'une suppression emporte, objet par objet.
 *
 * - « enfants » : les lignes que la cascade détruit. L'ordre compte, et
 *   « parent » dit de quelle table viennent les identifiants à suivre : les
 *   activités d'une opportunité ne se retrouvent qu'après les opportunités.
 * - « liens » : les colonnes que la suppression met à nul ailleurs. La ligne
 *   survit, mais elle a perdu sa référence ; la corbeille note laquelle, pour
 *   la rendre si elle est encore libre.
 * - « etiquette » : ce que l'écran montre. Jamais la charge.
 *
 * Les clés sont celles des champs personnalisés : la corbeille reprend donc
 * leurs valeurs sans table de correspondance, et emprunte aussi leurs libellés
 * — « Fiche tiers » ou « Demande interne » désignent la même chose des deux
 * côtés, et traduire deux fois le même mot finirait par le traduire deux fois
 * différemment.
 */
const OBJETS = {
  projet: {
    table: 'projects',
    libelle: 'champs.obj.projet',
    etiquette: (l) => l.name,
    enfants: [
      { table: 'project_members', colonne: 'project_id', parent: 'projects' },
      { table: 'project_milestones', colonne: 'project_id', parent: 'projects' },
      { table: 'project_tasks', colonne: 'project_id', parent: 'projects' },
      { table: 'project_time', colonne: 'project_id', parent: 'projects' },
    ],
    liens: [{ table: 'app_services', colonne: 'project_id' }],
  },
  ticket: {
    table: 'tickets',
    libelle: 'champs.obj.ticket',
    etiquette: (l) => `${l.reference} — ${l.subject}`,
    enfants: [{ table: 'ticket_messages', colonne: 'ticket_id', parent: 'tickets' }],
    liens: [],
  },
  partenaire: {
    table: 'partners',
    libelle: 'champs.obj.partenaire',
    etiquette: (l) => l.name,
    enfants: [
      { table: 'partner_contracts', colonne: 'partner_id', parent: 'partners' },
      { table: 'partner_contacts', colonne: 'partner_id', parent: 'partners' },
      { table: 'partner_documents', colonne: 'partner_id', parent: 'partners' },
      { table: 'partner_reviews', colonne: 'partner_id', parent: 'partners' },
      { table: 'crm_contacts', colonne: 'partner_id', parent: 'partners' },
      { table: 'opportunities', colonne: 'partner_id', parent: 'partners' },
      { table: 'quotes', colonne: 'partner_id', parent: 'partners' },
      { table: 'crm_activities', colonne: 'partner_id', parent: 'partners' },
      { table: 'sepa_mandates', colonne: 'partner_id', parent: 'partners' },
      { table: 'sales_orders', colonne: 'partner_id', parent: 'partners' },
      { table: 'sales_order_lines', colonne: 'order_id', parent: 'sales_orders' },
      { table: 'delivery_notes', colonne: 'order_id', parent: 'sales_orders' },
      { table: 'delivery_note_lines', colonne: 'note_id', parent: 'delivery_notes' },
      { table: 'delivery_note_lines', colonne: 'order_line_id', parent: 'sales_order_lines' },
      { table: 'sales_invoice_lines', colonne: 'order_line_id', parent: 'sales_order_lines' },
      // Une activité rattachée à une opportunité du tiers, mais pas au tiers
      // lui-même : la cascade l'emporte quand même, par l'opportunité.
      { table: 'crm_activities', colonne: 'opportunity_id', parent: 'opportunities' },
    ],
    liens: [
      { table: 'invoices', colonne: 'partner_id' },
      { table: 'items', colonne: 'partner_id' },
      { table: 'projects', colonne: 'partner_id' },
      { table: 'tickets', colonne: 'partner_id' },
      { table: 'subscriptions', colonne: 'partner_id' },
      { table: 'incoming_documents', colonne: 'partner_id' },
      { table: 'interest_declarations', colonne: 'partner_id' },
      { table: 'gift_records', colonne: 'partner_id' },
    ],
  },
  facture: {
    table: 'invoices',
    libelle: 'champs.obj.facture',
    etiquette: (l) => `${l.reference ? `${l.reference} — ` : ''}${l.label}`,
    enfants: [
      { table: 'dunning_notices', colonne: 'invoice_id', parent: 'invoices' },
      { table: 'sales_invoice_lines', colonne: 'invoice_id', parent: 'invoices' },
      { table: 'stripe_sessions', colonne: 'invoice_id', parent: 'invoices' },
    ],
    liens: [
      { table: 'entries', colonne: 'invoice_id' },
      { table: 'quotes', colonne: 'invoice_id' },
      { table: 'bank_transactions', colonne: 'invoice_id' },
      { table: 'incoming_documents', colonne: 'invoice_id' },
    ],
  },
  equipement: {
    table: 'assets',
    libelle: 'champs.obj.equipement',
    etiquette: (l) => l.name,
    enfants: [{ table: 'asset_assignments', colonne: 'asset_id', parent: 'assets' }],
    liens: [],
  },
  evenement: {
    table: 'company_events',
    libelle: 'champs.obj.evenement',
    etiquette: (l) => l.title,
    enfants: [{ table: 'event_registrations', colonne: 'event_id', parent: 'company_events' }],
    liens: [],
  },
  /*
   * Le type de demande, et non la demande.
   *
   * Une demande ne se supprime jamais seule : elle s'annule, et elle reste.
   * Mais supprimer le TYPE emportait en silence toutes les demandes déjà
   * closes qui le citaient — la garde ne compte que celles en cours. C'est la
   * perte la plus discrète du produit, et c'est celle que la corbeille répare.
   */
  type_demande: {
    table: 'request_forms',
    libelle: 'corbeille.obj.typeDemande',
    etiquette: (l) => l.label,
    enfants: [
      { table: 'request_steps', colonne: 'form_id', parent: 'request_forms' },
      { table: 'workflow_requests', colonne: 'form_id', parent: 'request_forms' },
      { table: 'workflow_decisions', colonne: 'request_id', parent: 'workflow_requests' },
    ],
    liens: [],
  },
};

/** Combien de jours la corbeille garde ce qu'on y jette, sans réglage. */
const JOURS_PAR_DEFAUT = 30;

const colonnes = (table) => db.prepare(`PRAGMA table_info(${table})`).all().map((c) => c.name);

/**
 * Le périmètre, pour les tables qui le portent.
 *
 * La corbeille est un moteur générique : elle touche sept tables, dont
 * certaines sont déjà cloisonnées et d'autres pas encore. Plutôt que de tenir
 * une seconde liste des unes et des autres — qui vieillirait sans qu'on le
 * remarque —, elle demande à la table si elle porte la colonne. On ne jette et
 * on ne remet en place que dans la société où l'on travaille : une facture
 * d'une autre entité du groupe n'est pas « introuvable » par hasard, elle l'est
 * par construction.
 */
function garde(table, alias = '') {
  if (!colonnes(table).includes('company_id')) return { clause: '', params: [] };
  return { clause: ` AND ${alias ? `${alias}.` : ''}company_id = ?`, params: [societes.courant()] };
}

/**
 * Mettre de côté ce que la suppression va emporter.
 *
 * À appeler AVANT la suppression, et avant customFields.oublier : c'est le seul
 * instant où tout est encore là. Rend l'identifiant de la ligne de corbeille,
 * ou null si l'objet est inconnu ou l'enregistrement déjà parti — un appelant
 * n'a donc pas à se protéger pour supprimer.
 */
function jeter(objet, recordId, { parId = null } = {}) {
  const decl = OBJETS[objet];
  if (!decl) return null;
  const id = Number(recordId) || 0;
  if (id <= 0) return null;

  const sienne = garde(decl.table);
  const ligne = db.prepare(`SELECT * FROM ${decl.table} WHERE id = ?${sienne.clause}`)
    .get(id, ...sienne.params);
  if (!ligne) return null;

  // Les identifiants retenus table par table : les enfants d'un enfant les
  // suivent.
  const retenus = { [decl.table]: [id] };
  const enfants = [];
  for (const { table, colonne, parent } of decl.enfants) {
    const parents = retenus[parent] || [];
    if (parents.length === 0) continue;
    const marques = parents.map(() => '?').join(', ');
    const leur = garde(table);
    const lignes = db.prepare(`SELECT * FROM ${table} WHERE ${colonne} IN (${marques})${leur.clause}`)
      .all(...parents, ...leur.params);
    if (lignes.length === 0) continue;
    enfants.push({ table, lignes });
    retenus[table] = (retenus[table] || []).concat(lignes.map((l) => l.id).filter((n) => n != null));
  }

  const liens = [];
  for (const { table, colonne } of decl.liens) {
    const leur = garde(table);
    const ids = db.prepare(`SELECT id FROM ${table} WHERE ${colonne} = ?${leur.clause}`)
      .all(id, ...leur.params).map((l) => l.id);
    if (ids.length > 0) liens.push({ table, colonne, ids });
  }

  /*
   * Les champs personnalisés de tout ce qui part, et non du seul enregistrement
   * jeté : supprimer un type de demande emporte ses demandes, qui sont elles
   * aussi décorables. La correspondance se lit dans les champs personnalisés
   * eux-mêmes — table par table — plutôt que d'être recopiée ici, où elle
   * aurait vieilli.
   */
  const parTable = Object.fromEntries(
    Object.entries(customFields.OBJETS).map(([cle, o]) => [o.table, cle]),
  );
  const champs = [];
  for (const [table, ids] of Object.entries(retenus)) {
    const cle = parTable[table];
    if (!cle || ids.length === 0) continue;
    const marques = ids.map(() => '?').join(', ');
    const lignes = db.prepare(`
      SELECT v.field_id, v.record_id, v.valeur FROM custom_values v
      JOIN custom_fields f ON f.id = v.field_id
      WHERE f.objet = ? AND v.record_id IN (${marques})
    `).all(cle, ...ids);
    if (lignes.length > 0) champs.push({ objet: cle, lignes });
  }

  const charge = JSON.stringify({ ligne, enfants, liens, champs });

  const info = db.prepare(`
    INSERT INTO trash (company_id, objet, record_id, libelle, charge, supprime_par)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(societes.courant(), objet, id,
    String(decl.etiquette(ligne) || `#${id}`).slice(0, 200), charge, parId);

  /*
   * Les valeurs capturées s'effacent ici, puisqu'elles sont désormais dans la
   * charge. Aucune clé étrangère ne les emporterait — la table visée dépend de
   * la colonne « objet » — et les laisser derrière ferait des orphelines qu'un
   * balayage retirerait avant qu'on pense à remettre l'enregistrement en place.
   */
  for (const { objet: cle, lignes } of champs) {
    const ids = [...new Set(lignes.map((l) => l.record_id))];
    const marques = ids.map(() => '?').join(', ');
    db.prepare(`
      DELETE FROM custom_values WHERE record_id IN (${marques})
        AND field_id IN (SELECT id FROM custom_fields WHERE objet = ?)
    `).run(...ids, cle);
  }

  return Number(info.lastInsertRowid);
}

/**
 * La suppression n'a pas eu lieu : défaire la mise de côté.
 *
 * Nécessaire parce que « jeter » passe avant la suppression, et qu'une
 * suppression peut refuser — un type de demande qui porte des demandes en
 * cours, un tiers qu'un bon de commande retient. Sans cela, le refus laisserait
 * une ligne de corbeille pour un enregistrement toujours vivant, et aurait
 * emporté ses champs personnalisés au passage.
 */
function rendre(trashId) {
  const t = ligne(trashId);
  if (!t) return false;

  let charge;
  try {
    charge = JSON.parse(t.charge);
  } catch {
    return false;
  }
  db.transaction(() => {
    for (const groupe of charge.champs || []) {
      for (const c of groupe.lignes || []) {
        db.prepare(`
          INSERT INTO custom_values (field_id, record_id, valeur)
          VALUES (?, ?, ?)
          ON CONFLICT(field_id, record_id) DO UPDATE SET valeur = excluded.valeur
        `).run(c.field_id, c.record_id, c.valeur);
      }
    }
    db.prepare('DELETE FROM trash WHERE id = ? AND company_id = ?').run(t.id, societes.courant());
  })();
  return true;
}

/**
 * Mettre de côté, puis supprimer — dans cet ordre, et sans pouvoir l'inverser.
 *
 * Les sept écrans de suppression appellent ceci plutôt que « jeter » suivi de
 * leur propre suppression : l'ordre est alors tenu par le module et non par
 * chaque appelant, et un refus ou une erreur de la suppression défait la mise
 * de côté au lieu de laisser une corbeille qui mentirait.
 *
 * « supprimer » rend ce que rend la suppression de l'appelant ; false ou une
 * exception comptent pour un échec.
 */
function jeterPuis(objet, recordId, options, supprimer) {
  const trashId = jeter(objet, recordId, options);
  try {
    const verdict = supprimer();
    const echec = verdict === false || (verdict && verdict.ok === false);
    if (echec && trashId) rendre(trashId);
    return verdict;
  } catch (err) {
    if (trashId) rendre(trashId);
    throw err;
  }
}

function lister({ objet = null, limite = 200 } = {}) {
  const sql = objet
    ? `SELECT t.id, t.objet, t.record_id, t.libelle, t.supprime_le,
              u.first_name, u.last_name
         FROM trash t LEFT JOIN users u ON u.id = t.supprime_par
         WHERE t.company_id = ? AND t.objet = ?
         ORDER BY t.supprime_le DESC, t.id DESC LIMIT ?`
    : `SELECT t.id, t.objet, t.record_id, t.libelle, t.supprime_le,
              u.first_name, u.last_name
         FROM trash t LEFT JOIN users u ON u.id = t.supprime_par
         WHERE t.company_id = ?
         ORDER BY t.supprime_le DESC, t.id DESC LIMIT ?`;
  const lignes = objet
    ? db.prepare(sql).all(societes.courant(), objet, Number(limite) || 200)
    : db.prepare(sql).all(societes.courant(), Number(limite) || 200);
  return lignes.map((l) => ({
    ...l,
    auteur: l.first_name ? `${l.first_name} ${l.last_name}` : null,
  }));
}

function ligne(id) {
  return db.prepare('SELECT * FROM trash WHERE id = ? AND company_id = ?')
    .get(Number(id) || 0, societes.courant()) || null;
}

function compter(objet = null) {
  return objet
    ? db.prepare('SELECT COUNT(*) AS n FROM trash WHERE company_id = ? AND objet = ?')
      .get(societes.courant(), objet).n
    : db.prepare('SELECT COUNT(*) AS n FROM trash WHERE company_id = ?')
      .get(societes.courant()).n;
}

/** Réinsérer une ligne sauvegardée, en ne gardant que les colonnes qui existent. */
function reposer(table, ligneSauvee, colonnesConnues) {
  const champsPoses = Object.keys(ligneSauvee).filter((c) => colonnesConnues.includes(c));
  const marques = champsPoses.map(() => '?').join(', ');
  db.prepare(`INSERT INTO ${table} (${champsPoses.join(', ')}) VALUES (${marques})`)
    .run(...champsPoses.map((c) => ligneSauvee[c]));
}

/**
 * Remettre en place.
 *
 * Tout ou rien : une transaction, et la ligne de corbeille ne disparaît que si
 * la remise a réussi. Deux refus possibles, tous deux dits plutôt que devinés :
 * l'identifiant d'origine est repris par un autre enregistrement, ou une table
 * de la sauvegarde n'existe plus — ce qui arrive après une mise à niveau qui
 * retire une fonction.
 *
 * Les colonnes inconnues de la table d'aujourd'hui sont écartées : une
 * sauvegarde faite avant une mise à niveau se remet quand même, en perdant ce
 * que la version actuelle ne sait plus tenir. Mieux vaut cela qu'un refus.
 */
function restaurer(id) {
  const t = ligne(id);
  if (!t) return { ok: false, raison: 'corbeille.err.introuvable' };
  const decl = OBJETS[t.objet];
  if (!decl) return { ok: false, raison: 'corbeille.err.objet' };

  let charge;
  try {
    charge = JSON.parse(t.charge);
  } catch {
    return { ok: false, raison: 'corbeille.err.charge' };
  }

  const sienne = garde(decl.table);
  const dejaLa = db.prepare(`SELECT 1 AS un FROM ${decl.table} WHERE id = ?${sienne.clause}`)
    .get(t.record_id, ...sienne.params);
  if (dejaLa) return { ok: false, raison: 'corbeille.err.reprise' };

  try {
    db.transaction(() => {
      reposer(decl.table, charge.ligne, colonnes(decl.table));

      for (const { table, lignes } of charge.enfants || []) {
        const connues = colonnes(table);
        if (connues.length === 0) continue;
        for (const l of lignes) reposer(table, l, connues);
      }

      // Un lien ne se rend que s'il est encore libre : si quelqu'un a rattaché
      // la facture à un autre tiers depuis, c'est son choix qui gagne.
      for (const { table, colonne, ids } of charge.liens || []) {
        if (!colonnes(table).includes(colonne)) continue;
        const marques = ids.map(() => '?').join(', ');
        const leur = garde(table);
        db.prepare(`
          UPDATE ${table} SET ${colonne} = ?
          WHERE id IN (${marques}) AND ${colonne} IS NULL${leur.clause}
        `).run(t.record_id, ...ids, ...leur.params);
      }

      for (const groupe of charge.champs || []) {
        for (const c of groupe.lignes || []) {
          // Un champ éteint depuis, ou supprimé, ne revient pas : sa définition
          // n'existe plus, et une valeur sans définition ne s'affiche nulle part.
          const existe = db.prepare('SELECT 1 AS un FROM custom_fields WHERE id = ? AND company_id = ?')
            .get(c.field_id, societes.courant());
          if (!existe) continue;
          db.prepare(`
            INSERT INTO custom_values (field_id, record_id, valeur)
            VALUES (?, ?, ?)
            ON CONFLICT(field_id, record_id) DO UPDATE SET valeur = excluded.valeur
          `).run(c.field_id, c.record_id, c.valeur);
        }
      }

      db.prepare('DELETE FROM trash WHERE id = ? AND company_id = ?').run(t.id, societes.courant());
    })();
  } catch (err) {
    return { ok: false, raison: 'corbeille.err.remise', detail: err.message };
  }

  return { ok: true, objet: t.objet, recordId: t.record_id, libelle: t.libelle };
}

/** Vider une ligne : la suppression devient définitive. */
function vider(id) {
  return db.prepare('DELETE FROM trash WHERE id = ? AND company_id = ?')
    .run(Number(id) || 0, societes.courant()).changes > 0;
}

/**
 * La purge par ancienneté.
 *
 * Une corbeille qu'on ne vide jamais garde indéfiniment des données qu'on a
 * voulu supprimer — ce qui est exactement ce qu'un droit à l'effacement
 * interdit. Le délai est réglable ; zéro veut dire « garder sans limite », et
 * c'est un choix explicite, pas le défaut.
 */
function purger({ jours = JOURS_PAR_DEFAUT } = {}) {
  const n = Number(jours);
  if (!Number.isFinite(n) || n <= 0) return 0;
  /*
   * Hors périmètre, et c'est le sens même de la purge : le balayage qui l'appelle
   * tourne sans session, donc sans société. Purger la seule société qui mène
   * garderait indéfiniment, dans les autres, des données qu'on a voulu supprimer
   * — ce qui est exactement ce qu'un droit à l'effacement interdit.
   */
  return cloison.sansPerimetre(() => db
    .prepare(`DELETE FROM trash WHERE supprime_le < datetime('now', ?)`)
    .run(`-${Math.floor(n)} days`).changes);
}

module.exports = {
  OBJETS,
  JOURS_PAR_DEFAUT,
  jeter,
  jeterPuis,
  rendre,
  lister,
  ligne,
  compter,
  restaurer,
  vider,
  purger,
};
