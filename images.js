/* =========================================================================
   Réduire une capture à une palette, et la réécrire.

   ── Pourquoi ce fichier existe ──────────────────────────────────────────

   Les cent quarante-six images de `medias/` sont des PNG à palette : deux
   cent cinquante-six couleurs, un octet par point. Une capture brute sort du
   navigateur en couleurs vraies, quatre octets par point, et pèse trois à
   cinq fois plus. Sur un site dont les images sont le gros du poids, cela se
   voit : la page d'accueil passerait de douze à soixante mégaoctets.

   Les outils qui font cela — pngquant, oxipng — ne sont pas là, et le site
   n'a aucune dépendance : pas de package.json, rien à installer pour le
   construire. On ne va pas en ajouter une pour une opération qui tient en
   trois cents lignes, et qui ne changera plus jamais : le format PNG est
   figé depuis 1996.

   ── Ce que fait le fichier, dans l'ordre ────────────────────────────────

       lire le PNG du navigateur      → des points en rouge-vert-bleu
       compter les couleurs           → un histogramme grossi au 1/64
       découper en deux cent cinquante-six boîtes  (median cut)
       rendre chaque point à sa boîte → un octet par point
       réécrire un PNG à palette

   La perte est réelle et voulue : un dégradé très doux peut montrer des
   bandes. Sur des copies d'écran d'interface — des aplats, du texte, des
   bordures — elle ne se voit pas, et c'est bien pourquoi les captures qui
   sont déjà en ligne sont à palette elles aussi.
   ========================================================================= */

const zlib = require('zlib');

/* ------------------------------------------------------------ le contrôle */

const TABLE_CRC = (() => {
  const table = new Int32Array(256);
  for (let n = 0; n < 256; n += 1) {
    let c = n;
    for (let k = 0; k < 8; k += 1) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[n] = c;
  }
  return table;
})();

function crc32(octets) {
  let c = -1;
  for (let i = 0; i < octets.length; i += 1) c = TABLE_CRC[(c ^ octets[i]) & 0xff] ^ (c >>> 8);
  return (c ^ -1) >>> 0;
}

const SIGNATURE = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);

/* -------------------------------------------------------------- la lecture */

/**
 * Défaire un PNG en points.
 *
 * On ne lit que ce que produit le navigateur : huit bits par canal, sans
 * entrelacement, en couleurs vraies avec ou sans transparence. Tout le reste
 * du format — les palettes en entrée, les seize bits, l'entrelacement Adam7 —
 * n'arrivera jamais ici, et prétendre le lire serait du code que rien
 * n'éprouve. On refuse donc franchement plutôt que de deviner.
 */
function lire(tampon) {
  if (!tampon.subarray(0, 8).equals(SIGNATURE)) throw new Error('ce n’est pas un PNG');

  let entete = null;
  const morceaux = [];
  let position = 8;

  while (position + 8 <= tampon.length) {
    const taille = tampon.readUInt32BE(position);
    const type = tampon.toString('ascii', position + 4, position + 8);
    const donnees = tampon.subarray(position + 8, position + 8 + taille);
    position += 12 + taille;

    if (type === 'IHDR') {
      entete = {
        largeur: donnees.readUInt32BE(0),
        hauteur: donnees.readUInt32BE(4),
        bits: donnees[8],
        couleur: donnees[9],
        entrelacement: donnees[12],
      };
    } else if (type === 'IDAT') {
      morceaux.push(donnees);
    } else if (type === 'IEND') {
      break;
    }
  }

  if (!entete) throw new Error('PNG sans en-tête');
  if (entete.bits !== 8 || entete.entrelacement !== 0 || (entete.couleur !== 6 && entete.couleur !== 2)) {
    throw new Error(`PNG non géré (bits ${entete.bits}, couleur ${entete.couleur}, entrelacement ${entete.entrelacement})`);
  }

  const canaux = entete.couleur === 6 ? 4 : 3;
  const brut = zlib.inflateSync(Buffer.concat(morceaux));
  const { largeur, hauteur } = entete;
  const ligne = largeur * canaux;
  const points = Buffer.allocUnsafe(largeur * hauteur * canaux);

  /*
   * Défiltrer.
   *
   * Chaque ligne du PNG commence par un octet qui dit comment elle a été
   * prédite à partir de sa voisine de gauche et de celle du dessus. On
   * refait l'addition en sens inverse, ligne après ligne, et l'ordre compte :
   * une ligne a besoin de la précédente déjà remise à l'endroit.
   */
  for (let y = 0; y < hauteur; y += 1) {
    const filtre = brut[y * (ligne + 1)];
    const source = (y * (ligne + 1)) + 1;
    const sortie = y * ligne;
    const dessus = sortie - ligne;

    /*
     * Le type de filtre est choisi une fois par ligne, et non une fois par
     * octet : le laisser dans la boucle intérieure ferait quarante millions
     * de branchements par capture pour une valeur qui ne bouge pas.
     */
    if (filtre === 0) {
      points.set(brut.subarray(source, source + ligne), sortie);
    } else if (filtre === 1) {
      for (let x = 0; x < canaux; x += 1) points[sortie + x] = brut[source + x];
      for (let x = canaux; x < ligne; x += 1) {
        points[sortie + x] = (brut[source + x] + points[sortie + x - canaux]) & 0xff;
      }
    } else if (filtre === 2) {
      if (y === 0) points.set(brut.subarray(source, source + ligne), sortie);
      else for (let x = 0; x < ligne; x += 1) points[sortie + x] = (brut[source + x] + points[dessus + x]) & 0xff;
    } else if (filtre === 3) {
      for (let x = 0; x < ligne; x += 1) {
        const a = x >= canaux ? points[sortie + x - canaux] : 0;
        const b = y > 0 ? points[dessus + x] : 0;
        points[sortie + x] = (brut[source + x] + ((a + b) >> 1)) & 0xff;
      }
    } else if (filtre === 4) {
      for (let x = 0; x < ligne; x += 1) {
        const a = x >= canaux ? points[sortie + x - canaux] : 0;
        const b = y > 0 ? points[dessus + x] : 0;
        const c = (x >= canaux && y > 0) ? points[dessus + x - canaux] : 0;
        // Paeth : on garde celui des trois voisins dont la somme s'écarte le
        // moins de leur estimation commune.
        const p = a + b - c;
        const da = p > a ? p - a : a - p;
        const db = p > b ? p - b : b - p;
        const dc = p > c ? p - c : c - p;
        points[sortie + x] = (brut[source + x] + (da <= db && da <= dc ? a : (db <= dc ? b : c))) & 0xff;
      }
    } else {
      throw new Error(`filtre PNG inconnu : ${filtre}`);
    }
  }

  return { largeur, hauteur, canaux, points };
}

/* ----------------------------------------------------------- l'histogramme */

/*
 * La finesse du comptage.
 *
 * Compter les couleurs exactes d'une capture de deux mille points de large
 * donnerait des centaines de milliers d'entrées, presque toutes vues une
 * seule fois — les bavures d'antialiasing autour du texte. On arrondit donc
 * à six bits par canal : soixante-quatre niveaux, deux cent soixante-deux
 * mille cases possibles, dont quelques milliers seulement se remplissent. La
 * couleur retenue reste la moyenne exacte des points de la case, pas la case
 * elle-même — l'arrondi sert à regrouper, jamais à décider.
 */
const BITS = 6;
const DECALAGE = 8 - BITS;

const CASES = 1 << (BITS * 3);

/**
 * Compter, en une passe et sans allocation par point.
 *
 * La première écriture de ce comptage tenait dans une Map, une entrée par
 * couleur arrondie. C'était juste, et inutilisable : dix millions de
 * recherches par image, plusieurs minutes pour une seule capture. Quatre
 * tableaux à cases fixes font le même travail sans jamais allouer — la clé
 * arrondie *est* l'indice.
 */
function histogramme({ largeur, hauteur, canaux, points }) {
  const compte = new Uint32Array(CASES);
  const sommeR = new Float64Array(CASES);
  const sommeV = new Float64Array(CASES);
  const sommeB = new Float64Array(CASES);
  const total = largeur * hauteur;

  for (let i = 0; i < total; i += 1) {
    const p = i * canaux;
    let r = points[p];
    let v = points[p + 1];
    let b = points[p + 2];

    if (canaux === 4) {
      const a = points[p + 3];
      if (a !== 255) {
        // Aplati sur blanc : le site pose ces images sur des cartes claires,
        // et un PNG à palette ne porte pas de demi-transparence sans une
        // table de plus dont on n'a pas l'usage.
        r = (((r * a) + (255 * (255 - a))) / 255) | 0;
        v = (((v * a) + (255 * (255 - a))) / 255) | 0;
        b = (((b * a) + (255 * (255 - a))) / 255) | 0;
      }
    }

    const cle = ((r >> DECALAGE) << (BITS * 2)) | ((v >> DECALAGE) << BITS) | (b >> DECALAGE);
    compte[cle] += 1;
    sommeR[cle] += r; sommeV[cle] += v; sommeB[cle] += b;
  }

  // Seules les cases occupées repartent — quelques milliers sur deux cent
  // soixante-deux mille, et c'est sur elles seules que le découpage travaille.
  const occupees = [];
  for (let cle = 0; cle < CASES; cle += 1) {
    const n = compte[cle];
    if (n !== 0) occupees.push({ cle, n, r: sommeR[cle] / n, v: sommeV[cle] / n, b: sommeB[cle] / n });
  }
  return occupees;
}

/* --------------------------------------------------- le découpage en boîtes */

/*
 * Pondéré comme l'œil voit : le vert porte l'essentiel de la lumière perçue,
 * le bleu presque rien. Une boîte qui s'étale sur le bleu gêne moins qu'une
 * boîte qui s'étale sur le vert, et elle passe donc après.
 */
const PR = 0.30;
const PV = 0.59;
const PB = 0.11;

/**
 * Ce que coûte une boîte, et par où la couper.
 *
 * Le coût est l'erreur qu'elle fera vraiment : la somme, sur tous ses points,
 * du carré de l'écart à la couleur moyenne qui les remplacera. C'est
 * exactement ce qu'on cherche à faire baisser, et c'est donc cela qu'on
 * mesure — plutôt que la largeur de la boîte, qui donnerait le même poids à
 * un recoin vide qu'au fond de page sur lequel s'étalent les trois quarts de
 * l'image.
 */
function mesurer(couleurs) {
  let n = 0;
  let sr = 0; let sv = 0; let sb = 0;
  let qr = 0; let qv = 0; let qb = 0;

  for (const c of couleurs) {
    n += c.n;
    sr += c.r * c.n; sv += c.v * c.n; sb += c.b * c.n;
    qr += c.r * c.r * c.n; qv += c.v * c.v * c.n; qb += c.b * c.b * c.n;
  }

  const er = PR * (qr - ((sr * sr) / n));
  const ev = PV * (qv - ((sv * sv) / n));
  const eb = PB * (qb - ((sb * sb) / n));
  const pire = Math.max(er, ev, eb);

  return {
    n,
    somme: [sr, sv, sb],
    moyenne: [Math.round(sr / n), Math.round(sv / n), Math.round(sb / n)],
    // Une boîte d'une seule couleur ne se coupe pas : son erreur est nulle,
    // et elle ne sera donc jamais choisie.
    erreur: couleurs.length < 2 ? 0 : er + ev + eb,
    axe: pire === er ? 'r' : (pire === ev ? 'v' : 'b'),
  };
}

/**
 * Où couper, et pourquoi pas à la médiane.
 *
 * La première version coupait au point qui laisse autant de points de chaque
 * côté. Sur une page d'interface, c'est une mauvaise idée : le fond occupe
 * les trois quarts de l'image en une seule couleur, la médiane tombe donc
 * toujours dessus, et le fond repart du même côté à chaque tour. La boîte se
 * vidait par le bord, quelques couleurs par passage — au bout de deux cent
 * cinquante-cinq coupes elle en contenait encore six mille sept cents, le
 * fond gris se rendait à neuf points de sa vraie valeur, et cela se voyait
 * sur toute la page.
 *
 * On coupe donc là où les deux moitiés s'écartent le plus l'une de l'autre.
 * Une couleur qui pèse à elle seule les trois quarts de la boîte en est
 * détachée du premier coup, et devient sa propre entrée de palette — exacte.
 */
function couper(couleurs, axe, total) {
  couleurs.sort((x, y) => x[axe] - y[axe]);

  const N = total.n;
  const S = total.somme[axe === 'r' ? 0 : (axe === 'v' ? 1 : 2)];
  let n = 0;
  let s = 0;
  let meilleur = -1;
  let coupe = 1;

  for (let i = 0; i < couleurs.length - 1; i += 1) {
    n += couleurs[i].n;
    s += couleurs[i][axe] * couleurs[i].n;
    const reste = N - n;
    if (reste <= 0) break;
    const score = ((s * s) / n) + (((S - s) * (S - s)) / reste);
    if (score > meilleur) { meilleur = score; coupe = i + 1; }
  }

  return coupe;
}

/**
 * Découper l'espace des couleurs en autant de boîtes que la palette en tient.
 *
 * À chaque tour on ouvre la boîte qui fait le plus d'erreur, et on la coupe
 * en deux. On s'arrête quand la palette est pleine, ou quand plus aucune
 * boîte ne contient deux couleurs différentes — une capture de moins de deux
 * cent cinquante-six couleurs sort alors exacte, au point près.
 */
function decouper(couleurs, combien) {
  const boites = [{ couleurs, stat: mesurer(couleurs) }];

  while (boites.length < combien) {
    let choisie = -1;
    let pire = 0;
    for (let i = 0; i < boites.length; i += 1) {
      if (boites[i].stat.erreur > pire) { pire = boites[i].stat.erreur; choisie = i; }
    }
    if (choisie === -1) break;

    const { couleurs: liste, stat } = boites[choisie];
    const k = couper(liste, stat.axe, stat);
    const gauche = liste.slice(0, k);
    const droite = liste.slice(k);

    boites.splice(choisie, 1,
      { couleurs: gauche, stat: mesurer(gauche) },
      { couleurs: droite, stat: mesurer(droite) });
  }

  return boites;
}

/* --------------------------------------------------------------- l'écriture */

function morceau(type, donnees) {
  const taille = Buffer.allocUnsafe(4);
  taille.writeUInt32BE(donnees.length, 0);
  const corps = Buffer.concat([Buffer.from(type, 'ascii'), donnees]);
  const controle = Buffer.allocUnsafe(4);
  controle.writeUInt32BE(crc32(corps), 0);
  return Buffer.concat([taille, corps, controle]);
}

/**
 * Réduire une capture à une palette, et rendre le PNG.
 *
 * Rend `null` quand l'image y perdrait — une capture de moins de deux cent
 * cinquante-six couleurs est déjà écrite au mieux par le navigateur, et la
 * réécrire ne gagnerait rien. L'appelant garde alors l'original.
 */
function palettiser(tampon, combien = 256) {
  const image = lire(tampon);
  const couleurs = histogramme(image);

  const boites = decouper(couleurs, combien);
  const palette = boites.map((boite) => boite.stat.moyenne);

  /*
   * De la case au numéro de couleur.
   *
   * On ne cherche le plus proche qu'une fois par case occupée — quelques
   * milliers — et non une fois par point, qui se compterait en millions. Le
   * reste du travail n'est plus qu'une lecture de table.
   */
  /*
   * De la case au numéro de couleur.
   *
   * On ne cherche le plus proche qu'une fois par case occupée — quelques
   * milliers — et non une fois par point, qui se compterait en millions. Le
   * reste du travail n'est plus qu'une lecture de table.
   *
   * La distance se mesure depuis la couleur vraie de la case, sa moyenne, et
   * non depuis le coin dont elle porte le nom. Mesurée depuis le coin, elle
   * se trompait de voisin au deuxième point près : le fond de la page de
   * connexion, (238, 241, 246), s'écrivait (236, 239, 244) alors que sa
   * propre entrée existait, juste.
   */
  const index = new Uint8Array(CASES);
  for (const boite of boites) {
    for (const c of boite.couleurs) {
      let meilleur = 0;
      let distance = Infinity;
      for (let i = 0; i < palette.length; i += 1) {
        const dr = c.r - palette[i][0];
        const dv = c.v - palette[i][1];
        const db = c.b - palette[i][2];
        const d = (dr * dr * PR) + (dv * dv * PV) + (db * db * PB);
        if (d < distance) { distance = d; meilleur = i; }
      }
      index[c.cle] = meilleur;
    }
  }

  const { largeur, hauteur, canaux, points } = image;
  const lignes = Buffer.allocUnsafe(hauteur * (largeur + 1));

  for (let y = 0; y < hauteur; y += 1) {
    const depart = y * (largeur + 1);
    lignes[depart] = 0; // pas de filtre : sur des numéros de couleur, prédire
                        // à partir du voisin donne du bruit, pas du gain.
    for (let x = 0; x < largeur; x += 1) {
      const p = ((y * largeur) + x) * canaux;
      let r = points[p]; let v = points[p + 1]; let b = points[p + 2];
      if (canaux === 4) {
        const a = points[p + 3];
        if (a !== 255) {
          r = (((r * a) + (255 * (255 - a))) / 255) | 0;
          v = (((v * a) + (255 * (255 - a))) / 255) | 0;
          b = (((b * a) + (255 * (255 - a))) / 255) | 0;
        }
      }
      lignes[depart + 1 + x] = index[((r >> DECALAGE) << (BITS * 2)) | ((v >> DECALAGE) << BITS) | (b >> DECALAGE)];
    }
  }

  const entete = Buffer.alloc(13);
  entete.writeUInt32BE(largeur, 0);
  entete.writeUInt32BE(hauteur, 4);
  entete[8] = 8;  // huit bits par numéro de couleur
  entete[9] = 3;  // image à palette
  entete[10] = 0; entete[11] = 0; entete[12] = 0;

  const table = Buffer.allocUnsafe(palette.length * 3);
  palette.forEach(([r, v, b], i) => { table[i * 3] = r; table[(i * 3) + 1] = v; table[(i * 3) + 2] = b; });

  return Buffer.concat([
    SIGNATURE,
    morceau('IHDR', entete),
    morceau('PLTE', table),
    morceau('IDAT', zlib.deflateSync(lignes, { level: 9 })),
    morceau('IEND', Buffer.alloc(0)),
  ]);
}

module.exports = { lire, palettiser };
