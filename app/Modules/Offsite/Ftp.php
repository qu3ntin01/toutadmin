<?php

declare(strict_types=1);

namespace App\Modules\Offsite;

/**
 * Destination FTP.
 *
 * Le format d'archive est écrit à la main — une sauvegarde doit rester lisible
 * sans outil. Le transport, lui, n'a pas cette contrainte : ce n'est pas un
 * format à relire dans dix ans, mais un protocole à parler correctement, TLS
 * compris. cURL, présent partout où PHP l'est, le parle mieux qu'une
 * réimplémentation — et il sait le FTPS explicite comme l'implicite, ce que
 * l'extension ftp de PHP ne fait pas.
 */
final class Ftp
{
    public const MODES = [
        ['key' => 'ftps', 'label' => 'FTPS explicite (recommandé)'],
        ['key' => 'ftps-implicite', 'label' => 'FTPS implicite (port 990)'],
        ['key' => 'ftp', 'label' => 'FTP simple — sans chiffrement'],
    ];

    public const DEFAULT_TIMEOUT = 30;

    public static function modeOf(?string $key): array
    {
        foreach (self::MODES as $mode) {
            if ($mode['key'] === $key) {
                return $mode;
            }
        }
        return self::MODES[0];
    }

    public static function normalizeDirectory(?string $value): string
    {
        $trimmed = str_replace('\\', '/', trim((string) $value));
        if ($trimmed === '') {
            return '';
        }
        // Un chemin distant reste relatif au dossier d'accueil sauf s'il
        // commence par une barre : on n'invente rien, on nettoie seulement les
        // répétitions.
        return rtrim((string) preg_replace('#/{2,}#', '/', $trimmed), '/');
    }

    /**
     * Le chemin distant d'un fichier. Une barre de tête est conservée : un
     * dossier annoncé absolu doit le rester, sinon le dépôt atterrit ailleurs
     * que là où l'administrateur l'a demandé.
     */
    public static function remotePath(array $config, string $fileName = ''): string
    {
        $directory = self::normalizeDirectory($config['directory'] ?? '');
        if ($directory === '') {
            return $fileName;
        }
        return $fileName === '' ? $directory : $directory . '/' . $fileName;
    }

    /** L'URL d'un fichier sur la destination, mode et dossier compris. */
    public static function url(array $config, string $fileName = ''): string
    {
        $mode = self::modeOf($config['mode'] ?? null)['key'];
        $scheme = $mode === 'ftps-implicite' ? 'ftps' : 'ftp';
        $port = (int) ($config['port'] ?? 0) ?: ($mode === 'ftps-implicite' ? 990 : 21);
        // Le chemin de l'URL est toujours relatif au dossier d'accueil sauf
        // barre de tête : c'est la convention de cURL comme celle du protocole.
        $path = ltrim(self::remotePath($config, $fileName), '/');
        return $scheme . '://' . $config['host'] . ':' . $port . '/' . $path;
    }

    /**
     * Un transfert cURL. Isolé ici pour que les tests puissent le remplacer :
     * le reste du module se vérifie alors sans serveur FTP.
     */
    public static ?\Closure $transport = null;

    private static function run(array $options): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($options);
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => "L'extension cURL de PHP est nécessaire à l'externalisation FTP."];
        }

        $handle = curl_init();
        curl_setopt_array($handle, $options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::DEFAULT_TIMEOUT,
            CURLOPT_TIMEOUT => self::DEFAULT_TIMEOUT * 10,
        ]);
        $body = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);

        return $error === ''
            ? ['ok' => true, 'body' => (string) $body]
            : ['ok' => false, 'message' => $error];
    }

    /** Les options communes : identifiants, TLS, création du dossier distant. */
    private static function common(array $config): array
    {
        $mode = self::modeOf($config['mode'] ?? null)['key'];
        $options = [
            CURLOPT_USERPWD => ($config['user'] ?? '') . ':' . ($config['password'] ?? ''),
            CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_RETRY,
        ];
        if ($mode === 'ftps') {
            $options[CURLOPT_USE_SSL] = CURLUSESSL_ALL;
        }
        // Un certificat auto-signé est fréquent sur un NAS d'entreprise ; le
        // refuser d'office empêcherait la sauvegarde. Le choix est explicite et
        // affiché à l'écran, il n'est pas pris à la place de l'administrateur.
        if (!empty($config['allowSelfSigned'])) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        return $options;
    }

    public static function upload(array $config, string $fileName, string $bytes): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);

        $result = self::run(self::common($config) + [
            CURLOPT_URL => self::url($config, $fileName),
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $stream,
            CURLOPT_INFILESIZE => strlen($bytes),
        ]);
        fclose($stream);

        return $result['ok']
            ? ['ok' => true, 'message' => "$fileName déposé (" . strlen($bytes) . ' octets).']
            : $result;
    }

    /** Vérifie que la connexion s'établit et que le dossier est accessible en écriture. */
    public static function test(array $config): array
    {
        $probe = '.essai-toutadmin-' . time();
        $sent = self::upload($config, $probe, 'essai');
        if (!$sent['ok']) {
            return $sent;
        }
        $cleaned = self::remove($config, $probe);
        return [
            'ok' => true,
            'message' => $cleaned['ok']
                ? 'Connexion établie et écriture vérifiée.'
                : "Écriture vérifiée, mais le fichier d'essai n'a pas pu être supprimé : " . $cleaned['message'],
        ];
    }

    public static function list(array $config, string $prefix = 'sauvegarde-'): array
    {
        // Une barre finale dit à cURL qu'il s'agit d'un dossier à lister, non
        // d'un fichier à télécharger.
        $result = self::run(self::common($config) + [
            CURLOPT_URL => rtrim(self::url($config), '/') . '/',
        ]);
        if (!$result['ok']) {
            return $result;
        }

        $files = [];
        foreach (preg_split('/\r?\n/', (string) $result['body']) ?: [] as $line) {
            $name = trim($line);
            if ($name === '' || !str_starts_with($name, $prefix)) {
                continue;
            }
            $files[] = ['name' => $name, 'bytes' => 0, 'modifiedAt' => null];
        }
        // Le nom porte l'horodatage : le tri décroissant met la plus récente en tête.
        usort($files, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));
        return ['ok' => true, 'files' => $files];
    }

    public static function remove(array $config, string $fileName): array
    {
        $result = self::run(self::common($config) + [
            CURLOPT_URL => rtrim(self::url($config), '/') . '/',
            CURLOPT_QUOTE => ['DELE ' . self::remotePath($config, $fileName)],
            CURLOPT_NOBODY => true,
        ]);
        return $result['ok'] ? ['ok' => true] : $result;
    }
}
