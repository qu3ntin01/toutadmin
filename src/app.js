const path = require('path');
const express = require('express');
const session = require('express-session');
const helmet = require('helmet');

const security = require('./security');
const authRoutes = require('./routes/auth');
const adminRoutes = require('./routes/admin');
const employeeRoutes = require('./routes/employee');
const rhRoutes = require('./routes/rh');

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
  app.use(express.static(path.join(__dirname, '..', 'public'), { maxAge: isProd ? '1d' : 0 }));

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

  app.use(security.csrfMiddleware);

  app.use((req, res, next) => {
    res.locals.currentUser = req.session.user || null;
    res.locals.flash = req.session.flash || null;
    delete req.session.flash;
    next();
  });

  app.use('/', authRoutes);
  app.use('/admin', adminRoutes);
  app.use('/mon-espace', employeeRoutes);
  app.use('/rh', rhRoutes);

  app.use((req, res) => {
    res.status(404).render('error', { message: 'Page introuvable.' });
  });

  // eslint-disable-next-line no-unused-vars
  app.use((err, req, res, next) => {
    console.error(err);
    res.status(500).render('error', { message: 'Une erreur est survenue. Merci de réessayer.' });
  });

  return app;
}

module.exports = createApp;
