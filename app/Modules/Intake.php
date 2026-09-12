<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Config;
use App\Core\Db;
use App\Core\FileType;

/**
 * Réception des pièces comptables.
 *
 * Une facture arrive de deux façons : quelqu'un la dépose, ou elle tombe dans
 * la boîte aux lettres de la comptabilité. Dans les deux cas elle suit le même
 * chemin : le fichier est conservé tel quel, son texte est lu, l'analyse est
 * rangée à côté, et **le comptable tranche**. Rien n'entre en comptabilité sans
 * un clic — une lecture automatique qui écrit directement dans les comptes est
 * une erreur qu'on découvre au bilan.
 *
 * L'empreinte du fichier fait office de garde-fou : la même facture déposée à
 * la main puis reçue par courriel ne fait qu'une seule ligne.
 */
final class Intake
{
    public const MAX_BYTES = 15 * 1024 * 1024;

    public const ACCEPTED = [
        'application/pdf' => '.pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
        'text/plain' => '.txt',
    ];

    public const STATUSES = ['À traiter', 'Facturée', 'Écartée'];

    public static function directory(): string
    {
        $dir = (string) Config::get('docs_dir', '');
        if ($dir === '') {
            $dir = (string) Config::get('data_dir', dirname((string) Config::get('db_path'))) . '/pieces';
        }
        return $dir;
    }

    private static function ensureDir(): string
    {
        $dir = self::directory();
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        return $dir;
    }

    public static function fingerprint(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    // ---------- Fusion des deux lectures ----------

    private static function coherentTriplet(?float $ht, ?float $vat, ?float $ttc): bool
    {
        return $ht !== null && $vat !== null && $ttc !== null
            && abs(round($ht + $vat, 2) - $ttc) <= 0.02;
    }

    /**
     * Les règles et le modèle ne se valent pas champ par champ :
     *
     *   — un SIRET ou un IBAN trouvés par les règles ont passé leur **clé de
     *     contrôle**. Ils sont vrais, et rien ne les remplace ;
     *   — les montants se jugent en bloc, sur leur cohérence : le triplet où
     *     HT + TVA = TTC l'emporte, quel qu'en soit l'auteur ;
     *   — pour le reste — nom du fournisseur, numéro, dates — le modèle comble ce
     *     que les règles n'ont pas trouvé, sans écraser ce qu'elles ont trouvé.
     *
     * Chaque champ garde la trace de son origine : l'écran l'affiche, et le
     * comptable sait ce qu'il valide.
     */
    public static function merge(array $rules, ?array $aiFields): array
    {
        if ($aiFields === null) {
            return [
                'fields' => $rules['fields'],
                'sources' => [],
                'coherent' => $rules['coherent'],
                'confidence' => $rules['confidence'],
            ];
        }

        $fields = $rules['fields'];
        $sources = [];
        $take = static function (string $name, mixed $value) use (&$fields, &$sources): void {
            if ($value === null || $value === '') {
                return;
            }
            $fields[$name] = $value;
            $sources[$name] = 'ia';
        };

        // Identifiants : les règles gagnent quand elles ont trouvé, parce qu'elles
        // ont vérifié. Sinon on accepte celui du modèle, après la même vérification.
        if (empty($fields['siret']) && !empty($aiFields['siret'])) {
            $candidate = (string) $aiFields['siret'];
            if (strlen($candidate) === 14 && InvoiceScan::luhnValid($candidate)) {
                $take('siret', $candidate);
            } elseif (strlen($candidate) === 9 && InvoiceScan::luhnValid($candidate)) {
                $take('siren', $candidate);
            }
        }
        if (empty($fields['iban']) && !empty($aiFields['iban']) && InvoiceScan::ibanValid((string) $aiFields['iban'])) {
            $take('iban', $aiFields['iban']);
        }
        if (empty($fields['vatNumber']) && !empty($aiFields['vatNumber'])) {
            $take('vatNumber', $aiFields['vatNumber']);
        }

        foreach (['reference', 'issueDate', 'dueDate', 'currency', 'supplierName'] as $name) {
            if (empty($fields[$name]) && !empty($aiFields[$name])) {
                $take($name, $aiFields[$name]);
            }
        }
        if (!empty($aiFields['label'])) {
            $take('label', $aiFields['label']);
        }

        // Montants : on garde le triplet cohérent.
        $rulesCoherent = self::coherentTriplet($fields['amountHt'] ?? null, $fields['amountVat'] ?? null, $fields['amountTtc'] ?? null);
        $aiCoherent = self::coherentTriplet($aiFields['amountHt'] ?? null, $aiFields['amountVat'] ?? null, $aiFields['amountTtc'] ?? null);

        if (!$rulesCoherent && $aiCoherent) {
            $take('amountHt', $aiFields['amountHt']);
            $take('amountVat', $aiFields['amountVat']);
            $take('amountTtc', $aiFields['amountTtc']);
            if (($aiFields['vatRate'] ?? null) !== null) {
                $take('vatRate', $aiFields['vatRate']);
            }
        } elseif (!$rulesCoherent) {
            foreach (['amountHt', 'amountVat', 'amountTtc', 'vatRate'] as $name) {
                if (($fields[$name] ?? null) === null) {
                    $take($name, $aiFields[$name] ?? null);
                }
            }
        }

        // Le rapprochement se rejoue sur les identifiants fusionnés : un SIRET
        // apporté par le modèle peut désigner un tiers déjà connu.
        if (empty($fields['partnerId'])) {
            $match = InvoiceScan::matchPartner(
                ($fields['supplierName'] ?? '') . ' ' . ($fields['siret'] ?? '') . ' ' . ($fields['vatNumber'] ?? ''),
                [
                    'sirets' => !empty($fields['siret']) ? [(string) $fields['siret']] : [],
                    'sirens' => !empty($fields['siren']) ? [(string) $fields['siren']] : [],
                    'vats' => !empty($fields['vatNumber']) ? [(string) $fields['vatNumber']] : [],
                ]
            );
            if ($match !== null) {
                $fields['partnerId'] = (int) $match['partner']['id'];
                $fields['partnerReason'] = $match['reason'];
                $sources['partnerId'] = 'ia';
            }
        }

        $merged = self::coherentTriplet($fields['amountHt'] ?? null, $fields['amountVat'] ?? null, $fields['amountTtc'] ?? null);
        $bonus = $sources === [] ? 0 : 10;

        return [
            'fields' => $fields,
            'sources' => $sources,
            'coherent' => $merged,
            'confidence' => max(0, min(100, $rules['confidence'] + $bonus + ($merged && !$rulesCoherent ? 15 : 0))),
        ];
    }

    /**
     * L'extracteur PDF ajoute parfois un repère de pagination (« -- 1 of 3 -- »)
     * qui n'appartient pas au document : compté comme du texte, il ferait passer
     * un scan sans aucun contenu pour une page lisible.
     */
    public static function usefulText(?string $raw): string
    {
        return trim(preg_replace('/^\s*--\s*\d+\s+of\s+\d+\s*--\s*$/m', '', (string) $raw) ?? '');
    }

    /** Lit un document et rend son analyse, sans rien écrire. */
    public static function examine(string $bytes, string $mimeType, array $options = []): array
    {
        $fileName = (string) ($options['fileName'] ?? '');
        $text = self::usefulText(Cv::extractText($bytes, $mimeType));

        $rules = InvoiceScan::analyse($text, ['fileName' => $fileName]);
        $analysis = $rules;
        $analysis['sources'] = [];

        if (Ai::isReady() && trim($text) !== '') {
            $aiResult = Ai::analyse($text);
            if (!empty($aiResult['ok'])) {
                $merged = self::merge($rules, $aiResult['fields']);
                $analysis = array_merge($rules, $merged, [
                    'source' => 'règles + modèle',
                    'model' => $aiResult['model'] ?? '',
                ]);
            } else {
                $analysis['aiError'] = $aiResult['message'] ?? '';
            }
        }

        return ['analysis' => $analysis, 'text' => $text];
    }

    // ---------- Réception ----------

    /**
     * Enregistre une pièce reçue. Rend ['ok' => false, 'duplicate' => id] si le
     * même fichier est déjà là : deux exemplaires d'une facture ne sont pas deux
     * factures.
     */
    public static function receive(array $input): array
    {
        $bytes = (string) ($input['bytes'] ?? '');
        $mimeType = (string) ($input['mimeType'] ?? '');
        $originalName = (string) ($input['originalName'] ?? '');
        $source = (string) ($input['source'] ?? 'Dépôt');
        $mail = $input['mail'] ?? [];

        if ($bytes === '') {
            return ['ok' => false, 'message' => 'Fichier vide.'];
        }
        if (!isset(self::ACCEPTED[$mimeType])) {
            return ['ok' => false, 'message' => 'Seuls les PDF, DOCX et fichiers texte sont acceptés.'];
        }
        if ($mimeType !== 'text/plain' && !FileType::matches($bytes, $mimeType)) {
            return ['ok' => false, 'message' => "Ce fichier n'est pas du type annoncé : dépôt refusé."];
        }

        $hash = self::fingerprint($bytes);
        $existing = Db::get('SELECT id FROM incoming_documents WHERE sha256 = ?', [$hash]);
        if ($existing !== null) {
            return ['ok' => false, 'message' => 'Cette pièce est déjà arrivée.', 'duplicate' => (int) $existing['id']];
        }

        ['analysis' => $analysis, 'text' => $text] = self::examine($bytes, $mimeType, ['fileName' => $originalName]);

        $dir = self::ensureDir();
        $name = bin2hex(random_bytes(16)) . (self::ACCEPTED[$mimeType] ?? '.bin');
        file_put_contents($dir . '/' . $name, $bytes);
        @chmod($dir . '/' . $name, 0600);

        $id = Db::insert(
            'INSERT INTO incoming_documents (source, file_name, original_name, mime_type, byte_size, sha256,
                                             mail_uid, mail_from, mail_subject, mail_date,
                                             text_length, analysis, confidence, partner_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $source, $name, mb_substr($originalName, 0, 200), $mimeType, strlen($bytes), $hash,
                mb_substr((string) ($mail['uid'] ?? ''), 0, 40),
                mb_substr((string) ($mail['from'] ?? ''), 0, 200),
                mb_substr((string) ($mail['subject'] ?? ''), 0, 300),
                $mail['date'] ?? null,
                strlen($text), (string) json_encode($analysis, JSON_UNESCAPED_UNICODE),
                $analysis['confidence'], $analysis['fields']['partnerId'] ?? null,
                $input['createdBy'] ?? null,
            ]
        );

        return ['ok' => true, 'id' => $id, 'analysis' => $analysis];
    }

    /** Rejoue l'analyse sur une pièce déjà reçue — après avoir activé le modèle, par exemple. */
    public static function reanalyse(int $id): array
    {
        $document = self::byId($id);
        if ($document === null) {
            return ['ok' => false, 'message' => 'Pièce introuvable.'];
        }

        $target = self::pathOf($document);
        if (!is_file($target)) {
            return ['ok' => false, 'message' => 'Fichier absent du serveur.'];
        }

        ['analysis' => $analysis, 'text' => $text] = self::examine(
            (string) file_get_contents($target),
            (string) $document['mime_type'],
            ['fileName' => (string) $document['original_name']]
        );

        Db::run(
            'UPDATE incoming_documents SET analysis = ?, confidence = ?, partner_id = ?, text_length = ? WHERE id = ?',
            [
                (string) json_encode($analysis, JSON_UNESCAPED_UNICODE), $analysis['confidence'],
                $analysis['fields']['partnerId'] ?? null, strlen($text), (int) $document['id'],
            ]
        );

        return ['ok' => true, 'analysis' => $analysis];
    }

    // ---------- Lectures ----------

    private static function decorate(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $analysis = json_decode((string) $row['analysis'], true);
        $analysis = is_array($analysis) ? $analysis : [];

        $row['analysis'] = $analysis;
        $row['fields'] = $analysis['fields'] ?? [];
        $row['sources'] = $analysis['sources'] ?? [];
        $row['notes'] = $analysis['notes'] ?? [];
        return $row;
    }

    public static function byId(int $id): ?array
    {
        return self::decorate(Db::get(
            'SELECT d.*, p.name AS partner_name, i.reference AS invoice_reference
             FROM incoming_documents d
             LEFT JOIN partners p ON p.id = d.partner_id
             LEFT JOIN invoices i ON i.id = d.invoice_id
             WHERE d.id = ?',
            [$id]
        ));
    }

    public static function all(array $filters = []): array
    {
        $status = $filters['status'] ?? null;
        $limit = (int) ($filters['limit'] ?? 200);
        $clause = $status !== null ? 'WHERE d.status = ?' : '';
        $params = $status !== null ? [$status, $limit] : [$limit];

        return array_map([self::class, 'decorate'], Db::all(
            "SELECT d.*, p.name AS partner_name, i.reference AS invoice_reference
             FROM incoming_documents d
             LEFT JOIN partners p ON p.id = d.partner_id
             LEFT JOIN invoices i ON i.id = d.invoice_id
             $clause
             ORDER BY d.received_at DESC, d.id DESC LIMIT ?",
            $params
        ));
    }

    public static function pathOf(array $document): string
    {
        return self::directory() . '/' . basename((string) $document['file_name']);
    }

    /** L'empreinte est revérifiée avant de servir le fichier, comme au coffre-fort. */
    public static function verify(array $document): array
    {
        $target = self::pathOf($document);
        if (!is_file($target)) {
            return ['ok' => false, 'reason' => 'fichier absent'];
        }
        $actual = self::fingerprint((string) file_get_contents($target));
        return $actual === $document['sha256'] ? ['ok' => true] : ['ok' => false, 'reason' => 'empreinte différente'];
    }

    public static function setStatus(int $id, string $status, array $options = []): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            return ['ok' => false, 'message' => 'Statut inconnu.'];
        }

        Db::run(
            "UPDATE incoming_documents SET status = ?, note = ?, invoice_id = COALESCE(?, invoice_id),
                    handled_by = ?, handled_at = datetime('now') WHERE id = ?",
            [
                $status, mb_substr((string) ($options['note'] ?? ''), 0, 500),
                $options['invoiceId'] ?? null, $options['userId'] ?? null, $id,
            ]
        );
        return ['ok' => true];
    }

    public static function attachPartner(int $id, ?int $partnerId): void
    {
        Db::run('UPDATE incoming_documents SET partner_id = ? WHERE id = ?', [$partnerId ?: null, $id]);
    }

    /** Une pièce déjà facturée ne se supprime pas : elle est la pièce justificative. */
    public static function remove(int $id): array
    {
        $document = self::byId($id);
        if ($document === null) {
            return ['ok' => false, 'message' => 'Pièce introuvable.'];
        }
        if ($document['status'] === 'Facturée') {
            return ['ok' => false, 'message' => 'Cette pièce justifie une facture : elle se conserve.'];
        }

        $target = self::pathOf($document);
        if (is_file($target)) {
            unlink($target);
        }
        Db::run('DELETE FROM incoming_documents WHERE id = ?', [(int) $document['id']]);
        return ['ok' => true];
    }

    public static function summary(): array
    {
        $byStatus = [];
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM incoming_documents GROUP BY status') as $row) {
            $byStatus[$row['status']] = (int) $row['n'];
        }

        return [
            'waiting' => $byStatus['À traiter'] ?? 0,
            'invoiced' => $byStatus['Facturée'] ?? 0,
            'discarded' => $byStatus['Écartée'] ?? 0,
            'unreadable' => (int) Db::value("SELECT COUNT(*) FROM incoming_documents WHERE status = 'À traiter' AND text_length = 0"),
            'lowConfidence' => (int) Db::value("SELECT COUNT(*) FROM incoming_documents WHERE status = 'À traiter' AND confidence < 40"),
        ];
    }
}
