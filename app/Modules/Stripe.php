<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Cloison;
use App\Core\Db;
use App\Core\Secrets;
use App\Core\Settings;

/**
 * Le paiement en ligne des factures client par Stripe Checkout.
 *
 * Le produit ne voit jamais un numéro de carte : il demande à Stripe une page
 * de paiement pour le montant TTC d'une facture, en donne l'adresse au client,
 * et Stripe le prévient quand c'est payé. Ce qu'il garde, c'est la session
 * Checkout qu'il a lui-même créée — identifiant, facture, montant. La société
 * est celle de la facture : la session lui est rattachée.
 *
 * L'avis de Stripe (le « webhook ») arrive sans session ni jeton CSRF : il
 * s'authentifie par sa signature, un HMAC-SHA256 du corps brut et de l'heure
 * d'émission, avec le secret de signature propre à ce point d'arrivée. Une
 * signature fausse, une heure trop éloignée, une session inconnue d'ici, un
 * montant ou une devise qui ne correspondent pas : la facture ne bouge pas.
 *
 * Les deux clés sont chiffrées en base et jamais réaffichées. Le même module
 * que src/stripe.js dans l'édition Node.
 */
final class Stripe
{
    private const API = 'https://api.stripe.com';
    private const TOLERANCE = 300;
    private const TIMEOUT = 10;
    // Les devises sans décimales chez Stripe : le montant s'y donne en unités.
    private const SANS_DECIMALES = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /**
     * Le transport vers l'API, injectable : les tests ne parlent pas à Stripe.
     * (méthode, chemin, champs, clé) → ['status' => int, 'corps' => mixed]
     */
    public static ?\Closure $transport = null;

    public static function config(): array
    {
        $s = Settings::all();
        return [
            'enabled' => ($s['stripe_enabled'] ?? '') === '1',
            'secretKey' => ($s['stripe_secret_key'] ?? '') !== '' ? Secrets::decrypt((string) $s['stripe_secret_key']) : '',
            'webhookSecret' => ($s['stripe_webhook_secret'] ?? '') !== '' ? Secrets::decrypt((string) $s['stripe_webhook_secret']) : '',
            'hasSecretKey' => ($s['stripe_secret_key'] ?? '') !== '',
            'hasWebhookSecret' => ($s['stripe_webhook_secret'] ?? '') !== '',
        ];
    }

    /** Prêt à servir : activé, et les deux clés connues. */
    public static function pret(): bool
    {
        $c = self::config();
        return $c['enabled'] && $c['secretKey'] !== '' && $c['webhookSecret'] !== '';
    }

    /** Une clé laissée vide garde l'ancienne ; une clé donnée doit avoir la forme que Stripe lui donne. */
    public static function enregistrer(bool $enabled, string $secretKey, string $webhookSecret): array
    {
        $valeurs = ['stripe_enabled' => $enabled ? '1' : '0'];
        $cle = trim($secretKey);
        $signature = trim($webhookSecret);
        if ($cle !== '') {
            if (preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]{10,}$/', $cle) !== 1) {
                return ['ok' => false, 'reason' => 'secret-key'];
            }
            $valeurs['stripe_secret_key'] = Secrets::encrypt($cle);
        }
        if ($signature !== '') {
            if (preg_match('#^whsec_[A-Za-z0-9+/=]{10,}$#', $signature) !== 1) {
                return ['ok' => false, 'reason' => 'webhook-secret'];
            }
            $valeurs['stripe_webhook_secret'] = Secrets::encrypt($signature);
        }
        if ($enabled) {
            $actuel = self::config();
            if (!isset($valeurs['stripe_secret_key']) && !$actuel['hasSecretKey']) {
                return ['ok' => false, 'reason' => 'secret-key'];
            }
            if (!isset($valeurs['stripe_webhook_secret']) && !$actuel['hasWebhookSecret']) {
                return ['ok' => false, 'reason' => 'webhook-secret'];
            }
        }
        Settings::setMany($valeurs);
        return ['ok' => true];
    }

    /** En plus petite unité de la devise. */
    public static function centimes(float $montant, string $devise): int
    {
        return in_array($devise, self::SANS_DECIMALES, true) ? (int) round($montant) : (int) round($montant * 100);
    }

    /** Le dernier lien de paiement d'une facture de la société courante. */
    public static function lienDe(int $invoiceId): ?array
    {
        return Db::get(
            'SELECT s.session_id, s.url, s.status, s.amount_cents, s.currency, s.created_at FROM stripe_sessions s
             JOIN invoices i ON i.id = s.invoice_id
             WHERE s.invoice_id = ? AND i.company_id = ? ORDER BY s.created_at DESC, s.rowid DESC LIMIT 1',
            [$invoiceId, Societes::courant()]
        );
    }

    /**
     * Demander à Stripe une page de paiement pour une facture client émise. Le
     * montant est celui de la facture, TTC, figé à cet instant : l'avis de
     * paiement ne sera accepté que s'il dit le même.
     */
    public static function creerLien(?array $invoice, string $origine): array
    {
        if (!self::pret()) {
            return ['ok' => false, 'reason' => 'disabled'];
        }
        if ($invoice === null || $invoice['direction'] !== 'Client') {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if ($invoice['status'] !== 'Émise') {
            return ['ok' => false, 'reason' => 'status'];
        }
        $devise = strtoupper((string) ($invoice['currency'] ?: 'EUR'));
        $montant = self::centimes((float) $invoice['amount_ttc'], $devise);
        if ($montant <= 0) {
            return ['ok' => false, 'reason' => 'amount'];
        }
        $nom = mb_substr($invoice['reference'] !== '' ? 'Facture ' . $invoice['reference'] : 'Facture n° ' . $invoice['id'], 0, 120);
        $champs = [
            'mode' => 'payment',
            'line_items[0][quantity]' => '1',
            'line_items[0][price_data][currency]' => strtolower($devise),
            'line_items[0][price_data][unit_amount]' => (string) $montant,
            'line_items[0][price_data][product_data][name]' => $nom,
            'client_reference_id' => 'facture-' . $invoice['id'],
            'metadata[invoice_id]' => (string) $invoice['id'],
            'success_url' => $origine . '/paiement/merci.html',
            'cancel_url' => $origine . '/paiement/annule.html',
        ];
        $r = self::http('POST', '/v1/checkout/sessions', $champs, self::config()['secretKey']);
        $corps = is_array($r['corps']) ? $r['corps'] : [];
        if ($r['status'] !== 200 || !is_string($corps['id'] ?? null) || !is_string($corps['url'] ?? null) || !str_starts_with($corps['url'], 'https://')) {
            $erreur = is_array($corps['error'] ?? null) ? (string) ($corps['error']['code'] ?? $corps['error']['type'] ?? '') : (string) $r['status'];
            return ['ok' => false, 'reason' => 'stripe', 'detail' => $erreur];
        }
        Db::run(
            "INSERT INTO stripe_sessions (session_id, invoice_id, amount_cents, currency, url, status, created_at) VALUES (?, ?, ?, ?, ?, 'open', ?)",
            [$corps['id'], (int) $invoice['id'], $montant, $devise, $corps['url'], gmdate('c')]
        );
        return ['ok' => true, 'url' => $corps['url'], 'sessionId' => $corps['id']];
    }

    /**
     * Vérifier l'en-tête Stripe-Signature : « t=<heure>,v1=<hmac>[,v1=…] ». Le
     * HMAC porte sur « <heure>.<corps brut> » ; l'heure doit être à moins de
     * cinq minutes de la nôtre, pour qu'un avis capté ne se rejoue pas plus tard.
     */
    public static function signatureValide(string $corps, string $entete, string $secret, ?int $maintenant = null): string|true
    {
        $maintenant ??= time();
        if ($secret === '' || $entete === '') {
            return 'signature';
        }
        $t = null;
        $v1 = [];
        foreach (explode(',', $entete) as $morceau) {
            [$cle, $valeur] = array_map('trim', explode('=', $morceau, 2) + [1 => '']);
            if ($cle === 't' && preg_match('/^\d{1,12}$/', $valeur) === 1) {
                $t = (int) $valeur;
            }
            if ($cle === 'v1' && preg_match('/^[0-9a-f]{64}$/', $valeur) === 1) {
                $v1[] = $valeur;
            }
        }
        if ($t === null || $v1 === []) {
            return 'signature';
        }
        if (abs($maintenant - $t) > self::TOLERANCE) {
            return 'timestamp';
        }
        $attendu = hash_hmac('sha256', $t . '.' . $corps, $secret);
        foreach ($v1 as $v) {
            if (hash_equals($attendu, $v)) {
                return true;
            }
        }
        return 'signature';
    }

    /**
     * Recevoir un avis. Rend ['status', 'reason', 'session'?] : 400 pour un avis
     * qui n'est pas de Stripe, 200 pour tout le reste — y compris ce qu'on
     * ignore, sans quoi Stripe le renverrait pendant trois jours.
     */
    public static function recevoir(string $corps, string $entete, ?int $maintenant = null): array
    {
        $verdict = self::signatureValide($corps, $entete, self::config()['webhookSecret'], $maintenant);
        if ($verdict !== true) {
            return ['status' => 400, 'reason' => $verdict];
        }
        $evenement = json_decode($corps, true);
        if (!is_array($evenement)) {
            return ['status' => 400, 'reason' => 'json'];
        }
        $type = $evenement['type'] ?? '';
        if ($type !== 'checkout.session.completed' && $type !== 'checkout.session.async_payment_succeeded') {
            return ['status' => 200, 'reason' => 'ignored'];
        }
        $objet = is_array($evenement['data']['object'] ?? null) ? $evenement['data']['object'] : [];
        if (($objet['payment_status'] ?? '') !== 'paid') {
            return ['status' => 200, 'reason' => 'unpaid'];
        }
        // Seule une session créée ici fait foi ; sa facture et son montant sont
        // ceux qu'on a gardés, pas ceux que l'avis raconte.
        $session = Db::get('SELECT * FROM stripe_sessions WHERE session_id = ?', [(string) ($objet['id'] ?? '')]);
        if ($session === null) {
            return ['status' => 200, 'reason' => 'unknown-session'];
        }
        if ($session['status'] === 'paid') {
            return ['status' => 200, 'reason' => 'duplicate'];
        }
        if ((int) ($objet['amount_total'] ?? -1) !== (int) $session['amount_cents']
            || strtoupper((string) ($objet['currency'] ?? '')) !== $session['currency']) {
            return ['status' => 200, 'reason' => 'amount', 'session' => $session];
        }
        $societe = Cloison::sansPerimetre(static fn () => Db::value('SELECT company_id FROM invoices WHERE id = ?', [(int) $session['invoice_id']]));
        $marquee = $societe !== null && $societe !== false && Societes::dans((int) $societe, static function () use ($session): bool {
            $facture = Finance::invoiceById((int) $session['invoice_id']);
            if ($facture === null || $facture['status'] === 'Annulée') {
                return false;
            }
            if ($facture['status'] !== 'Payée') {
                Finance::setInvoiceStatus((int) $facture['id'], 'Payée');
            }
            return true;
        });
        Db::run('UPDATE stripe_sessions SET status = ?, paid_at = ? WHERE session_id = ?', [$marquee ? 'paid' : 'orphan', gmdate('c'), $session['session_id']]);
        return ['status' => 200, 'reason' => $marquee ? 'paid' : 'invoice', 'session' => $session];
    }

    /** @return array{status: int, corps: mixed} */
    private static function http(string $methode, string $chemin, array $champs, string $cle): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($methode, $chemin, $champs, $cle);
        }
        $handle = curl_init(self::API . $chemin);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $methode,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cle, 'Content-Type: application/x-www-form-urlencoded', 'Stripe-Version: 2024-06-20'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ] + ($methode === 'POST' ? [CURLOPT_POSTFIELDS => http_build_query($champs, '', '&', PHP_QUERY_RFC1738)] : []));
        $reponse = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($reponse) || strlen($reponse) > 1048576) {
            throw new \RuntimeException('stripe');
        }
        return ['status' => $status, 'corps' => json_decode($reponse, true)];
    }
}
