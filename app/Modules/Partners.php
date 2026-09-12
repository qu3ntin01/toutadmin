<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Fiche d'un tiers : ses interlocuteurs, ses pièces de conformité et son
 * évaluation.
 *
 * Un partenaire n'est pas qu'une ligne dans un tableau, reliée à des contrats
 * et à des factures. Il y manque ce qui fait qu'on travaille avec lui en
 * confiance : à qui l'on parle, ce qu'il doit fournir et jusqu'à quand cela
 * vaut, et ce qu'on a pensé de lui la dernière fois. Une attestation de
 * vigilance périmée engage la responsabilité du donneur d'ordre : ce n'est pas
 * une pièce jointe, c'est une échéance.
 */
final class Partners
{
    public const DOCUMENT_KINDS = [
        'Attestation de vigilance',
        'Assurance',
        'Kbis',
        'Coordonnées bancaires',
        'Certification',
        'Autre',
    ];

    /** En deçà, une pièce est « bientôt périmée » : le temps d'en redemander une. */
    public const EXPIRY_WARNING_DAYS = 45;

    // ---------- Contacts ----------

    public static function contacts(int $partnerId): array
    {
        return Db::all(
            'SELECT * FROM partner_contacts WHERE partner_id = ?
             ORDER BY is_primary DESC, name COLLATE NOCASE',
            [$partnerId]
        );
    }

    public static function addContact(array $fields): int
    {
        $id = Db::insert(
            'INSERT INTO partner_contacts (partner_id, name, role, email, phone, is_primary, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $fields['partnerId'], $fields['name'], $fields['role'] ?? '',
                $fields['email'] ?? '', $fields['phone'] ?? '',
                !empty($fields['isPrimary']) ? 1 : 0, $fields['notes'] ?? '',
            ]
        );
        if (!empty($fields['isPrimary'])) {
            self::setPrimary((int) $fields['partnerId'], $id);
        }
        return $id;
    }

    /** Un seul interlocuteur principal par tiers : désigner le nouveau retire l'ancien. */
    public static function setPrimary(int $partnerId, int $contactId): bool
    {
        Db::run('UPDATE partner_contacts SET is_primary = 0 WHERE partner_id = ?', [$partnerId]);
        return Db::run(
            'UPDATE partner_contacts SET is_primary = 1 WHERE id = ? AND partner_id = ?',
            [$contactId, $partnerId]
        ) > 0;
    }

    public static function removeContact(int $id): ?int
    {
        $row = Db::get('SELECT partner_id FROM partner_contacts WHERE id = ?', [$id]);
        Db::run('DELETE FROM partner_contacts WHERE id = ?', [$id]);
        return $row === null ? null : (int) $row['partner_id'];
    }

    // ---------- Conformité ----------

    public static function documents(int $partnerId): array
    {
        return Db::all(
            'SELECT * FROM partner_documents WHERE partner_id = ? ORDER BY expires_on IS NULL, expires_on, kind',
            [$partnerId]
        );
    }

    public static function addDocument(array $fields): int
    {
        return Db::insert(
            'INSERT INTO partner_documents (partner_id, kind, reference, issued_on, expires_on, notes)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                (int) $fields['partnerId'], $fields['kind'], $fields['reference'] ?? '',
                $fields['issuedOn'] ?? null, $fields['expiresOn'] ?? null, $fields['notes'] ?? '',
            ]
        );
    }

    public static function removeDocument(int $id): ?int
    {
        $row = Db::get('SELECT partner_id FROM partner_documents WHERE id = ?', [$id]);
        Db::run('DELETE FROM partner_documents WHERE id = ?', [$id]);
        return $row === null ? null : (int) $row['partner_id'];
    }

    /** L'état d'une pièce : valable, bientôt périmée, périmée, ou sans date. */
    public static function documentState(array $document, ?string $today = null): string
    {
        $today ??= gmdate('Y-m-d');
        $expires = $document['expires_on'] ?? null;
        if ($expires === null || $expires === '') {
            return 'sans_date';
        }
        if ($expires < $today) {
            return 'perime';
        }
        $limit = gmdate('Y-m-d', (int) strtotime($today . ' UTC +' . self::EXPIRY_WARNING_DAYS . ' days'));
        return $expires <= $limit ? 'bientot' : 'valable';
    }

    // ---------- Évaluation ----------

    public static function reviews(int $partnerId): array
    {
        return Db::all(
            'SELECT r.*, u.first_name, u.last_name
             FROM partner_reviews r LEFT JOIN users u ON u.id = r.reviewer_id
             WHERE r.partner_id = ? ORDER BY r.reviewed_on DESC, r.id DESC',
            [$partnerId]
        );
    }

    public static function addReview(array $fields): int
    {
        return Db::insert(
            'INSERT INTO partner_reviews (partner_id, reviewed_on, reviewer_id, quality, lead_time, price, comment, next_review)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $fields['partnerId'], $fields['reviewedOn'], $fields['reviewerId'] ?? null,
                $fields['quality'] ?? null, $fields['leadTime'] ?? null, $fields['price'] ?? null,
                $fields['comment'] ?? '', $fields['nextReview'] ?? null,
            ]
        );
    }

    public static function removeReview(int $id): ?int
    {
        $row = Db::get('SELECT partner_id FROM partner_reviews WHERE id = ?', [$id]);
        Db::run('DELETE FROM partner_reviews WHERE id = ?', [$id]);
        return $row === null ? null : (int) $row['partner_id'];
    }

    /** La note d'une évaluation : moyenne des critères renseignés, sur cinq. */
    public static function reviewScore(array $review): ?float
    {
        $marks = array_values(array_filter(
            [$review['quality'] ?? null, $review['lead_time'] ?? null, $review['price'] ?? null],
            static fn (mixed $mark): bool => $mark !== null
        ));
        if ($marks === []) {
            return null;
        }
        return round(array_sum(array_map('floatval', $marks)) / count($marks), 1);
    }

    // ---------- Vue d'ensemble ----------

    public static function sheet(int $partnerId): ?array
    {
        $partner = Db::get('SELECT * FROM partners WHERE id = ?', [$partnerId]);
        if ($partner === null) {
            return null;
        }

        $today = gmdate('Y-m-d');
        $documents = array_map(static function (array $document) use ($today): array {
            $document['state'] = self::documentState($document, $today);
            return $document;
        }, self::documents((int) $partner['id']));

        $reviews = array_map(static function (array $review): array {
            $review['score'] = self::reviewScore($review);
            return $review;
        }, self::reviews((int) $partner['id']));

        $nextReviews = array_values(array_filter(array_column($reviews, 'next_review')));
        sort($nextReviews);

        return [
            'partner' => $partner,
            'contacts' => self::contacts((int) $partner['id']),
            'documents' => $documents,
            'reviews' => $reviews,
            'contracts' => Db::all(
                'SELECT * FROM partner_contracts WHERE partner_id = ?
                 ORDER BY end_date IS NULL, end_date DESC, id DESC',
                [(int) $partner['id']]
            ),
            'invoices' => Db::all(
                'SELECT id, reference, label, direction, issue_date, due_date, amount_ht, status
                 FROM invoices WHERE partner_id = ? ORDER BY issue_date DESC, id DESC LIMIT 30',
                [(int) $partner['id']]
            ),
            'compliance' => [
                'expired' => count(array_filter($documents, static fn (array $d): bool => $d['state'] === 'perime')),
                'soon' => count(array_filter($documents, static fn (array $d): bool => $d['state'] === 'bientot')),
                'total' => count($documents),
            ],
            // La note retenue est celle de la dernière évaluation : une moyenne de
            // tout l'historique lisserait justement ce qu'on cherche à voir, une
            // dégradation.
            'lastScore' => $reviews === [] ? null : $reviews[0]['score'],
            'nextReview' => $nextReviews[0] ?? null,
        ];
    }
}
