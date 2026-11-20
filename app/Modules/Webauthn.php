<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Cbor;
use App\Core\CborOctets;
use App\Core\Db;
use App\Core\Request;
use App\Core\Security;

/**
 * Les clés de sécurité et les clés d'accès (WebAuthn), en second facteur — les
 * mêmes vérifications que l'édition Node, sans bibliothèque : défi de la
 * session, origine, site, présence, signature ES256 ou RS256, compteur.
 */
final class Webauthn
{
    public const ES256 = -7;
    public const RS256 = -257;
    private const DUREE_DEFI = 300;

    private static function b64u(string $octets): string
    {
        return rtrim(strtr(base64_encode($octets), '+/', '-_'), '=');
    }

    private static function deB64u(string $texte): string
    {
        $b = strtr($texte, '-_', '+/');
        $d = base64_decode($b . str_repeat('=', (4 - strlen($b) % 4) % 4), true);
        return $d === false ? '' : $d;
    }

    /** Le site tel que le navigateur le voit : son nom d'hôte, et son origine. */
    public static function site(Request $request): array
    {
        $hote = (string) ($request->headers['host'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $id = trim((string) preg_replace('/:\d+$/', '', $hote), '[]');
        return ['id' => $id, 'origine' => (Security::isSecure() ? 'https' : 'http') . '://' . $hote];
    }

    private static function defi(): array
    {
        return ['valeur' => self::b64u(random_bytes(32)), 'emis' => time()];
    }

    private static function poignee(int $userId): string
    {
        $p = Db::value('SELECT webauthn_handle FROM users WHERE id = ?', [$userId]);
        if (is_string($p) && $p !== '') {
            return $p;
        }
        Db::run('UPDATE users SET webauthn_handle = ? WHERE id = ? AND webauthn_handle IS NULL', [self::b64u(random_bytes(16)), $userId]);
        return (string) Db::value('SELECT webauthn_handle FROM users WHERE id = ?', [$userId]);
    }

    public static function clesDe(int $userId): array
    {
        return Db::all('SELECT * FROM webauthn_credentials WHERE user_id = ? ORDER BY id', [$userId]);
    }

    public static function aDesCles(int $userId): bool
    {
        return Db::get('SELECT 1 AS ok FROM webauthn_credentials WHERE user_id = ?', [$userId]) !== null;
    }

    public static function optionsEnregistrement(array $user, array $site, string $nomDuSite): array
    {
        $d = self::defi();
        $nom = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        return ['defi' => $d, 'options' => [
            'challenge' => $d['valeur'],
            'rp' => ['id' => $site['id'], 'name' => $nomDuSite],
            'user' => ['id' => self::poignee((int) $user['id']), 'name' => $user['email'], 'displayName' => $nom !== '' ? $nom : $user['email']],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => self::ES256], ['type' => 'public-key', 'alg' => self::RS256]],
            'timeout' => self::DUREE_DEFI * 1000,
            'attestation' => 'none',
            'authenticatorSelection' => ['userVerification' => 'preferred', 'residentKey' => 'discouraged'],
            'excludeCredentials' => array_map(static fn (array $c): array => ['type' => 'public-key', 'id' => $c['credential_id']], self::clesDe((int) $user['id'])),
        ]];
    }

    public static function optionsConnexion(int $userId, array $site): array
    {
        $d = self::defi();
        return ['defi' => $d, 'options' => [
            'challenge' => $d['valeur'],
            'rpId' => $site['id'],
            'timeout' => self::DUREE_DEFI * 1000,
            'userVerification' => 'preferred',
            'allowCredentials' => array_map(static fn (array $c): array => ['type' => 'public-key', 'id' => $c['credential_id']], self::clesDe($userId)),
        ]];
    }

    private static function lireClientData(string $brut, string $type, ?array $defi, array $site): array
    {
        $json = json_decode(self::deB64u($brut), true);
        if (!is_array($json)) {
            return ['ok' => false, 'reason' => 'client-data'];
        }
        if (($json['type'] ?? '') !== $type) {
            return ['ok' => false, 'reason' => 'type'];
        }
        if ($defi === null || time() - (int) ($defi['emis'] ?? 0) > self::DUREE_DEFI) {
            return ['ok' => false, 'reason' => 'expired'];
        }
        if (!hash_equals(self::deB64u((string) $defi['valeur']), self::deB64u((string) ($json['challenge'] ?? '')))) {
            return ['ok' => false, 'reason' => 'challenge'];
        }
        if (($json['origin'] ?? '') !== $site['origine']) {
            return ['ok' => false, 'reason' => 'origin'];
        }
        return ['ok' => true, 'hash' => hash('sha256', self::deB64u($brut), true)];
    }

    private static function lireAuthData(string $b, array $site): array
    {
        if (strlen($b) < 37) {
            return ['ok' => false, 'reason' => 'auth-data'];
        }
        if (!hash_equals(hash('sha256', $site['id'], true), substr($b, 0, 32))) {
            return ['ok' => false, 'reason' => 'rp'];
        }
        $flags = ord($b[32]);
        if (($flags & 0x01) === 0) {
            return ['ok' => false, 'reason' => 'presence'];
        }
        $sortie = ['ok' => true, 'flags' => $flags, 'compteur' => unpack('N', substr($b, 33, 4))[1]];
        if (($flags & 0x40) !== 0) {
            if (strlen($b) < 55) {
                return ['ok' => false, 'reason' => 'auth-data'];
            }
            $longueur = unpack('n', substr($b, 53, 2))[1];
            if ($longueur < 16 || $longueur > 1023 || strlen($b) < 55 + $longueur) {
                return ['ok' => false, 'reason' => 'auth-data'];
            }
            $sortie['credentialId'] = substr($b, 55, $longueur);
            try {
                $sortie['cose'] = Cbor::lire($b, 55 + $longueur)['valeur'];
            } catch (\RuntimeException) {
                return ['ok' => false, 'reason' => 'cose'];
            }
        }
        return $sortie;
    }

    private static function derLongueur(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $o = ltrim(pack('N', $n), "\0");
        return chr(0x80 | strlen($o)) . $o;
    }

    private static function der(int $tag, string $contenu): string
    {
        return chr($tag) . self::derLongueur(strlen($contenu)) . $contenu;
    }

    private static function derEntier(string $octets): string
    {
        $o = ltrim($octets, "\0");
        if ($o === '' || (ord($o[0]) & 0x80) !== 0) {
            $o = "\0" . $o;
        }
        return self::der(0x02, $o);
    }

    /** Une clé COSE en clé publique SPKI (PEM), avec son algorithme. */
    public static function coseVersPem(mixed $cose): ?array
    {
        if (!is_array($cose)) {
            return null;
        }
        $kty = $cose[1] ?? null;
        $alg = $cose[3] ?? null;
        if ($kty === 2 && $alg === self::ES256 && ($cose[-1] ?? null) === 1) {
            $x = $cose[-2] ?? null;
            $y = $cose[-3] ?? null;
            if (!$x instanceof CborOctets || !$y instanceof CborOctets || strlen($x->octets) !== 32 || strlen($y->octets) !== 32) {
                return null;
            }
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x->octets . $y->octets;
        } elseif ($kty === 3 && $alg === self::RS256) {
            $n = $cose[-1] ?? null;
            $e = $cose[-2] ?? null;
            if (!$n instanceof CborOctets || !$e instanceof CborOctets || strlen(ltrim($n->octets, "\0")) < 256) {
                return null;
            }
            $cle = self::der(0x30, self::derEntier($n->octets) . self::derEntier($e->octets));
            $algo = self::der(0x30, self::der(0x06, hex2bin('2a864886f70d010101')) . "\x05\x00");
            $der = self::der(0x30, $algo . self::der(0x03, "\0" . $cle));
        } else {
            return null;
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        if (openssl_pkey_get_public($pem) === false) {
            return null;
        }
        return ['pem' => $pem, 'alg' => $alg];
    }

    public static function verifierEnregistrement(string $clientData, string $attestation, ?array $defi, array $site): array
    {
        $client = self::lireClientData($clientData, 'webauthn.create', $defi, $site);
        if (!$client['ok']) {
            return $client;
        }
        try {
            $objet = Cbor::lire(self::deB64u($attestation))['valeur'];
        } catch (\RuntimeException) {
            return ['ok' => false, 'reason' => 'attestation'];
        }
        if (!is_array($objet) || !(($objet['authData'] ?? null) instanceof CborOctets)) {
            return ['ok' => false, 'reason' => 'attestation'];
        }
        $auth = self::lireAuthData($objet['authData']->octets, $site);
        if (!$auth['ok']) {
            return $auth;
        }
        if (!isset($auth['credentialId'])) {
            return ['ok' => false, 'reason' => 'no-credential'];
        }
        $cle = self::coseVersPem($auth['cose']);
        if ($cle === null) {
            return ['ok' => false, 'reason' => 'algorithm'];
        }
        return ['ok' => true, 'credentialId' => self::b64u($auth['credentialId']), 'pem' => $cle['pem'], 'alg' => $cle['alg'], 'compteur' => $auth['compteur']];
    }

    public static function enregistrer(int $userId, string $nom, array $verdict): array
    {
        if (Db::get('SELECT 1 AS ok FROM webauthn_credentials WHERE credential_id = ?', [$verdict['credentialId']]) !== null) {
            return ['ok' => false, 'reason' => 'duplicate'];
        }
        $nom = mb_substr(trim($nom), 0, 60);
        $id = Db::insert(
            'INSERT INTO webauthn_credentials (user_id, credential_id, public_key, algorithm, sign_count, name) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $verdict['credentialId'], $verdict['pem'], $verdict['alg'], $verdict['compteur'], $nom !== '' ? $nom : 'Clé de sécurité']
        );
        return ['ok' => true, 'id' => $id];
    }

    public static function verifierConnexion(int $userId, string $credentialId, string $clientData, string $authenticatorData, string $signature, ?array $defi, array $site): array
    {
        $cle = Db::get('SELECT * FROM webauthn_credentials WHERE credential_id = ? AND user_id = ?', [$credentialId, $userId]);
        if ($cle === null) {
            return ['ok' => false, 'reason' => 'unknown-key'];
        }
        $client = self::lireClientData($clientData, 'webauthn.get', $defi, $site);
        if (!$client['ok']) {
            return $client;
        }
        $authBrut = self::deB64u($authenticatorData);
        $auth = self::lireAuthData($authBrut, $site);
        if (!$auth['ok']) {
            return $auth;
        }
        $valide = openssl_verify($authBrut . $client['hash'], self::deB64u($signature), (string) $cle['public_key'], OPENSSL_ALGO_SHA256) === 1;
        if (!$valide) {
            return ['ok' => false, 'reason' => 'signature'];
        }
        $avant = (int) $cle['sign_count'];
        if (($auth['compteur'] !== 0 || $avant !== 0) && $auth['compteur'] <= $avant) {
            return ['ok' => false, 'reason' => 'counter'];
        }
        Db::run("UPDATE webauthn_credentials SET sign_count = ?, last_used_at = datetime('now') WHERE id = ?", [$auth['compteur'], (int) $cle['id']]);
        return ['ok' => true, 'cleId' => (int) $cle['id']];
    }

    public static function supprimer(int $userId, int $id): bool
    {
        return Db::run('DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?', [$id, $userId]) > 0;
    }
}
