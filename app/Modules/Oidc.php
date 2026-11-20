<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Secrets;
use App\Core\Settings;

/**
 * La connexion unique par OpenID Connect, en flux « code d'autorisation » avec
 * PKCE — les mêmes vérifications que l'édition Node, sans bibliothèque : état,
 * nonce, émetteur, jeton RS256 ou ES256 seulement (jamais « none » ni HMAC),
 * clé désignée par son « kid », audience, expiration, adresse vérifiée.
 *
 * Elle n'ouvre que des comptes existants, retrouvés par leur adresse : elle
 * n'en crée jamais.
 */
final class Oidc
{
    private const DUREE_ETAT = 600;
    private const CACHE = 3600;
    private const DECALAGE = 120;
    private const TIMEOUT = 10;

    /** Le transport HTTP, injectable : les tests n'ouvrent aucune connexion. */
    public static ?\Closure $transport = null;

    private static array $cache = [];

    private static function normaliser(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    public static function issuerValide(string $url): bool
    {
        $u = parse_url($url);
        if (!is_array($u) || !isset($u['scheme'], $u['host']) || isset($u['user']) || isset($u['pass']) || isset($u['query']) || isset($u['fragment'])) {
            return false;
        }
        if ($u['scheme'] === 'https') {
            return true;
        }
        return $u['scheme'] === 'http' && in_array($u['host'], ['localhost', '127.0.0.1', '[::1]'], true);
    }

    public static function config(): array
    {
        $s = Settings::all();
        $domaines = array_values(array_filter(array_map(
            static fn (string $d): string => strtolower(trim($d)),
            preg_split('/[\s,;]+/', (string) ($s['sso_oidc_domains'] ?? '')) ?: []
        )));
        return [
            'enabled' => ($s['sso_oidc_enabled'] ?? '') === '1',
            'issuer' => self::normaliser((string) ($s['sso_oidc_issuer'] ?? '')),
            'clientId' => (string) ($s['sso_oidc_client_id'] ?? ''),
            'clientSecret' => ($s['sso_oidc_client_secret'] ?? '') !== '' ? Secrets::decrypt((string) $s['sso_oidc_client_secret']) : '',
            'hasSecret' => ($s['sso_oidc_client_secret'] ?? '') !== '',
            'label' => ($s['sso_oidc_label'] ?? '') !== '' ? (string) $s['sso_oidc_label'] : 'SSO',
            'domains' => $domaines,
        ];
    }

    public static function bouton(): ?array
    {
        $c = self::config();
        return $c['enabled'] && $c['issuer'] !== '' && $c['clientId'] !== '' ? ['label' => $c['label']] : null;
    }

    public static function enregistrer(bool $enabled, string $issuer, string $clientId, string $clientSecret, string $label, string $domains): array
    {
        $iss = self::normaliser($issuer);
        if (($enabled || $iss !== '') && !self::issuerValide($iss)) {
            return ['ok' => false, 'reason' => 'issuer'];
        }
        $id = trim($clientId);
        if ($enabled && $id === '') {
            return ['ok' => false, 'reason' => 'client'];
        }
        if ($enabled && $clientSecret === '' && Settings::get('sso_oidc_client_secret') === '') {
            return ['ok' => false, 'reason' => 'secret'];
        }
        $valeurs = [
            'sso_oidc_enabled' => $enabled ? '1' : '0',
            'sso_oidc_issuer' => $iss,
            'sso_oidc_client_id' => mb_substr($id, 0, 255),
            'sso_oidc_label' => mb_substr(trim($label), 0, 60),
            'sso_oidc_domains' => mb_substr(trim($domains), 0, 500),
        ];
        // Un champ de secret laissé vide garde le secret enregistré.
        if ($clientSecret !== '') {
            $valeurs['sso_oidc_client_secret'] = Secrets::encrypt($clientSecret);
        }
        Settings::setMany($valeurs);
        self::$cache = [];
        return ['ok' => true];
    }

    /** @return array{status: int, corps: mixed} */
    private static function http(string $methode, string $url, array $entetes = [], string $corps = ''): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($methode, $url, $entetes, $corps);
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $methode,
            CURLOPT_HTTPHEADER => $entetes,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ] + ($methode === 'POST' ? [CURLOPT_POSTFIELDS => $corps] : []));
        $reponse = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($reponse) || strlen($reponse) > 1048576) {
            return ['status' => 0, 'corps' => null];
        }
        return ['status' => $status, 'corps' => json_decode($reponse, true)];
    }

    private static function decouverte(string $issuer): array
    {
        $cle = "decouverte:$issuer";
        if (isset(self::$cache[$cle]) && time() - self::$cache[$cle]['t'] < self::CACHE) {
            return self::$cache[$cle]['v'];
        }
        $r = self::http('GET', "$issuer/.well-known/openid-configuration");
        $doc = $r['corps'];
        if ($r['status'] !== 200 || !is_array($doc)) {
            throw new \RuntimeException('découverte');
        }
        if (self::normaliser((string) ($doc['issuer'] ?? '')) !== $issuer) {
            throw new \RuntimeException('émetteur');
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $champ) {
            $u = parse_url((string) ($doc[$champ] ?? ''));
            if (!is_array($u) || !isset($u['scheme'], $u['host']) || !self::issuerValide($u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : ''))) {
                throw new \RuntimeException($champ);
            }
        }
        self::$cache[$cle] = ['t' => time(), 'v' => $doc];
        return $doc;
    }

    private static function jeuDeCles(string $uri, bool $force = false): array
    {
        $cle = "jwks:$uri";
        if (!$force && isset(self::$cache[$cle]) && time() - self::$cache[$cle]['t'] < self::CACHE) {
            return self::$cache[$cle]['v'];
        }
        $r = self::http('GET', $uri);
        if ($r['status'] !== 200 || !is_array($r['corps']) || !is_array($r['corps']['keys'] ?? null)) {
            throw new \RuntimeException('jwks');
        }
        self::$cache[$cle] = ['t' => time(), 'v' => $r['corps']['keys']];
        return $r['corps']['keys'];
    }

    private static function b64u(string $o): string
    {
        return rtrim(strtr(base64_encode($o), '+/', '-_'), '=');
    }

    private static function deB64u(string $t): string
    {
        $b = strtr($t, '-_', '+/');
        $d = base64_decode($b . str_repeat('=', (4 - strlen($b) % 4) % 4), true);
        return $d === false ? '' : $d;
    }

    /** Le départ vers le fournisseur : l'adresse, et ce que la session doit garder. */
    public static function demarrer(string $redirectUri): array
    {
        $c = self::config();
        if (self::bouton() === null) {
            return ['ok' => false, 'reason' => 'disabled'];
        }
        $doc = self::decouverte($c['issuer']);
        $etat = [
            'state' => self::b64u(random_bytes(24)),
            'nonce' => self::b64u(random_bytes(24)),
            'verifier' => self::b64u(random_bytes(48)),
            'emis' => time(),
        ];
        $url = (string) $doc['authorization_endpoint'];
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query([
            'response_type' => 'code',
            'client_id' => $c['clientId'],
            'redirect_uri' => $redirectUri,
            'scope' => 'openid email profile',
            'state' => $etat['state'],
            'nonce' => $etat['nonce'],
            'code_challenge' => self::b64u(hash('sha256', $etat['verifier'], true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
        return ['ok' => true, 'url' => $url, 'etat' => $etat];
    }

    private static function egal(mixed $a, mixed $b): bool
    {
        $a = (string) $a;
        $b = (string) $b;
        return $a !== '' && strlen($a) === strlen($b) && hash_equals($a, $b);
    }

    private static function der(int $tag, string $contenu): string
    {
        $n = strlen($contenu);
        $l = $n < 0x80 ? chr($n) : chr(0x80 | strlen(ltrim(pack('N', $n), "\0"))) . ltrim(pack('N', $n), "\0");
        return chr($tag) . $l . $contenu;
    }

    private static function derEntier(string $o): string
    {
        $o = ltrim($o, "\0");
        if ($o === '' || (ord($o[0]) & 0x80) !== 0) {
            $o = "\0" . $o;
        }
        return self::der(0x02, $o);
    }

    /** Une clé JWK (RSA ou EC P-256) en PEM. */
    private static function jwkVersPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? '') === 'RSA') {
            $n = self::deB64u((string) ($jwk['n'] ?? ''));
            $e = self::deB64u((string) ($jwk['e'] ?? ''));
            if (strlen(ltrim($n, "\0")) < 256 || $e === '') {
                return null;
            }
            $algo = self::der(0x30, self::der(0x06, hex2bin('2a864886f70d010101')) . "\x05\x00");
            $der = self::der(0x30, $algo . self::der(0x03, "\0" . self::der(0x30, self::derEntier($n) . self::derEntier($e))));
        } elseif (($jwk['kty'] ?? '') === 'EC' && ($jwk['crv'] ?? '') === 'P-256') {
            $x = self::deB64u((string) ($jwk['x'] ?? ''));
            $y = self::deB64u((string) ($jwk['y'] ?? ''));
            if (strlen($x) !== 32 || strlen($y) !== 32) {
                return null;
            }
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
        } else {
            return null;
        }
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    public static function verifierJeton(string $jwt, string $jwksUri, string $issuer, string $clientId, string $nonce, ?int $maintenant = null): array
    {
        $maintenant ??= time();
        $parties = explode('.', $jwt);
        if (count($parties) !== 3) {
            return ['ok' => false, 'reason' => 'format'];
        }
        $entete = json_decode(self::deB64u($parties[0]), true);
        $charge = json_decode(self::deB64u($parties[1]), true);
        if (!is_array($entete) || !is_array($charge)) {
            return ['ok' => false, 'reason' => 'format'];
        }
        $alg = (string) ($entete['alg'] ?? '');
        if (!in_array($alg, ['RS256', 'ES256'], true)) {
            return ['ok' => false, 'reason' => 'alg'];
        }
        $kty = $alg === 'RS256' ? 'RSA' : 'EC';
        $kid = $entete['kid'] ?? null;
        $trouver = static function (array $cles) use ($kty, $kid, $alg): ?array {
            foreach ($cles as $k) {
                if (is_array($k) && ($k['kty'] ?? '') === $kty && ($kid === null || ($k['kid'] ?? null) === $kid)
                    && (($k['use'] ?? 'sig') === 'sig') && (($k['alg'] ?? $alg) === $alg)) {
                    return $k;
                }
            }
            return null;
        };
        $jwk = $trouver(self::jeuDeCles($jwksUri)) ?? $trouver(self::jeuDeCles($jwksUri, true));
        if ($jwk === null) {
            return ['ok' => false, 'reason' => 'kid'];
        }
        $pem = self::jwkVersPem($jwk);
        $signature = self::deB64u($parties[2]);
        if ($alg === 'ES256') {
            // JOSE porte r et s bout à bout ; OpenSSL attend une structure DER.
            if (strlen($signature) !== 64) {
                return ['ok' => false, 'reason' => 'signature'];
            }
            $signature = self::der(0x30, self::derEntier(substr($signature, 0, 32)) . self::derEntier(substr($signature, 32)));
        }
        if ($pem === null || openssl_verify($parties[0] . '.' . $parties[1], $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            return ['ok' => false, 'reason' => 'signature'];
        }
        if (self::normaliser((string) ($charge['iss'] ?? '')) !== $issuer) {
            return ['ok' => false, 'reason' => 'iss'];
        }
        $audiences = is_array($charge['aud'] ?? null) ? $charge['aud'] : [$charge['aud'] ?? null];
        if (!in_array($clientId, $audiences, true)) {
            return ['ok' => false, 'reason' => 'aud'];
        }
        if (count($audiences) > 1 && ($charge['azp'] ?? null) !== $clientId) {
            return ['ok' => false, 'reason' => 'azp'];
        }
        if (!is_int($charge['exp'] ?? null) || $charge['exp'] + self::DECALAGE < $maintenant) {
            return ['ok' => false, 'reason' => 'exp'];
        }
        if (is_int($charge['iat'] ?? null) && $charge['iat'] - self::DECALAGE > $maintenant) {
            return ['ok' => false, 'reason' => 'iat'];
        }
        if (is_int($charge['nbf'] ?? null) && $charge['nbf'] - self::DECALAGE > $maintenant) {
            return ['ok' => false, 'reason' => 'nbf'];
        }
        if (!self::egal($charge['nonce'] ?? '', $nonce)) {
            return ['ok' => false, 'reason' => 'nonce'];
        }
        return ['ok' => true, 'claims' => $charge];
    }

    /** Le retour du fournisseur : rend l'adresse vérifiée ; l'appelant trouve le compte. */
    public static function terminer(string $code, string $state, ?array $etat, string $redirectUri): array
    {
        $c = self::config();
        if (self::bouton() === null) {
            return ['ok' => false, 'reason' => 'disabled'];
        }
        if ($etat === null || time() - (int) ($etat['emis'] ?? 0) > self::DUREE_ETAT) {
            return ['ok' => false, 'reason' => 'expired'];
        }
        if (!self::egal($state, $etat['state'] ?? '')) {
            return ['ok' => false, 'reason' => 'state'];
        }
        if ($code === '') {
            return ['ok' => false, 'reason' => 'code'];
        }
        $doc = self::decouverte($c['issuer']);
        $corps = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri, 'code_verifier' => (string) $etat['verifier']];
        $entetes = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];
        $methodes = is_array($doc['token_endpoint_auth_methods_supported'] ?? null) ? $doc['token_endpoint_auth_methods_supported'] : ['client_secret_basic'];
        if (in_array('client_secret_basic', $methodes, true) || !in_array('client_secret_post', $methodes, true)) {
            $entetes[] = 'Authorization: Basic ' . base64_encode(rawurlencode($c['clientId']) . ':' . rawurlencode($c['clientSecret']));
        } else {
            $corps['client_id'] = $c['clientId'];
            $corps['client_secret'] = $c['clientSecret'];
        }
        $r = self::http('POST', (string) $doc['token_endpoint'], $entetes, http_build_query($corps, '', '&', PHP_QUERY_RFC3986));
        if ($r['status'] !== 200 || !is_array($r['corps']) || !is_string($r['corps']['id_token'] ?? null)) {
            return ['ok' => false, 'reason' => 'token'];
        }
        $verdict = self::verifierJeton($r['corps']['id_token'], (string) $doc['jwks_uri'], $c['issuer'], $c['clientId'], (string) $etat['nonce']);
        if (!$verdict['ok']) {
            return $verdict;
        }
        $email = strtolower(trim((string) ($verdict['claims']['email'] ?? '')));
        if ($email === '') {
            return ['ok' => false, 'reason' => 'no-email'];
        }
        $verifiee = $verdict['claims']['email_verified'] ?? null;
        if ($verifiee === false || $verifiee === 'false') {
            return ['ok' => false, 'reason' => 'unverified'];
        }
        if ($c['domains'] !== [] && !in_array(substr((string) strrchr($email, '@'), 1), $c['domains'], true)) {
            return ['ok' => false, 'reason' => 'domain'];
        }
        return ['ok' => true, 'email' => $email, 'sub' => (string) ($verdict['claims']['sub'] ?? '')];
    }
}
