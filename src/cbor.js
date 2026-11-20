/**
 * Un lecteur CBOR (RFC 8949) réduit à ce que WebAuthn transporte : entiers,
 * chaînes d'octets et de texte, tableaux, tables, booléens et nul. Pas de
 * flottant, pas de longueur indéfinie : un authentificateur n'en envoie pas,
 * et un message qui en contient est refusé plutôt que deviné.
 *
 * Il rend aussi la position où la lecture s'est arrêtée : les données
 * d'authentification mettent la clé publique au milieu d'un flux d'octets, et
 * il faut savoir où elle finit.
 */

const LIMITE_PROFONDEUR = 16;

function lire(buffer, debut = 0) {
  const b = Buffer.isBuffer(buffer) ? buffer : Buffer.from(buffer);
  let pos = debut;

  const octet = () => {
    if (pos >= b.length) throw new Error('cbor: fin inattendue');
    return b[pos++];
  };
  const longueur = (info) => {
    if (info < 24) return info;
    if (info === 24) return octet();
    if (info === 25) { const v = b.readUInt16BE(pos); pos += 2; return v; }
    if (info === 26) { const v = b.readUInt32BE(pos); pos += 4; return v; }
    if (info === 27) {
      const v = b.readBigUInt64BE(pos); pos += 8;
      if (v > BigInt(Number.MAX_SAFE_INTEGER)) throw new Error('cbor: entier trop grand');
      return Number(v);
    }
    throw new Error('cbor: longueur indéfinie refusée');
  };
  const tranche = (n) => {
    if (pos + n > b.length) throw new Error('cbor: fin inattendue');
    const t = b.subarray(pos, pos + n);
    pos += n;
    return t;
  };

  const valeur = (profondeur) => {
    if (profondeur > LIMITE_PROFONDEUR) throw new Error('cbor: trop imbriqué');
    const initial = octet();
    const majeur = initial >> 5;
    const info = initial & 0x1f;
    switch (majeur) {
      case 0: return longueur(info);
      case 1: return -1 - longueur(info);
      case 2: return Buffer.from(tranche(longueur(info)));
      case 3: return tranche(longueur(info)).toString('utf8');
      case 4: {
        const n = longueur(info);
        const t = [];
        for (let i = 0; i < n; i += 1) t.push(valeur(profondeur + 1));
        return t;
      }
      case 5: {
        const n = longueur(info);
        const m = new Map();
        for (let i = 0; i < n; i += 1) {
          const cle = valeur(profondeur + 1);
          m.set(cle, valeur(profondeur + 1));
        }
        return m;
      }
      case 6: longueur(info); return valeur(profondeur + 1); // une étiquette : on lit ce qu'elle étiquette
      case 7:
        if (info === 20) return false;
        if (info === 21) return true;
        if (info === 22) return null;
        throw new Error('cbor: valeur simple non prise en charge');
      default: throw new Error('cbor: type inconnu');
    }
  };

  const v = valeur(0);
  return { valeur: v, fin: pos };
}

module.exports = { lire };
