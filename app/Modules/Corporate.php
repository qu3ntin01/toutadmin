<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Vie juridique et conformité.
 *
 * Le produit savait tout de l'entreprise employeur et rien de l'entreprise
 * société : qui la détient, qui la dirige, ce que les assemblées ont voté, et
 * qui a reçu le pouvoir d'engager quoi. C'est pourtant la couche qui répond à
 * la question la plus fréquente d'un contrôle ou d'une due diligence.
 *
 * Un choix de fond : la détention n'est pas une colonne, c'est une somme. Elle
 * se recalcule des mouvements de titres, comme un solde bancaire se recalcule
 * de ses écritures. Personne ne peut donc corriger un capital sans laisser la
 * ligne qui l'explique — ce qui est exactement l'objet d'un registre.
 */
final class Corporate
{
    public const SHAREHOLDER_KINDS = ['Personne physique', 'Personne morale'];
    public const MOVEMENT_KINDS = ['Souscription', 'Cession', 'Acquisition', 'Réduction'];
    public const MANDATE_ROLES = ['Président', 'Directeur général', 'Directeur général délégué', 'Gérant', 'Membre du conseil', 'Commissaire aux comptes'];
    public const MANDATE_STATUSES = ['En cours', 'Échu', 'Révoqué'];
    public const MEETING_KINDS = ['Assemblée générale ordinaire', 'Assemblée générale extraordinaire', 'Assemblée générale mixte'];
    public const MEETING_STATUSES = ['Convoquée', 'Tenue', 'Annulée'];
    public const RESOLUTION_OUTCOMES = ['En attente', 'Adoptée', 'Rejetée'];

    public const INTEREST_KINDS = ['Intérêt financier', 'Mandat externe', 'Lien familial', 'Activité accessoire', 'Autre'];
    public const INTEREST_STATUSES = ['Déclaré', 'Examiné', 'Mesure prise', 'Clos'];
    public const GIFT_DIRECTIONS = ['Reçu', 'Offert'];
    public const GIFT_KINDS = ['Cadeau', 'Invitation', 'Voyage', 'Autre'];
    public const GIFT_STATUSES = ['Déclaré', 'Accepté', 'Refusé', 'Restitué'];
    public const DELEGATION_STATUSES = ['En vigueur', 'Suspendue', 'Échue', 'Révoquée'];

    // Au-delà, un cadeau ou une invitation demande un examen : le seuil n'est pas
    // une règle de droit mais un usage, et il vaut mieux l'écrire que le supposer.
    public const GIFT_REVIEW_THRESHOLD = 150;

    // ---------------------------------------------------------------- capital

    public static function shareholders(): array
    {
        return Db::all(
            'SELECT s.*, u.first_name, u.last_name,
                    (SELECT COALESCE(SUM(m.shares), 0) FROM share_movements m WHERE m.shareholder_id = s.id) AS shares
             FROM shareholders s LEFT JOIN users u ON u.id = s.user_id
             ORDER BY shares DESC, s.name COLLATE NOCASE'
        );
    }

    public static function shareholderById(int $id): ?array
    {
        return Db::get(
            'SELECT s.*, (SELECT COALESCE(SUM(m.shares), 0) FROM share_movements m WHERE m.shareholder_id = s.id) AS shares
             FROM shareholders s WHERE s.id = ?',
            [$id]
        );
    }

    public static function createShareholder(array $fields): int
    {
        return Db::insert(
            'INSERT INTO shareholders (name, kind, user_id, registration, email, address, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['name'], $fields['kind'], $fields['userId'] ?? null, $fields['registration'] ?? '',
                $fields['email'] ?? '', $fields['address'] ?? '', $fields['notes'] ?? '',
            ]
        );
    }

    public static function removeShareholder(int $id): bool
    {
        $held = self::shareholderById($id);
        // Un associé qui détient encore des titres ne s'efface pas : ses parts
        // disparaîtraient du capital sans qu'aucune cession ne l'explique.
        if ($held === null || (float) $held['shares'] !== 0.0) {
            return false;
        }
        Db::run('DELETE FROM shareholders WHERE id = ?', [$id]);
        return true;
    }

    public static function movements(?int $shareholderId = null, int $limit = 200): array
    {
        $clause = $shareholderId !== null ? 'WHERE m.shareholder_id = ?' : '';
        $params = $shareholderId !== null ? [$shareholderId, $limit] : [$limit];

        return Db::all(
            "SELECT m.*, s.name AS shareholder_name, c.name AS counterparty_name
             FROM share_movements m
             JOIN shareholders s ON s.id = m.shareholder_id
             LEFT JOIN shareholders c ON c.id = m.counterparty_id
             $clause
             ORDER BY m.moved_on DESC, m.id DESC LIMIT ?",
            $params
        );
    }

    /**
     * Inscrit un mouvement. Une cession entre deux associés en écrit deux, dans la
     * même transaction : le registre ne doit jamais montrer des titres partis de
     * chez l'un sans être arrivés chez l'autre.
     */
    public static function recordMovement(array $fields): array
    {
        return Db::transaction(static function () use ($fields): array {
            $holder = self::shareholderById((int) $fields['shareholderId']);
            if ($holder === null) {
                return ['ok' => false, 'reason' => 'introuvable'];
            }

            $kind = $fields['kind'];
            $shares = (float) $fields['shares'];
            $signed = in_array($kind, ['Cession', 'Réduction'], true) ? -abs($shares) : abs($shares);
            // On ne cède pas plus qu'on ne détient : le capital ne devient pas négatif.
            if ($signed < 0 && (float) $holder['shares'] + $signed < 0) {
                return ['ok' => false, 'reason' => 'insuffisant', 'held' => (float) $holder['shares']];
            }

            $note = mb_substr((string) ($fields['note'] ?? ''), 0, 300);
            $sql = 'INSERT INTO share_movements (shareholder_id, kind, moved_on, shares, unit_price, counterparty_id, note)
                    VALUES (?, ?, ?, ?, ?, ?, ?)';
            $counterparty = $fields['counterpartyId'] ?? null;
            Db::insert($sql, [
                (int) $fields['shareholderId'], $kind, $fields['movedOn'], $signed,
                $fields['unitPrice'] ?? null, $counterparty, $note,
            ]);

            if ($kind === 'Cession' && $counterparty) {
                Db::insert($sql, [
                    (int) $counterparty, 'Acquisition', $fields['movedOn'], abs($shares),
                    $fields['unitPrice'] ?? null, (int) $fields['shareholderId'], $note,
                ]);
            }
            return ['ok' => true];
        });
    }

    public static function removeMovement(int $id): void
    {
        Db::run('DELETE FROM share_movements WHERE id = ?', [$id]);
    }

    /** La répartition du capital, en titres et en pourcentage. */
    public static function capital(): array
    {
        $holders = array_values(array_filter(
            self::shareholders(),
            static fn (array $s): bool => (float) $s['shares'] !== 0.0
        ));
        $total = array_sum(array_map(static fn (array $s): float => (float) $s['shares'], $holders));

        return [
            'total' => $total,
            'holders' => array_map(static function (array $s) use ($total): array {
                $s['share'] = $total ? round((float) $s['shares'] / $total * 100, 2) : 0.0;
                return $s;
            }, $holders),
            // Le seuil au-delà duquel un associé décide seul en assemblée ordinaire.
            'majority' => array_values(array_map(
                static fn (array $s): string => $s['name'],
                array_filter($holders, static fn (array $s): bool => $total > 0 && (float) $s['shares'] / $total > 0.5)
            )),
        ];
    }

    // ---------------------------------------------------------------- mandats

    public static function mandates(bool $includeEnded = true): array
    {
        $clause = $includeEnded ? '' : "WHERE m.status = 'En cours'";
        return Db::all(
            "SELECT m.*, u.first_name, u.last_name
             FROM corporate_mandates m LEFT JOIN users u ON u.id = m.user_id
             $clause
             ORDER BY m.status = 'En cours' DESC, m.started_on DESC"
        );
    }

    public static function createMandate(array $fields): int
    {
        return Db::insert(
            'INSERT INTO corporate_mandates (holder_name, user_id, role, started_on, ends_on, appointed_by, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['holderName'], $fields['userId'] ?? null, $fields['role'], $fields['startedOn'],
                $fields['endsOn'] ?? null, $fields['appointedBy'] ?? '', $fields['notes'] ?? '',
            ]
        );
    }

    public static function setMandateStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::MANDATE_STATUSES, true)) {
            return false;
        }
        Db::run('UPDATE corporate_mandates SET status = ? WHERE id = ?', [$status, $id]);
        return true;
    }

    public static function removeMandate(int $id): void
    {
        Db::run('DELETE FROM corporate_mandates WHERE id = ?', [$id]);
    }

    // ---------------------------------------------------------------- assemblées

    public static function nextMeetingReference(): string
    {
        $year = (int) gmdate('Y');
        $count = (int) Db::value('SELECT COUNT(*) FROM general_meetings WHERE reference LIKE ?', ["AG-$year-%"]);
        return sprintf('AG-%d-%03d', $year, $count + 1);
    }

    public static function meetings(): array
    {
        return Db::all(
            'SELECT m.*, (SELECT COUNT(*) FROM meeting_resolutions r WHERE r.meeting_id = m.id) AS resolution_count
             FROM general_meetings m ORDER BY m.held_on DESC, m.id DESC'
        );
    }

    public static function meetingById(int $id): ?array
    {
        return Db::get('SELECT * FROM general_meetings WHERE id = ?', [$id]);
    }

    public static function createMeeting(array $fields): int
    {
        return Db::insert(
            'INSERT INTO general_meetings (reference, kind, held_on, location, quorum_required)
             VALUES (?, ?, ?, ?, ?)',
            [
                self::nextMeetingReference(), $fields['kind'], $fields['heldOn'],
                $fields['location'] ?? '', $fields['quorumRequired'] ?? 0,
            ]
        );
    }

    /** La fiche ne touche pas au procès-verbal : il se rédige de son côté. */
    public static function updateMeeting(int $id, array $fields): void
    {
        Db::run(
            'UPDATE general_meetings
             SET kind = ?, held_on = ?, location = ?, quorum_required = ?, shares_present = ?, status = ?
             WHERE id = ?',
            [
                $fields['kind'], $fields['heldOn'], $fields['location'] ?? '', $fields['quorumRequired'] ?? 0,
                $fields['sharesPresent'] ?? 0, $fields['status'], $id,
            ]
        );
    }

    /**
     * Le procès-verbal se modifie seul, sans repasser par la fiche. L'écran de
     * rédaction n'a alors rien à réémettre du statut ni de la date : un formulaire
     * qui renvoie des champs qu'il n'édite pas finit un jour par les écraser.
     */
    public static function updateMinutes(int $id, string $minutes): void
    {
        Db::run('UPDATE general_meetings SET minutes = ? WHERE id = ?', [mb_substr($minutes, 0, 20000), $id]);
    }

    public static function removeMeeting(int $id): void
    {
        Db::run('DELETE FROM general_meetings WHERE id = ?', [$id]);
    }

    public static function resolutions(int $meetingId): array
    {
        return Db::all('SELECT * FROM meeting_resolutions WHERE meeting_id = ? ORDER BY position, id', [$meetingId]);
    }

    public static function addResolution(int $meetingId, string $label, float $majorityRequired): int
    {
        $position = (int) Db::value(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM meeting_resolutions WHERE meeting_id = ?',
            [$meetingId]
        );
        return Db::insert(
            'INSERT INTO meeting_resolutions (meeting_id, position, label, majority_required) VALUES (?, ?, ?, ?)',
            [$meetingId, $position, $label, $majorityRequired]
        );
    }

    /**
     * Enregistre un vote et en tire le sort de la résolution.
     *
     * La majorité se calcule sur les voix exprimées — pour et contre — les
     * abstentions étant écartées du dénominateur, ce qui est la règle statutaire
     * la plus répandue. Elle est écrite ici plutôt que supposée, parce que des
     * statuts peuvent en retenir une autre, et qu'il vaut mieux savoir laquelle
     * ce registre applique.
     */
    public static function recordVote(int $id, float $votesFor, float $votesAgainst, float $votesAbstain): bool
    {
        $resolution = Db::get('SELECT majority_required FROM meeting_resolutions WHERE id = ?', [$id]);
        if ($resolution === null) {
            return false;
        }

        $expressed = $votesFor + $votesAgainst;
        $share = $expressed > 0 ? $votesFor / $expressed * 100 : 0.0;
        $outcome = $expressed === 0.0
            ? 'En attente'
            : ($share >= (float) $resolution['majority_required'] ? 'Adoptée' : 'Rejetée');

        Db::run(
            'UPDATE meeting_resolutions SET votes_for = ?, votes_against = ?, votes_abstain = ?, outcome = ? WHERE id = ?',
            [$votesFor, $votesAgainst, $votesAbstain, $outcome, $id]
        );
        return true;
    }

    public static function removeResolution(int $id): void
    {
        Db::run('DELETE FROM meeting_resolutions WHERE id = ?', [$id]);
    }

    /** Le quorum d'une assemblée : atteint, ou de combien il manque. */
    public static function quorum(array $meeting): array
    {
        $total = self::capital()['total'];
        $required = (float) ($meeting['quorum_required'] ?? 0);
        $present = (float) ($meeting['shares_present'] ?? 0);

        return [
            'total' => $total,
            'present' => $present,
            'required' => $required,
            'share' => $total ? round($present / $total * 100, 2) : 0.0,
            'reached' => $present >= $required,
            'missing' => max(0.0, round($required - $present, 2)),
        ];
    }

    // ---------------------------------------------------------------- conformité

    public static function declarations(?int $userId = null): array
    {
        $clause = $userId !== null ? 'WHERE d.user_id = ?' : '';
        $params = $userId !== null ? [$userId] : [];

        return Db::all(
            "SELECT d.*, u.first_name, u.last_name, p.name AS partner_name,
                    r.first_name AS reviewer_first_name, r.last_name AS reviewer_last_name
             FROM interest_declarations d
             JOIN users u ON u.id = d.user_id
             LEFT JOIN partners p ON p.id = d.partner_id
             LEFT JOIN users r ON r.id = d.reviewed_by
             $clause
             ORDER BY d.status = 'Clos', d.declared_on DESC, d.id DESC",
            $params
        );
    }

    public static function declareInterest(array $fields): int
    {
        return Db::insert(
            'INSERT INTO interest_declarations (user_id, kind, entity, partner_id, description, declared_on, ends_on)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['userId'], $fields['kind'], $fields['entity'], $fields['partnerId'] ?? null,
                $fields['description'] ?? '', $fields['declaredOn'], $fields['endsOn'] ?? null,
            ]
        );
    }

    public static function reviewDeclaration(int $id, string $status, string $measure, ?int $reviewerId): bool
    {
        if (!in_array($status, self::INTEREST_STATUSES, true)) {
            return false;
        }
        Db::run(
            "UPDATE interest_declarations
             SET status = ?, measure = ?, reviewed_by = ?, reviewed_at = datetime('now')
             WHERE id = ?",
            [$status, mb_substr($measure, 0, 1000), $reviewerId, $id]
        );
        return true;
    }

    public static function gifts(?int $userId = null): array
    {
        $clause = $userId !== null ? 'WHERE g.user_id = ?' : '';
        $params = $userId !== null ? [$userId] : [];

        return Db::all(
            "SELECT g.*, u.first_name, u.last_name, p.name AS partner_name
             FROM gift_records g
             JOIN users u ON u.id = g.user_id
             LEFT JOIN partners p ON p.id = g.partner_id
             $clause
             ORDER BY g.occurred_on DESC, g.id DESC",
            $params
        );
    }

    public static function declareGift(array $fields): int
    {
        return Db::insert(
            'INSERT INTO gift_records (user_id, direction, kind, partner_id, third_party, occurred_on, value, description)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['userId'], $fields['direction'], $fields['kind'], $fields['partnerId'] ?? null,
                $fields['thirdParty'] ?? '', $fields['occurredOn'], $fields['value'] ?? 0, $fields['description'] ?? '',
            ]
        );
    }

    public static function reviewGift(int $id, string $status, ?int $reviewerId): bool
    {
        if (!in_array($status, self::GIFT_STATUSES, true)) {
            return false;
        }
        Db::run(
            "UPDATE gift_records SET status = ?, reviewed_by = ?, reviewed_at = datetime('now') WHERE id = ?",
            [$status, $reviewerId, $id]
        );
        return true;
    }

    public static function delegations(bool $includeEnded = true): array
    {
        $clause = $includeEnded ? '' : "WHERE d.status = 'En vigueur'";
        return Db::all(
            "SELECT d.*, u.first_name, u.last_name, g.first_name AS granted_first_name, g.last_name AS granted_last_name
             FROM power_delegations d
             JOIN users u ON u.id = d.holder_id
             LEFT JOIN users g ON g.id = d.granted_by_id
             $clause
             ORDER BY d.status = 'En vigueur' DESC, d.starts_on DESC"
        );
    }

    public static function createDelegation(array $fields): int
    {
        return Db::insert(
            'INSERT INTO power_delegations (holder_id, granted_by_id, scope, amount_limit, starts_on, ends_on, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['holderId'], $fields['grantedById'] ?? null, $fields['scope'],
                $fields['amountLimit'] ?? null, $fields['startsOn'], $fields['endsOn'] ?? null, $fields['notes'] ?? '',
            ]
        );
    }

    public static function setDelegationStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::DELEGATION_STATUSES, true)) {
            return false;
        }
        Db::run('UPDATE power_delegations SET status = ? WHERE id = ?', [$status, $id]);
        return true;
    }

    public static function removeDelegation(int $id): void
    {
        Db::run('DELETE FROM power_delegations WHERE id = ?', [$id]);
    }

    /** Les cadeaux au-delà du seuil que personne n'a encore examinés. */
    public static function giftsToReview(): array
    {
        return array_values(array_filter(
            self::gifts(),
            static fn (array $gift): bool => (float) $gift['value'] > self::GIFT_REVIEW_THRESHOLD
                && $gift['status'] === 'Déclaré'
        ));
    }

    public static function summary(): array
    {
        $capital = self::capital();

        return [
            'shareholders' => count($capital['holders']),
            'shares' => $capital['total'],
            'mandates' => (int) Db::value("SELECT COUNT(*) FROM corporate_mandates WHERE status = 'En cours'"),
            'meetings' => (int) Db::value('SELECT COUNT(*) FROM general_meetings'),
            'openDeclarations' => (int) Db::value(
                "SELECT COUNT(*) FROM interest_declarations WHERE status IN ('Déclaré','Examiné')"
            ),
            'giftsToReview' => count(self::giftsToReview()),
            'delegations' => (int) Db::value("SELECT COUNT(*) FROM power_delegations WHERE status = 'En vigueur'"),
        ];
    }
}
