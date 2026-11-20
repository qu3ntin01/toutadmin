<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Config;
use App\Core\Db;
use App\Core\FileType;

/**
 * Coffre-fort numérique.
 *
 * Un bulletin de paie dématérialisé doit rester accessible au salarié bien
 * après son départ — cinquante ans, dans le cas général. Trois conséquences,
 * qui commandent tout ce module :
 *
 *   1. le document ne se modifie pas : il porte l'empreinte SHA-256 de son
 *      contenu, vérifiée à chaque téléchargement ;
 *   2. il ne se supprime pas à la légère : un retrait laisse la ligne, son
 *      auteur et son motif ;
 *   3. la personne partie doit pouvoir entrer, alors que son compte est fermé
 *      et qu'elle aura oublié son mot de passe (voir les codes d'accès).
 */
final class Vault
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const ACCEPTED = [
        'application/pdf' => '.pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
        'image/png' => '.png',
        'image/jpeg' => '.jpg',
    ];

    public const CATEGORIES = [
        'Bulletin de paie',
        'Contrat de travail',
        'Avenant',
        'Certificat de travail',
        'Attestation employeur',
        'Solde de tout compte',
        'Autre document',
    ];

    // Durée pendant laquelle un bulletin dématérialisé doit rester accessible.
    public const RETENTION_YEARS = 50;

    // Un code d'accès reste valable ce nombre de jours, sauf choix contraire.
    public const DEFAULT_GRANT_DAYS = 90;
    public const MAX_GRANT_DAYS = 3650;

    public static function directory(): string
    {
        $dir = (string) Config::get('vault_dir', '');
        if ($dir === '') {
            // À côté de la base, donc hors de la racine web : aucune requête ne
            // peut aller chercher un bulletin de paie directement.
            $dir = (string) Config::get('data_dir', dirname((string) Config::get('db_path'))) . '/coffre';
        }
        return $dir;
    }

    private static function ensureDir(): void
    {
        $dir = self::directory();
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
    }

    public static function fingerprint(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    public static function retentionFrom(string $isoDate): string
    {
        return gmdate('Y-m-d', strtotime($isoDate . ' +' . self::RETENTION_YEARS . ' years'));
    }

    /**
     * Dépose un document. Rend ['ok' => …] plutôt qu'une exception : un dépôt
     * refusé est une erreur d'usage, pas une panne.
     */
    public static function deposit(array $data): array
    {
        $file = $data['file'] ?? null;
        if (!is_array($file) || ($file['bytes'] ?? '') === '') {
            return ['ok' => false, 'message' => 'Aucun fichier reçu.'];
        }
        if (!in_array($data['category'], self::CATEGORIES, true)) {
            return ['ok' => false, 'message' => 'Catégorie invalide.'];
        }
        if (!isset(self::ACCEPTED[$file['mime']])) {
            return ['ok' => false, 'message' => 'Format non pris en charge : PDF, DOCX, PNG ou JPEG.'];
        }
        if (strlen($file['bytes']) > self::MAX_BYTES) {
            return ['ok' => false, 'message' => 'Document trop volumineux : 10 Mo maximum.'];
        }
        if (!FileType::matches($file['bytes'], $file['mime'])) {
            return ['ok' => false, 'message' => "Ce fichier n'est pas du type annoncé : dépôt refusé."];
        }

        $hash = self::fingerprint($file['bytes']);
        $userId = (int) $data['userId'];

        // Le même document déposé deux fois pour la même personne n'a pas à
        // occuper deux places : on rend l'existant plutôt que d'empiler.
        $existing = Db::get(
            'SELECT id FROM vault_documents WHERE user_id = ? AND sha256 = ? AND removed_at IS NULL',
            [$userId, $hash]
        );
        if ($existing !== null) {
            return [
                'ok' => false,
                'message' => 'Ce document est déjà au coffre de cette personne.',
                'duplicate' => (int) $existing['id'],
            ];
        }

        self::ensureDir();
        $name = bin2hex(random_bytes(16)) . self::ACCEPTED[$file['mime']];
        $target = self::directory() . '/' . $name;
        file_put_contents($target, $file['bytes']);
        chmod($target, 0600);

        $id = Db::insert(
            'INSERT INTO vault_documents (user_id, category, title, period, payslip_id, file_name, original_name,
                                          mime_type, byte_size, sha256, deposited_by, retention_until)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId, $data['category'], $data['title'], $data['period'] ?? '', $data['payslipId'] ?? null,
                $name, mb_substr((string) ($file['name'] ?? ''), 0, 200), $file['mime'], strlen($file['bytes']),
                $hash, $data['depositedBy'] ?? null, self::retentionFrom(gmdate('Y-m-d')),
            ]
        );
        return ['ok' => true, 'id' => $id, 'sha256' => $hash];
    }

    public static function byId(int $id): ?array
    {
        return Db::get('SELECT * FROM vault_documents WHERE id = ?', [$id]);
    }

    public static function documentsFor(int $userId, bool $includeRemoved = false): array
    {
        $clause = $includeRemoved ? '' : 'AND removed_at IS NULL';
        return Db::all(
            "SELECT d.*, u.first_name AS deposited_first_name, u.last_name AS deposited_last_name
             FROM vault_documents d LEFT JOIN users u ON u.id = d.deposited_by
             WHERE d.user_id = ? $clause
             ORDER BY d.period DESC, d.deposited_at DESC, d.id DESC",
            [$userId]
        );
    }

    public static function pathOf(array $document): string
    {
        return self::directory() . '/' . basename((string) $document['file_name']);
    }

    /**
     * Recalcule l'empreinte du fichier et la compare à celle enregistrée. Un
     * document dont le contenu a bougé n'est pas servi : c'est tout l'intérêt.
     */
    public static function verify(array $document): array
    {
        $target = self::pathOf($document);
        if (!is_file($target)) {
            return ['ok' => false, 'reason' => 'fichier absent'];
        }
        $actual = self::fingerprint((string) file_get_contents($target));
        if (!hash_equals((string) $document['sha256'], $actual)) {
            return ['ok' => false, 'reason' => 'empreinte différente', 'actual' => $actual];
        }
        return ['ok' => true];
    }

    /** Contrôle d'intégrité de tout le coffre, pour la console RH. */
    public static function integrityAudit(): array
    {
        $rows = Db::all('SELECT * FROM vault_documents WHERE removed_at IS NULL');
        $broken = array_values(array_filter($rows, static fn (array $row): bool => !self::verify($row)['ok']));
        return ['total' => count($rows), 'broken' => $broken];
    }

    /**
     * Retrait d'un document. La ligne reste, avec son auteur et son motif :
     * c'est la trace du retrait qui compte autant que le document.
     */
    public static function remove(int $id, ?int $removedBy, string $reason): array
    {
        $document = self::byId($id);
        if ($document === null || !empty($document['removed_at'])) {
            return ['ok' => false, 'message' => 'Document introuvable.'];
        }
        if (mb_strlen(trim($reason)) < 5) {
            return ['ok' => false, 'message' => 'Un retrait demande un motif explicite.'];
        }

        $target = self::pathOf($document);
        if (is_file($target)) {
            unlink($target);
        }
        Db::run(
            "UPDATE vault_documents SET removed_at = datetime('now'), removed_by = ?, removal_reason = ? WHERE id = ?",
            [$removedBy, mb_substr(trim($reason), 0, 300), $id]
        );
        return ['ok' => true];
    }

    // ---------- Codes d'accès après le départ ----------

    private static function hashCode(string $code): string
    {
        $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper($code)) ?? '';
        return hash('sha256', $normalized);
    }

    /** Un code lisible à voix haute, sans caractères qui se confondent. */
    public static function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $groups = [];
        for ($g = 0; $g < 3; $g++) {
            $chunk = '';
            for ($i = 0; $i < 5; $i++) {
                $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $groups[] = $chunk;
        }
        return implode('-', $groups);
    }

    /** Émettre un code révoque le précédent : un seul vaut à la fois. */
    public static function issueGrant(int $userId, int $days = self::DEFAULT_GRANT_DAYS, ?int $createdBy = null): array
    {
        $span = min(self::MAX_GRANT_DAYS, max(1, $days));
        $expires = gmdate('c', time() + $span * 86400);
        $code = self::generateCode();

        Db::transaction(static function () use ($userId, $code, $createdBy, $expires): void {
            Db::run(
                "UPDATE vault_access_grants SET revoked_at = datetime('now') WHERE user_id = ? AND revoked_at IS NULL",
                [$userId]
            );
            Db::insert(
                'INSERT INTO vault_access_grants (user_id, code_hash, created_by, expires_at) VALUES (?, ?, ?, ?)',
                [$userId, self::hashCode($code), $createdBy, $expires]
            );
        });
        return ['code' => $code, 'expiresAt' => $expires];
    }

    public static function grantsFor(int $userId): array
    {
        return Db::all('SELECT * FROM vault_access_grants WHERE user_id = ? ORDER BY id DESC', [$userId]);
    }

    public static function activeGrant(int $userId): ?array
    {
        return Db::get(
            'SELECT * FROM vault_access_grants
             WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ?
             ORDER BY id DESC LIMIT 1',
            [$userId, gmdate('c')]
        );
    }

    public static function revokeGrants(int $userId): int
    {
        return Db::run(
            "UPDATE vault_access_grants SET revoked_at = datetime('now') WHERE user_id = ? AND revoked_at IS NULL",
            [$userId]
        );
    }

    /**
     * Échange un couple adresse + code contre l'identité correspondante.
     *
     * Les deux sont exigés ensemble et la réponse est la même dans tous les cas
     * d'échec : ni l'existence du compte, ni celle du code ne se déduisent d'ici.
     */
    public static function redeem(string $email, string $code): ?array
    {
        $user = Db::get('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
        if ($user === null) {
            return null;
        }
        $grant = self::activeGrant((int) $user['id']);
        if ($grant === null) {
            return null;
        }
        if (!hash_equals((string) $grant['code_hash'], self::hashCode($code))) {
            return null;
        }
        Db::run(
            "UPDATE vault_access_grants SET last_used_at = datetime('now'), uses = uses + 1 WHERE id = ?",
            [$grant['id']]
        );
        return $user;
    }

    /** Une personne a un coffre dès lors qu'un document y a été déposé pour elle. */
    public static function hasDocuments(int $userId): bool
    {
        return Db::get('SELECT 1 AS ok FROM vault_documents WHERE user_id = ? AND removed_at IS NULL', [$userId]) !== null;
    }

    public static function summary(): array
    {
        return [
            'documents' => (int) Db::value('SELECT COUNT(*) FROM vault_documents WHERE removed_at IS NULL'),
            'holders' => (int) Db::value('SELECT COUNT(DISTINCT user_id) FROM vault_documents WHERE removed_at IS NULL'),
            'departed' => (int) Db::value(
                'SELECT COUNT(DISTINCT d.user_id) FROM vault_documents d JOIN users u ON u.id = d.user_id
                 WHERE d.removed_at IS NULL AND u.active = 0'
            ),
            'grants' => (int) Db::value(
                'SELECT COUNT(*) FROM vault_access_grants WHERE revoked_at IS NULL AND expires_at > ?',
                [gmdate('c')]
            ),
        ];
    }
}
