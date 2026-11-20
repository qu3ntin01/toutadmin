/**
 * Lecture de fichiers CSV.
 *
 * Écrit à la main plutôt qu'emprunté : un import de masse est une porte
 * d'entrée dans la base, et la dépendance qui lit le fichier est aussi
 * sensible que celle qui l'écrit. Le format est simple, les pièges connus.
 *
 * Ce qui est géré parce qu'on le rencontre vraiment :
 * — le point-virgule, séparateur des tableurs francophones, et la tabulation ;
 * — les guillemets, les virgules et les retours à la ligne à l'intérieur d'un
 *   champ, avec le doublement de guillemet comme échappement ;
 * — la marque d'ordre des octets qu'Excel ajoute en tête, invisible et qui
 *   ferait d'« id » une colonne nommée « ﻿id » ;
 * — les fins de ligne Windows.
 */

const DELIMITERS = [';', ',', '\t', '|'];
const MAX_ROWS = 5000;

/** Le séparateur le plus présent dans la première ligne — pas de devinette au-delà. */
function detectDelimiter(text) {
  const firstLine = text.split(/\r?\n/, 1)[0] || '';
  let best = ';';
  let bestCount = 0;
  for (const delimiter of DELIMITERS) {
    // Les séparateurs entre guillemets ne comptent pas.
    let count = 0;
    let quoted = false;
    for (let i = 0; i < firstLine.length; i += 1) {
      if (firstLine[i] === '"') quoted = !quoted;
      else if (!quoted && firstLine[i] === delimiter) count += 1;
    }
    if (count > bestCount) {
      best = delimiter;
      bestCount = count;
    }
  }
  return best;
}

/** Découpe le texte en lignes de champs. Rend [] pour un texte vide. */
function split(text, delimiter) {
  const rows = [];
  let field = '';
  let row = [];
  let quoted = false;

  for (let i = 0; i < text.length; i += 1) {
    const char = text[i];

    if (quoted) {
      if (char === '"') {
        if (text[i + 1] === '"') { field += '"'; i += 1; }
        else quoted = false;
      } else field += char;
      continue;
    }

    if (char === '"') quoted = true;
    else if (char === delimiter) { row.push(field); field = ''; }
    else if (char === '\n') { row.push(field); rows.push(row); row = []; field = ''; }
    else if (char !== '\r') field += char;
  }

  if (field !== '' || row.length) {
    row.push(field);
    rows.push(row);
  }
  return rows;
}

const normalizeHeader = (value) => String(value || '')
  .replace(/^﻿/, '')
  .trim()
  .toLowerCase()
  .normalize('NFD')
  .replace(/[̀-ͯ]/g, '')
  .replace(/[^a-z0-9]+/g, '_')
  .replace(/^_+|_+$/g, '');

/**
 * @returns {{ok: boolean, message?: string, headers: string[], rows: object[]}}
 * Les lignes sont rendues comme objets dont les clés sont les en-têtes
 * normalisés : « Prénom », « prenom » et « PRENOM » désignent la même colonne,
 * parce que personne ne recopie un modèle au caractère près.
 */
function parse(text, { delimiter = null, maxRows = MAX_ROWS } = {}) {
  const content = String(text || '').replace(/^﻿/, '').trim();
  if (!content) return { ok: false, message: 'Fichier vide.', headers: [], rows: [] };

  const separator = delimiter || detectDelimiter(content);
  const lines = split(content, separator).filter((row) => row.some((cell) => cell.trim() !== ''));
  if (!lines.length) return { ok: false, message: 'Fichier vide.', headers: [], rows: [] };

  const headers = lines[0].map(normalizeHeader);
  if (headers.some((h) => !h)) return { ok: false, message: 'Une colonne est sans nom dans la première ligne.', headers: [], rows: [] };
  if (new Set(headers).size !== headers.length) {
    return { ok: false, message: 'Deux colonnes portent le même nom.', headers: [], rows: [] };
  }

  const body = lines.slice(1);
  if (body.length > maxRows) {
    return { ok: false, message: `${body.length} lignes : au-delà de ${maxRows}, découpez le fichier.`, headers, rows: [] };
  }

  const rows = body.map((cells, index) => {
    const row = { __line: index + 2 };
    headers.forEach((header, column) => {
      row[header] = (cells[column] === undefined ? '' : String(cells[column])).trim();
    });
    return row;
  });

  return { ok: true, delimiter: separator, headers, rows };
}

module.exports = { DELIMITERS, MAX_ROWS, detectDelimiter, normalizeHeader, parse };
