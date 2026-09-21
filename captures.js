#!/usr/bin/env node
/* =========================================================================
   Les captures du site, prises sur une instance réelle.

   ── Pourquoi ce fichier existe ──────────────────────────────────────────

   Les cent quarante-six images de `medias/` ont longtemps été faites à la
   main, et rien ne disait comment. Résultat : elles vieillissaient sans que
   personne ne sache les refaire, et un site qui montre une version du
   produit d'il y a six mois ment aussi sûrement qu'un chiffre recopié de
   travers.

   Ce script les refait toutes. Il ne maquette rien : il ouvre un vrai CMS,
   peuplé du jeu de démonstration, et photographie ce qu'un client verrait.

   ── Ce qu'il faut avant de le lancer ────────────────────────────────────

   Deux instances, et c'est voulu. L'assistant d'installation ne répond plus
   une fois la configuration posée — la porte se referme, et c'est
   exactement ce qu'on veut en production ; on ne peut donc pas le
   photographier sur l'instance de démonstration.

       cd /chemin/vers/le/cms

       # celle qui a tout : le jeu de démonstration, options comprises
       DB_PATH=/tmp/demo.sqlite ADMIN_EMAIL=… ADMIN_PASSWORD=… \
         node -e "require('./src/db')"
       DB_PATH=/tmp/demo.sqlite node scripts/seed-demo.js
       DB_PATH=/tmp/demo.sqlite node -e "const m=require('./src/modules'); \
         for (const k of m.KEYS) m.setEnabled(k, true)"
       DB_PATH=/tmp/demo.sqlite PORT=8500 \
         LOGIN_RATE_LIMIT=40 GLOBAL_RATE_LIMIT=2000 node src/server.js &

       # celle qui n'a rien, laissée devant son assistant
       DB_PATH=/tmp/neuf.sqlite PORT=8501 node src/server.js &

   Les deux plafonds méritent un mot. Le CMS n'accepte que dix connexions par
   quart d'heure et par adresse, et c'est bien ainsi : c'est ce qui rend
   inutile d'essayer les mots de passe en rafale. Ce script n'en demande que
   quatre — une par rôle et par thème — et tient donc dans le quota tel quel.
   Mais on refait rarement les captures du premier coup, et trois tours en un
   quart d'heure épuisent le compte. Le réglage est desserré sur l'instance de
   démonstration, qui ne contient rien et n'est ouverte à personne. Sur une
   instance qui sert, on n'y touche pas.

   `GLOBAL_RATE_LIMIT` est l'autre : trois cents requêtes par minute et par
   adresse. Un visiteur n'en fait jamais autant ; ce script ouvre cent
   cinquante et une pages avec leurs feuilles de style et leurs icônes, aussi
   vite que la machine le permet, et le dépasse vers la centième. Au-delà, le
   CMS répond « Too many requests » — et une capture de ce refus ressemble à
   une capture. Le script sait maintenant le reconnaître au code de réponse,
   mais mieux vaut ne pas y arriver.

   Puis, ici :

       npm install --no-save playwright      # jamais dans le dépôt du site

       CMS=http://127.0.0.1:8500 CMS_NEUF=http://127.0.0.1:8501 \
       ADMIN=direction@toutadmin.demo ADMIN_MDP=… \
       MEMBRE=claire.moreau@entreprise.com MEMBRE_MDP=demo-1234 \
         node captures.js [nom-de-capture …]

   Sans argument, il refait tout. Avec des noms, il ne refait que ceux-là :
   une capture ratée se reprend seule, sans attendre les cent quarante-cinq
   autres.

   ── Ce qu'il laisse derrière lui ────────────────────────────────────────

   Les quatre-vingt-dix captures des autres langues changent la langue des
   deux comptes de démonstration, parce que c'est la seule façon honnête de
   l'obtenir (voir `poserLangue`). Le script les remet en français en
   partant. Rien d'autre de l'instance n'est touché.
   ========================================================================= */

const fs = require('fs');
const path = require('path');

const MEDIA = require('./medias.js');
const { palettiser } = require('./images.js');
const RACINE = __dirname;
const SORTIE = path.join(RACINE, 'medias');

const CMS = (process.env.CMS || 'http://127.0.0.1:8500').replace(/\/+$/, '');
/*
 * Une seconde instance, jamais installée.
 *
 * L'assistant d'installation ne répond plus une fois « config » posé — la
 * porte se referme, et c'est exactement ce qu'on veut en production. On ne
 * peut donc pas le photographier sur l'instance de démonstration : il faut
 * une base vierge, sur un autre port, qu'on laisse à sa première étape.
 */
const CMS_NEUF = (process.env.CMS_NEUF || 'http://127.0.0.1:8501').replace(/\/+$/, '');
const ADMIN = process.env.ADMIN || 'direction@toutadmin.demo';
const ADMIN_MDP = process.env.ADMIN_MDP || 'Demonstration-2026!';
const MEMBRE = process.env.MEMBRE || 'claire.moreau@entreprise.com';
const MEMBRE_MDP = process.env.MEMBRE_MDP || 'demo-1234';

/*
 * Le format, et pourquoi il est le même partout.
 *
 * 1080 points de large, doublés : 2160 pixels, ce que demande un écran dense
 * sans peser trois mégaoctets. La hauteur suit la page — un tableau de bord
 * n'a pas la taille d'un écran de connexion, et le recadrer à une hauteur
 * fixe couperait justement ce qu'on voulait montrer.
 */
const LARGEUR = 1080;
const DENSITE = 2;

/*
 * Jusqu'où descend la photo.
 *
 * La capture suit la page, et certaines sont longues : l'espace du membre,
 * peuplé du jeu de démonstration, fait deux mille deux cents points de haut.
 * Le site n'en montre jamais autant — la galerie recadre en seize-dixièmes,
 * le héros s'arrête à cinq cent quarante points — et la visionneuse, qui
 * seule affiche l'image entière, la tient dans quatre-vingt-quatre pour cent
 * de la fenêtre : au-delà d'un millier de points, l'interface s'y écrase en
 * un ruban qu'on ne lit plus. On coupe donc, et ce qui est coupé n'aurait de
 * toute façon jamais été vu.
 */
const HAUTEUR_MAX = 1400;

/*
 * Où va chaque capture.
 *
 * « role » dit avec quel compte : l'administration voit les écrans de
 * pilotage, un membre voit son espace. Photographier l'espace d'un membre
 * depuis un compte d'administration montrerait un écran que personne n'a
 * jamais sous les yeux.
 */
const CIBLES = {
  // ── L'espace du membre ───────────────────────────────────────────────
  'membre-espace': { chemin: '/mon-espace', role: 'membre' },
  'membre-equipe': { chemin: '/mon-equipe', role: 'membre' },
  'membre-demandes': { chemin: '/demandes', role: 'membre' },
  'membre-agenda': { chemin: '/agenda', role: 'membre' },
  'membre-salles': { chemin: '/salles', role: 'membre' },
  'membre-messagerie': { chemin: '/messagerie', role: 'membre' },
  'membre-connaissances': { chemin: '/base-de-connaissances', role: 'membre' },
  'membre-coffre-fort': { chemin: '/coffre-fort', role: 'membre' },
  'membre-profil': { chemin: '/mon-profil', role: 'membre' },
  // Au pied du profil : sans l'ancre, la coupe du haut ne l'atteint jamais.
  'membre-appareils': { chemin: '/mon-profil', role: 'membre', ancre: '#appareils' },
  'membre-juridique-membre': { chemin: '/juridique', role: 'membre' },
  'membre-cse': { chemin: '/cse', role: 'membre' },
  annuaire: { chemin: '/annuaire', role: 'membre' },
  organigramme: { chemin: '/organigramme', role: 'membre' },
  notifications: { chemin: '/notifications', role: 'membre' },
  recherche: { chemin: '/recherche?q=cong', role: 'membre' },
  // Déconnecté : c'est la seule page que voit quelqu'un qui n'est pas entré.
  connexion: { chemin: '/connexion', role: 'visiteur' },

  // ── L'administration ─────────────────────────────────────────────────
  pilotage: { chemin: '/pilotage', role: 'admin' },
  admin: { chemin: '/admin', role: 'admin' },
  rh: { chemin: '/rh', role: 'admin' },
  parcours: { chemin: '/parcours', role: 'admin' },
  gestion: { chemin: '/gestion', role: 'admin' },
  direction: { chemin: '/direction', role: 'admin' },
  projets: { chemin: '/projets', role: 'admin' },
  support: { chemin: '/support', role: 'admin' },
  planning: { chemin: '/planning', role: 'admin' },
  qualite: { chemin: '/qualite', role: 'admin' },
  'sante-securite': { chemin: '/sante-securite', role: 'admin' },
  informatique: { chemin: '/informatique', role: 'admin' },
  developpement: { chemin: '/developpement', role: 'admin' },
  evenements: { chemin: '/evenements', role: 'admin' },
  juridique: { chemin: '/juridique', role: 'admin' },
  alertes: { chemin: '/alertes', role: 'admin' },
  securite: { chemin: '/securite', role: 'admin' },
  rgpd: { chemin: '/rgpd', role: 'admin' },
  parapheur: { chemin: '/parapheur', role: 'admin' },
  accueil: { chemin: '/accueil', role: 'admin' },
  import: { chemin: '/import', role: 'admin' },
  integrations: { chemin: '/integrations', role: 'admin' },
  sauvegardes: { chemin: '/sauvegardes', role: 'admin' },

  // ── Les modules livrés éteints ───────────────────────────────────────
  comptabilite: { chemin: '/comptabilite', role: 'admin' },
  paie: { chemin: '/paie', role: 'admin' },
  tresorerie: { chemin: '/tresorerie', role: 'admin' },
  immobilisations: { chemin: '/immobilisations', role: 'admin' },
  pieces: { chemin: '/pieces', role: 'admin' },
  facturation: { chemin: '/facturation-electronique', role: 'admin' },
  crm: { chemin: '/crm', role: 'admin' },
  stock: { chemin: '/stock', role: 'admin' },
  flotte: { chemin: '/flotte', role: 'admin' },

  /*
   * ── Ce que le produit sait faire de lui-même ─────────────────────────
   *
   * Absents du site jusqu'ici, et ce sont pourtant les écrans qui expliquent
   * comment il vit : ce qui prouve la licence, ce qui le tient à jour, ce
   * qu'il garde comme trace.
   *
   * Deux autres ont été essayés puis retirés, et le dire évite de les
   * remettre sans y penser : « /applications » affiche un bandeau rouge sur
   * l'instance de démonstration — elle n'est reliée à aucun éditeur, donc
   * sans catalogue à lire — et « /sondages » y est vide, le jeu de
   * démonstration n'en posant aucun. Les photographier donnerait une galerie
   * de vente montrant une erreur et un écran vide.
   */
  licence: { chemin: '/licence', role: 'admin' },
  'mises-a-jour': { chemin: '/mises-a-jour', role: 'admin' },
  journaux: { chemin: '/journaux', role: 'admin' },

  // ── L'assistant, sur une instance qui n'a rien encore ────────────────
  assistant: { chemin: '/install', role: 'visiteur', instance: 'neuf' },
};

/** Les captures en thème sombre : même adresse, autre peau. */
for (const nom of MEDIA.pairs) {
  const base = CIBLES[nom];
  if (!base) throw new Error(`« ${nom} » est déclaré en paire mais n'a pas de cible.`);
  CIBLES[`${nom}-sombre`] = { ...base, theme: 'dark' };
}

/* -------------------------------------------------------------- outillage */

function chromium() {
  /*
   * Playwright n'est pas une dépendance du site : il ne sert qu'ici, et un
   * site statique n'a pas à traîner un navigateur dans son package.json. On
   * le cherche donc là où il est, et l'on dit quoi faire s'il manque.
   */
  try {
    return require('playwright').chromium;
  } catch (e) {
    console.error('Playwright est introuvable. Posez-le le temps des captures :');
    console.error('  npm install --no-save playwright');
    process.exit(1);
  }
}

/** Le binaire, quand la variable d'environnement dit où il vit. */
function executablePath() {
  const dossier = process.env.PLAYWRIGHT_BROWSERS_PATH;
  if (!dossier || !fs.existsSync(dossier)) return undefined;
  for (const entree of fs.readdirSync(dossier)) {
    const chrome = path.join(dossier, entree, 'chrome-linux', 'chrome');
    if (fs.existsSync(chrome)) return chrome;
  }
  return undefined;
}

/**
 * Entrer, et vérifier qu'on est entré.
 *
 * Une session qui échoue en silence donne cent quarante-six captures de la
 * page de connexion, toutes valides en apparence. On le vérifie donc ici, une
 * fois, plutôt que de le découvrir en regardant les images.
 */
async function entrer(contexte, courriel, motDePasse) {
  const page = await contexte.newPage();
  try {
    await page.goto(`${CMS}/connexion`, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', courriel);
    await page.fill('input[name="password"]', motDePasse);
    await page.click('button[type="submit"]');
    await page.waitForURL((u) => !String(u).includes('/connexion'), { timeout: 15000 });
  } catch (erreur) {
    /*
     * Dire ce que la page dit.
     *
     * Le CMS refuse la onzième connexion en un quart d'heure depuis la même
     * adresse, et l'annonce en toutes lettres à l'écran. Sans cette lecture,
     * le script ne rapportait qu'un « délai dépassé » de quinze secondes, qui
     * ressemble à une panne de réseau et envoie chercher au mauvais endroit.
     */
    const dit = await page.locator('.flash, .alert, [role="alert"]').first()
      .textContent({ timeout: 1000 }).catch(() => null);
    await page.close();
    throw new Error(dit ? `connexion refusée — ${dit.trim().replace(/\s+/g, ' ')}` : erreur.message);
  }
  await page.close();
}

/**
 * Choisir la langue, comme un utilisateur la choisit.
 *
 * Poser un cookie ne suffit pas, et c'est voulu côté produit : une fois la
 * session ouverte, c'est la préférence enregistrée du compte qui l'emporte
 * sur tout le reste. Le cookie n'a donc d'effet que devant la page de
 * connexion. Sans ce détour, les quatre-vingt-dix captures des quinze autres
 * langues sortaient en français — toutes valides en apparence, et toutes
 * fausses.
 *
 * On passe donc par l'écran qui sert à cela, « Mon profil », et l'on vérifie
 * ensuite que la page répond bien dans la langue demandée.
 */
async function poserLangue(contexte, langue) {
  const page = await contexte.newPage();
  try {
    const reponse = await page.goto(`${CMS}/mon-profil`, { waitUntil: 'domcontentloaded' });
    if (reponse && !reponse.ok()) {
      // Même cause que plus haut : le plafond de requêtes répond en texte
      // brut, sans titre. C'est ce qui donnait un « page « » » sibyllin.
      throw new Error(`le serveur a répondu ${reponse.status()} sur « Mon profil »`);
    }
    const formulaire = page.locator('form[action="/mon-profil/informations"]');
    const choix = formulaire.locator('select[name="locale"]');

    /*
     * Cinq secondes, et non les trente par défaut.
     *
     * Si le formulaire n'est pas là, il ne viendra pas : la page est autre
     * chose — une redirection vers la connexion, un plafond de requêtes, une
     * erreur. Attendre une demi-minute retarde le diagnostic sans rien
     * changer, et le diagnostic est ce qui manque le plus quand on relit un
     * tour de cent cinquante captures au petit matin.
     */
    await choix.waitFor({ state: 'visible', timeout: 5000 }).catch(async () => {
      const titre = await page.title().catch(() => '?');
      throw new Error(`« Mon profil » n'a pas son formulaire de langue — page « ${titre} » à ${page.url()}`);
    });

    await choix.selectOption(langue);
    await Promise.all([
      page.waitForLoadState('domcontentloaded'),
      formulaire.locator('button[type="submit"]').click(),
    ]);

    const rendu = await page.getAttribute('html', 'lang');
    if (rendu !== langue) {
      throw new Error(`la page répond en « ${rendu} » et non en « ${langue} »`);
    }
  } finally {
    await page.close();
  }
}

/** Le calme avant la photo : polices posées, images arrivées, rien qui bouge. */
async function reposer(page) {
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.evaluate(() => document.fonts && document.fonts.ready).catch(() => {});
  /*
   * Les animations d'entrée sont coupées le temps de la photo. Une capture
   * prise au milieu d'un fondu montre une carte à moitié transparente, et
   * l'on croit à un défaut d'affichage.
   */
  await page.addStyleTag({
    content: `*, *::before, *::after { animation: none !important;
      transition: none !important; scroll-behavior: auto !important; }`,
  }).catch(() => {});
  await page.waitForTimeout(250);
}

async function photographier(contexte, nom, cible) {
  const page = await contexte.newPage();
  const fichier = path.join(SORTIE, `${nom}.png`);
  try {
    const racine = cible.instance === 'neuf' ? CMS_NEUF : CMS;
    const reponse = await page.goto(racine + cible.chemin, { waitUntil: 'domcontentloaded', timeout: 20000 });

    /*
     * Ce qu'on a reçu est-il bien la page demandée ?
     *
     * Le CMS plafonne les requêtes par adresse, et répond au-delà « Too many
     * requests, please try again later. » — du texte brut, sans mise en
     * forme. Le navigateur l'affiche sans broncher, la capture réussit, le
     * fichier est écrit, et le script annonce une coche verte. Cinq écrans
     * coréens ont ainsi été mis en ligne sous la forme d'un refus en anglais
     * sur fond blanc, à neuf kilo-octets — la seule chose qui les trahissait
     * était leur poids.
     *
     * Le code de réponse, lui, le disait depuis le début. On le regarde.
     */
    if (reponse && !reponse.ok()) {
      throw new Error(`le serveur a répondu ${reponse.status()} — ce n’est pas la page attendue`);
    }
    if (cible.role !== 'visiteur' && page.url().includes('/connexion')) {
      throw new Error('renvoyé à la connexion — la session n’a pas tenu');
    }
    await reposer(page);

    const hauteur = await page.evaluate(() => document.documentElement.scrollHeight);

    /*
     * Une ancre, quand ce qu'on veut montrer n'est pas en haut de la page.
     *
     * La coupe à 1400 points garde le haut, ce qui est le bon défaut : c'est
     * là que se trouve l'essentiel d'un tableau de bord. Mais une carte placée
     * en bas d'un écran long — « Mes appareils », au pied du profil — n'entre
     * jamais dans le cadre, et la capture montre alors tout sauf le sujet.
     * L'ancre cadre à partir de l'élément nommé.
     */
    const depart = cible.ancre
      ? await page.evaluate((sel) => {
          const noeud = document.querySelector(sel);
          if (!noeud) return null;
          const haut = noeud.getBoundingClientRect().top + window.scrollY;
          return Math.max(0, Math.round(haut) - 24);
        }, cible.ancre)
      : 0;
    if (cible.ancre && depart === null) {
      throw new Error(`l’ancre « ${cible.ancre} » est absente de la page`);
    }

    const reste = hauteur - depart;
    const brut = await page.screenshot({
      fullPage: true,
      ...(reste > HAUTEUR_MAX || depart > 0
        ? { clip: { x: 0, y: depart, width: LARGEUR, height: Math.min(reste, HAUTEUR_MAX) } }
        : {}),
    });

    /*
     * Le navigateur rend un PNG en couleurs vraies ; le site en sert des
     * PNG à palette, quatre fois plus légers pour un écart qu'on ne voit
     * pas. La conversion est ici et non dans un outil à part : une capture
     * qu'il faudrait « penser à compresser ensuite » finit tôt ou tard en
     * ligne sans l'avoir été.
     */
    const image = palettiser(brut);
    fs.writeFileSync(fichier, image);

    const coupee = cible.ancre
      ? ` (cadrée sur ${cible.ancre}, à ${depart} de ${hauteur})`
      : (hauteur > HAUTEUR_MAX ? ` (coupée à ${HAUTEUR_MAX} de ${hauteur})` : '');
    console.log(`  ✓ ${nom.padEnd(26)} ${cible.chemin.padEnd(24)} `
      + `${Math.round(brut.length / 1024)} → ${Math.round(image.length / 1024)} Ko${coupee}`);
    return true;
  } catch (erreur) {
    console.log(`  ✗ ${nom.padEnd(26)} ${erreur.message}`);
    return false;
  } finally {
    await page.close();
  }
}

/* ------------------------------------------------------------------ le fil */

(async () => {
  const demandees = process.argv.slice(2);
  const nav = await chromium().launch({ executablePath: executablePath() });

  /*
   * Les six écrans du tour sont repris dans les quinze autres langues : le
   * site montre l'interface dans la langue du visiteur, et une capture
   * française sur une page japonaise est une promesse qu'on ne tient pas.
   */
  const AUTRES = ['en', 'es', 'de', 'it', 'pt', 'nl', 'pl', 'ru', 'tr', 'ar', 'hi', 'zh', 'ja', 'ko', 'vi'];

  /*
   * L'ordre du travail : par langue, et non par écran.
   *
   * Changer de langue, c'est écrire la préférence d'un compte puis recharger.
   * Groupées par écran, les quatre-vingt-dix captures traduites demandaient
   * quatre-vingt-dix bascules ; groupées par langue, elles en demandent
   * trente — une par langue et par rôle.
   */
  const travaux = [];
  for (const [nom, cible] of Object.entries(CIBLES)) travaux.push({ nom, cible, langue: 'fr' });
  for (const langue of AUTRES) {
    for (const nom of MEDIA.tour) {
      travaux.push({ nom: `${nom}-${langue}`, cible: CIBLES[nom], langue });
    }
  }

  const aFaire = demandees.length
    ? travaux.filter((t) => demandees.includes(t.nom))
    : travaux;

  if (!aFaire.length) {
    console.error('Aucune capture de ce nom. Les noms connus sont ceux de medias.js.');
    process.exit(1);
  }

  let faites = 0;
  let ratees = 0;

  /*
   * Un contexte par rôle et par thème — et surtout pas un par langue.
   *
   * Le CMS n'accepte que dix connexions par quart d'heure et par adresse.
   * C'est une protection contre l'essai de mots de passe en rafale, elle est
   * à sa place, et ce script n'a pas à la desserrer pour se simplifier la
   * vie : la première version ouvrait une session par langue, en épuisait le
   * quota à la cinquième, et s'arrêtait sur un « délai dépassé » au milieu
   * des captures traduites. Sept contextes suffisent, la langue se changeant
   * à l'intérieur d'une session comme le ferait n'importe quel utilisateur.
   */
  const contextes = new Map();
  /*
   * La langue où en est chaque session. « null » veut dire « on ne sait pas
   * encore » — et non « français » : la préférence est enregistrée sur le
   * compte, elle a pu rester dans une autre langue si un tour précédent s'est
   * arrêté en chemin. On la pose donc explicitement la première fois, même
   * pour le français.
   */
  const langues = new Map();

  const contexteDe = async (role, theme, instance) => {
    const cle = `${role}/${theme || 'light'}/${instance || 'demo'}`;
    if (contextes.has(cle)) return contextes.get(cle);

    const contexte = await nav.newContext({
      viewport: { width: LARGEUR, height: 760 },
      deviceScaleFactor: DENSITE,
      // Le thème est posé avant le premier rendu : la page le lit sur <html>
      // en tête de document, et l'appliquer après coup se verrait. Le réglage
      // du système va avec, pour les rares règles qui suivent la machine
      // plutôt que la préférence enregistrée.
      colorScheme: theme === 'dark' ? 'dark' : 'light',
    });
    await contexte.addInitScript((t) => {
      try {
        localStorage.setItem('pm-theme', t);
      } catch (e) { /* stockage bloqué : le défaut fera l'affaire */ }
    }, theme || 'light');

    if (role === 'admin') await entrer(contexte, ADMIN, ADMIN_MDP);
    if (role === 'membre') await entrer(contexte, MEMBRE, MEMBRE_MDP);

    langues.set(contexte, null);
    contextes.set(cle, contexte);
    return contexte;
  };

  /** La langue du contexte, changée seulement si elle ne l'est pas déjà. */
  const enLangue = async (contexte, role, langue, instance) => {
    if (instance === 'neuf' || role === 'visiteur') {
      // Devant la page de connexion et devant l'assistant, aucun compte n'est
      // ouvert : c'est le cookie qui décide, et lui seul.
      await contexte.addCookies([{ name: 'locale', value: langue,
        url: instance === 'neuf' ? CMS_NEUF : CMS }]);
      return;
    }
    if (langues.get(contexte) === langue) return;
    /*
     * Une seconde chance, et une seule.
     *
     * Un tour de cent cinquante captures a buté une fois sur un « Mon
     * profil » sans formulaire, à la centième — irreproductible ensuite, la
     * même séquence rejouée à la main passant sans broncher. Une panne
     * passagère ne doit pas coûter les cinquante captures suivantes ; une
     * panne installée, elle, doit s'arrêter et se dire.
     */
    try {
      await poserLangue(contexte, langue);
    } catch (premiere) {
      console.log(`  … ${langue} : ${premiere.message} — on réessaie une fois`);
      await poserLangue(contexte, langue);
    }
    langues.set(contexte, langue);
  };

  console.log(`Captures : ${aFaire.length} à prendre sur ${CMS}`);
  if (aFaire.some((t) => t.cible.instance === 'neuf')) {
    console.log(`           et l'assistant sur ${CMS_NEUF}, instance vierge`);
  }
  console.log('');

  try {
    for (const { nom, cible, langue } of aFaire) {
      const contexte = await contexteDe(cible.role, cible.theme, cible.instance);
      try {
        await enLangue(contexte, cible.role, langue, cible.instance);
      } catch (erreur) {
        /*
         * Pas de capture plutôt qu'une capture en français sous un nom
         * japonais : une image fausse passerait la relecture, et se
         * retrouverait en ligne.
         */
        console.log(`  ✗ ${nom.padEnd(26)} langue non posée — ${erreur.message}`);
        ratees += 1;
        continue;
      }
      const ok = await photographier(contexte, nom, cible);
      ok ? faites++ : ratees++;
    }
  } finally {
    /*
     * Rendre l'instance comme on l'a trouvée, même si le tour s'est arrêté
     * en chemin.
     *
     * Changer la langue du site, c'est changer la préférence enregistrée du
     * compte. Sans ce retour, l'instance de démonstration resterait dans la
     * dernière langue de la liste pour qui l'ouvrirait ensuite — et c'est
     * exactement ce qui s'est produit le jour où le script s'est arrêté au
     * milieu : elle est restée en italien. D'où le « finally ».
     *
     * Deux comptes, deux préférences : remettre celle de l'administration ne
     * remet pas celle du membre, et la moitié des écrans resterait traduite.
     */
    for (const [cle, contexte] of contextes) {
      const role = cle.split('/')[0];
      if (role === 'visiteur' || (langues.get(contexte) || 'fr') === 'fr') continue;
      try {
        await poserLangue(contexte, 'fr');
      } catch (erreur) {
        console.log(`  … ${role} : la langue n'a pas pu être remise en français — ${erreur.message}`);
      }
    }

    await nav.close();
  }

  console.log(`\n${faites} capture(s) prise(s)` + (ratees ? `, ${ratees} en échec` : '.'));
  if (ratees) process.exit(1);
})();
