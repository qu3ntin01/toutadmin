const { band, frame, head } = require('./_shared');

// La même adresse que dans la barre et le pied de page : deux domaines pour
// la même documentation, sur le même site, et l'un des deux est forcément mort.
const DOC_URL = 'https://docs.toutadmin.com/';

/* Le téléchargement est ouvert à tout le monde, formule gratuite comprise :
   ce qui décide de la formule, c'est l'effectif, pas l'endroit où tourne
   l'instance. L'adresse est écrite ici et nulle part ailleurs. */
const DOWNLOAD_URL = 'https://app.toutadmin.com/telecharger';

/* Les blocs de commandes ne sont pas traduits : ce sont les mêmes lignes dans
   les seize langues, et traduire une commande la rendrait fausse. Seule la
   prose qui les entoure passe par le dictionnaire. */
// Les lignes restent courtes : une commande qu'il faut faire défiler
// horizontalement pour la lire entière est une commande qu'on recopie mal.
const QUICK_NODE = 'git clone &lt;votre-dépôt&gt; toutadmin\n'
  + 'cd toutadmin && npm ci --omit=dev\n'
  + 'INSTALL_TOKEN=$(openssl rand -hex 16) \\\n'
  + 'NODE_ENV=production node src/server.js';

const QUICK_PHP = '# 1. déposer le dossier (racine web → public/)\n'
  + '# 2. cp config.sample.php config.php\n'
  + '# 3. chmod 770 data/\n'
  + '# 4. ouvrir https://votre-domaine/installation';

const NODE_STEPS = [
  'git clone &lt;votre-dépôt&gt; /var/www/toutadmin\ncd /var/www/toutadmin\nnpm ci --omit=dev',
  'cp .env.example .env\n# PORT, NODE_ENV=production, INSTALL_TOKEN…',
  'sudo systemctl enable --now toutadmin\njournalctl -u toutadmin -f',
  'sudo certbot --nginx -d intranet.exemple.fr',
];

const PHP_STEPS = [
  '# déposer le dossier ; la racine web pointe sur public/, pas sur le dossier',
  'cp config.sample.php config.php\nphp -r "echo bin2hex(random_bytes(32));"   # session_secret',
  'chmod 770 data/     # le dossier, pas seulement la base',
  '* * * * * /usr/bin/php /var/www/toutadmin/tools/cron.php',
];

const WIZARD_URL = 'https://votre-domaine/installation';

module.exports = function installation(ctx) {
  const { t, esc, icon } = ctx;

  const steps = (prefix, blocks) => blocks.map((code, i) => `<li class="reveal">
              <h3>${esc(t(`${prefix}.s${i + 1}t`))}</h3>
              <p>${esc(t(`${prefix}.s${i + 1}b`))}</p>
              <pre><code>${code}</code></pre>
            </li>`).join('\n            ');

  const editionCard = (key, ic) => `<article class="card reveal">
            <span class="ico">${icon(ic)}</span>
            <h3>${esc(t(`install.${key}.name`))}</h3>
            <p>${esc(t(`install.${key}.for`))}</p>
            <p class="muted">${esc(t(`install.${key}.needs`))}</p>
          </article>`;

  const quickCard = (key, code) => `<article class="card reveal">
            <h3>${esc(t(`install.${key}.name`))}</h3>
            <p>${esc(t(`install.quick.${key}`))}</p>
            <pre><code>${code}</code></pre>
          </article>`;

  const checks = [1, 2, 3].map((n) => `<article class="card reveal">
            <h3>${esc(t(`install.check${n}t`))}</h3>
            <p>${esc(t(`install.check${n}b`))}</p>
          </article>`).join('\n          ');

  // Les cinq étapes de l'assistant, dans l'ordre où il les présente.
  const wizardSteps = [1, 2, 3, 4, 5].map((n) => `<li class="reveal">
              <span class="num">${n}</span>
              <span><strong>${esc(t(`install.w${n}t`))}</strong><span class="muted">${esc(t(`install.w${n}b`))}</span></span>
            </li>`).join('\n            ');

  return {
    title: `${t('nav.install')} — ${t('site.name')}`,
    description: t('install.lede'),
    body: `
    <section class="hero section-tight">
      <div class="hero-bg"></div><div class="hero-grid-lines"></div>
      <div class="wrap center">
        <span class="eyebrow reveal">${esc(t('nav.install'))}</span>
        <h1 class="reveal">${esc(t('install.title'))}</h1>
        <p class="lede reveal">${esc(t('install.lede'))}</p>
        <div class="hero-cta reveal">
          <a class="btn" href="${ctx.href('contact')}">${esc(t('install.saasCta'))}${icon('arrow')}</a>
          <a class="btn btn-ghost" href="${DOWNLOAD_URL}" rel="noopener">${icon('box')}${esc(t('install.dlCta'))}</a>
        </div>
        <p class="hero-note reveal">${esc(t('install.saasNote'))}</p>
      </div>
    </section>

    <section class="section-tight">
      <div class="wrap">
        <div class="note reveal mx-auto">
          <h3>${esc(t('install.licenceTitle'))}</h3>
          <p>${esc(t('install.licenceBody'))}</p>
          <p><a class="link-arrow" href="${ctx.href('tarifs')}">${esc(t('install.licenceCta'))}${icon('arrow')}</a></p>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="wrap">
        ${head(ctx, null, t('install.chooseTitle'), t('install.chooseLede'))}
        <div class="grid g2 stagger">
          ${editionCard('node', 'code')}
          ${editionCard('php', 'box')}
        </div>
      </div>
    </section>

    <section class="section tinted">
      <div class="wrap">
        <div class="note reveal mx-auto">
          <h3>${esc(t('install.sameTitle'))}</h3>
          <p>${esc(t('install.sameBody'))}</p>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="wrap">
        ${head(ctx, t('install.quickEyebrow'), t('install.quickTitle'), t('install.quickLede'))}
        <div class="grid g2 stagger">
          ${quickCard('node', QUICK_NODE)}
          ${quickCard('php', QUICK_PHP)}
        </div>
      </div>
    </section>

    <section class="section tinted">
      <div class="wrap">
        ${head(ctx, null, t('install.wizardTitle'), t('install.wizardLede'))}
        <div class="wizard">
          <div class="wizard-text reveal">
            <ol class="wizard-steps">
            ${wizardSteps}
            </ol>
            <pre class="reveal"><code>${WIZARD_URL}</code></pre>
            <div class="note">
              <h3>${esc(t('install.tokenTitle'))}</h3>
              <p>${esc(t('install.tokenBody'))}</p>
            </div>
          </div>
          <div class="wizard-shot reveal">
            ${frame(ctx, 'assistant')}
          </div>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="wrap">
        ${head(ctx, t('install.node.name'), t('install.nodeTitle'), null)}
        <ol class="steps stagger">
            ${steps('install.node', NODE_STEPS)}
        </ol>
      </div>
    </section>

    <section class="section tinted">
      <div class="wrap">
        ${head(ctx, t('install.php.name'), t('install.phpTitle'), null)}
        <ol class="steps stagger">
            ${steps('install.php', PHP_STEPS)}
        </ol>
      </div>
    </section>

    <section class="section">
      <div class="wrap">
        ${head(ctx, null, t('install.checkTitle'), null)}
        <div class="grid g3 stagger">
          ${checks}
        </div>
      </div>
    </section>

    <section class="section tinted">
      <div class="wrap">
        <div class="band reveal">
          <h2>${esc(t('install.docTitle'))}</h2>
          <p>${esc(t('install.docBody'))}</p>
          <div class="band-cta">
            <a class="btn btn-light" href="${DOC_URL}" rel="noopener">${esc(t('install.docCta'))}${icon('arrow')}</a>
          </div>
        </div>
      </div>
    </section>

    ${band(ctx)}
`,
  };
};
