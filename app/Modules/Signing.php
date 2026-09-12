<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Config;
use App\Core\Db;
use App\Core\FileType;

/**
 * Parapheur : signature électronique interne.
 *
 * Ce que le module garantit, et qui suffit à un contrat de travail, un avenant
 * ou un accord interne :
 *
 *   — le document est **figé** dès la mise à la signature. Son empreinte
 *     SHA-256 est calculée là, revérifiée avant chaque signature et à chaque
 *     téléchargement. Signer un document qui peut changer ensuite ne
 *     signifierait rien ;
 *   — chaque signature porte **qui, quand, depuis quelle adresse**, et un
 *     sceau calculé par l'instance (HMAC de l'empreinte du document, du
 *     signataire et de l'horodatage, avec une clé qui vit hors de la base) :
 *     une ligne recopiée à la main dans la base ne passerait pas la
 *     vérification ;
 *   — le signataire **prouve sa présence** en ressaisissant son mot de passe,
 *     et consent explicitement. Une session ouverte sur un poste laissé sans
 *     surveillance ne suffit pas à engager quelqu'un ;
 *   — l'ordre des signataires est **respecté** : le parapheur circule, il ne
 *     s'éparpille pas.
 *
 * Ce que le module ne prétend pas être : une signature électronique
 * **qualifiée** au sens eIDAS. Celle-ci demande un prestataire de confiance
 * certifié et une identification en face-à-face. Ici, la valeur probante
 * repose sur le faisceau — empreinte, sceau, horodatage, journal d'audit —
 * comme pour une signature simple.
 */
final class Signing
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_SIGNERS = 12;

    public const KINDS = ['Contrat de travail', 'Avenant', 'Accord interne', 'Règlement', 'Procès-verbal', 'Document'];
    public const STATUSES = ['En cours', 'Signé', 'Refusé', 'Annulé'];

    public const ACCEPTED = [
        'application/pdf' => '.pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
    ];

    public static function directory(): string
    {
        return (string) Config::get('data_dir', dirname((string) Config::get('db_path'))) . '/parapheur';
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

    /** La clé des sceaux : dérivée du secret de l'instance, qui vit hors de la base. */
    private static function sealKey(): string
    {
        return hash_hkdf('sha256', Config::secret(), 32, 'parapheur');
    }

    public static function computeSeal(string $documentHash, int $userId, string $signedAt): string
    {
        return hash_hmac('sha256', "$documentHash|$userId|$signedAt", self::sealKey());
    }

    // ---------- Lectures ----------

    public static function byId(int $id): ?array
    {
        return Db::get('SELECT * FROM signature_requests WHERE id = ?', [$id]);
    }

    public static function signersOf(int $requestId): array
    {
        return Db::all(
            'SELECT s.*, u.first_name, u.last_name, u.grade, u.email
             FROM signature_signers s JOIN users u ON u.id = s.user_id
             WHERE s.request_id = ? ORDER BY s.position, s.id',
            [$requestId]
        );
    }

    public static function decorate(?array $request): ?array
    {
        if ($request === null) {
            return null;
        }
        $signers = self::signersOf((int) $request['id']);
        $signed = count(array_filter($signers, static fn (array $s): bool => $s['status'] === 'Signé'));
        $next = null;
        foreach ($signers as $signer) {
            if ($signer['status'] === 'En attente') {
                $next = $signer;
                break;
            }
        }

        $request['signers'] = $signers;
        $request['signedCount'] = $signed;
        $request['total'] = count($signers);
        $request['progress'] = $signers === [] ? 0 : (int) round($signed / count($signers) * 100);
        $request['next'] = $next;
        $request['overdue'] = !empty($request['deadline'])
            && $request['status'] === 'En cours'
            && $request['deadline'] < gmdate('Y-m-d');
        return $request;
    }

    public static function list(?string $status = null, int $limit = 200): array
    {
        $clause = $status === null ? '' : 'WHERE r.status = ?';
        $params = $status === null ? [$limit] : [$status, $limit];
        return array_map(
            static fn (array $row): array => (array) self::decorate($row),
            Db::all(
                "SELECT r.*, u.first_name AS author_first, u.last_name AS author_last
                 FROM signature_requests r LEFT JOIN users u ON u.id = r.created_by
                 $clause ORDER BY r.created_at DESC LIMIT ?",
                $params
            )
        );
    }

    /** Les documents que cette personne doit signer maintenant : c'est à son tour. */
    public static function pendingFor(int $userId): array
    {
        return array_values(array_filter(
            self::list('En cours'),
            static fn (array $r): bool => $r['next'] !== null && (int) $r['next']['user_id'] === $userId
        ));
    }

    public static function pendingCountFor(int $userId): int
    {
        return count(self::pendingFor($userId));
    }

    /** Tout ce qui concerne une personne : à signer, signé, refusé. */
    public static function forUser(int $userId): array
    {
        return array_values(array_filter(self::list(), static function (array $r) use ($userId): bool {
            foreach ($r['signers'] as $signer) {
                if ((int) $signer['user_id'] === $userId) {
                    return true;
                }
            }
            return false;
        }));
    }

    public static function isParty(array $request, int $userId): bool
    {
        if ((int) $request['created_by'] === $userId) {
            return true;
        }
        foreach (self::signersOf((int) $request['id']) as $signer) {
            if ((int) $signer['user_id'] === $userId) {
                return true;
            }
        }
        return false;
    }

    // ---------- Création ----------

    /**
     * Met un document à la signature. Le texte ou le fichier, l'un ou l'autre :
     * dans les deux cas l'empreinte porte sur ce qui sera relu, à l'octet près.
     */
    public static function create(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $kind = (string) ($data['kind'] ?? '');
        $body = (string) ($data['body'] ?? '');
        $file = $data['file'] ?? null;
        $signers = $data['signers'] ?? [];

        if (!in_array($kind, self::KINDS, true)) {
            return ['ok' => false, 'message' => 'Nature de document inconnue.'];
        }
        if ($title === '') {
            return ['ok' => false, 'message' => 'Un intitulé est requis.'];
        }
        if ($file === null && trim($body) === '') {
            return ['ok' => false, 'message' => 'Déposez un fichier ou saisissez le texte à signer.'];
        }
        if ($signers === []) {
            return ['ok' => false, 'message' => 'Désignez au moins un signataire.'];
        }
        if (count($signers) > self::MAX_SIGNERS) {
            return ['ok' => false, 'message' => self::MAX_SIGNERS . ' signataires au maximum.'];
        }

        $ids = array_map(static fn (array $s): int => (int) $s['userId'], $signers);
        if (count(array_unique($ids)) !== count($ids)) {
            return ['ok' => false, 'message' => 'Un signataire ne peut pas figurer deux fois.'];
        }
        foreach ($ids as $id) {
            $user = Db::get('SELECT id, active FROM users WHERE id = ?', [$id]);
            if ($user === null || (int) $user['active'] !== 1) {
                return ['ok' => false, 'message' => 'Un signataire désigné est inconnu ou désactivé.'];
            }
        }

        $stored = ['fileName' => '', 'originalName' => '', 'mimeType' => '', 'size' => 0];
        if ($file !== null) {
            if (!isset(self::ACCEPTED[$file['mime']])) {
                return ['ok' => false, 'message' => 'Format non pris en charge : PDF ou DOCX.'];
            }
            if (strlen($file['bytes']) > self::MAX_BYTES) {
                return ['ok' => false, 'message' => 'Document trop volumineux : 10 Mo maximum.'];
            }
            if (!FileType::matches($file['bytes'], $file['mime'])) {
                return ['ok' => false, 'message' => "Ce fichier n'est pas du type annoncé : dépôt refusé."];
            }
            $hash = self::fingerprint($file['bytes']);
            self::ensureDir();
            $name = bin2hex(random_bytes(16)) . self::ACCEPTED[$file['mime']];
            $target = self::directory() . '/' . $name;
            file_put_contents($target, $file['bytes']);
            chmod($target, 0600);
            $stored = [
                'fileName' => $name,
                'originalName' => mb_substr((string) ($file['name'] ?? ''), 0, 200),
                'mimeType' => $file['mime'],
                'size' => strlen($file['bytes']),
            ];
        } else {
            $hash = self::fingerprint($body);
        }

        $id = Db::transaction(static function () use ($title, $kind, $body, $file, $stored, $hash, $data, $signers): int {
            $requestId = Db::insert(
                'INSERT INTO signature_requests (title, kind, body, file_name, original_name, mime_type, byte_size, sha256, deadline, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    mb_substr($title, 0, 200), $kind, $file !== null ? '' : $body,
                    $stored['fileName'], $stored['originalName'], $stored['mimeType'], $stored['size'],
                    $hash, $data['deadline'] ?? null, $data['createdBy'] ?? null,
                ]
            );
            foreach (array_values($signers) as $index => $signer) {
                Db::insert(
                    'INSERT INTO signature_signers (request_id, user_id, position, role_label) VALUES (?, ?, ?, ?)',
                    [$requestId, (int) $signer['userId'], $index + 1, mb_substr((string) ($signer['roleLabel'] ?? ''), 0, 80)]
                );
            }
            return $requestId;
        });
        return ['ok' => true, 'id' => $id, 'sha256' => $hash];
    }

    // ---------- Intégrité ----------

    public static function pathOf(array $request): ?string
    {
        return empty($request['file_name']) ? null : self::directory() . '/' . basename((string) $request['file_name']);
    }

    /** Recalcule l'empreinte de ce qui a été mis à la signature. */
    public static function verifyDocument(array $request): array
    {
        if (empty($request['file_name'])) {
            $actual = self::fingerprint((string) $request['body']);
            return hash_equals((string) $request['sha256'], $actual)
                ? ['ok' => true]
                : ['ok' => false, 'reason' => 'le texte ne correspond plus à son empreinte', 'actual' => $actual];
        }

        $target = (string) self::pathOf($request);
        if (!is_file($target)) {
            return ['ok' => false, 'reason' => 'fichier absent'];
        }
        $actual = self::fingerprint((string) file_get_contents($target));
        return hash_equals((string) $request['sha256'], $actual)
            ? ['ok' => true]
            : ['ok' => false, 'reason' => 'empreinte différente', 'actual' => $actual];
    }

    /** Vérifie chaque sceau : une ligne écrite à la main en base ne passe pas. */
    public static function verifySeals(array $request): array
    {
        $out = [];
        foreach (self::signersOf((int) $request['id']) as $signer) {
            if ($signer['status'] !== 'Signé') {
                continue;
            }
            $expected = self::computeSeal(
                (string) $request['sha256'],
                (int) $signer['user_id'],
                (string) $signer['signed_at']
            );
            $out[] = ['signer' => $signer, 'valid' => hash_equals($expected, (string) $signer['seal'])];
        }
        return $out;
    }

    public static function verify(array|int $requestOrId): array
    {
        $request = is_array($requestOrId) ? $requestOrId : self::byId($requestOrId);
        if ($request === null) {
            return ['ok' => false, 'reason' => 'document introuvable'];
        }
        $document = self::verifyDocument($request);
        $seals = self::verifySeals($request);
        $broken = array_values(array_filter($seals, static fn (array $s): bool => !$s['valid']));

        return [
            'ok' => $document['ok'] && $broken === [],
            'document' => $document,
            'seals' => $seals,
            'broken' => array_map(
                static fn (array $s): string => trim($s['signer']['first_name'] . ' ' . $s['signer']['last_name']),
                $broken
            ),
        ];
    }

    // ---------- Signature ----------

    public static function canSign(?array $request, int $userId): array
    {
        if ($request === null || $request['status'] !== 'En cours') {
            return ['ok' => false, 'message' => "Ce document n'est plus à la signature."];
        }
        $signers = self::signersOf((int) $request['id']);
        $mine = null;
        $waiting = null;
        foreach ($signers as $signer) {
            if ((int) $signer['user_id'] === $userId) {
                $mine = $signer;
            }
            if ($waiting === null && $signer['status'] === 'En attente') {
                $waiting = $signer;
            }
        }
        if ($mine === null) {
            return ['ok' => false, 'message' => 'Vous ne figurez pas parmi les signataires.'];
        }
        if ($mine['status'] !== 'En attente') {
            return ['ok' => false, 'message' => 'Vous avez déjà ' . mb_strtolower($mine['status']) . ' ce document.'];
        }
        // Le parapheur circule : chacun signe à son tour.
        if ($waiting !== null && (int) $waiting['user_id'] !== $userId) {
            return ['ok' => false, 'message' => "C'est au tour de "
                . trim($waiting['first_name'] . ' ' . $waiting['last_name']) . ' de signer.'];
        }
        return ['ok' => true, 'signer' => $mine];
    }

    /**
     * Signe. Le mot de passe est redemandé : une session ouverte sur un poste
     * laissé sans surveillance ne doit pas suffire à engager quelqu'un.
     */
    public static function sign(int $requestId, int $userId, string $password, bool $consent, string $ip = ''): array
    {
        $request = self::byId($requestId);
        $allowed = self::canSign($request, $userId);
        if (!$allowed['ok']) {
            return $allowed;
        }
        if (!$consent) {
            return ['ok' => false, 'message' => 'Cochez la case de consentement pour signer.'];
        }

        $user = Db::get('SELECT id, password_hash, must_change_password FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            return ['ok' => false, 'message' => 'Compte introuvable.'];
        }
        if ((int) $user['must_change_password'] === 1) {
            return ['ok' => false, 'message' => "Choisissez d'abord votre mot de passe définitif."];
        }
        if ($password === '' || !password_verify($password, (string) $user['password_hash'])) {
            return ['ok' => false, 'message' => "Mot de passe incorrect : la signature n'a pas été apposée."];
        }

        // On revérifie le document juste avant d'engager quelqu'un dessus.
        $integrity = self::verifyDocument((array) $request);
        if (!$integrity['ok']) {
            return ['ok' => false, 'message' => 'Document altéré depuis sa mise à la signature ('
                . $integrity['reason'] . ') : signature refusée.'];
        }

        $signedAt = gmdate('c');
        $seal = self::computeSeal((string) $request['sha256'], $userId, $signedAt);

        $remaining = Db::transaction(static function () use ($request, $userId, $signedAt, $seal, $ip): int {
            Db::run(
                "UPDATE signature_signers SET status = 'Signé', signed_at = ?, seal = ?, ip = ?
                 WHERE request_id = ? AND user_id = ? AND status = 'En attente'",
                [$signedAt, $seal, mb_substr($ip, 0, 60), $request['id'], $userId]
            );
            $left = (int) Db::value(
                "SELECT COUNT(*) FROM signature_signers WHERE request_id = ? AND status = 'En attente'",
                [$request['id']]
            );
            if ($left === 0) {
                Db::run(
                    "UPDATE signature_requests SET status = 'Signé', completed_at = ? WHERE id = ?",
                    [$signedAt, $request['id']]
                );
            }
            return $left;
        });

        if ($remaining === 0) {
            Webhooks::emit('document.signe', [
                'id' => (int) $request['id'], 'titre' => $request['title'], 'nature' => $request['kind'],
                'empreinte' => $request['sha256'],
                'signataires' => array_map(
                    static fn (array $s): string => trim($s['first_name'] . ' ' . $s['last_name']),
                    self::signersOf((int) $request['id'])
                ),
            ]);
        }
        return ['ok' => true, 'seal' => $seal, 'signedAt' => $signedAt, 'remaining' => $remaining, 'completed' => $remaining === 0];
    }

    /** Refuser interrompt le circuit : les suivants n'ont plus à se prononcer. */
    public static function refuse(int $requestId, int $userId, string $reason, string $ip = ''): array
    {
        $request = self::byId($requestId);
        $allowed = self::canSign($request, $userId);
        if (!$allowed['ok']) {
            return $allowed;
        }
        if (trim($reason) === '') {
            return ['ok' => false, 'message' => 'Un refus se motive.'];
        }

        $now = gmdate('c');
        $motive = mb_substr(trim($reason), 0, 500);
        Db::transaction(static function () use ($request, $userId, $now, $motive, $ip): void {
            Db::run(
                "UPDATE signature_signers SET status = 'Refusé', signed_at = ?, reason = ?, ip = ?
                 WHERE request_id = ? AND user_id = ?",
                [$now, $motive, mb_substr($ip, 0, 60), $request['id'], $userId]
            );
            Db::run(
                "UPDATE signature_requests SET status = 'Refusé', completed_at = ?, closing_reason = ? WHERE id = ?",
                [$now, $motive, $request['id']]
            );
        });
        return ['ok' => true];
    }

    public static function cancel(int $requestId, string $reason = ''): array
    {
        $request = self::byId($requestId);
        if ($request === null) {
            return ['ok' => false, 'message' => 'Document introuvable.'];
        }
        if ($request['status'] !== 'En cours') {
            return ['ok' => false, 'message' => "Ce document n'est plus à la signature."];
        }
        Db::run(
            "UPDATE signature_requests SET status = 'Annulé', completed_at = ?, closing_reason = ? WHERE id = ?",
            [gmdate('c'), mb_substr($reason, 0, 500), $request['id']]
        );
        return ['ok' => true];
    }

    /**
     * Un document signé ne se supprime pas : il est la preuve de l'engagement.
     * Seul un document annulé ou refusé, sans aucune signature apposée, s'efface.
     */
    public static function remove(int $requestId): array
    {
        $request = self::byId($requestId);
        if ($request === null) {
            return ['ok' => false, 'message' => 'Document introuvable.'];
        }
        $signed = (int) Db::value(
            "SELECT COUNT(*) FROM signature_signers WHERE request_id = ? AND status = 'Signé'",
            [$request['id']]
        );
        if ($signed > 0) {
            return ['ok' => false, 'message' => 'Un document déjà signé ne se supprime pas : il fait preuve.'];
        }
        if ($request['status'] === 'En cours') {
            return ['ok' => false, 'message' => 'Annulez le document avant de le supprimer.'];
        }

        $target = self::pathOf($request);
        if ($target !== null && is_file($target)) {
            unlink($target);
        }
        Db::run('DELETE FROM signature_requests WHERE id = ?', [$request['id']]);
        return ['ok' => true];
    }

    /** Les éléments de l'attestation de signature, tels qu'ils seront imprimés. */
    public static function certificate(int $requestId): ?array
    {
        $request = self::decorate(self::byId($requestId));
        if ($request === null) {
            return null;
        }
        return ['request' => $request, 'verification' => self::verify($request)];
    }

    public static function summary(): array
    {
        return [
            'running' => (int) Db::value("SELECT COUNT(*) FROM signature_requests WHERE status = 'En cours'"),
            'signed' => (int) Db::value("SELECT COUNT(*) FROM signature_requests WHERE status = 'Signé'"),
            'refused' => (int) Db::value("SELECT COUNT(*) FROM signature_requests WHERE status = 'Refusé'"),
            'overdue' => count(array_filter(self::list('En cours'), static fn (array $r): bool => $r['overdue'])),
        ];
    }
}
