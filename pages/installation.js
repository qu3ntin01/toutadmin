const { band, pageHero, head } = require('./_shared');

const DOC_URL = 'https://docs.toutadmin.fr';

/* Les blocs de commandes ne sont pas traduits : ce sont les mêmes lignes dans
   les seize langues, et traduire une commande la rendrait fausse. Seule la
   prose qui les entoure passe par le dictionnaire. */
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

  const checks = [1, 2, 3].map((n) => `<article class="card reveal">
            <h3>${esc(t(`install.check${n}t`))}</h3>
            <p>${esc(t(`install.check${n}b`))}</p>
          </article>`).join('\n          ');

  return {
    title: `${t('nav.install')} — ${t('site.name')}`,
    description: t('install.lede'),
    body: `
    ${pageHero(ctx, t('nav.install'), t('install.title'), t('install.lede'))}

    <section class="section-tight">
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
