<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Secrets;
use App\Core\Settings;

/**
 * Analyse d'une facture par un modèle de langage — optionnelle, et éteinte par
 * défaut.
 *
 * La lecture par règles (voir InvoiceScan) fonctionne toujours, hors ligne, et
 * ne fait sortir aucune donnée. Le modèle vient par-dessus, pour les cas que
 * les règles lisent mal : mises en page exotiques, factures étrangères,
 * documents où le libellé du fournisseur ne ressemble à rien de connu.
 *
 * Trois précautions, parce qu'envoyer une facture chez un tiers n'est pas
 * anodin :
 *
 *   — **c'est un choix explicite.** Rien n'est envoyé tant qu'un administrateur
 *     n'a pas activé la fonction, choisi le service et saisi sa clé. L'écran
 *     dit ce qui part et où ;
 *   — **la clé est chiffrée en base**, comme les autres secrets ;
 *   — **le modèle ne décide de rien.** Il propose des champs ; les règles
 *     gardent la main sur ce qu'elles savent vérifier (SIRET, IBAN, cohérence
 *     des montants), et le comptable valide avant toute écriture.
 *
 * Deux familles de services sont acceptées : l'API Claude d'Anthropic, et tout
 * service compatible OpenAI — Mistral, OVHcloud, Scaleway, un Ollama posé sur
 * le réseau interne. Le choix reste à l'entreprise, y compris celui de ne rien
 * envoyer du tout.
 *
 * L'édition PHP appelle les deux API en HTTP directement : pas de bibliothèque
 * à installer, et rien de plus que ce que fait le SDK côté Node.
 */
final class Ai
{
    public const PROVIDERS = [
        [
            'key' => 'anthropic',
            'label' => 'API Claude (Anthropic)',
            'defaultModel' => 'claude-opus-5',
            'hint' => 'Clé « sk-ant-… » créée depuis la console Anthropic.',
            'needsBaseUrl' => false,
        ],
        [
            'key' => 'openai',
            'label' => 'Service compatible OpenAI (Mistral, OVHcloud, Scaleway, Ollama…)',
            'defaultModel' => 'mistral-small-latest',
            'hint' => "Indiquez l'adresse du service, par exemple https://api.mistral.ai/v1 — un modèle hébergé chez vous ne fait sortir aucune donnée.",
            'needsBaseUrl' => true,
        ],
    ];

    public const EFFORTS = ['low', 'medium', 'high'];
    public const MAX_CHARS = 20000;
    public const TIMEOUT = 60;
    public const MAX_TOKENS = 4000;
    public const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
    public const ANTHROPIC_VERSION = '2023-06-01';

    public const KEYS = [
        'enabled' => 'ai.enabled',
        'provider' => 'ai.provider',
        'model' => 'ai.model',
        'baseUrl' => 'ai.base_url',
        'effort' => 'ai.effort',
        'key' => 'ai.key',
    ];

    /** Un appel HTTP. Remplaçable pour que les tests se passent du réseau. */
    public static ?\Closure $transport = null;

    /**
     * Le schéma est envoyé au modèle et sert aussi de filtre au retour : un champ
     * qui n'y figure pas est ignoré, quoi que le modèle ait décidé d'ajouter.
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fournisseur' => ['type' => ['string', 'null'], 'description' => "Raison sociale de l'émetteur de la facture"],
                'reference' => ['type' => ['string', 'null'], 'description' => 'Numéro de la facture'],
                'date_facture' => ['type' => ['string', 'null'], 'description' => "Date d'émission, au format AAAA-MM-JJ"],
                'date_echeance' => ['type' => ['string', 'null'], 'description' => 'Date limite de paiement, au format AAAA-MM-JJ'],
                'montant_ht' => ['type' => ['number', 'null'], 'description' => 'Total hors taxes'],
                'montant_tva' => ['type' => ['number', 'null'], 'description' => 'Montant total de la TVA'],
                'montant_ttc' => ['type' => ['number', 'null'], 'description' => 'Total toutes taxes comprises'],
                'taux_tva' => ['type' => ['number', 'null'], 'description' => 'Taux de TVA en pourcentage'],
                'devise' => ['type' => ['string', 'null'], 'description' => 'Code ISO de la devise, par exemple EUR'],
                'siret' => ['type' => ['string', 'null'], 'description' => "SIRET ou SIREN de l'émetteur, chiffres uniquement"],
                'numero_tva' => ['type' => ['string', 'null'], 'description' => "Numéro de TVA intracommunautaire de l'émetteur"],
                'iban' => ['type' => ['string', 'null'], 'description' => 'IBAN de règlement'],
                'objet' => ['type' => ['string', 'null'], 'description' => 'Objet de la facture en une ligne'],
            ],
            'required' => ['fournisseur', 'reference', 'date_facture', 'montant_ht', 'montant_tva', 'montant_ttc', 'devise'],
            'additionalProperties' => false,
        ];
    }

    public static function prompt(): string
    {
        return implode("\n", [
            "Tu lis une facture reçue par une entreprise française et tu en extrais les champs demandés.",
            "L'émetteur est le fournisseur, pas le destinataire : ne confonds pas les deux blocs d'adresse.",
            'Les dates sont rendues au format AAAA-MM-JJ. Les montants sont des nombres, sans symbole ni espace, le point comme séparateur décimal.',
            "Un champ que le document ne donne pas vaut null. N'invente aucune valeur, ne complète pas par déduction commerciale.",
            'Réponds uniquement par le JSON demandé.',
        ]);
    }

    // ---------- Configuration ----------

    public static function providerByKey(?string $key): ?array
    {
        foreach (self::PROVIDERS as $provider) {
            if ($provider['key'] === $key) {
                return $provider;
            }
        }
        return null;
    }

    public static function config(): array
    {
        $provider = self::providerByKey(Settings::get(self::KEYS['provider'])) ?? self::PROVIDERS[0];
        $effort = (string) Settings::get(self::KEYS['effort']);

        return [
            'enabled' => Settings::get(self::KEYS['enabled']) === '1',
            'provider' => $provider['key'],
            'model' => (string) (Settings::get(self::KEYS['model']) ?: $provider['defaultModel']),
            'baseUrl' => (string) Settings::get(self::KEYS['baseUrl']),
            'effort' => in_array($effort, self::EFFORTS, true) ? $effort : 'medium',
            'key' => Secrets::decrypt(Settings::get(self::KEYS['key'])),
        ];
    }

    /** Ce qu'on affiche : la clé n'en sort jamais, seulement le fait qu'elle est posée. */
    public static function displayConfig(): array
    {
        $current = self::config();
        $current['key'] = Secrets::mask(Settings::get(self::KEYS['key']));
        return $current;
    }

    public static function setConfig(array $values): array
    {
        $provider = self::providerByKey((string) ($values['provider'] ?? ''));
        if ($provider === null) {
            return ['ok' => false, 'message' => 'Service inconnu.'];
        }

        $model = mb_substr(trim((string) ($values['model'] ?? '')), 0, 120) ?: $provider['defaultModel'];
        $baseUrl = mb_substr(trim((string) ($values['baseUrl'] ?? '')), 0, 300);

        if ($provider['needsBaseUrl'] && $baseUrl !== '') {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
            if ($scheme !== 'http' && $scheme !== 'https') {
                return ['ok' => false, 'message' => 'Adresse du service invalide.'];
            }
        }
        if ($provider['needsBaseUrl'] && $baseUrl === '') {
            return ['ok' => false, 'message' => "Ce service demande l'adresse de son API."];
        }

        $effort = (string) ($values['effort'] ?? '');
        Settings::set(self::KEYS['provider'], $provider['key']);
        Settings::set(self::KEYS['model'], $model);
        Settings::set(self::KEYS['baseUrl'], $baseUrl);
        Settings::set(self::KEYS['effort'], in_array($effort, self::EFFORTS, true) ? $effort : 'medium');

        // Une clé laissée vide conserve la précédente : l'écran ne l'affiche pas,
        // il ne peut donc pas la renvoyer.
        $typed = trim((string) ($values['key'] ?? ''));
        if ($typed !== '') {
            Settings::set(self::KEYS['key'], Secrets::encrypt($typed));
        }

        $enabled = !empty($values['enabled']);
        if ($enabled && self::config()['key'] === '') {
            return ['ok' => false, 'message' => "Renseignez la clé avant d'activer l'analyse."];
        }
        Settings::set(self::KEYS['enabled'], $enabled ? '1' : '0');

        return ['ok' => true];
    }

    public static function isReady(): bool
    {
        $current = self::config();
        return $current['enabled'] && $current['key'] !== '' && $current['model'] !== '';
    }

    public static function status(): ?array
    {
        $stored = (string) Settings::get('ai.status');
        if ($stored === '') {
            return null;
        }
        $decoded = json_decode($stored, true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function recordStatus(array $result): void
    {
        Settings::set('ai.status', (string) json_encode([
            'ok' => !empty($result['ok']),
            'message' => mb_substr((string) ($result['message'] ?? ''), 0, 300),
            'at' => gmdate('c'),
            'model' => (string) ($result['model'] ?? ''),
        ], JSON_UNESCAPED_UNICODE));
    }

    // ---------- Normalisation de la réponse ----------

    private static function clean(mixed $value, int $max = 200): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = mb_substr(trim((string) $value), 0, $max);
        return $text === '' ? null : $text;
    }

    private static function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = is_int($value) || is_float($value) ? round((float) $value, 2) : InvoiceScan::parseNumber($value);
        return $parsed === null || !is_finite($parsed) ? null : round($parsed, 2);
    }

    /**
     * Ce que le modèle rend est traité comme une saisie d'utilisateur : filtré par
     * le schéma, normalisé, et jamais repris tel quel.
     */
    public static function normalise(mixed $raw): array
    {
        $data = is_array($raw) ? $raw : [];
        $digits = static fn (?string $value): ?string => $value === null
            ? null : ((preg_replace('/\D/', '', $value) ?: null));
        $squeeze = static fn (?string $value): ?string => $value === null
            ? null : (strtoupper(preg_replace('/\s/', '', $value) ?? '') ?: null);

        $currency = self::clean($data['devise'] ?? null, 8);

        return [
            'supplierName' => self::clean($data['fournisseur'] ?? null),
            'reference' => self::clean($data['reference'] ?? null, 60),
            'issueDate' => InvoiceScan::parseDate(self::clean($data['date_facture'] ?? null, 40)),
            'dueDate' => InvoiceScan::parseDate(self::clean($data['date_echeance'] ?? null, 40)),
            'amountHt' => self::number($data['montant_ht'] ?? null),
            'amountVat' => self::number($data['montant_tva'] ?? null),
            'amountTtc' => self::number($data['montant_ttc'] ?? null),
            'vatRate' => self::number($data['taux_tva'] ?? null),
            'currency' => $currency === null ? null : (substr(strtoupper($currency), 0, 3) ?: null),
            'siret' => $digits(self::clean($data['siret'] ?? null, 20)),
            'vatNumber' => $squeeze(self::clean($data['numero_tva'] ?? null, 20)),
            'iban' => $squeeze(self::clean($data['iban'] ?? null, 40)),
            'label' => self::clean($data['objet'] ?? null, 160),
        ];
    }

    // ---------- Appels ----------

    /** Une requête HTTP, injectable : les tests n'ouvrent aucune connexion. */
    private static function http(string $url, array $headers, string $body): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($url, $headers, $body);
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            return ['ok' => false, 'status' => 0, 'body' => $error];
        }
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string) $response];
    }

    /** Le texte d'une réponse Anthropic : les blocs de texte, mis bout à bout. */
    private static function textOf(array $payload): string
    {
        $parts = [];
        foreach ($payload['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $parts[] = (string) ($block['text'] ?? '');
            }
        }
        return implode("\n", $parts);
    }

    /** Le JSON d'une réponse, même enrobé de texte : le modèle bavarde parfois. */
    private static function decodeJson(string $text): ?array
    {
        $decoded = json_decode(trim($text), true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($decoded) ? $decoded : null;
    }

    /** API Claude, appelée directement : sortie contrainte par le schéma. */
    private static function callAnthropic(string $text, array $current): array
    {
        $response = self::http(
            self::ANTHROPIC_URL,
            [
                'content-type: application/json',
                'x-api-key: ' . $current['key'],
                'anthropic-version: ' . self::ANTHROPIC_VERSION,
            ],
            (string) json_encode([
                'model' => $current['model'],
                'max_tokens' => self::MAX_TOKENS,
                'system' => self::prompt() . "\n\nSchéma attendu :\n" . json_encode(self::schema()),
                'messages' => [['role' => 'user', 'content' => $text]],
            ], JSON_UNESCAPED_UNICODE)
        );

        if (!$response['ok']) {
            return $response['status'] === 0
                ? ['ok' => false, 'message' => 'Service indisponible : ' . mb_substr($response['body'], 0, 200)]
                : ['ok' => false, 'message' => "Service en erreur ({$response['status']}) : " . mb_substr($response['body'], 0, 200)];
        }

        $payload = json_decode($response['body'], true);
        if (!is_array($payload)) {
            return ['ok' => false, 'message' => 'Réponse illisible du modèle.'];
        }
        // Un refus est un cas normal, pas une panne : la lecture par règles reste
        // acquise, et c'est elle qui sert de repli.
        if (($payload['stop_reason'] ?? '') === 'refusal') {
            return ['ok' => false, 'message' => 'Le modèle a refusé de traiter ce document.'];
        }

        $parsed = self::decodeJson(self::textOf($payload));
        if ($parsed === null) {
            return ['ok' => false, 'message' => 'Réponse illisible du modèle.'];
        }
        return ['ok' => true, 'fields' => self::normalise($parsed), 'model' => (string) ($payload['model'] ?? $current['model'])];
    }

    /** Tout service compatible OpenAI, y compris auto-hébergé. */
    private static function callOpenAiCompatible(string $text, array $current): array
    {
        $url = rtrim($current['baseUrl'], '/') . '/chat/completions';
        $response = self::http(
            $url,
            ['content-type: application/json', 'authorization: Bearer ' . $current['key']],
            (string) json_encode([
                'model' => $current['model'],
                'temperature' => 0,
                'max_tokens' => self::MAX_TOKENS,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => self::prompt() . "\n\nSchéma attendu :\n" . json_encode(self::schema())],
                    ['role' => 'user', 'content' => $text],
                ],
            ], JSON_UNESCAPED_UNICODE)
        );

        if (!$response['ok']) {
            return $response['status'] === 0
                ? ['ok' => false, 'message' => 'Service indisponible : ' . mb_substr($response['body'], 0, 200)]
                : ['ok' => false, 'message' => "Service en erreur ({$response['status']}) : " . mb_substr($response['body'], 0, 200)];
        }

        $payload = json_decode($response['body'], true);
        $content = $payload['choices'][0]['message']['content'] ?? null;
        $parsed = is_string($content) ? self::decodeJson($content) : null;
        if ($parsed === null) {
            return ['ok' => false, 'message' => 'Réponse illisible du modèle.'];
        }
        return ['ok' => true, 'fields' => self::normalise($parsed), 'model' => (string) ($payload['model'] ?? $current['model'])];
    }

    /**
     * Analyse un texte de facture. Ne lève jamais : un service injoignable rend un
     * échec, et l'appelant garde la lecture par règles.
     */
    public static function analyse(?string $text): array
    {
        if (!self::isReady()) {
            return ['ok' => false, 'message' => "L'analyse par modèle n'est pas activée."];
        }

        $current = self::config();
        $content = mb_substr((string) $text, 0, self::MAX_CHARS);
        if (trim($content) === '') {
            return ['ok' => false, 'message' => 'Document sans texte lisible : rien à envoyer.'];
        }

        $result = $current['provider'] === 'anthropic'
            ? self::callAnthropic($content, $current)
            : self::callOpenAiCompatible($content, $current);

        $result['provider'] = $current['provider'];
        $result['truncated'] = mb_strlen((string) $text) > self::MAX_CHARS;
        return $result;
    }

    /** Essai sur une facture d'exemple : le seul moyen de savoir que la clé passe. */
    public static function test(): array
    {
        $sample = implode("\n", [
            'PAPETERIE DU CENTRE SARL',
            'SIRET 732 829 320 00074',
            'FACTURE N° A-2026-88',
            'Date de facture : 04/02/2026',
            'Total HT 200,00 €',
            'TVA 20 % 40,00 €',
            'Net à payer 240,00 €',
        ]);

        $result = self::analyse($sample);
        if (empty($result['ok'])) {
            self::recordStatus($result);
            return $result;
        }

        $found = ($result['fields']['amountTtc'] ?? null) === 240.0
            && ($result['fields']['reference'] ?? null) === 'A-2026-88';
        $verdict = [
            'ok' => $found,
            'model' => $result['model'] ?? '',
            'message' => $found
                ? "Lecture correcte de la facture d'essai ({$result['model']})."
                : "Le service a répondu, mais la facture d'essai est mal lue : "
                  . mb_substr((string) json_encode($result['fields'], JSON_UNESCAPED_UNICODE), 0, 200),
        ];
        self::recordStatus($verdict);
        return $verdict;
    }
}
