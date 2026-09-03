const path = require('path');
const express = require('express');
const session = require('express-session');
const helmet = require('helmet');
const cookieParser = require('cookie-parser');

const db = require('./db');
const security = require('./security');
const i18n = require('./i18n');
const { UPLOAD_DIR } = require('./uploads');
const messageRoutes = require('./routes/messages');
const authRoutes = require('./routes/auth');
const adminRoutes = require('./routes/admin');
const employeeRoutes = require('./routes/employee');
const rhRoutes = require('./routes/rh');
const managerRoutes = require('./routes/manager');
const profileRoutes = require('./routes/profile');
const directoryRoutes = require('./routes/directory');

function assertProductionSecrets() {
  if (process.env.NODE_ENV !== 'production') return;

  if (!process.env.SESSION_SECRET || process.env.SESSION_SECRET === 'change-moi-en-production') {
    throw new Error('SESSION_SECRET doit être défini avec une valeur forte et unique en production.');
  }
  if (!process.env.ADMIN_PASSWORD || process.env.ADMIN_PASSWORD === 'change-moi-123') {
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
      secret: process.env.SESSION_SECRET || 'dev-secret-non-securise',
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

  // L'i18n doit précéder le contrôle CSRF : ce dernier rend une page d'erreur
  // traduite quand un jeton manque, et aurait besoin de t() avant de l'avoir.
  app.use(i18n.middleware);
  app.use(security.csrfMiddleware);

  app.use((req, res, next) => {
    const user = req.session.user || null;
    res.locals.currentUser = user;
    res.locals.flash = req.session.flash || null;
    delete req.session.flash;

    // Compteurs et droits affichés dans la navigation de chaque page.
    if (user) {
      res.locals.unreadMessages = messageRoutes.unreadCount(user.id);
      res.locals.isManager =
        db.prepare('SELECT COUNT(*) AS n FROM users WHERE manager_id = ?').get(user.id).n > 0;
    } else {
      res.locals.unreadMessages = 0;
      res.locals.isManager = false;
    }
    next();
  });

  app.use('/', authRoutes);
  app.use('/admin', adminRoutes);
  app.use('/mon-espace', employeeRoutes);
  app.use('/mon-profil', profileRoutes);
  app.use('/annuaire', directoryRoutes);
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
