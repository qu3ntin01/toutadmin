<?php

declare(strict_types=1);

/**
 * Un serveur IMAP minimal, le temps d'un test.
 *
 * Il ne parle que ce dont le client a besoin — connexion, sélection d'un
 * dossier, recherche des non-lus, téléchargement, drapeau « lu », déplacement —
 * mais il le parle vraiment : sans lui, le client IMAP écrit à la main ne
 * serait éprouvé que contre un simulacre, c'est-à-dire pas éprouvé.
 *
 * Lancé par les tests avec, en argument, le fichier JSON des messages ; il
 * annonce son port sur la sortie standard, sert une connexion, puis écrit
 * l'état final des messages dans ce même fichier.
 */

$statePath = $argv[1] ?? '';
$state = json_decode((string) file_get_contents($statePath), true);
$messages = $state['messages'];
$user = $state['user'];
$password = $state['password'];

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, "serveur impossible : $error\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1) . "\n";
flush();

$client = @stream_socket_accept($server, 10);
if ($client === false) {
    exit(1);
}

$write = static function (string $line) use ($client): void {
    fwrite($client, $line . "\r\n");
};

$write('* OK [CAPABILITY IMAP4rev1 UIDPLUS MOVE] Serveur d\'essai prêt');

$authenticated = false;
$selected = null;

while (($line = fgets($client)) !== false) {
    $line = rtrim($line, "\r\n");
    $space = strpos($line, ' ');
    if ($space === false) {
        continue;
    }
    $tag = substr($line, 0, $space);
    $command = substr($line, $space + 1);
    $verb = strtoupper(explode(' ', $command)[0]);

    if ($verb === 'CAPABILITY') {
        $write('* CAPABILITY IMAP4rev1 UIDPLUS MOVE');
        $write("$tag OK CAPABILITY");
        continue;
    }
    if ($verb === 'LOGIN') {
        preg_match('/^LOGIN "(.*)" "(.*)"$/', $command, $m);
        $authenticated = ($m[1] ?? '') === $user && ($m[2] ?? '') === $password;
        $write($authenticated ? "$tag OK LOGIN" : "$tag NO identifiants refusés");
        continue;
    }
    if (!$authenticated) {
        $write("$tag NO connectez-vous d'abord");
        continue;
    }
    if ($verb === 'SELECT') {
        preg_match('/^SELECT "(.*)"$/', $command, $m);
        $selected = $m[1] ?? 'INBOX';
        $inbox = array_filter($messages, static fn (array $row): bool => $row['folder'] === $selected);
        $write('* ' . count($inbox) . ' EXISTS');
        $write("$tag OK [READ-WRITE] SELECT");
        continue;
    }
    if ($verb === 'UID') {
        $parts = explode(' ', $command);
        $sub = strtoupper($parts[1] ?? '');

        if ($sub === 'SEARCH') {
            $found = [];
            foreach ($messages as $row) {
                if ($row['folder'] === $selected && !$row['seen']) {
                    $found[] = $row['uid'];
                }
            }
            $write('* SEARCH' . ($found === [] ? '' : ' ' . implode(' ', $found)));
            $write("$tag OK SEARCH");
            continue;
        }
        if ($sub === 'FETCH') {
            $uid = (int) ($parts[2] ?? 0);
            foreach ($messages as $row) {
                if ($row['uid'] === $uid) {
                    $source = base64_decode($row['source'], true) ?: '';
                    $write('* 1 FETCH (UID ' . $uid . ' BODY[] {' . strlen($source) . '}');
                    fwrite($client, $source);
                    $write(')');
                }
            }
            $write("$tag OK FETCH");
            continue;
        }
        if ($sub === 'STORE') {
            $uid = (int) ($parts[2] ?? 0);
            foreach ($messages as $index => $row) {
                if ($row['uid'] === $uid && str_contains($command, '\\Seen')) {
                    $messages[$index]['seen'] = true;
                }
            }
            $write("$tag OK STORE");
            continue;
        }
        if ($sub === 'MOVE') {
            $uid = (int) ($parts[2] ?? 0);
            preg_match('/"(.*)"$/', $command, $m);
            foreach ($messages as $index => $row) {
                if ($row['uid'] === $uid) {
                    $messages[$index]['folder'] = $m[1] ?? 'INBOX';
                }
            }
            $write("$tag OK MOVE");
            continue;
        }
        $write("$tag BAD commande inconnue");
        continue;
    }
    if ($verb === 'LOGOUT') {
        $write('* BYE');
        $write("$tag OK LOGOUT");
        break;
    }
    $write("$tag BAD commande inconnue");
}

$state['messages'] = $messages;
file_put_contents($statePath, json_encode($state));
fclose($client);
fclose($server);
