<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Accueil : registre des visiteurs et courrier.
 *
 * Deux cahiers qui traînent encore sur un comptoir dans la plupart des
 * entreprises. Le registre des visiteurs n'est pas un formalisme : en cas
 * d'évacuation, il répond à « qui est dans les murs ? », ce qu'aucune liste de
 * salariés ne sait faire. Le courrier, lui, se perd entre l'accueil et le
 * destinataire — d'où un état « à remettre » qui reste visible tant que
 * personne n'a signé la remise.
 */
final class FrontDesk
{
    public const MAIL_DIRECTIONS = ['Entrant', 'Sortant'];
    public const MAIL_KINDS = ['Lettre', 'Recommandé', 'Recommandé avec AR', 'Colis', 'Pli administratif'];
    public const MAIL_STATUSES = ['À remettre', 'Remis', 'Archivé'];

    /** Le périmètre courant : l'accueil d'une société n'est pas celui d'une autre. */
    private static function ici(): ?int
    {
        return Societes::courant();
    }

    private static function today(): string
    {
        return gmdate('Y-m-d');
    }

    private static function now(): string
    {
        return gmdate('H:i');
    }

    // ---------- Visiteurs ----------

    public static function visitors(?string $day = null, int $limit = 200): array
    {
        $clause = $day !== null ? 'AND v.visited_on = ?' : '';
        $params = $day !== null ? [self::ici(), $day, $limit] : [self::ici(), $limit];

        return Db::all(
            "SELECT v.*, u.first_name AS host_first, u.last_name AS host_last
             FROM visitors v LEFT JOIN users u ON u.id = v.host_id
             WHERE v.company_id = ? $clause
             ORDER BY v.visited_on DESC, v.arrived_at DESC, v.id DESC LIMIT ?",
            $params
        );
    }

    /** Ceux qui sont entrés et n'ont pas été rendus : la liste d'évacuation. */
    public static function present(): array
    {
        return Db::all(
            "SELECT v.*, u.first_name AS host_first, u.last_name AS host_last
             FROM visitors v LEFT JOIN users u ON u.id = v.host_id
             WHERE v.company_id = ? AND v.visited_on = date('now')
               AND v.arrived_at != '' AND v.departed_at = ''
             ORDER BY v.arrived_at",
            [self::ici()]
        );
    }

    public static function checkIn(array $fields): int
    {
        return Db::insert(
            'INSERT INTO visitors (company_id, visited_on, arrived_at, first_name, last_name, company, purpose, host_id, badge, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::ici(), $fields['visitedOn'] ?? self::today(), $fields['arrivedAt'] ?? self::now(),
                $fields['firstName'] ?? '', $fields['lastName'], $fields['company'] ?? '',
                $fields['purpose'] ?? '', $fields['hostId'] ?? null, $fields['badge'] ?? '',
                $fields['notes'] ?? '', $fields['createdBy'] ?? null,
            ]
        );
    }

    public static function checkOut(int $id, ?string $at = null): bool
    {
        return Db::run(
            "UPDATE visitors SET departed_at = ?
             WHERE id = ? AND company_id = ? AND departed_at = ''",
            [$at ?? self::now(), $id, self::ici()]
        ) > 0;
    }

    public static function deleteVisitor(int $id): bool
    {
        if (Db::run('DELETE FROM visitors WHERE id = ? AND company_id = ?', [$id, self::ici()]) === 0) {
            return false;
        }

        return true;
    }

    // ---------- Courrier ----------

    public static function mail(?string $direction = null, ?string $status = null, int $limit = 300): array
    {
        $clauses = ['m.company_id = ?'];
        $params = [self::ici()];
        if ($direction !== null) {
            $clauses[] = 'm.direction = ?';
            $params[] = $direction;
        }
        if ($status !== null) {
            $clauses[] = 'm.status = ?';
            $params[] = $status;
        }
        $where = 'WHERE ' . implode(' AND ', $clauses);
        $params[] = $limit;

        return Db::all(
            "SELECT m.*, u.first_name, u.last_name FROM mail_items m
             LEFT JOIN users u ON u.id = m.recipient_id
             $where
             ORDER BY m.logged_on DESC, m.id DESC LIMIT ?",
            $params
        );
    }

    public static function logMail(array $fields): int
    {
        return Db::insert(
            'INSERT INTO mail_items (company_id, direction, logged_on, kind, correspondent, recipient_id, recipient_label,
                                     tracking, subject, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::ici(), $fields['direction'], $fields['loggedOn'] ?? self::today(), $fields['kind'],
                $fields['correspondent'] ?? '', $fields['recipientId'] ?? null,
                $fields['recipientLabel'] ?? '', $fields['tracking'] ?? '',
                $fields['subject'] ?? '', $fields['notes'] ?? '',
            ]
        );
    }

    /** La remise est datée et signée d'un nom : c'est tout l'intérêt du registre. */
    public static function handOver(int $id, ?int $handedBy): bool
    {
        return Db::run(
            "UPDATE mail_items SET status = 'Remis', handed_on = date('now'), handed_by = ?
             WHERE id = ? AND company_id = ? AND status = 'À remettre'",
            [$handedBy, $id, self::ici()]
        ) > 0;
    }

    public static function archiveMail(int $id): bool
    {
        return Db::run(
            "UPDATE mail_items SET status = 'Archivé' WHERE id = ? AND company_id = ?",
            [$id, self::ici()]
        ) > 0;
    }

    public static function deleteMail(int $id): bool
    {
        if (Db::run('DELETE FROM mail_items WHERE id = ? AND company_id = ?', [$id, self::ici()]) === 0) {
            return false;
        }

        return true;
    }

    /** Le courrier d'une personne : ce qui l'attend à l'accueil. */
    public static function mailFor(int $userId): array
    {
        return Db::all(
            "SELECT * FROM mail_items
             WHERE company_id = ? AND recipient_id = ? AND status = 'À remettre'
             ORDER BY logged_on",
            [self::ici(), $userId]
        );
    }

    public static function summary(): array
    {
        return [
            'presentNow' => count(self::present()),
            'visitorsToday' => (int) Db::value(
                "SELECT COUNT(*) FROM visitors WHERE company_id = ? AND visited_on = date('now')",
                [self::ici()]
            ),
            'visitorsMonth' => (int) Db::value(
                "SELECT COUNT(*) FROM visitors WHERE company_id = ? AND visited_on >= date('now', '-30 days')",
                [self::ici()]
            ),
            'pendingMail' => (int) Db::value(
                "SELECT COUNT(*) FROM mail_items WHERE company_id = ? AND status = 'À remettre'",
                [self::ici()]
            ),
            'registered' => (int) Db::value(
                "SELECT COUNT(*) FROM mail_items
                 WHERE company_id = ? AND kind LIKE 'Recommandé%' AND status = 'À remettre'",
                [self::ici()]
            ),
        ];
    }
}
