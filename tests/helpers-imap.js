const net = require('net');

/**
 * Un serveur IMAP minimal, en mémoire, pour éprouver la capture pour de vrai.
 *
 * Il ne parle que ce dont le client a besoin — connexion, sélection d'un
 * dossier, recherche des non-lus, téléchargement, drapeau « lu », déplacement —
 * mais il le parle vraiment : sans lui, la relève ne serait vérifiée que contre
 * un simulacre, c'est-à-dire pas vérifiée.
 */
/**
 * Les noms de dossiers voyagent en UTF-7 modifié (RFC 3501) : « Traitées »
 * s'écrit « Trait&AOk-es » sur le fil. On le redécode pour que les tests
 * s'écrivent avec le vrai nom.
 */
function decodeMailboxName(value) {
  return String(value || '').replace(/&([^-]*)-/g, (match, encoded) => {
    if (!encoded) return '&';
    const base64 = encoded.replace(/,/g, '/');
    const bytes = Buffer.from(base64 + '='.repeat((4 - (base64.length % 4)) % 4), 'base64');
    let out = '';
    for (let i = 0; i + 1 < bytes.length; i += 2) out += String.fromCharCode(bytes.readUInt16BE(i));
    return out;
  });
}

function startImapServer({ user = 'factures', password = 'motdepasse', messages = [] } = {}) {
  // Chaque message : { uid, source (Buffer|string), seen, folder }
  const mailbox = messages.map((message, index) => ({
    uid: message.uid || index + 1,
    source: Buffer.isBuffer(message.source) ? message.source : Buffer.from(String(message.source), 'utf8'),
    seen: Boolean(message.seen),
    folder: 'INBOX',
  }));

  const sockets = new Set();
  const moved = [];

  const server = net.createServer((socket) => {
    sockets.add(socket);
    socket.on('close', () => sockets.delete(socket));
    socket.on('error', () => {});

    let authenticated = false;
    let selected = null;
    let buffer = '';

    const send = (line) => socket.write(`${line}\r\n`);
    const inbox = () => mailbox.filter((m) => m.folder === 'INBOX');

    send('* OK [CAPABILITY IMAP4rev1 UIDPLUS MOVE] Serveur d\'essai prêt');

    socket.on('data', (chunk) => {
      buffer += chunk.toString('utf8');
      let index;
      while ((index = buffer.indexOf('\r\n')) !== -1) {
        const line = buffer.slice(0, index);
        buffer = buffer.slice(index + 2);
        handle(line);
      }
    });

    function handle(line) {
      const [tag, ...rest] = line.split(' ');
      const command = (rest[0] || '').toUpperCase();
      const args = rest.slice(1);

      const unquote = (value) => String(value || '').replace(/^"|"$/g, '');

      if (command === 'CAPABILITY') {
        send('* CAPABILITY IMAP4rev1 UIDPLUS MOVE');
        return send(`${tag} OK CAPABILITY`);
      }

      if (command === 'LOGIN') {
        authenticated = unquote(args[0]) === user && unquote(args[1]) === password;
        return send(authenticated ? `${tag} OK connecté` : `${tag} NO identifiants refusés`);
      }

      if (!authenticated) return send(`${tag} NO authentification requise`);

      if (command === 'LIST' || command === 'LSUB') {
        // « LIST "" "" » interroge la racine : la réponse ne donne que le
        // séparateur. Répondre autre chose ferait prendre un dossier pour un
        // préfixe d'espace de noms, et tous les chemins seraient doublés.
        if (unquote(args[0]) === '' && unquote(args[1]) === '') {
          send('* LIST (\\Noselect) "/" ""');
          return send(`${tag} OK LIST`);
        }
        send('* LIST (\\HasNoChildren) "/" "INBOX"');
        send('* LIST (\\HasNoChildren) "/" "Trait&AOk-es"');
        return send(`${tag} OK LIST`);
      }

      if (command === 'SELECT' || command === 'EXAMINE') {
        selected = unquote(args[0]);
        const rows = inbox();
        send(`* ${rows.length} EXISTS`);
        send('* 0 RECENT');
        send('* FLAGS (\\Seen \\Answered \\Flagged \\Deleted \\Draft)');
        send('* OK [PERMANENTFLAGS (\\Seen \\Answered \\Flagged \\Deleted \\Draft \\*)] autorisés');
        send('* OK [UIDVALIDITY 1] validité');
        send(`* OK [UIDNEXT ${mailbox.length + 1}] suivant`);
        return send(`${tag} OK [READ-WRITE] ${command}`);
      }

      if (command === 'UID') {
        const sub = (args[0] || '').toUpperCase();
        const params = args.slice(1);

        if (sub === 'SEARCH') {
          // On ne gère que ce que le client demande : les non-lus, depuis une date.
          const wantsUnseen = params.map((p) => p.toUpperCase()).includes('UNSEEN');
          const found = inbox().filter((m) => (wantsUnseen ? !m.seen : true)).map((m) => m.uid);
          send(`* SEARCH ${found.join(' ')}`.trim());
          return send(`${tag} OK SEARCH`);
        }

        if (sub === 'FETCH') {
          const uid = Number(params[0]);
          const message = mailbox.find((m) => m.uid === uid);
          if (!message) return send(`${tag} OK FETCH`);

          const sequence = inbox().indexOf(message) + 1;
          socket.write(`* ${sequence} FETCH (UID ${uid} BODY[] {${message.source.length}}\r\n`);
          socket.write(message.source);
          socket.write(')\r\n');
          return send(`${tag} OK FETCH`);
        }

        if (sub === 'STORE') {
          const uid = Number(params[0]);
          const message = mailbox.find((m) => m.uid === uid);
          if (message && params.join(' ').toUpperCase().includes('\\SEEN')) message.seen = true;
          if (message) {
            const sequence = inbox().indexOf(message) + 1;
            send(`* ${sequence} FETCH (UID ${uid} FLAGS (${message.seen ? '\\Seen' : ''}))`);
          }
          return send(`${tag} OK STORE`);
        }

        if (sub === 'MOVE') {
          const uid = Number(params[0]);
          const message = mailbox.find((m) => m.uid === uid);
          if (message) {
            message.folder = decodeMailboxName(unquote(params.slice(1).join(' ')));
            moved.push({ uid, folder: message.folder });
          }
          return send(`${tag} OK MOVE`);
        }

        return send(`${tag} BAD sous-commande UID non gérée`);
      }

      if (command === 'NOOP' || command === 'CHECK' || command === 'CLOSE' || command === 'UNSELECT') {
        return send(`${tag} OK ${command}`);
      }

      if (command === 'LOGOUT') {
        send('* BYE au revoir');
        send(`${tag} OK LOGOUT`);
        return socket.end();
      }

      return send(`${tag} BAD commande non gérée : ${command}`);
    }
  });

  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      resolve({
        port: server.address().port,
        mailbox,
        moved,
        close: () => new Promise((done) => {
          for (const socket of sockets) socket.destroy();
          server.close(done);
        }),
      });
    });
  });
}

module.exports = { startImapServer, decodeMailboxName };
