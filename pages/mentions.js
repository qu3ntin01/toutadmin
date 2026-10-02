const { pageHero } = require('./_shared');

/**
 * Les mentions légales.
 *
 * Rien ici n'est écrit en dur : tout vient de « editeur.json », et la page n'est
 * construite que si ce fichier est rempli. Une mention légale inventée serait
 * pire que l'absence de page — l'absence est une omission, une adresse fausse est
 * une fausse déclaration.
 *
 * La construction, elle, dit à chaque passage ce qui manque. C'est le seul rappel
 * qui tienne : un commentaire dans un fichier se lit une fois.
 */
module.exports = function mentions(ctx) {
  const { t, esc, editeur } = ctx;

  const ligne = (cle, valeur) => (String(valeur || '').trim()
    ? `<div class="legal-row"><dt>${esc(t(`legal.f.${cle}`))}</dt><dd>${esc(valeur)}</dd></div>`
    : '');

  return {
    title: `${t('legal.title')} — ${t('site.name')}`,
    description: t('legal.lede'),
    body: `
    ${pageHero(ctx, t('nav.legal'), t('legal.title'), t('legal.lede'))}

    <section class="section">
      <div class="wrap wrap-narrow">

        <h2>${esc(t('legal.h.editor'))}</h2>
        <dl class="legal">
          ${ligne('name', editeur.raisonSociale)}
          ${ligne('form', editeur.forme)}
          ${ligne('capital', editeur.capital)}
          ${ligne('siren', editeur.siren)}
          ${ligne('vat', editeur.tva)}
          ${ligne('address', editeur.siege)}
          ${ligne('director', editeur.directeur)}
          ${ligne('email', editeur.courriel)}
        </dl>

        <h2>${esc(t('legal.h.host'))}</h2>
        <dl class="legal">
          ${ligne('host', editeur.hebergeur)}
          ${ligne('hostAddress', editeur.hebergeurAdresse)}
          ${ligne('hostPhone', editeur.hebergeurTelephone)}
        </dl>

        <h2>${esc(t('legal.h.ip'))}</h2>
        <p>${esc(t('legal.p.ip'))}</p>

        <h2>${esc(t('legal.h.data'))}</h2>
        <p>${esc(t('legal.p.data'))}</p>

        <h2>${esc(t('legal.h.cookies'))}</h2>
        <p>${esc(t('legal.p.cookies'))}</p>

      </div>
    </section>`,
  };
};
