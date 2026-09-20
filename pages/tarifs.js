const { band, pageHero, head } = require('./_shared');
const { plans, planCard, euro, LAUNCH_PRICE, LAUNCH_CLIENTS, LAUNCH_MONTHS,
  REPLY_DAYS, SETUP_FEE, HOURLY_RATE, HOURS_PACK, HOURS_PACK_FEE } = require('./_plans');

module.exports = function pricing(ctx) {
  const { t, esc, icon } = ctx;

  const perks = {
    1: ['pricing.f.allSpaces', 'pricing.f.languages', 'pricing.f.security', 'pricing.f.updates', 'pricing.f.saas'],
    2: ['pricing.f.allSpaces', 'pricing.f.local', 'pricing.f.backupOff', 'pricing.f.api', 'pricing.f.supportMail'],
  };

  /*
 * Les deux nombres du premier avantage — les espaces et les modules — sortent
 * de `chiffres.json` comme ceux de la page d'accueil, et pour la même raison :
 * « Les 35 espaces » était faux dans seize langues à la fois.
 */
  const n = ctx.chiffres;

  const cards = plans(ctx).map((plan) => {
    const list = `<ul class="plan-list">
              ${perks[plan.n].map((key) => `<li>${icon('check')}<span>${esc(t(key, { espaces: n.espaces, modules: n.modules }))}</span></li>`).join('\n              ')}
            </ul>`;
    return planCard(ctx, plan, list);
  }).join('\n          ');

  /*
   * Ce qui est compris — une colonne, pas deux.
   *
   * La page comparait deux formules fonction par fonction, avec des « Non » en
   * face de la colonne gratuite : sauvegardes externalisées, multisite, SLA. Ce
   * tableau datait des paliers. Depuis qu'il n'y en a plus — un forfait, et
   * rien d'autre —, il n'y a plus qu'un produit, et il est entier pour tout le
   * monde. Le laisser aurait contredit, quinze lignes
   * plus haut, la promesse « tout le produit est dans toutes les formules ».
   *
   * Restent deux prestations qui ne sont pas du logiciel et se chiffrent au cas
   * par cas : reprendre des données existantes, et s'engager sur un niveau de
   * service. Elles sont dites « en option », ce qu'elles sont.
   */
  const yes = `<span class="mark-yes">${esc(t('pricing.yes'))}</span>`;
  const opt = `<span class="mark-opt">${esc(t('pricing.option'))}</span>`;
  const rows = [
    ['pricing.f.allSpaces', yes],
    ['pricing.f.languages', yes],
    ['pricing.f.security', yes],
    ['pricing.f.updates', yes],
    ['pricing.f.api', yes],
    ['pricing.f.saas', yes],
    ['pricing.f.local', yes],
    ['pricing.f.backupOff', yes],
    ['pricing.f.multisite', yes],
    ['pricing.f.support', `<span class="mark-yes">${esc(t('pricing.f.supportMail'))}</span>`],
    ['pricing.f.setup', opt],
    ['pricing.f.migration', opt],
    ['pricing.f.sla', opt],
  ].map(([key, valeur]) =>
    `<tr><th scope="row">${esc(t(key, { espaces: n.espaces, modules: n.modules }))}</th><td class="c">${valeur}</td></tr>`).join('\n              ');

  // Les trois paliers d'une licence qui s'arrête. On les met sur la page qui
  // vend : un client a le droit de savoir ce qui se passe s'il cesse de payer,
  // et le savoir d'avance vaut mieux que le découvrir.
  const steps = [
    ['licence.step1.when', 'licence.step1.what'],
    ['licence.step2.when', 'licence.step2.what'],
    ['licence.step3.when', 'licence.step3.what'],
  ].map(([when, what]) =>
    `<tr><th scope="row">${esc(t(when))}</th><td>${esc(t(what))}</td></tr>`).join('\n              ');

  /*
   * Le tarif de lancement a une section à lui, et trois nombres qui se lisent
   * d'un coup : dix euros, cent clients, douze mois. Il est daté et il est
   * compté — une promotion sans fin n'est pas une promotion, c'est le prix, et
   * un client qui la découvre en la perdant la vit comme une hausse.
   */
  const faq = [1, 9, 6, 7, 4].map((n) => `<details class="reveal">
            <summary>${esc(t(`faq.q${n}`))}${icon('plus')}</summary>
            <div class="answer">${esc(t(`faq.a${n}`))}</div>
          </details>`).join('\n          ');

  return {
    title: `${t('nav.pricing')} — ${t('site.name')}`,
    description: t('pricing.lede'),
    body: `
    ${pageHero(ctx, t('nav.pricing'), t('pricing.title'), t('pricing.lede'))}

    <section class="section-tight">
      <div class="wrap">
        <p class="center reveal"><span class="eyebrow">${icon('users')}${esc(t('pricing.flat'))}</span></p>
        <div class="plans stagger mt-l">
          ${cards}
        </div>
        <p class="center stat-n mt-m reveal">${esc(t('pricing.vat'))}</p>
      </div>
    </section>

    <section class="section tinted">
      <div class="wrap">
        ${head(ctx, null, t('pricing.launchTitle'), t('pricing.launchLede'))}

        <div class="plans plans-3 stagger mt-l">
          ${[
            [t('pricing.launch.priceName'), euro(ctx.locale.code, LAUNCH_PRICE), t('pricing.perMonth')],
            [t('pricing.launch.seatsName'), String(LAUNCH_CLIENTS), t('pricing.launch.seatsUnit')],
            [t('pricing.launch.termName'), String(LAUNCH_MONTHS), t('pricing.launch.termUnit')],
          ].map(([nom, valeur, unite], i) => `<article class="plan${i === 0 ? ' is-featured' : ''} reveal">
            <div class="plan-name">${esc(nom)}</div>
            <div class="plan-price">
              <span class="plan-amount">${esc(valeur)}</span>
              <span class="plan-per">${esc(unite)}</span>
            </div>
          </article>`).join('\n          ')}
        </div>

        <div class="note reveal mx-auto mt-l">
          <h3>${esc(t('pricing.argTitle'))}</h3>
          <p>${esc(t('pricing.argBody'))}</p>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="wrap">
        ${head(ctx, null, t('pricing.tableTitle'), null)}
        <div class="table-wrap reveal">
          <table>
            <thead>
              <tr>
                <th scope="col">${esc(t('pricing.col.feature'))}</th>
                <th scope="col">${esc(t('pricing.col.included'))}</th>
              </tr>
            </thead>
            <tbody>
              ${rows}
            </tbody>
          </table>
        </div>
        <div class="note reveal mt-l">
          <h3>${esc(t('pricing.countTitle'))}</h3>
          <p>${esc(t('pricing.countBody'))}</p>
        </div>
      </div>
    </section>

    <section class="section tinted">
      <div class="wrap">
        ${head(ctx, null, t('support.title'), t('support.lede'))}

        <div class="plans plans-3 stagger mt-l">
          ${[
            ['support.t1', t('pricing.free'), ''],
            ['support.t2', t('support.t2.amount', { jours: String(REPLY_DAYS) }), t('support.included')],
            ['support.t3', t('support.t3.amount'), ''],
          ].map(([cle, montant, per], i) => `<article class="plan${i === 1 ? ' is-featured' : ''} reveal">
            <div class="plan-name">${esc(t(`${cle}.name`))}</div>
            <div class="plan-for">${esc(t(`${cle}.who`))}</div>
            <p class="plan-value">${esc(montant)}${per ? ` <span class="plan-per">${esc(per)}</span>` : ''}</p>
            <p>${esc(t(`${cle}.what`))}</p>
          </article>`).join('\n          ')}
        </div>

        <div class="note reveal mx-auto mt-l">
          <h3>${esc(t('support.scopeTitle'))}</h3>
          <p>${esc(t('support.scopeBody'))}</p>
        </div>

        <div class="table-wrap reveal mt-l">
          <table>
            <thead>
              <tr>
                <th scope="col">${esc(t('support.optTitle'))}</th>
                <th scope="col">${esc(t('support.col.price'))}</th>
              </tr>
            </thead>
            <tbody>
              ${[
                ['support.opt.setup', euro(ctx.locale.code, SETUP_FEE)],
                ['support.opt.migration', t('support.quote')],
                ['support.opt.priority', t('support.opt.priorityPrice', {
                  rate: euro(ctx.locale.code, HOURLY_RATE),
                  hours: String(HOURS_PACK),
                  pack: euro(ctx.locale.code, HOURS_PACK_FEE),
                })],
                ['support.opt.sla', t('support.quote')],
              ].map(([cle, prix]) => `<tr>
                <th scope="row">${esc(t(cle))}<span class="opt-what">${esc(t(`${cle}Body`))}</span></th>
                <td class="c"><strong>${esc(prix)}</strong></td>
              </tr>`).join('\n              ')}
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="wrap">
        ${head(ctx, null, t('licence.title'), t('licence.lede'))}

        <div class="table-wrap reveal">
          <table>
            <thead>
              <tr>
                <th scope="col">${esc(t('licence.col.when'))}</th>
                <th scope="col">${esc(t('licence.col.what'))}</th>
              </tr>
            </thead>
            <tbody>
              ${steps}
            </tbody>
          </table>
        </div>

        <div class="note reveal mx-auto mt-l">
          <h3>${esc(t('licence.neverTitle'))}</h3>
          <p>${esc(t('licence.neverBody'))}</p>
        </div>

        <div class="note reveal mx-auto mt-l">
          <h3>${esc(t('licence.offlineTitle'))}</h3>
          <p>${esc(t('licence.offlineBody'))}</p>
        </div>
      </div>
    </section>

    <section class="section tinted">
      <div class="wrap">
        ${head(ctx, null, t('faq.title'), null)}
        <div class="faq mx-auto stagger">
          ${faq}
        </div>
      </div>
    </section>

    ${band(ctx)}
`,
  };
};
