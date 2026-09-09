const path = require('path');
const express = require('express');
const session = require('express-session');
const helmet = require('helmet');
const cookieParser = require('cookie-parser');

const db = require('./db');
const security = require('./security');
const audit = require('./audit');
const i18n = require('./i18n');
const install = require('./install');
const sessionStore = require('./session-store');
const settings = require('./settings');
const themes = require('./themes');
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
const installRoutes = require('./routes/install');
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
const integrationRoutes = require('./routes/integrations');
const demandeRoutes = require('./routes/demandes');
const pieceRoutes = require('./routes/pieces');
const informatiqueRoutes = require('./routes/informatique');
const developpementRoutes = require('./routes/developpement');
const evenementRoutes = require('./routes/evenements');
const partenaireRoutes = require('./routes/partenaires');

function assertProductionSecrets() {
  if (process.env.NODE_ENV !== 'production') return;

  // Un ADMIN_PASSWORD par défaut reste inacceptable ; son absence, elle, est
  // désormais normale : l'assistant d'installation crée le compte.
  if (process.env.ADMIN_PASSWORD === 'change-moi-123') {
    throw new Error('ADMIN_PASSWORD doit être défini avec un mot de passe fort en production.');
  }
}

function createApp() {
  assertProductionSecrets();

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
  app.use(security.globalLimiter);

  app.use(express.urlencoded({ extended: true, limit: '20kb' }));
  app.use(cookieParser());
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
        secure: isProd,
        maxAge: 1000 * 60 * idleMinutes,
      },
    })
  );

  // Avant toute autre chose : une session ouverte ne vaut que ce que la base dit
  // encore du compte. Désactivation, fin de contrat, verrouillage, changement de
  // rôle — tout prend effet ici, sans attendre une reconnexion.
  app.use(revalidateSession);

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

    // Compteurs et droits affichés dans la navigation de chaque page.
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
      res.locals.pendingAcks = talent.pendingAckCount(user.id);
      res.locals.pendingSurveys = surveys.pendingCountFor(user.id);
      res.locals.pendingSignatures = signing.pendingCountFor(user.id);
      res.locals.pendingApprovals = workflows.awaitingCount(user.id);
      res.locals.enabledModules = modules.enabled();
    } else {
      res.locals.unreadMessages = 0;
      res.locals.unreadNotifications = 0;
      res.locals.hasVault = false;
      res.locals.isManager = false;
      res.locals.isCseMember = false;
      res.locals.isCseElected = false;
      res.locals.isHr = false;
      res.locals.isFinance = false;
      res.locals.isIt = false;
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

  // Tant que l'instance n'est pas installée, tout mène à l'assistant ; une fois
  // installée, l'assistant est définitivement fermé (voir routes/install.js).
  app.use((req, res, next) => {
    if (req.path.startsWith('/installation') || install.isInstalled()) return next();
    res.redirect('/installation');
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

  app.use('/installation', installRoutes);
  app.use('/', authRoutes);
  app.use('/admin', adminRoutes);
  app.use('/securite', securiteRoutes);
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
  app.use('/informatique', informatiqueRoutes);
  app.use('/developpement', developpementRoutes);
  app.use('/evenements', evenementRoutes);
  app.use('/partenaires', partenaireRoutes);
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
  app.use((err, req, res, next) => {
    // Un fichier trop lourd est une erreur d'utilisation, pas une panne serveur.
    if (err && err.name === 'MulterError' && req.session) {
      req.session.flash = { type: 'error', message: 'Image trop volumineuse : 2 Mo maximum.' };
      return res.redirect('/mon-profil');
    }

    console.error(err);
    res.status(500).render('error', { message: 'Une erreur est survenue. Merci de réessayer.' });
  });

  return app;
}

module.exports = createApp;
