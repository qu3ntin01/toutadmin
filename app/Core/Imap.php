<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Client IMAP, écrit à la main.
 *
 * L'édition Node s'appuie sur une bibliothèque ; l'édition PHP ne dépend de
 * rien, pas même de l'extension `imap` — qui est absente de beaucoup
 * d'hébergements mutualisés et dépréciée depuis PHP 8.3. Le protocole tient
 * en une poignée de commandes, et c'est tout ce dont la relève a besoin :
 * ouvrir un dossier, chercher les messages non lus, en télécharger le contenu,
 * poser un drapeau, éventuellement déplacer.
 *
 * Ce client ne supprime jamais rien : il n'expose ni EXPUNGE ni le drapeau
 * `\Deleted`. La boîte reste la source ; le CMS n'en est qu'un lecteur.
 */
final class Imap
{
    private const CRLF = "\r\n";

    /** @var resource|null */
    private $socket = null;
    private int $counter = 0;
    private array $capabilities = [];

    public function __construct(
        private readonly string $host,
        private readonly int $port = 993,
        private readonly bool $secure = true,
        private readonly bool $allowSelfSigned = false,
        private readonly int $timeout = 30,
    ) {
    }

    // ---------- Connexion ----------

    public function connect(): void
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => !$this->allowSelfSigned,
            'verify_peer_name' => !$this->allowSelfSigned,
            'SNI_enabled' => true,
        ]]);

        $scheme = $this->secure ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errno,
            $error,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            throw new \RuntimeException($error !== '' ? $error : "connexion impossible ($errno)");
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, $this->timeout);

        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK')) {
            throw new \RuntimeException('accueil inattendu du serveur : ' . mb_substr($greeting, 0, 120));
        }

        // Une connexion en clair se relève par STARTTLS si le serveur le propose :
        // un mot de passe ne traverse pas le réseau en clair.
        if (!$this->secure) {
            $this->capabilities = $this->capabilities();
            if (in_array('STARTTLS', $this->capabilities, true)) {
                $this->command('STARTTLS');
                $crypto = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($crypto !== true) {
                    throw new \RuntimeException('passage en TLS refusé.');
                }
            }
        }
    }

    public function capabilities(): array
    {
        $response = $this->command('CAPABILITY');
        $found = [];
        foreach ($response['lines'] as $line) {
            if (str_starts_with($line, '* CAPABILITY')) {
                $found = array_merge($found, array_slice(explode(' ', trim($line)), 2));
            }
        }
        return array_map('strtoupper', $found);
    }

    public function login(string $user, string $password): void
    {
        $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($password));
        $this->capabilities = $this->capabilities();
    }

    public function logout(): void
    {
        if ($this->socket === null) {
            return;
        }
        try {
            $this->command('LOGOUT');
        } catch (\Throwable) {
            // La connexion est déjà perdue : il n'y a rien à sauver.
        }
        @fclose($this->socket);
        $this->socket = null;
    }

    // ---------- Commandes ----------

    public function select(string $folder): array
    {
        $response = $this->command('SELECT ' . self::quote($folder));
        $exists = 0;
        foreach ($response['lines'] as $line) {
            if (preg_match('/^\* (\d+) EXISTS/', $line, $m)) {
                $exists = (int) $m[1];
            }
        }
        return ['exists' => $exists];
    }

    /**
     * Les UID des messages non lus depuis une date. Un UID survit à la
     * renumérotation du dossier, pas un numéro de séquence : c'est lui qu'on
     * garde en base.
     */
    public function searchUnseenSince(\DateTimeImmutable $since): array
    {
        $response = $this->command('UID SEARCH UNSEEN SINCE ' . $since->format('j-M-Y'));
        $uids = [];
        foreach ($response['lines'] as $line) {
            if (preg_match('/^\* SEARCH(.*)$/', trim($line), $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $token) {
                    if ($token !== '' && ctype_digit($token)) {
                        $uids[] = (int) $token;
                    }
                }
            }
        }
        return $uids;
    }

    /** Le message entier, sans le marquer lu : BODY.PEEK ne pose aucun drapeau. */
    public function fetchMessage(int $uid): string
    {
        $response = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[])');
        return $response['literals'] === [] ? '' : (string) $response['literals'][0];
    }

    public function markSeen(int $uid): void
    {
        $this->command('UID STORE ' . $uid . ' +FLAGS (\\Seen)');
    }

    /**
     * Déplace un message. MOVE (RFC 6851) est la commande propre ; les serveurs
     * qui ne l'ont pas reçoivent une copie suivie du drapeau `\Deleted` et d'un
     * EXPUNGE borné à cet UID — jamais un EXPUNGE global, qui emporterait les
     * messages que quelqu'un d'autre vient de supprimer.
     */
    public function move(int $uid, string $folder): void
    {
        if (in_array('MOVE', $this->capabilities, true)) {
            $this->command('UID MOVE ' . $uid . ' ' . self::quote($folder));
            return;
        }
        $this->command('UID COPY ' . $uid . ' ' . self::quote($folder));
        $this->command('UID STORE ' . $uid . ' +FLAGS (\\Deleted)');
        if (in_array('UIDPLUS', $this->capabilities, true)) {
            $this->command('UID EXPUNGE ' . $uid);
        }
    }

    // ---------- Protocole ----------

    public static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * Envoie une commande étiquetée et lit jusqu'à sa réponse finale. Les
     * littéraux ({1234}) sont lus en octets, pas en lignes : un courriel en
     * contient, et une lecture ligne à ligne s'y perdrait.
     */
    public function command(string $command): array
    {
        if ($this->socket === null) {
            throw new \RuntimeException('connexion fermée.');
        }

        $tag = 'a' . (++$this->counter);
        $this->write($tag . ' ' . $command . self::CRLF);

        $lines = [];
        $literals = [];
        while (true) {
            $line = $this->readLine();
            if ($line === '') {
                throw new \RuntimeException('connexion interrompue.');
            }

            // Un littéral annonce sa longueur en fin de ligne : on lit ces octets
            // puis on reprend la lecture des lignes.
            if (preg_match('/\{(\d+)\}$/', rtrim($line, "\r\n"), $m)) {
                $literals[] = $this->readBytes((int) $m[1]);
                $lines[] = $line;
                continue;
            }

            $lines[] = $line;
            if (str_starts_with($line, $tag . ' ')) {
                $verdict = strtoupper(explode(' ', trim($line))[1] ?? '');
                if ($verdict !== 'OK') {
                    throw new \RuntimeException(trim(mb_substr($line, strlen($tag) + 1)));
                }
                return ['lines' => $lines, 'literals' => $literals];
            }
        }
    }

    private function write(string $payload): void
    {
        if (@fwrite($this->socket, $payload) === false) {
            throw new \RuntimeException('écriture impossible.');
        }
    }

    private function readLine(): string
    {
        $line = fgets($this->socket);
        if ($line === false) {
            $info = stream_get_meta_data($this->socket);
            throw new \RuntimeException(!empty($info['timed_out']) ? 'délai dépassé.' : 'connexion interrompue.');
        }
        return $line;
    }

    private function readBytes(int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($this->socket, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('lecture interrompue.');
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }
}
