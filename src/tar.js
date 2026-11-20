const fs = require('fs');
const path = require('path');

/**
 * Écriture et lecture d'archives tar.
 *
 * Écrit à la main plutôt qu'emprunté : le format tient en un en-tête de 512
 * octets par fichier, et une sauvegarde ne doit dépendre de rien qu'on ne
 * puisse relire soi-même dans dix ans. Seul le strict nécessaire est géré —
 * fichiers ordinaires, chemins relatifs courts —, et tout le reste est refusé
 * explicitement plutôt que deviné.
 */

const BLOCK = 512;
// Le champ « name » d'un en-tête ustar fait 100 octets. Au-delà, il faudrait le
// champ « prefix » : on préfère refuser, nos chemins étant courts par construction.
const MAX_NAME = 100;

function octal(value, length) {
  // Un champ numérique tar est de l'octal ASCII, terminé par un espace et un nul.
  return Buffer.from(value.toString(8).padStart(length - 2, '0') + ' \0', 'ascii');
}

function header({ name, size, mode = 0o600, mtime = Math.floor(Date.now() / 1000), type = '0' }) {
  if (Buffer.byteLength(name) > MAX_NAME) throw new Error(`Chemin trop long pour une archive tar : ${name}`);

  const block = Buffer.alloc(BLOCK, 0);
  block.write(name, 0, 100, 'utf8');
  octal(mode, 8).copy(block, 100);
  octal(0, 8).copy(block, 108);          // uid
  octal(0, 8).copy(block, 116);          // gid
  octal(size, 12).copy(block, 124);
  octal(mtime, 12).copy(block, 136);
  block.write('        ', 148, 8, 'ascii'); // somme de contrôle : espaces pendant le calcul
  block.write(type, 156, 1, 'ascii');
  block.write('ustar\0', 257, 6, 'ascii');
  block.write('00', 263, 2, 'ascii');

  let sum = 0;
  for (const byte of block) sum += byte;
  block.write(sum.toString(8).padStart(6, '0') + '\0 ', 148, 8, 'ascii');
  return block;
}

function padding(size) {
  const remainder = size % BLOCK;
  return remainder === 0 ? Buffer.alloc(0) : Buffer.alloc(BLOCK - remainder, 0);
}

/**
 * Assemble une archive à partir d'entrées { name, source } — source étant un
 * chemin sur disque ou un Buffer.
 */
function pack(entries) {
  const parts = [];
  for (const entry of entries) {
    const data = Buffer.isBuffer(entry.source) ? entry.source : fs.readFileSync(entry.source);
    const mtime = Buffer.isBuffer(entry.source)
      ? Math.floor(Date.now() / 1000)
      : Math.floor(fs.statSync(entry.source).mtimeMs / 1000);

    parts.push(header({ name: entry.name, size: data.length, mtime }));
    parts.push(data);
    parts.push(padding(data.length));
  }
  // Deux blocs nuls closent l'archive.
  parts.push(Buffer.alloc(BLOCK * 2, 0));
  return Buffer.concat(parts);
}

function readOctal(buffer) {
  const text = buffer.toString('ascii').replace(/\0.*$/, '').trim();
  return text ? parseInt(text, 8) : 0;
}

function isZeroBlock(block) {
  for (const byte of block) if (byte !== 0) return false;
  return true;
}

/** Rend la liste des entrées { name, size, data } d'une archive. */
function unpack(buffer) {
  const entries = [];
  let offset = 0;
  // Une archive complète se termine par deux blocs nuls. Sans eux, elle a été
  // coupée : mieux vaut le dire que rendre une liste incomplète en silence.
  let terminated = false;

  while (offset + BLOCK <= buffer.length) {
    const block = buffer.subarray(offset, offset + BLOCK);
    if (isZeroBlock(block)) {
      terminated = true;
      break;
    }

    const magic = block.subarray(257, 262).toString('ascii');
    if (magic !== 'ustar') throw new Error('Archive illisible : en-tête tar absent.');

    // La somme de contrôle protège d'une archive tronquée ou corrompue.
    const declared = readOctal(block.subarray(148, 156));
    const check = Buffer.from(block);
    check.write('        ', 148, 8, 'ascii');
    let sum = 0;
    for (const byte of check) sum += byte;
    if (sum !== declared) throw new Error('Archive corrompue : somme de contrôle incorrecte.');

    const name = block.subarray(0, 100).toString('utf8').replace(/\0.*$/, '');
    const size = readOctal(block.subarray(124, 136));
    const type = block.subarray(156, 157).toString('ascii');

    offset += BLOCK;
    if (offset + size > buffer.length) throw new Error('Archive tronquée.');

    // Seuls les fichiers ordinaires nous intéressent ; le reste est ignoré.
    if (type === '0' || type === '\0') {
      entries.push({ name, size, data: buffer.subarray(offset, offset + size) });
    }
    offset += size + padding(size).length;
  }

  if (!terminated) throw new Error('Archive tronquée : les blocs de fin manquent.');
  return entries;
}

/**
 * Un nom d'entrée sûr : relatif, sans remontée, sous l'une des racines
 * attendues. Une archive est une donnée d'entrée comme une autre — même
 * produite par nous, elle peut avoir été remplacée.
 */
function safeName(name, allowedRoots) {
  if (typeof name !== 'string' || name.length === 0) return null;
  if (name.startsWith('/') || /^[a-zA-Z]:/.test(name)) return null;
  if (name.includes('\0') || name.includes('\\')) return null;

  const segments = name.split('/');
  if (segments.some((segment) => segment === '' || segment === '.' || segment === '..')) return null;
  if (!allowedRoots.includes(segments[0])) return null;

  return path.join(...segments);
}

module.exports = { BLOCK, MAX_NAME, pack, unpack, safeName };
