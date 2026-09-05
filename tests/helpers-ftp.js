const net = require('net');

/**
 * Un serveur FTP minimal, en mémoire, pour éprouver réellement le client.
 *
 * Il ne parle que ce dont le client a besoin — connexion, mode passif, dépôt,
 * liste, suppression — mais il le parle pour de vrai : sans lui, le client FTP
 * ne serait vérifié que par des simulacres, c'est-à-dire pas vérifié.
 */
function startFtpServer({ user = 'sauvegarde', password = 'motdepasse' } = {}) {
  const files = new Map();
  const directories = new Set(['/']);

  // Les sockets encore ouvertes empêcheraient server.close() d'aboutir : on les
  // garde sous la main pour pouvoir vraiment rendre la main à la fin d'un test.
  const sockets = new Set();

  const server = net.createServer((socket) => {
    sockets.add(socket);
    socket.on('close', () => sockets.delete(socket));
    let authenticated = false;
    let pendingUser = null;
    let dataServer = null;
    // Le client se connecte au port de données dès qu'il a reçu le « 227 »,
    // souvent avant même d'envoyer STOR : la connexion doit être attendue
    // depuis PASV, sinon l'événement est perdu.
    let dataConnection = null;
    let pendingCommand = null;

    const send = (line) => socket.write(`${line}\r\n`);

    /** Ouvre un port de données et annonce son adresse au client. */
    const openPassive = () => new Promise((resolve) => {
      if (dataServer) dataServer.close();
      dataServer = net.createServer();
      dataConnection = new Promise((connected) => dataServer.once('connection', connected));
      dataServer.listen(0, '127.0.0.1', () => {
        const { port } = dataServer.address();
        send(`227 Entering Passive Mode (127,0,0,1,${Math.floor(port / 256)},${port % 256})`);
        resolve();
      });
    });

    const withDataConnection = async (handler) => {
      if (!dataConnection) return send('425 Aucune connexion de données');
      const data = await dataConnection;
      const close = () => {
        if (dataServer) dataServer.close();
        dataServer = null;
        dataConnection = null;
      };
      handler(data, close);
    };

    send('220 Serveur d\'essai prêt');

    let buffer = '';
    socket.on('data', async (chunk) => {
      buffer += chunk.toString('utf8');
      let index;
      while ((index = buffer.indexOf('\r\n')) !== -1) {
        const line = buffer.slice(0, index);
        buffer = buffer.slice(index + 2);

        const [command, ...rest] = line.split(' ');
        const argument = rest.join(' ');

        switch (command.toUpperCase()) {
          case 'USER':
            pendingUser = argument;
            send('331 Mot de passe attendu');
            break;
          case 'PASS':
            authenticated = pendingUser === user && argument === password;
            send(authenticated ? '230 Connecté' : '530 Identifiants refusés');
            break;
          case 'FEAT':
            socket.write('211-Fonctions\r\n UTF8\r\n211 Fin\r\n');
            break;
          case 'OPTS':
          case 'TYPE':
          case 'NOOP':
            send('200 OK');
            break;
          case 'PWD':
            send('257 "/" répertoire courant');
            break;
          case 'CWD':
            send(directories.has(argument) ? '250 OK' : '550 Dossier introuvable');
            break;
          case 'MKD':
            directories.add(argument);
            send(`257 "${argument}" créé`);
            break;
          case 'PASV':
            await openPassive();
            break;
          case 'STOR':
            pendingCommand = argument;
            send('150 Ouverture de la connexion de données');
            await withDataConnection((data, done) => {
              const parts = [];
              data.on('data', (part) => parts.push(part));
              data.on('end', () => {
                files.set(pendingCommand, Buffer.concat(parts));
                done();
                send('226 Transfert terminé');
              });
            });
            break;
          case 'LIST':
            send('150 Liste');
            await withDataConnection((data, done) => {
              const lines = [...files.entries()]
                .map(([name, content]) => `-rw-r--r-- 1 owner group ${content.length} Sep 05 12:00 ${name}`)
                .join('\r\n');
              data.end(lines ? `${lines}\r\n` : '');
              done();
              send('226 Liste terminée');
            });
            break;
          case 'DELE':
            send(files.delete(argument) ? '250 Supprimé' : '550 Fichier introuvable');
            break;
          case 'QUIT':
            send('221 Au revoir');
            socket.end();
            break;
          default:
            send('502 Commande non gérée');
        }
      }
    });

    socket.on('error', () => {});
  });

  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      resolve({
        port: server.address().port,
        files,
        directories,
        close: () => new Promise((done) => {
          for (const socket of sockets) socket.destroy();
          server.close(done);
        }),
      });
    });
  });
}

module.exports = { startFtpServer };
