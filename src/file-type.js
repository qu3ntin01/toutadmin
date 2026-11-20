/**
 * Contrôle du type réel d'un fichier reçu.
 *
 * Le type MIME d'un envoi multipart est déclaré par le client : il suffit de le
 * changer pour faire passer n'importe quoi pour un PDF. On regarde donc les
 * premiers octets, qui, eux, viennent du fichier.
 */

const SIGNATURES = {
  'image/jpeg': (buf) => buf.length > 3 && buf[0] === 0xff && buf[1] === 0xd8 && buf[2] === 0xff,
  'image/png': (buf) => buf.length > 8 && buf.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])),
  // WebP : « RIFF » .... « WEBP »
  'image/webp': (buf) => buf.length > 12 && buf.subarray(0, 4).toString('latin1') === 'RIFF' && buf.subarray(8, 12).toString('latin1') === 'WEBP',
  'application/pdf': (buf) => buf.subarray(0, 5).toString('latin1') === '%PDF-',
  // Un .docx est une archive ZIP : « PK\x03\x04 ».
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': (buf) =>
    buf.length > 4 && buf[0] === 0x50 && buf[1] === 0x4b && (buf[2] === 0x03 || buf[2] === 0x05 || buf[2] === 0x07),
};

// Ce qui n'a pas de signature — du texte — est validé autrement : on refuse
// simplement ce qui contient des octets nuls ou des séquences de contrôle, signe
// qu'on nous a passé un binaire déguisé en .txt.
const TEXT_TYPES = new Set(['text/plain', 'text/markdown', 'text/csv']);

function looksLikeText(buffer) {
  const sample = buffer.subarray(0, 4096);
  if (sample.includes(0)) return false;

  let suspicious = 0;
  for (const byte of sample) {
    const printable = byte === 9 || byte === 10 || byte === 13 || (byte >= 32 && byte !== 127);
    if (!printable) suspicious += 1;
  }
  // Un texte encodé en UTF-8 n'a aucune raison d'être truffé d'octets de contrôle.
  return suspicious / Math.max(1, sample.length) < 0.02;
}

/** Le contenu correspond-il vraiment au type annoncé ? */
function matches(buffer, mimetype) {
  if (!Buffer.isBuffer(buffer) || buffer.length === 0) return false;
  if (TEXT_TYPES.has(mimetype)) return looksLikeText(buffer);
  const check = SIGNATURES[mimetype];
  return check ? check(buffer) : false;
}

module.exports = { matches, looksLikeText, SIGNATURES };
