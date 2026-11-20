<?php

declare(strict_types=1);

namespace App\Modules\Offsite;

/**
 * Destination Google Drive, par compte de service.
 *
 * Pas de bibliothèque : l'échange tient en un jeton JWT signé et deux appels
 * HTTPS. Un compte de service convient mieux qu'un consentement utilisateur
 * pour une sauvegarde qui doit partir sans personne devant l'écran — il n'y a
 * pas de jeton de rafraîchissement à voir expirer ni de consentement à
 * renouveler.
 *
 * Un compte de service ne possède aucun espace : le dossier de destination doit
 * lui être partagé depuis un compte Drive, ce qui borne son accès à ce seul
 * dossier. C'est la propriété qu'on recherche.
 */
final class Drive
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';
    public const FILES_URL = 'https://www.googleapis.com/drive/v3/files';
    public const SCOPE = 'https://www.googleapis.com/auth/drive';

    public const DEFAULT_TIMEOUT = 60;
    // Une marge : un jeton qui expire pendant l'envoi ferait échouer la sauvegarde.
    private const TOKEN_LIFETIME = 3600;
    private const TOKEN_MARGIN = 60;

    /** Un appel HTTP. Remplaçable pour que les tests se passent du réseau. */
    public static ?\Closure $transport = null;

    private static array $tokenCache = [];

    public static function base64url(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }

    /**
     * La clé d'un compte de service arrive collée depuis un fichier JSON : les
     * retours à la ligne y sont souvent échappés en « \n ». On accepte les deux.
     */
    public static function normalizeKey(?string $privateKey): string
    {
        return trim(str_replace('\n', "\n", (string) $privateKey));
    }

    public static function buildAssertion(array $config, ?int $now = null): string
    {
        $issued = (int) floor(($now ?? time() * 1000) / 1000);
        $header = self::base64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::base64url((string) json_encode([
            'iss' => $config['clientEmail'] ?? '',
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $issued,
            'exp' => $issued + self::TOKEN_LIFETIME,
        ]));

        // Une clé illisible se dit franchement plutôt que de partir en
        // avertissement PHP et en refus obscur de Google.
        $key = openssl_pkey_get_private(self::normalizeKey($config['privateKey'] ?? ''));
        if ($key === false) {
            return '';
        }
        $signature = '';
        openssl_sign("$header.$claims", $signature, $key, OPENSSL_ALGO_SHA256);
        return "$header.$claims." . self::base64url($signature);
    }

    private static function http(string $url, array $options): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($url, $options);
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => '',
                    'message' => "L'extension cURL de PHP est nécessaire à l'externalisation Drive."];
        }

        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => self::DEFAULT_TIMEOUT,
            CURLOPT_CUSTOMREQUEST => $options['method'] ?? 'GET',
            CURLOPT_HTTPHEADER => $options['headers'] ?? [],
        ] + (isset($options['body']) ? [CURLOPT_POSTFIELDS => $options['body']] : []));

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($error !== '') {
            return ['ok' => false, 'status' => 0, 'body' => '', 'message' => $error];
        }
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string) $body];
    }

    public static function accessToken(array $config, ?int $now = null): array
    {
        $now ??= time();
        $cacheKey = (string) ($config['clientEmail'] ?? '');
        $cached = self::$tokenCache[$cacheKey] ?? null;
        if ($cached !== null && $cached['expiresAt'] - self::TOKEN_MARGIN > $now) {
            return ['ok' => true, 'token' => $cached['token']];
        }

        $assertion = self::buildAssertion($config, $now * 1000);
        if ($assertion === '') {
            return ['ok' => false, 'message' => "La clé privée du compte de service est illisible : "
                . 'recopiez le champ private_key du fichier JSON, retours à la ligne compris.'];
        }

        $response = self::http(self::TOKEN_URL, [
            'method' => 'POST',
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
            'body' => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]),
        ]);
        if (!$response['ok']) {
            return ['ok' => false, 'message' => isset($response['message'])
                ? 'Google inaccessible : ' . $response['message']
                : 'Authentification refusée par Google (' . $response['status'] . ') : '
                  . mb_substr((string) $response['body'], 0, 300)];
        }

        $payload = json_decode((string) $response['body'], true);
        if (!is_array($payload)) {
            return ['ok' => false, 'message' => 'Réponse illisible du service de jetons Google.'];
        }
        if (empty($payload['access_token'])) {
            return ['ok' => false, 'message' => "Google n'a pas délivré de jeton."];
        }

        self::$tokenCache[$cacheKey] = [
            'token' => $payload['access_token'],
            'expiresAt' => $now + (int) ($payload['expires_in'] ?? self::TOKEN_LIFETIME),
        ];
        return ['ok' => true, 'token' => $payload['access_token']];
    }

    /** Vide le cache de jetons : à faire dès que la configuration change. */
    public static function forgetTokens(): void
    {
        self::$tokenCache = [];
    }

    private static function call(array $config, string $url, array $options): array
    {
        $auth = self::accessToken($config);
        if (!$auth['ok']) {
            return $auth;
        }
        $options['headers'] = array_merge($options['headers'] ?? [], ['Authorization: Bearer ' . $auth['token']]);
        $response = self::http($url, $options);
        if (!$response['ok']) {
            return ['ok' => false, 'message' => isset($response['message'])
                ? $response['message']
                : 'Drive a refusé (' . $response['status'] . ') : ' . mb_substr((string) $response['body'], 0, 300)];
        }
        $body = (string) $response['body'];
        return ['ok' => true, 'body' => $body === '' ? [] : (array) json_decode($body, true)];
    }

    /** Envoi en une requête « multipart/related » : métadonnées puis contenu. */
    public static function upload(array $config, string $fileName, string $bytes): array
    {
        $boundary = 'toutadmin-' . bin2hex(random_bytes(12));
        $metadata = (string) json_encode(['name' => $fileName, 'parents' => [$config['folderId'] ?? '']]);

        $body = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$metadata\r\n"
            . "--$boundary\r\nContent-Type: application/gzip\r\n\r\n"
            . $bytes
            . "\r\n--$boundary--\r\n";

        $result = self::call(
            $config,
            self::UPLOAD_URL . '?uploadType=multipart&supportsAllDrives=true&fields=id,name,size',
            ['method' => 'POST', 'headers' => ["Content-Type: multipart/related; boundary=$boundary"], 'body' => $body]
        );
        if (!$result['ok']) {
            return $result;
        }
        return [
            'ok' => true,
            'message' => "$fileName déposé sur Drive (" . strlen($bytes) . ' octets).',
            'id' => $result['body']['id'] ?? '',
        ];
    }

    public static function list(array $config, string $prefix = 'sauvegarde-'): array
    {
        $query = "'" . ($config['folderId'] ?? '') . "' in parents and trashed = false";
        $url = self::FILES_URL . '?q=' . rawurlencode($query)
            . '&fields=files(id,name,size,createdTime)&orderBy=createdTime desc&pageSize=200'
            . '&supportsAllDrives=true&includeItemsFromAllDrives=true';

        $result = self::call($config, $url, ['method' => 'GET']);
        if (!$result['ok']) {
            return $result;
        }
        $files = [];
        foreach ($result['body']['files'] ?? [] as $file) {
            if (!str_starts_with((string) $file['name'], $prefix)) {
                continue;
            }
            $files[] = [
                'id' => $file['id'], 'name' => $file['name'],
                'bytes' => (int) ($file['size'] ?? 0), 'modifiedAt' => $file['createdTime'] ?? null,
            ];
        }
        return ['ok' => true, 'files' => $files];
    }

    public static function remove(array $config, string $fileId): array
    {
        $result = self::call(
            $config,
            self::FILES_URL . '/' . rawurlencode($fileId) . '?supportsAllDrives=true',
            ['method' => 'DELETE']
        );
        return $result['ok'] ? ['ok' => true] : $result;
    }

    /** Vérifie l'authentification et l'accès en écriture au dossier. */
    public static function test(array $config): array
    {
        $probe = self::upload($config, '.essai-toutadmin-' . time() . '.txt', 'essai');
        if (!$probe['ok']) {
            return $probe;
        }
        $cleaned = self::remove($config, (string) $probe['id']);
        return [
            'ok' => true,
            'message' => $cleaned['ok']
                ? 'Authentification acceptée, écriture et suppression vérifiées.'
                : "Écriture vérifiée, mais le fichier d'essai n'a pas pu être supprimé : " . $cleaned['message'],
        ];
    }
}
