/**
 * Les sections de la console, et ce qu'elles contiennent.
 *
 * Une seule liste, tenue ici : le rail la parcourt pour se construire, les
 * écrans d'accueil de section pour choisir leurs tuiles. Deux copies de la même
 * liste auraient fini par diverger, et une rubrique qui figure dans l'une et
 * pas dans l'autre est un service qu'on ne retrouve plus.
 *
 * ── Pourquoi des groupes ─────────────────────────────────────────────────
 *
 * La console comptait trente-deux entrées à la file. Sur un portable, les
 * dernières tombaient sous le bord de l'écran, et les trouver demandait de
 * faire défiler un rail — c'est-à-dire de savoir déjà qu'elles existaient. Sept
 * groupes repliés valent mieux que trente-deux lignes dépliées : on choisit
 * d'abord un métier, puis un écran dedans.
 *
 * ── Ce qui compose une entrée ────────────────────────────────────────────
 *
 *   cle      ce qui la nomme dans le code et dans les adresses ;
 *   titre    la clé de traduction de son nom — jamais le mot lui-même ;
 *   quoi     la clé de traduction de ce qu'elle fait, en une ligne. C'est ce
 *            qui s'écrit sous la tuile : « Émettre une facture » se comprend,
 *            « Facturation » se devine ;
 *   href     où elle mène ;
 *   icone    celle du rail et de la tuile — la même des deux côtés, pour qu'on
 *            reconnaisse au tableau de bord ce qu'on a vu dans le rail ;
 *   groupe   la section à laquelle elle appartient ;
 *   prefixes les chemins qui lui appartiennent, pour allumer la bonne rubrique.
 *            Absent, on prend « href ».
 */

/** Les groupes, dans l'ordre où le rail les pose. */
const GROUPES = [
  { cle: 'pilotage', titre: 'group.pilotage', icone: 'home' },
  { cle: 'personnel', titre: 'group.personnel', icone: 'users' },
  { cle: 'gestion', titre: 'group.gestion', icone: 'euro' },
  { cle: 'relation', titre: 'group.relation', icone: 'lifebuoy' },
  { cle: 'technique', titre: 'group.technique', icone: 'tool' },
  { cle: 'conformite', titre: 'group.conformite', icone: 'scale' },
  { cle: 'reglages', titre: 'group.reglages', icone: 'settings' },
];

/**
 * Le catalogue.
 *
 * Les entrées marquées « onglet » vivent dans la console elle-même : elles
 * ouvrent un panneau de /admin plutôt qu'une page à elles. Le rail ne fait pas
 * la différence — et l'utilisateur non plus, ce qui est le but.
 */
const CATALOGUE = [
  // ---------------------------------------------------------- Pilotage
  { cle: 'pilotage', titre: 'nav.steering', quoi: 'quoi.steering', href: '/pilotage',
    icone: 'home', groupe: 'pilotage', droit: 'pilotage' },
  { cle: 'direction', titre: 'nav.governance', quoi: 'quoi.governance', href: '/direction',
    icone: 'ballot', groupe: 'pilotage', droit: 'admin' },
  { cle: 'qualite', titre: 'nav.quality', quoi: 'quoi.quality', href: '/qualite',
    icone: 'check', groupe: 'pilotage', droit: 'encadrement' },
  { cle: 'organigramme', titre: 'nav.orgChart', quoi: 'quoi.orgChart', href: '/organigramme',
    icone: 'network', groupe: 'pilotage', droit: 'tous' },

  // --------------------------------------------------------- Personnel
  { cle: 'personnel', titre: 'nav.personnel', quoi: 'quoi.personnel', href: '/admin#personnel',
    icone: 'users', groupe: 'personnel', onglet: 'personnel', droit: 'admin' },
  { cle: 'rh', titre: 'nav.hr', quoi: 'quoi.hr', href: '/admin#rh',
    icone: 'user-check', groupe: 'personnel', onglet: 'rh', droit: 'admin' },
  { cle: 'parcours', titre: 'nav.careerPaths', quoi: 'quoi.careerPaths', href: '/parcours',
    icone: 'trending', groupe: 'personnel', droit: 'rh' },
  { cle: 'sante', titre: 'nav.healthSafety', quoi: 'quoi.healthSafety', href: '/sante-securite',
    icone: 'heart', groupe: 'personnel', droit: 'rh' },
  { cle: 'planning', titre: 'nav.planning', quoi: 'quoi.planning', href: '/planning',
    icone: 'clock', groupe: 'personnel', droit: 'tous' },
  { cle: 'organisation', titre: 'nav.organisation', quoi: 'quoi.organisation', href: '/admin#organisation',
    icone: 'building', groupe: 'personnel', onglet: 'organisation', droit: 'admin' },
  { cle: 'affectations', titre: 'nav.assignments', quoi: 'quoi.assignments', href: '/admin#affectations',
    icone: 'link', groupe: 'personnel', onglet: 'affectations', droit: 'admin' },

  { cle: 'paie', titre: 'nav.payroll', quoi: 'quoi.payroll', href: '/paie',
    icone: 'payslip', groupe: 'personnel', droit: 'rh', module: 'paie' },
  { cle: 'coffre', titre: 'nav.vaultManage', quoi: 'quoi.vaultManage', href: '/coffre-fort/gestion',
    icone: 'safe', groupe: 'personnel', droit: 'rh' },

  // ----------------------------------------------------------- Gestion
  { cle: 'gestion', titre: 'nav.gestion', quoi: 'quoi.gestion', href: '/gestion',
    icone: 'euro', groupe: 'gestion', droit: 'gestion' },
  { cle: 'pieces', titre: 'nav.intake', quoi: 'quoi.intake', href: '/pieces',
    icone: 'receipt', groupe: 'gestion', droit: 'gestion' },
  { cle: 'projets', titre: 'nav.projects', quoi: 'quoi.projects', href: '/projets',
    icone: 'briefcase', groupe: 'gestion', droit: 'tous' },
  { cle: 'flotte', titre: 'nav.fleet', quoi: 'quoi.fleet', href: '/flotte',
    icone: 'truck', groupe: 'gestion', droit: 'gestion' },
  { cle: 'stock', titre: 'nav.stock', quoi: 'quoi.stock', href: '/stock',
    icone: 'box', groupe: 'gestion', droit: 'gestion', module: 'stock' },

  { cle: 'comptabilite', titre: 'nav.accounting', quoi: 'quoi.accounting', href: '/comptabilite',
    icone: 'ledger', groupe: 'gestion', droit: 'gestion', module: 'comptabilite' },
  { cle: 'tresorerie', titre: 'nav.treasury', quoi: 'quoi.treasury', href: '/tresorerie',
    icone: 'bank', groupe: 'gestion', droit: 'gestion', module: 'tresorerie' },
  { cle: 'immobilisations', titre: 'nav.assets', quoi: 'quoi.assets', href: '/immobilisations',
    icone: 'chart', groupe: 'gestion', droit: 'gestion', module: 'immobilisations' },
  { cle: 'einvoicing', titre: 'nav.eInvoicing', quoi: 'quoi.eInvoicing', href: '/facturation-electronique',
    icone: 'send', groupe: 'gestion', droit: 'gestion', module: 'facturation-electronique' },

  // ---------------------------------------------------------- Relation
  { cle: 'support', titre: 'nav.support', quoi: 'quoi.support', href: '/support',
    icone: 'lifebuoy', groupe: 'relation', droit: 'tous' },
  { cle: 'connaissances', titre: 'nav.knowledge', quoi: 'quoi.knowledge', href: '/base-de-connaissances',
    icone: 'book', groupe: 'relation', droit: 'tous' },
  { cle: 'demandes', titre: 'nav.internalRequests', quoi: 'quoi.internalRequests', href: '/demandes',
    icone: 'inbox', groupe: 'relation', droit: 'tous' },
  { cle: 'accueil', titre: 'nav.frontDesk', quoi: 'quoi.frontDesk', href: '/accueil',
    icone: 'bell', groupe: 'relation', droit: 'rh' },
  { cle: 'evenements', titre: 'nav.events', quoi: 'quoi.events', href: '/evenements',
    icone: 'calendar', groupe: 'relation', droit: 'tous' },
  { cle: 'actualites', titre: 'nav.news', quoi: 'quoi.news', href: '/admin#actualites',
    icone: 'megaphone', groupe: 'relation', onglet: 'actualites', droit: 'admin' },

  { cle: 'crm', titre: 'nav.crm', quoi: 'quoi.crm', href: '/crm',
    icone: 'handshake', groupe: 'relation', droit: 'gestion', module: 'crm' },

  // --------------------------------------------------------- Technique
  { cle: 'informatique', titre: 'nav.it', quoi: 'quoi.it', href: '/informatique',
    icone: 'server', groupe: 'technique', droit: 'informatique' },
  { cle: 'developpement', titre: 'nav.development', quoi: 'quoi.development', href: '/developpement',
    icone: 'code', groupe: 'technique', droit: 'informatique' },
  { cle: 'integrations', titre: 'nav.integrations', quoi: 'quoi.integrations', href: '/integrations',
    icone: 'plug', groupe: 'technique', droit: 'admin' },
  { cle: 'mises-a-jour', titre: 'nav.updates', quoi: 'quoi.updates', href: '/mises-a-jour',
    icone: 'download', groupe: 'technique', droit: 'admin' },
  { cle: 'sauvegardes', titre: 'nav.backups', quoi: 'quoi.backups', href: '/sauvegardes',
    icone: 'archive', groupe: 'technique', droit: 'admin' },
  { cle: 'journaux', titre: 'nav.logs', quoi: 'quoi.logs', href: '/journaux',
    icone: 'list', groupe: 'technique', droit: 'admin' },
  { cle: 'import', titre: 'nav.dataImport', quoi: 'quoi.dataImport', href: '/import',
    icone: 'upload', groupe: 'technique', droit: 'gestion' },

  // -------------------------------------------------------- Conformité
  { cle: 'securite', titre: 'nav.security', quoi: 'quoi.security', href: '/securite',
    icone: 'shield', groupe: 'conformite', droit: 'admin' },
  { cle: 'rgpd', titre: 'nav.privacy', quoi: 'quoi.privacy', href: '/rgpd',
    icone: 'lock', groupe: 'conformite', droit: 'admin' },
  { cle: 'juridique', titre: 'nav.legal', quoi: 'quoi.legal', href: '/juridique',
    icone: 'scale', groupe: 'conformite', droit: 'tous' },
  { cle: 'parapheur', titre: 'nav.signing', quoi: 'quoi.signing', href: '/parapheur',
    icone: 'pen', groupe: 'conformite', droit: 'tous' },

  // ---------------------------------------------------------- Réglages
  { cle: 'outils', titre: 'nav.tools', quoi: 'quoi.tools', href: '/admin#outils',
    icone: 'tool', groupe: 'reglages', onglet: 'outils', droit: 'admin' },
  { cle: 'modules', titre: 'nav.modules', quoi: 'quoi.modules', href: '/admin#modules',
    icone: 'plus', groupe: 'reglages', onglet: 'modules', droit: 'admin' },
  { cle: 'applications', titre: 'nav.appStore', quoi: 'quoi.appStore', href: '/applications',
    icone: 'grid', groupe: 'reglages', droit: 'admin' },
  { cle: 'apparence', titre: 'nav.appearance', quoi: 'quoi.appearance', href: '/admin#apparence',
    icone: 'settings', groupe: 'reglages', onglet: 'apparence', droit: 'admin' },
  { cle: 'champs', titre: 'nav.customFields', quoi: 'quoi.customFields', href: '/admin#champs',
    icone: 'layers', groupe: 'reglages', onglet: 'champs', droit: 'admin' },
  { cle: 'corbeille', titre: 'corbeille.title', quoi: 'quoi.corbeille', href: '/admin#corbeille',
    icone: 'trash', groupe: 'reglages', onglet: 'corbeille', droit: 'admin' },
  { cle: 'societes', titre: 'societes.title', quoi: 'quoi.societes', href: '/admin#societes',
    icone: 'offices', groupe: 'reglages', onglet: 'societes', droit: 'admin' },
];

/*
 * Qui peut ouvrir quoi.
 *
 * Un raccourci vers un écran qu'on ne peut pas ouvrir est une porte peinte sur
 * un mur : elle donne sur un refus. Tant que le rail groupé ne vivait que dans
 * la console — réservée à l'administration — la question ne se posait pas ;
 * posé sur les écrans de gestion, que tient aussi un compte « gestion » sans
 * être administrateur, il montrerait sinon la sécurité, le RGPD et les
 * journaux à qui se verra refuser les trois.
 *
 * Chaque nom répond au garde de la route correspondante, et un test le vérifie
 * pour de bon : il ouvre chaque service avec chaque rôle et compare ce que le
 * rail montre à ce que le serveur laisse passer. Changer un garde sans changer
 * ce tableau fait donc rougir la suite, plutôt que d'attendre un utilisateur.
 *
 * L'administration passe partout sauf là où c'est voulu — voir le référent du
 * dispositif d'alerte, qui n'est pas dans ce catalogue pour cette raison même.
 */
const DROITS = {
  tous: () => true,
  admin: (q) => q.role === 'admin',
  gestion: (q) => q.role === 'admin' || q.finance,
  rh: (q) => q.role === 'admin' || q.rh,
  informatique: (q) => q.role === 'admin' || q.informatique,
  encadrement: (q) => q.role === 'admin' || q.encadrement,
  // Le pilotage regarde toute l'entreprise : administration, gestion et RH.
  pilotage: (q) => q.role === 'admin' || q.finance || q.rh,
};

/**
 * Ce compte peut-il ouvrir ce service ?
 *
 * Deux conditions, pas une : le droit, et le module. Un service que son module
 * n'a pas débloqué n'a pas de route du tout — la proposer mènerait au même
 * refus qu'un droit manquant, et pour une raison que l'utilisateur ne peut pas
 * corriger lui-même.
 *
 * « qui.modules » absent veut dire « on ne sait pas » et non « aucun » : les
 * tests et les écrans qui rendent le catalogue entier n'ont pas à le fournir.
 */
function autorise(qui, service) {
  const regle = DROITS[service.droit];
  if (!regle) throw new Error(`Droit inconnu : ${service.droit}`);
  const profil = qui || {};
  /*
   * Une section que le client n'a pas installée n'existe pas pour lui : ni
   * dans le rail, ni dans les tuiles, ni dans la recherche, et ses routes
   * répondent comme une adresse inventée. « sections » absent veut dire « on
   * ne sait pas » et non « aucune », pour la même raison que « modules » :
   * les tests et les écrans qui rendent le catalogue entier ne le fournissent
   * pas.
   */
  if (profil.sections && !profil.sections.includes(service.groupe)) return false;
  if (service.module && profil.modules && !profil.modules.includes(service.module)) return false;
  // Un vrai booléen : les règles lisent des drapeaux qui valent « undefined »
  // tant que personne n'a été désigné, et « undefined » filtre comme « faux »
  // mais ne se compare pas comme lui.
  return Boolean(regle(profil));
}

/**
 * Les entrées d'un groupe, dans l'ordre du catalogue.
 *
 * Sans « qui », on les rend toutes — c'est ce dont les tests et les écrans
 * d'administration ont besoin. Avec, on ne garde que celles qui s'ouvriront.
 */
function servicesDe(groupe, qui) {
  const dedans = CATALOGUE.filter((s) => s.groupe === groupe);
  return qui ? dedans.filter((s) => autorise(qui, s)) : dedans;
}

/**
 * Le groupe d'un chemin, ou null — c'est ce qui décide du groupe ouvert.
 *
 * Le plus long préfixe gagne : /flotte/12 appartient à « flotte », pas à « / ».
 * À égalité, c'est le premier du catalogue — ce qui règle le cas de /admin, que
 * huit onglets revendiquent à six caractères chacun : on ouvre « personnel »,
 * l'onglet que la console ouvre elle-même quand aucune ancre ne dit le contraire.
 */
function groupeDuChemin(chemin) {
  const propre = String(chemin || '/').split('?')[0].replace(/\/$/, '') || '/';
  // Le tableau de bord d'une section appartient évidemment à sa section : il
  // n'est pas dans le catalogue, il le présente.
  const section = /^\/section\/([a-z-]+)$/.exec(propre);
  if (section && GROUPES.some((g) => g.cle === section[1])) return section[1];
  let trouve = null;
  let longueur = -1;
  for (const service of CATALOGUE) {
    for (const prefixe of service.prefixes || [service.href.split('#')[0]]) {
      if (prefixe === '/') continue;
      if ((propre === prefixe || propre.startsWith(prefixe + '/')) && prefixe.length > longueur) {
        trouve = service.groupe;
        longueur = prefixe.length;
      }
    }
  }
  return trouve;
}

/** Le groupe existe-t-il ? Les écrans de section s'en servent avant de rendre. */
function groupe(cle) {
  return GROUPES.find((g) => g.cle === cle) || null;
}

module.exports = { GROUPES, CATALOGUE, DROITS, autorise, servicesDe, groupeDuChemin, groupe };
