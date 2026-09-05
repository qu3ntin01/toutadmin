const db = require('./db');

// Réglages d'instance, posés à l'installation puis modifiables par l'administration.
const DEFAULTS = {
  company_name: 'Salarié Member',
  default_locale: 'fr',
  annual_leave_days: '25',
  installed_at: '',
  installed_version: '',
  // Palette de l'instance : posée par l'administration, valable pour tout le monde.
  theme_palette: 'institutionnel',
  // Politique de sécurité de l'instance.
  require_2fa_admin: '0',
  require_2fa_all: '0',
  audit_retention_days: '365',
  password_max_age_days: '0',
};

function get(key) {
  const row = db.prepare('SELECT value FROM settings WHERE key = ?').get(key);
  return row ? row.value : (DEFAULTS[key] ?? '');
}

function set(key, value) {
  db.prepare(`
    INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
    ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at
  `).run(key, String(value));
}

function setMany(entries) {
  const write = db.transaction((pairs) => {
    for (const [key, value] of Object.entries(pairs)) set(key, value);
  });
  write(entries);
}

function all() {
  const stored = Object.fromEntries(db.prepare('SELECT key, value FROM settings').all().map((r) => [r.key, r.value]));
  return { ...DEFAULTS, ...stored };
}

/** Initiales affichées dans le logo, dérivées du nom de l'entreprise. */
function brandInitials(name) {
  const words = String(name || DEFAULTS.company_name).trim().split(/\s+/).filter(Boolean);
  if (words.length === 0) return 'SM';
  if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
  return (words[0][0] + words[1][0]).toUpperCase();
}

module.exports = { DEFAULTS, get, set, setMany, all, brandInitials };
