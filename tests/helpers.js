const fs = require('fs');
const os = require('os');
const path = require('path');

const ADMIN_EMAIL = 'admin@test.local';
const ADMIN_PASSWORD = 'admin-password-de-test';

// Doit être appelé AVANT tout require de src/db : la base est ouverte au chargement du module.
function prepareEnvironment() {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pm-test-'));
  process.env.DB_PATH = path.join(dir, 'test.sqlite');
  process.env.ADMIN_EMAIL = ADMIN_EMAIL;
  process.env.ADMIN_PASSWORD = ADMIN_PASSWORD;
  process.env.SESSION_SECRET = 'secret-de-test';
  process.env.NODE_ENV = 'test';
  // La suite enchaîne bien plus de connexions qu'un humain : on desserre la limite réseau
  // (le verrouillage de compte, lui, reste testé tel quel).
  process.env.LOGIN_RATE_LIMIT = '5000';
  process.env.GLOBAL_RATE_LIMIT = '100000';
  return dir;
}

function startServer(app) {
  return new Promise((resolve) => {
    const server = app.listen(0, () => resolve(server));
  });
}

class Client {
  constructor(baseUrl) {
    this.baseUrl = baseUrl;
    // Bocal à cookies : la session, mais aussi la langue choisie avant connexion.
    this.cookies = new Map();
    this.csrfToken = null;
  }

  #absorbCookie(res) {
    const raw = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
    for (const entry of raw) {
      const [pair] = entry.split(';');
      const separator = pair.indexOf('=');
      if (separator > 0) this.cookies.set(pair.slice(0, separator), pair.slice(separator + 1));
    }
  }

  #headers(extra = {}) {
    if (this.cookies.size === 0) return extra;
    const cookie = [...this.cookies].map(([name, value]) => `${name}=${value}`).join('; ');
    return { cookie, ...extra };
  }

  async get(pathname) {
    const res = await fetch(this.baseUrl + pathname, { headers: this.#headers(), redirect: 'manual' });
    this.#absorbCookie(res);
    return res;
  }

  async html(pathname) {
    const res = await this.get(pathname);
    return { res, body: await res.text() };
  }

  // Le jeton CSRF est lié à la session : il change à chaque connexion/déconnexion.
  async refreshToken(pathname) {
    const { body } = await this.html(pathname);
    const match = body.match(/name="_csrf" value="([a-f0-9]+)"/);
    this.csrfToken = match ? match[1] : null;
    return this.csrfToken;
  }

  async post(pathname, data = {}, { withToken = true } = {}) {
    const form = new URLSearchParams(data);
    if (withToken && this.csrfToken) form.set('_csrf', this.csrfToken);

    const res = await fetch(this.baseUrl + pathname, {
      method: 'POST',
      headers: this.#headers({ 'content-type': 'application/x-www-form-urlencoded' }),
      body: form.toString(),
      redirect: 'manual',
    });
    this.#absorbCookie(res);
    return res;
  }

  async login(email, password) {
    await this.refreshToken('/connexion');
    const res = await this.post('/connexion', { email, password });
    const location = res.headers.get('location');
    if (location && location !== '/connexion') await this.refreshToken(location.split('#')[0]);
    return res;
  }

  async logout() {
    await this.post('/deconnexion');
    this.csrfToken = null;
  }

  // Les messages flash ne sont rendus qu'une fois, au chargement suivant.
  // EJS échappe le HTML : on redécode pour comparer au texte réellement affiché.
  async flash(pathname) {
    const { body } = await this.html(pathname);
    const match = body.match(/class="flash flash-(success|error)">([^<]*)</);
    if (!match) return null;

    const message = match[2]
      .replace(/&#39;/g, "'")
      .replace(/&quot;/g, '"')
      .replace(/&amp;/g, '&')
      .replace(/&lt;/g, '<')
      .replace(/&gt;/g, '>')
      .trim();

    return { type: match[1], message };
  }
}

module.exports = { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD };
