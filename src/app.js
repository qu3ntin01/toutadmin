const path = require('path');
const express = require('express');
const session = require('express-session');
const helmet = require('helmet');
const cookieParser = require('cookie-parser');

const db = require('./db');
const security = require('./security');
const logs = require('./logs');
const audit = require('./audit');
const i18n = require('./i18n');
const { asset } = require('./assets');
const install = require('./install');
const sessionStore = require('./session-store');
const settings = require('./settings');
const installable = require('./installable');
const themes = require('./themes');
const sections = require('./sections');
const sectionsReglages = require('./sections-reglages');
const cse = require('./cse');
const org = require('./org');
const talent = require('./talent');
const modules = require('./modules');
const notifications = require('./notifications');
const vault = require('./vault');
const surveys = require('./surveys');
const signing = require('./signing');
const workflows = require('./workflows');
const { revalidateSession, requirePasswordChange, restrictToVault } = require('./middleware/auth');
const emprunt = require('./emprunt');
const societes = require('./societes');
const societesRoutes = require('./routes/societes');
const sessions = require('./sessions');
const licenceGuard = require('./middleware/licence');
const installRoutes = require('./routes/install');
const sectionRoutes = require('./routes/sections');
const { UPLOAD_DIR } = require('./uploads');
const messageRoutes = require('./routes/messages');
const authRoutes = require('./routes/auth');
const adminRoutes = require('./routes/admin');
const employeeRoutes = require('./routes/employee');
const rhRoutes = require('./routes/rh');
const managerRoutes = require('./routes/manager');
const profileRoutes = require('./routes/profile');
const directoryRoutes = require('./routes/directory');
const cseRoutes = require('./routes/cse');
const agendaRoutes = require('./routes/agenda');
const gestionRoutes = require('./routes/gestion');
const roomRoutes = require('./routes/salles');
const comptabiliteRoutes = require('./routes/comptabilite');
const paieRoutes = require('./routes/paie');
const einvoicingRoutes = require('./routes/facturation-electronique');
const stockRoutes = require('./routes/stock');
const crmRoutes = require('./routes/crm');
const securiteRoutes = require('./routes/securite');
const journauxRoutes = require('./routes/journaux');
const misesAJourRoutes = require('./routes/mises-a-jour');
const applicationsRoutes = require('./routes/applications');
const licenceRoutes = require('./routes/licence');
const projetsRoutes = require('./routes/projets');
const supportRoutes = require('./routes/support');
const connaissancesRoutes = require('./routes/connaissances');
const parcoursRoutes = require('./routes/parcours');
const santeSecuriteRoutes = require('./routes/sante-securite');
const tresorerieRoutes = require('./routes/tresorerie');
const immobilisationsRoutes = require('./routes/immobilisations');
const flotteRoutes = require('./routes/flotte');
const notificationRoutes = require('./routes/notifications');
const pilotageRoutes = require('./routes/pilotage');
const rechercheRoutes = require('./routes/recherche');
const rgpdRoutes = require('./routes/rgpd');
const coffreRoutes = require('./routes/coffre-fort');
const sauvegardeRoutes = require('./routes/sauvegardes');
const directionRoutes = require('./routes/direction');
const sondageRoutes = require('./routes/sondages');
const planningRoutes = require('./routes/planning');
const qualiteRoutes = require('./routes/qualite');
const accueilRoutes = require('./routes/accueil');
const organigrammeRoutes = require('./routes/organigramme');
const importRoutes = require('./routes/import');
const parapheurRoutes = require('./routes/parapheur');
const apiRoutes = require('./routes/api');
const stripe = require('./stripe');
const integrationRoutes = require('./routes/integrations');
const demandeRoutes = require('./routes/demandes');
const pieceRoutes = require('./routes/pieces');
const actionsMasseRoutes = require('./routes/actions-masse');
const kanbanRoutes = require('./routes/kanban');
const informatiqueRoutes = require('./routes/informatique');
const developpementRoutes = require('./routes/developpement');
const evenementRoutes = require('./routes/evenements');
const partenaireRoutes = require('./routes/partenaires');
const alerteRoutes = require('./routes/alertes');
const juridiqueRoutes = require('./routes/juridique');

function assertProductionSecrets() {
  if (process.env.NODE_ENV !== 'production') return;

  // Un ADMIN_PASSWORD par défaut reste inacceptable ; son absence, elle, est
  // désormais normale : l'assistant d'installation crée le compte.
  if (process.env.ADMIN_PASSWORD === 'change-moi-123') {
    throw new Error('ADMIN_PASSWORD doit être défini avec un mot de passe fort en production.');
  }
}

/**
 * Les attributs du rail à poser sur <html> pour un chemin donné.
 *
 * « data-groupes » énumère les groupes ; « data-groupe-actif » dit lequel
 * contient la page courante. À défaut de choix gardé dans le navigateur, c'est
 * ce dernier qui reste ouvert : replier le groupe de la page cacherait la
 * rubrique allumée, et l'on ne saurait plus où l'on est.
 */
function railAttrs(chemin) {
  const tous = sections.GROUPES.map((g) => g.cle).join(' ');
  const actif = sections.groupeDuChemin(chemin);
  return ` data-groupes="${tous}"` + (actif ? ` data-groupe-actif="${actif}"` : '');
}

function createApp() {
  assertProductionSecrets();

  /*
   * Une instance qui tournait avant les sections a déjà ses réglages, posés à
   * la main dans les onglets de la console. On la marque configurée une fois
   * pour toutes, plutôt que de lui ouvrir sept assistants au premier
   * démarrage après la mise à jour.
   */
  sectionsReglages.rattraperInstanceExistante();

  const app = express();
  const isProd = process.env.NODE_ENV === 'production';

  if (process.env.TRUST_PROXY) {
    app.set('trust proxy', Number(process.env.TRUST_PROXY) || 1);
  }

  app.set('view engine', 'ejs');
  app.set('views', path.join(__dirname, '..', 'views'));
  app.disable('x-powered-by');

  app.use(security.nonceMiddleware);
  app.use(
    helmet({
      contentSecurityPolicy: {
        directives: {
          defaultSrc: ["'self'"],
          baseUri: ["'self'"],
          objectSrc: ["'none'"],
          frameAncestors: ["'none'"],
          formAction: ["'self'"],
          scriptSrc: ["'self'", (req, res) => `'nonce-${res.locals.nonce}'`],
          styleSrc: ["'self'"],
          fontSrc: ["'self'"],
          imgSrc: ["'self'", 'data:'],
          connectSrc: ["'self'"],
          upgradeInsecureRequests: isProd ? [] : null,
        },
      },
      crossOriginEmbedderPolicy: false,
    })
  );
  // Les capacités du navigateur dont le produit ne se sert pas sont fermées,
  // pour lui et pour tout ce qui s'y glisserait : une page de gestion n'a besoin
  // ni de la caméra, ni du micro, ni de la position. Les mêmes que l'édition PHP.
  app.use((req, res, next) => {
    res.setHeader('Permissions-Policy', 'geolocation=(), camera=(), microphone=(), payment=()');
    next();
  });
  app.use(security.globalLimiter);

  app.locals.asset = asset;
  // Un gabarit rendu hors requête (la compilation des traductions, par
  // exemple) n'a pas de chemin : il n'aura pas d'attributs, et pas d'erreur.
  app.locals.railAttrs = '';
  app.locals.sections = sections;

  // L'avis de paiement de Stripe : monté avant tout analyseur de corps, parce
  // que sa signature porte sur les octets bruts, et avant la session, parce
  // qu'il n'en a pas — ni cookie ni jeton CSRF. Il s'authentifie par sa
  // signature HMAC, son heure, et une session Checkout créée ici (src/stripe.js).
  app.post('/stripe/webhook', express.raw({ type: () => true, limit: '256kb' }), (req, res) => {
    const verdict = stripe.recevoir(req.body, req.get('stripe-signature'));
    if (verdict.reason === 'paid') {
      audit.log(req, 'facture.payee_en_ligne', 'invoices', verdict.session.invoice_id, { session: verdict.session.session_id });
    } else if (verdict.status !== 200 || ['amount', 'invoice'].includes(verdict.reason)) {
      audit.log(req, 'stripe.avis_refuse', 'invoices', verdict.session ? verdict.session.invoice_id : null, { motif: verdict.reason });
    }
    res.status(verdict.status).json({ received: verdict.status === 200 });
  });

  app.use(express.urlencoded({ extended: true, limit: '20kb' }));
  app.use(cookieParser());
  // Le cache long est sans danger : chaque adresse porte l'empreinte de son
  // fichier (voir src/assets.js), donc une mise à jour change l'adresse.
  app.use(express.static(path.join(__dirname, '..', 'public'), { maxAge: isProd ? '1d' : 0 }));
  // Photos de profil : servies en lecture seule, sans exécution ni indexation.
  app.use('/media/avatars', express.static(UPLOAD_DIR, { maxAge: '7d', index: false, dotfiles: 'ignore' }));

  // L'API est montée avant la session : elle ne pose ni ne lit de cookie, donc
  // pas de session, donc pas de jeton CSRF à vérifier. Son authentification
  // tient entièrement dans l'en-tête Authorization, et elle est en lecture seule.
  app.use('/api/v1', apiRoutes);

  // Expiration par inactivité : le cookie est repoussé à chaque requête, si bien
  // qu'un poste laissé sans surveillance se referme tout seul. Le plafond absolu,
  // lui, est contrôlé côté serveur (voir revalidateSession).
  const idleMinutes = Number(process.env.SESSION_IDLE_MINUTES) || 60;

  app.use(
    session({
      name: 'pm.sid',
      // Fourni par l'environnement, sinon généré et conservé dans data/session.key.
      secret: install.sessionSecret(),
      store: sessionStore.store(),
      resave: false,
      rolling: true,
      saveUninitialized: false,
      cookie: {
        httpOnly: true,
        sameSite: 'lax',
        /*
         * « auto », et non « en production ».
         *
         * Un cookie marqué Secure n'est pas renvoyé sur une connexion en
         * clair. Lié à NODE_ENV, il l'était donc dès qu'on posait
         * « production » — y compris sur une instance servie en HTTP, où plus
         * aucune session ne tenait : on remplissait l'assistant, on validait,
         * et la page répondait qu'elle n'était pas accessible. Le symptôme ne
         * disait rien de la cause, et c'est un conteneur qu'on ouvre sur
         * http://localhost:3000 qui l'a montré.
         *
         * « auto » regarde la connexion plutôt que le réglage : Secure
         * derrière du HTTPS, pas Secure sans. Derrière un reverse-proxy, c'est
         * « trust proxy » qui le renseigne — d'où TRUST_PROXY, qu'il faut
         * poser dès qu'il y en a un, et que l'assistant rappelle.
         */
        secure: 'auto',
        maxAge: 1000 * 60 * idleMinutes,
      },
    })
  );

  // Avant toute autre chose : une session ouverte ne vaut que ce que la base dit
  // encore du compte. Désactivation, fin de contrat, verrouillage, changement de
  // rôle — tout prend effet ici, sans attendre une reconnexion.
  app.use(revalidateSession);

  // Puis on note d'où vient la requête, sur la session qui la porte : c'est ce
  // qui rend l'écran « Mes appareils » lisible. Après revalidateSession, donc
  // jamais pour une session que la base vient de refuser.
  app.use(sessions.middleware);

  // L'i18n et les variables de marque doivent précéder le contrôle CSRF : ce
  // dernier rend une page d'erreur traduite et brandée quand un jeton manque,
  // et aurait besoin de t()/companyName avant de les avoir.
  app.use(i18n.middleware);

  app.use((req, res, next) => {
    const user = req.session.user || null;
    res.locals.currentUser = user;
    res.locals.flash = req.session.flash || null;
    delete req.session.flash;

    // Marque de l'instance, posée à l'installation.
    const companyName = settings.get('company_name');
    res.locals.companyName = companyName;
    res.locals.brandInitials = settings.brandInitials(companyName);
    // Palette de l'instance : posée sur <html>, elle vaut aussi avant connexion.
    res.locals.palette = themes.current();

    // Le rail replié a besoin de deux choses avant le premier rendu : la liste
    // des groupes, et celui d'où l'on est. Elles se posent sur <html> plutôt
    // que dans le gabarit du rail, parce que la feuille de style et le script
    // d'en-tête les lisent là, avant que le rail n'existe.
    res.locals.railAttrs = railAttrs(req.path);
    // Le catalogue des services, pour les rails et les écrans de section, et le
    // chemin courant, pour qu'ils sachent quelle rubrique allumer.
    res.locals.sections = sections;
    res.locals.cheminActuel = req.path;

    // Compteurs et droits affichés dans la navigation de chaque page.
    res.locals.sectionsActives = sectionsReglages.actives();

    if (user) {
      res.locals.unreadMessages = messageRoutes.unreadCount(user.id);
      res.locals.unreadNotifications = notifications.unreadCount(user.id);
      res.locals.hasVault = vault.hasDocuments(user.id);
      res.locals.isManager = org.isManager(user.id);
      const row = req.currentUser;
      res.locals.isCseMember = Boolean(row && cse.isEligible(row));
      res.locals.isCseElected = cse.isElected(user.id);
      // Les rôles désignés sont relus ici : une désignation vaut sans reconnexion.
      res.locals.isHr = Boolean(row && row.is_hr);
      res.locals.isFinance = Boolean(row && row.is_finance);
      res.locals.isIt = Boolean(row && row.is_it);
      res.locals.isReferent = Boolean(row && row.is_referent);
      res.locals.pendingAcks = talent.pendingAckCount(user.id);
      res.locals.pendingSurveys = surveys.pendingCountFor(user.id);
      res.locals.pendingSignatures = signing.pendingCountFor(user.id);
      res.locals.pendingApprovals = workflows.awaitingCount(user.id);
      res.locals.enabledModules = modules.enabled();
      // De quoi filtrer le rail : les mêmes drapeaux que les gardes de route
      // lisent, rassemblés sous les noms qu'emploie le catalogue des services.
      res.locals.droits = {
        role: user.role,
        finance: res.locals.isFinance,
        rh: res.locals.isHr,
        informatique: res.locals.isIt,
        encadrement: res.locals.isManager,
        // Un service qu'aucun module n'a débloqué n'a pas de route : le rail
        // ne doit pas le proposer.
        modules: res.locals.enabledModules.map((m) => m.key),
        // Ni celui d'une section que le client n'a pas installée.
        sections: res.locals.sectionsActives,
      };
    } else {
      res.locals.droits = { role: null };
      res.locals.unreadMessages = 0;
      res.locals.unreadNotifications = 0;
      res.locals.hasVault = false;
      res.locals.isManager = false;
      res.locals.isCseMember = false;
      res.locals.isCseElected = false;
      res.locals.isHr = false;
      res.locals.isFinance = false;
      res.locals.isIt = false;
      res.locals.isReferent = false;
      res.locals.pendingAcks = 0;
      res.locals.pendingSurveys = 0;
      res.locals.pendingSignatures = 0;
      res.locals.pendingApprovals = 0;
      res.locals.enabledModules = [];
    }
    next();
  });

  app.use(security.csrfMiddleware);

  // Un mot de passe temporaire n'ouvre qu'une seule page : celle qui le remplace.
  app.use(requirePasswordChange);

  // Un ancien salarié n'atteint que son coffre-fort, quoi qu'il demande.
  app.use(restrictToVault);

  /*
   * L'emprunt d'identité : il ferme les secrets de la personne empruntée, et se
   * termine de lui-même quand la durée est écoulée. Posé ici, en amont des
   * routeurs, il couvre aussi ce qui sera écrit demain — un verrou route par
   * route finirait par oublier une route.
   */
  app.use(emprunt.verrou);

  /*
   * Le périmètre courant, posé sur chaque requête.
   *
   * Après le verrou de l'emprunt, et c'est voulu : pendant un emprunt, le
   * périmètre est celui de la personne empruntée, pas celui de
   * l'administrateur — c'est bien son écran qu'on vient voir. Le verrou pouvant
   * terminer l'emprunt, il doit avoir parlé avant qu'on lise le périmètre.
   */
  app.use(societes.middleware);
  app.use((req, res, next) => {
    res.locals.emprunt = emprunt.encours(req);
    next();
  });

  // Tant que l'instance n'est pas installée, tout mène à l'assistant ; une fois
  // installée, l'assistant est définitivement fermé (voir routes/install.js).
  app.use((req, res, next) => {
    if (req.path.startsWith('/install') || install.isInstalled()) return next();
    res.redirect('/install');
  });

  // La licence : elle pose le bandeau sur toutes les pages, et ne refuse que
  // des écritures, au dernier palier seulement. Après l'assistant, parce qu'une
  // instance en cours d'installation n'a pas encore de licence à vérifier.
  app.use(licenceGuard.middleware);

  /*
   * Les sections que le client n'a pas installées, et celles qu'il n'a pas
   * encore configurées.
   *
   * Une seule garde plutôt que cinquante : le chemin dit à quelle section il
   * appartient (voir sections.groupeDuChemin), et il n'y a donc rien à
   * accrocher route par route — ce qu'on oublie sur une route est un trou
   * qu'on ne découvre qu'en production.
   *
   * L'espace membre n'est pas concerné : il n'est dans aucune section, et une
   * entreprise qui n'installe pas la comptabilité ne retire pas pour autant à
   * ses salariés leurs congés et leur fiche de paie.
   */
  app.use((req, res, next) => {
    const groupe = sections.groupeDuChemin(req.path);
    if (!groupe) return next();

    if (!sectionsReglages.estActive(groupe)) {
      // Comme un module éteint : la page dit ce qu'il en est, et à
      // l'administration seule elle dit où poser la section.
      const admin = req.session.user && req.session.user.role === 'admin';
      return res.status(404).render('error', {
        message: admin ? res.locals.t('sec.offAdmin') : res.locals.t('sec.off'),
      });
    }

    /*
     * La première ouverture passe par l'assistant — pour l'administration
     * seule. Un salarié n'a pas les droits d'y répondre : l'y envoyer
     * l'enfermerait dans un écran qu'il ne peut pas franchir, sur une section
     * qui marche très bien avec ses valeurs par défaut.
     *
     * Les POST passent aussi : renvoyer une écriture vers une page perdrait
     * ce que la personne vient de saisir, et l'assistant ne s'ouvre de toute
     * façon que sur un écran qu'on regarde.
     */
    if (req.method !== 'GET') return next();
    if (!req.session.user || req.session.user.role !== 'admin') return next();
    if (sectionsReglages.estConfiguree(groupe)) return next();
    res.redirect(`/section/${groupe}/assistant`);
  });

  // Journal d'audit automatique : toute requête qui modifie quelque chose laisse
  // une trace, sans qu'il faille y penser route par route. Les actions sensibles
  // ajoutent par-dessus une entrée détaillée (voir les appels à audit.log).
  app.use((req, res, next) => {
    if (req.method !== 'POST') return next();
    res.on('finish', () => {
      // Une requête refusée n'a rien changé : elle n'encombre pas le journal,
      // sauf si elle a été rejetée pour défaut de droits — ça, ça se sait.
      if (res.statusCode >= 400 && res.statusCode !== 403) return;
      if (req.auditHandled) return;
      // Les identifiants dans l'URL sont normalisés : « /admin/employes/12/supprimer »
      // et « .../37/supprimer » sont la même action, sur deux objets différents.
      const route = req.originalUrl.split('?')[0].replace(/\/\d+(?=\/|$)/g, '/:id');
      const numeric = (req.originalUrl.match(/\/(\d+)(?=\/|$)/) || [])[1];
      audit.log(req, route, '', numeric ? Number(numeric) : null,
        res.statusCode === 403 ? { refuse: true } : null);
    });
    next();
  });

  // L'adresse a raccourci : « /install ». C'est la seule du produit qu'on tape
  // vraiment — celle qu'on donne au client au téléphone —, et « installation »
  // n'y ajoutait rien. Les anciens liens ne tombent pas pour autant.
  app.use('/installation', (req, res) => {
    res.redirect(301, '/install' + (req.path === '/' ? '' : req.path));
  });
  // Le manifeste et l'icône : publics, à la marque de l'instance.
  installable.monter(app);
  app.use('/install', installRoutes);
  app.use('/', authRoutes);
  app.use('/admin', adminRoutes);
  app.use('/societes', societesRoutes);
  app.use('/section', sectionRoutes);
  app.use('/securite', securiteRoutes);
  app.use('/journaux', journauxRoutes);
  app.use('/mises-a-jour', misesAJourRoutes);
  app.use('/applications', applicationsRoutes);
  app.use('/licence', licenceRoutes);
  app.use('/projets', projetsRoutes);
  app.use('/support', supportRoutes);
  app.use('/base-de-connaissances', connaissancesRoutes);
  app.use('/parcours', parcoursRoutes);
  app.use('/sante-securite', santeSecuriteRoutes);
  app.use('/tresorerie', tresorerieRoutes);
  app.use('/immobilisations', immobilisationsRoutes);
  app.use('/flotte', flotteRoutes);
  app.use('/notifications', notificationRoutes);
  app.use('/pilotage', pilotageRoutes);
  app.use('/recherche', rechercheRoutes);
  app.use('/rgpd', rgpdRoutes);
  app.use('/coffre-fort', coffreRoutes);
  app.use('/sauvegardes', sauvegardeRoutes);
  app.use('/direction', directionRoutes);
  app.use('/sondages', sondageRoutes);
  app.use('/planning', planningRoutes);
  app.use('/qualite', qualiteRoutes);
  app.use('/accueil', accueilRoutes);
  app.use('/organigramme', organigrammeRoutes);
  app.use('/import', importRoutes);
  app.use('/parapheur', parapheurRoutes);
  app.use('/integrations', integrationRoutes);
  app.use('/demandes', demandeRoutes);
  app.use('/pieces', pieceRoutes);
  app.use('/actions-masse', actionsMasseRoutes);
  app.use('/kanban', kanbanRoutes);
  app.use('/informatique', informatiqueRoutes);
  app.use('/developpement', developpementRoutes);
  app.use('/evenements', evenementRoutes);
  app.use('/partenaires', partenaireRoutes);
  app.use('/alertes', alerteRoutes);
  app.use('/juridique', juridiqueRoutes);
  app.use('/mon-espace', employeeRoutes);
  app.use('/mon-profil', profileRoutes);
  app.use('/annuaire', directoryRoutes);
  app.use('/agenda', agendaRoutes);
  app.use('/cse', cseRoutes);
  app.use('/salles', roomRoutes);
  app.use('/gestion', gestionRoutes);
  app.use('/comptabilite', comptabiliteRoutes);
  app.use('/paie', paieRoutes);
  app.use('/facturation-electronique', einvoicingRoutes);
  app.use('/stock', stockRoutes);
  app.use('/crm', crmRoutes);
  app.use('/messagerie', messageRoutes);
  app.use('/mon-equipe', managerRoutes);
  app.use('/rh', rhRoutes);

  app.use((req, res) => {
    res.status(404).render('error', { message: 'Page introuvable.' });
  });

  // eslint-disable-next-line no-unused-vars
  /**
   * Rendre la page d'erreur, même quand rien n'a été préparé.
   *
   * « error.ejs » est un écran comme les autres : il lit la langue, la
   * palette, la marque de l'instance et le rail. Ces variables sont posées par
   * un middleware — et une erreur qui survient AVANT lui (l'analyse du corps,
   * la session, le limiteur) arrive donc sur un gabarit à qui il manque tout.
   *
   * Le rendu échouait alors, et Express répondait par SA page de secours :
   * une trace de pile, avec les chemins absolus du serveur et six lignes du
   * code source, livrés au navigateur. Une panne devenait une fuite.
   *
   * On complète donc ce qui manque avant de rendre, et si le rendu échoue
   * malgré tout, on répond en texte brut. Un message sec vaut mieux qu'un
   * plan du serveur.
   */
  function rendreErreur(req, res, statut, message) {
    const locale = (() => {
      try {
        return i18n.resolveLocale(req);
      } catch {
        return i18n.DEFAULT_LOCALE;
      }
    })();
    const t = (cle, params) => i18n.translate(locale, cle, params);

    const defauts = {
      locale,
      localeDir: i18n.localeInfo(locale).dir,
      locales: i18n.LOCALES,
      t,
      st: (valeur) => i18n.statusLabel(locale, valeur),
      palette: 'lavande',
      railAttrs: '',
      companyName: 'Toutadmin',
      brandInitials: 'TO',
      currentUser: null,
      flash: null,
      csrfToken: '',
      cheminActuel: req.path || '/',
      sections,
      nonce: res.locals.nonce || '',
    };
    for (const [cle, valeur] of Object.entries(defauts)) {
      if (res.locals[cle] === undefined) res.locals[cle] = valeur;
    }
    // La marque réelle si la base répond ; sinon celle du défaut ci-dessus.
    try {
      const nom = settings.get('company_name');
      if (nom) {
        res.locals.companyName = nom;
        res.locals.brandInitials = settings.brandInitials(nom);
      }
      res.locals.palette = themes.current();
    } catch {
      /* base injoignable : on garde les défauts, la page doit sortir */
    }

    res.status(statut);
    try {
      return res.render('error', { message });
    } catch {
      return res.type('text/plain').send(message);
    }
  }

  app.use((err, req, res, next) => {
    // Un fichier trop lourd est une erreur d'utilisation, pas une panne serveur.
    if (err && err.name === 'MulterError' && req.session) {
      req.session.flash = { type: 'error', message: 'Image trop volumineuse : 2 Mo maximum.' };
      return res.redirect('/mon-profil');
    }

    /*
     * Un formulaire trop gros non plus.
     *
     * Express refuse un corps au-delà de vingt kilo-octets, et jette. Personne
     * ne rattrapait : l'exception remontait jusqu'ici et le client lisait « Une
     * erreur est survenue », sur toutes les routes d'écriture du produit — il
     * suffit de coller un texte un peu long dans une description.
     *
     * Deux choses distinguent ce cas d'une panne : on sait exactement ce qui
     * s'est passé, et la personne peut y remédier elle-même. Elle mérite donc
     * une phrase qui le dit, un code qui le dit aussi (413), et de revenir là
     * d'où elle vient plutôt que sur une page d'erreur qui perd sa saisie.
     *
     * Ce n'est pas un rattrapage général : on ne traite que l'erreur d'analyse
     * du corps, reconnue à son type. Tout le reste continue vers le journal et
     * la page de dernier recours — masquer une panne derrière un message poli
     * serait bien pire que de l'afficher.
     */
    const corpsIllisible = err && (err.type === 'entity.too.large'
      || err.type === 'entity.parse.failed' || err.type === 'parameters.too.many');
    if (corpsIllisible) {
      /*
       * La langue se résout ici : l'analyse du corps échoue avant le
       * middleware qui pose « res.locals.t », et le message serait sinon en
       * français pour tout le monde.
       */
      const dire = (cle) => i18n.translate(i18n.resolveLocale(req), cle);
      const message = err.type === 'entity.too.large'
        ? dire('err.bodyTooLarge')
        : dire('err.bodyUnreadable');
      /*
       * Une page, et non un renvoi vers l'écran d'où l'on vient.
       *
       * Le renvoi aurait été plus agréable — il garde la personne dans son
       * écran. Il demanderait de poser un message dans la session, et il n'y
       * en a pas : l'analyse du corps échoue avant le middleware de session,
       * et « next(err) » saute tout ce qui reste pour venir ici directement.
       *
       * Déplacer l'analyseur après la session pour y arriver serait payer la
       * commodité d'un message par un changement d'ordre qui touche l'API, le
       * jeton CSRF et le limiteur. La page dit ce qu'il s'est passé et ce
       * qu'il faut faire ; cela suffit.
       */
      return rendreErreur(req, res, 413, message);
    }

    // Une erreur qui n'existe que dans la console du serveur n'existe pas pour
    // qui administre l'instance depuis un navigateur.
    logs.error('systeme', 'erreur.non_rattrapee', String((err && err.message) || err).slice(0, 300), {
      req,
      context: {
        chemin: String(req.originalUrl || req.url || '').slice(0, 200),
        methode: req.method,
        pile: String((err && err.stack) || '').split('\n').slice(0, 6).join(' | ').slice(0, 1200),
      },
    });

    console.error(err);
    rendreErreur(req, res, 500, i18n.translate(
      (() => { try { return i18n.resolveLocale(req); } catch { return i18n.DEFAULT_LOCALE; } })(),
      'err.unexpected'
    ));
  });

  return app;
}

module.exports = createApp;
