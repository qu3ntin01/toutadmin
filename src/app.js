const path = require('path');
const express = require('express');
const session = require('express-session');
const helmet = require('helmet');
const cookieParser = require('cookie-parser');

const db = require('./db');
const security = require('./security');
const i18n = require('./i18n');
const install = require('./install');
const settings = require('./settings');
const cse = require('./cse');
const org = require('./org');
const talent = require('./talent');
const modules = require('./modules');
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

  app.use(
    session({
      name: 'pm.sid',
      // Fourni par l'environnement, sinon généré et conservé dans data/session.key.
      secret: install.sessionSecret(),
      resave: false,
      saveUninitialized: false,
      cookie: {
        httpOnly: true,
        sameSite: 'lax',
        secure: isProd,
        maxAge: 1000 * 60 * 60 * 8,
      },
    })
  );

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

    // Compteurs et droits affichés dans la navigation de chaque page.
    if (user) {
      res.locals.unreadMessages = messageRoutes.unreadCount(user.id);
      res.locals.isManager = org.isManager(user.id);
      const row = db.prepare('SELECT role, contract_type, is_hr, is_finance FROM users WHERE id = ?').get(user.id);
      res.locals.isCseMember = Boolean(row && cse.isEligible(row));
      res.locals.isCseElected = cse.isElected(user.id);
      // Les rôles désignés sont relus ici : une désignation vaut sans reconnexion.
      res.locals.isHr = Boolean(row && row.is_hr);
      res.locals.isFinance = Boolean(row && row.is_finance);
      res.locals.pendingAcks = talent.pendingAckCount(user.id);
      res.locals.enabledModules = modules.enabled();
    } else {
      res.locals.unreadMessages = 0;
      res.locals.isManager = false;
      res.locals.isCseMember = false;
      res.locals.isCseElected = false;
      res.locals.isHr = false;
      res.locals.isFinance = false;
      res.locals.pendingAcks = 0;
      res.locals.enabledModules = [];
    }
    next();
  });

  app.use(security.csrfMiddleware);

  // Tant que l'instance n'est pas installée, tout mène à l'assistant ; une fois
  // installée, l'assistant est définitivement fermé (voir routes/install.js).
  app.use((req, res, next) => {
    if (req.path.startsWith('/installation') || install.isInstalled()) return next();
    res.redirect('/installation');
  });

  app.use('/installation', installRoutes);
  app.use('/', authRoutes);
  app.use('/admin', adminRoutes);
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
