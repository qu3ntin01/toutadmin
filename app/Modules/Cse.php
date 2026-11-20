<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Comité social et économique : mandats, élections, réunions, avantages.
 *
 * Le scrutin est le cœur du module, et il tient à une propriété : le bulletin
 * et l'émargement sont écrits ensemble mais restent deux lignes sans lien.
 * Savoir qui a voté sans savoir pour qui, c'est ce qu'un scrutin demande.
 */
final class Cse
{
    // Rôles siégeant au comité. Le président est l'employeur : il n'est pas élu, donc absent d'ici.
    public const MANDATE_ROLES = ['Titulaire', 'Suppléant', 'Secrétaire', 'Trésorier', 'Référent harcèlement'];
    public const ELECTION_STATUSES = ['Candidatures', 'Vote', 'Clôturée'];
    public const BENEFIT_CATEGORIES = ['Billetterie', 'Voyages', 'Sport & loisirs', 'Culture', 'Commerces', 'Restauration', 'Famille', 'Autre'];

    /** Le périmètre courant : chaque société a son comité, ses élections, ses avantages. */
    private static function ici(): ?int
    {
        return Societes::courant();
    }

    /** Le CSE représente les salariés : ni les administrateurs, ni les freelances non salariés. */
    public static function isEligible(array $user): bool
    {
        return Hr::isEligible($user);
    }

    // ---------- Mandats ----------

    public static function mandates(): array
    {
        return Db::all(
            'SELECT m.*, u.first_name, u.last_name, u.email, u.grade, u.avatar_file,
                    d.name AS department_name, t.name AS team_name
             FROM cse_mandates m
             JOIN users u ON u.id = m.user_id
             LEFT JOIN departments d ON d.id = u.department_id
             LEFT JOIN teams t ON t.id = u.team_id
             WHERE m.company_id = ?
             ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE',
            [self::ici()]
        );
    }

    public static function mandateFor(int $userId): ?array
    {
        return Db::get(
            'SELECT * FROM cse_mandates WHERE user_id = ? AND company_id = ?',
            [$userId, self::ici()]
        );
    }

    /** Un mandat échu ne donne plus accès à l'espace de gestion. */
    public static function isElected(int $userId): bool
    {
        return Db::get(
            "SELECT id FROM cse_mandates
             WHERE user_id = ? AND company_id = ? AND (ends_on IS NULL OR ends_on = '' OR ends_on >= ?)",
            [$userId, self::ici(), gmdate('Y-m-d')]
        ) !== null;
    }

    public static function addMandate(array $fields): void
    {
        Db::run(
            'INSERT INTO cse_mandates (company_id, user_id, mandate_role, started_on, ends_on, created_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(company_id, user_id) DO UPDATE SET mandate_role = excluded.mandate_role,
                                                            started_on = excluded.started_on,
                                                            ends_on = excluded.ends_on',
            [
                self::ici(), $fields['userId'], $fields['mandateRole'], $fields['startedOn'],
                $fields['endsOn'] ?? null, $fields['createdBy'] ?? null,
            ]
        );
    }

    public static function removeMandate(int $userId): bool
    {
        if (Db::run('DELETE FROM cse_mandates WHERE user_id = ? AND company_id = ?', [$userId, self::ici()]) === 0) {
            return false;
        }

        return true;
    }

    // ---------- Élections ----------

    public static function elections(): array
    {
        return Db::all('SELECT * FROM cse_elections WHERE company_id = ? ORDER BY created_at DESC', [self::ici()]);
    }

    public static function electionById(int $id): ?array
    {
        return Db::get('SELECT * FROM cse_elections WHERE id = ? AND company_id = ?', [$id, self::ici()]);
    }

    /** L'élection que voit un salarié : la dernière encore ouverte. */
    public static function openElection(): ?array
    {
        return Db::get(
            "SELECT * FROM cse_elections WHERE company_id = ? AND status != 'Clôturée'
             ORDER BY created_at DESC LIMIT 1",
            [self::ici()]
        );
    }

    public static function createElection(array $fields): int
    {
        return Db::insert(
            'INSERT INTO cse_elections (company_id, title, description, seats, candidacy_deadline, vote_start, vote_end, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::ici(), $fields['title'], $fields['description'] ?? '', $fields['seats'],
                $fields['candidacyDeadline'] ?? null, $fields['voteStart'] ?? null,
                $fields['voteEnd'] ?? null, $fields['createdBy'] ?? null,
            ]
        );
    }

    public static function setElectionStatus(int $id, string $status): array
    {
        if (!in_array($status, self::ELECTION_STATUSES, true)) {
            return ['ok' => false, 'reason' => 'bad-status'];
        }
        $election = self::electionById($id);
        if ($election === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if ($election['status'] === 'Clôturée') {
            return ['ok' => false, 'reason' => 'closed'];
        }

        // Ouvrir le vote sans candidat validé produirait un scrutin vide.
        if ($status === 'Vote' && self::candidacies($id, true) === []) {
            return ['ok' => false, 'reason' => 'no-candidate'];
        }

        Db::run(
            'UPDATE cse_elections SET status = ?, closed_at = ? WHERE id = ? AND company_id = ?',
            [$status, $status === 'Clôturée' ? gmdate('c') : null, $id, self::ici()]
        );
        return ['ok' => true];
    }

    public static function deleteElection(int $id): bool
    {
        if (Db::run('DELETE FROM cse_elections WHERE id = ? AND company_id = ?', [$id, self::ici()]) === 0) {
            return false;
        }

        return true;
    }

    // ---------- Candidatures ----------

    public static function candidacies(int $electionId, bool $validatedOnly = false): array
    {
        $where = $validatedOnly ? "AND c.status = 'Validée'" : '';
        return Db::all(
            "SELECT c.*, u.first_name, u.last_name, u.grade, u.avatar_file, d.name AS department_name
             FROM cse_candidacies c
             JOIN users u ON u.id = c.user_id
             LEFT JOIN departments d ON d.id = u.department_id
             WHERE c.election_id = ? $where
             ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE",
            [$electionId]
        );
    }

    public static function candidacyFor(int $electionId, int $userId): ?array
    {
        return Db::get('SELECT * FROM cse_candidacies WHERE election_id = ? AND user_id = ?', [$electionId, $userId]);
    }

    public static function applyForElection(int $electionId, int $userId, string $statement = ''): array
    {
        $election = self::electionById($electionId);
        if ($election === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if ($election['status'] !== 'Candidatures') {
            return ['ok' => false, 'reason' => 'closed'];
        }
        if (self::candidacyFor($electionId, $userId) !== null) {
            return ['ok' => false, 'reason' => 'already-applied'];
        }

        Db::insert(
            'INSERT INTO cse_candidacies (election_id, user_id, statement) VALUES (?, ?, ?)',
            [$electionId, $userId, $statement]
        );
        return ['ok' => true];
    }

    public static function withdrawCandidacy(int $electionId, int $userId): array
    {
        $election = self::electionById($electionId);
        // Retirer sa candidature une fois le vote ouvert fausserait le scrutin en cours.
        if ($election === null || $election['status'] !== 'Candidatures') {
            return ['ok' => false, 'reason' => 'closed'];
        }
        $removed = Db::run('DELETE FROM cse_candidacies WHERE election_id = ? AND user_id = ?', [$electionId, $userId]);
        return $removed > 0 ? ['ok' => true] : ['ok' => false, 'reason' => 'not-found'];
    }

    public static function reviewCandidacy(int $id, string $status, ?int $reviewerId): array
    {
        if (!in_array($status, ['Validée', 'Refusée'], true)) {
            return ['ok' => false, 'reason' => 'bad-status'];
        }
        $candidacy = Db::get('SELECT * FROM cse_candidacies WHERE id = ?', [$id]);
        if ($candidacy === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }

        $election = self::electionById((int) $candidacy['election_id']);
        if ($election === null || $election['status'] !== 'Candidatures') {
            return ['ok' => false, 'reason' => 'closed'];
        }

        Db::run(
            'UPDATE cse_candidacies SET status = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?',
            [$status, $reviewerId, gmdate('c'), $id]
        );
        return ['ok' => true];
    }

    // ---------- Scrutin ----------

    public static function hasVoted(int $electionId, int $userId): bool
    {
        return Db::get('SELECT election_id FROM cse_voters WHERE election_id = ? AND user_id = ?', [$electionId, $userId]) !== null;
    }

    /** Le bulletin et l'émargement sont écrits ensemble, mais restent deux lignes sans lien entre elles. */
    public static function castBallot(int $electionId, int $userId, int $candidacyId): array
    {
        $election = self::electionById($electionId);
        if ($election === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if ($election['status'] !== 'Vote') {
            return ['ok' => false, 'reason' => 'not-open'];
        }
        if (self::hasVoted($electionId, $userId)) {
            return ['ok' => false, 'reason' => 'already-voted'];
        }

        $candidacy = Db::get(
            "SELECT id FROM cse_candidacies WHERE id = ? AND election_id = ? AND status = 'Validée'",
            [$candidacyId, $electionId]
        );
        if ($candidacy === null) {
            return ['ok' => false, 'reason' => 'bad-candidate'];
        }

        Db::transaction(static function () use ($electionId, $userId, $candidacyId): void {
            Db::insert('INSERT INTO cse_voters (election_id, user_id) VALUES (?, ?)', [$electionId, $userId]);
            Db::insert('INSERT INTO cse_ballots (election_id, candidacy_id) VALUES (?, ?)', [$electionId, $candidacyId]);
        });
        return ['ok' => true];
    }

    public static function turnout(int $electionId): array
    {
        $voters = (int) Db::value('SELECT COUNT(*) FROM cse_voters WHERE election_id = ?', [$electionId]);
        $electorate = (int) Db::value(
            "SELECT COUNT(*) FROM users WHERE role = 'employee' AND active = 1 AND contract_type != 'Freelance'"
        );
        return [
            'voters' => $voters,
            'electorate' => $electorate,
            'rate' => $electorate > 0 ? round($voters / $electorate * 1000) / 10 : 0.0,
        ];
    }

    /** Résultats : les candidats validés, du plus au moins voté. */
    public static function results(int $electionId): array
    {
        return Db::all(
            "SELECT c.id, c.user_id, u.first_name, u.last_name, u.grade, d.name AS department_name,
                    (SELECT COUNT(*) FROM cse_ballots b WHERE b.candidacy_id = c.id) AS votes
             FROM cse_candidacies c
             JOIN users u ON u.id = c.user_id
             LEFT JOIN departments d ON d.id = u.department_id
             WHERE c.election_id = ? AND c.status = 'Validée'
             ORDER BY votes DESC, u.last_name COLLATE NOCASE",
            [$electionId]
        );
    }

    // ---------- Réunions ----------

    public static function meetings(int $limit = 60): array
    {
        return Db::all(
            'SELECT * FROM cse_meetings WHERE company_id = ? ORDER BY meeting_date DESC LIMIT ?',
            [self::ici(), $limit]
        );
    }

    /** Vue salarié : les réunions à venir, et les précédentes dont le compte-rendu est publié. */
    public static function meetingsForStaff(int $limit = 40): array
    {
        return Db::all(
            'SELECT * FROM cse_meetings
             WHERE company_id = ? AND (meeting_date >= ? OR minutes_published = 1)
             ORDER BY meeting_date DESC LIMIT ?',
            [self::ici(), gmdate('Y-m-d'), $limit]
        );
    }

    public static function upcomingMeetings(int $limit = 5): array
    {
        return Db::all(
            'SELECT * FROM cse_meetings WHERE company_id = ? AND meeting_date >= ?
             ORDER BY meeting_date LIMIT ?',
            [self::ici(), gmdate('Y-m-d'), $limit]
        );
    }

    public static function createMeeting(array $fields): int
    {
        return Db::insert(
            'INSERT INTO cse_meetings (company_id, title, meeting_date, meeting_time, location, agenda, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                self::ici(), $fields['title'], $fields['meetingDate'], $fields['meetingTime'] ?? '',
                $fields['location'] ?? '', $fields['agenda'] ?? '', $fields['createdBy'] ?? null,
            ]
        );
    }

    public static function saveMinutes(int $id, string $minutes, bool $publish): bool
    {
        return Db::run(
            'UPDATE cse_meetings SET minutes = ?, minutes_published = ? WHERE id = ? AND company_id = ?',
            [$minutes, $publish ? 1 : 0, $id, self::ici()]
        ) > 0;
    }

    public static function deleteMeeting(int $id): bool
    {
        if (Db::run('DELETE FROM cse_meetings WHERE id = ? AND company_id = ?', [$id, self::ici()]) === 0) {
            return false;
        }

        return true;
    }

    // ---------- Avantages et réductions ----------

    public static function benefits(bool $activeOnly = false): array
    {
        // Un avantage périmé disparaît de la vue salarié sans que personne ait à le désactiver.
        if ($activeOnly) {
            return Db::all(
                "SELECT * FROM cse_benefits
                 WHERE company_id = ? AND active = 1
                   AND (valid_until IS NULL OR valid_until = '' OR valid_until >= ?)
                 ORDER BY category COLLATE NOCASE, title COLLATE NOCASE",
                [self::ici(), gmdate('Y-m-d')]
            );
        }
        return Db::all(
            'SELECT * FROM cse_benefits WHERE company_id = ?
             ORDER BY category COLLATE NOCASE, title COLLATE NOCASE',
            [self::ici()]
        );
    }

    public static function benefitById(int $id): ?array
    {
        return Db::get('SELECT * FROM cse_benefits WHERE id = ? AND company_id = ?', [$id, self::ici()]);
    }

    public static function createBenefit(array $data): int
    {
        return Db::insert(
            'INSERT INTO cse_benefits (company_id, title, category, partner, description, discount, code, url, valid_until, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::ici(), $data['title'], $data['category'] ?? '', $data['partner'] ?? '', $data['description'] ?? '',
                $data['discount'] ?? '', $data['code'] ?? '', $data['url'] ?? '',
                $data['validUntil'] ?? null, $data['createdBy'] ?? null,
            ]
        );
    }

    public static function updateBenefit(int $id, array $data): void
    {
        Db::run(
            'UPDATE cse_benefits
             SET title = ?, category = ?, partner = ?, description = ?, discount = ?, code = ?, url = ?, valid_until = ?, active = ?
             WHERE id = ? AND company_id = ?',
            [
                $data['title'], $data['category'] ?? '', $data['partner'] ?? '', $data['description'] ?? '',
                $data['discount'] ?? '', $data['code'] ?? '', $data['url'] ?? '',
                $data['validUntil'] ?? null, !empty($data['active']) ? 1 : 0, $id, self::ici(),
            ]
        );
    }

    public static function deleteBenefit(int $id): bool
    {
        if (Db::run('DELETE FROM cse_benefits WHERE id = ? AND company_id = ?', [$id, self::ici()]) === 0) {
            return false;
        }

        return true;
    }
}
