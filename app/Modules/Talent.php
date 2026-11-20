<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Audit;
use App\Core\Db;

/**
 * Documents d'entreprise, formation et entretiens annuels.
 *
 * Trois sujets qui se ressemblent peu mais partagent une même contrainte : ce
 * qui est dit à une personne doit rester prouvable. Un accusé de réception est
 * daté et définitif, une place en formation est accordée ou refusée par écrit,
 * et un entretien réalisé garde le commentaire du salarié à côté de celui du
 * manager.
 */
final class Talent
{
    /** Le périmètre courant : formations, entretiens et postes sont d'une société. */
    private static function ici(): ?int
    {
        return Societes::courant();
    }

    public const DOCUMENT_CATEGORIES = ['Règlement intérieur', 'Politique', 'Procédure', 'Sécurité', 'Note de service', 'Autre'];
    public const SESSION_STATUSES = ['Planifiée', 'Confirmée', 'Terminée', 'Annulée'];
    public const REGISTRATION_STATUSES = ['Demandée', 'Inscrite', 'Refusée', 'Terminée', 'Annulée'];
    public const REVIEW_STATUSES = ['Planifié', 'Réalisé', 'Annulé'];
    public const OPENING_STATUSES = ['Ouvert', 'En cours', 'Pourvu', 'Annulé'];
    public const CANDIDATE_STAGES = ['Reçue', 'Présélection', 'Entretien', 'Offre', 'Recruté', 'Refusé'];

    // ---------- Documents d'entreprise ----------

    public static function documents(): array
    {
        return Db::all(
            'SELECT d.*, (SELECT COUNT(*) FROM document_acks a WHERE a.document_id = d.id) AS ack_count
             FROM company_documents d
             WHERE d.company_id = ?
             ORDER BY d.published_at DESC, d.title COLLATE NOCASE',
            [self::ici()]
        );
    }

    public static function documentById(int $id): ?array
    {
        return Db::get('SELECT * FROM company_documents WHERE id = ? AND company_id = ?', [$id, self::ici()]);
    }

    public static function createDocument(array $data): int
    {
        $id = Db::insert(
            'INSERT INTO company_documents (company_id, title, category, description, url, requires_ack, published_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::ici(), $data['title'], $data['category'] ?? '', $data['description'] ?? '', $data['url'] ?? '',
                !empty($data['requiresAck']) ? 1 : 0, $data['publishedAt'] ?: gmdate('Y-m-d'), $data['createdBy'] ?? null,
            ]
        );
        Audit::log('document.publie', 'company_documents', $id, ['titre' => $data['title']]);
        return $id;
    }

    public static function deleteDocument(int $id): bool
    {
        if (Db::run('DELETE FROM company_documents WHERE id = ? AND company_id = ?', [$id, self::ici()]) === 0) {
            return false;
        }

        Audit::log('document.supprime', 'company_documents', $id);

        return true;
    }

    /** Vue salarié : chaque document, et s'il en a déjà accusé réception. */
    public static function documentsFor(int $userId): array
    {
        return Db::all(
            'SELECT d.*, a.acked_at
             FROM company_documents d
             LEFT JOIN document_acks a ON a.document_id = d.id AND a.user_id = ?
             WHERE d.company_id = ?
             ORDER BY d.published_at DESC, d.title COLLATE NOCASE',
            [$userId, self::ici()]
        );
    }

    public static function pendingAckCount(int $userId): int
    {
        return (int) Db::value(
            'SELECT COUNT(*) FROM company_documents d
             WHERE d.company_id = ? AND d.requires_ack = 1
               AND NOT EXISTS (SELECT 1 FROM document_acks a WHERE a.document_id = d.id AND a.user_id = ?)',
            [self::ici(), $userId]
        );
    }

    /** L'accusé de réception est daté et définitif : on ne le retire pas. */
    public static function acknowledge(int $documentId, int $userId): bool
    {
        if (self::documentById($documentId) === null) {
            return false;
        }
        Db::run('INSERT OR IGNORE INTO document_acks (document_id, user_id) VALUES (?, ?)', [$documentId, $userId]);
        Audit::log('document.accuse_reception', 'company_documents', $documentId);
        return true;
    }

    public static function acksOf(int $documentId): array
    {
        return Db::all(
            'SELECT a.*, u.first_name, u.last_name
             FROM document_acks a JOIN users u ON u.id = a.user_id
             WHERE a.document_id = ? ORDER BY a.acked_at DESC',
            [$documentId]
        );
    }

    // ---------- Formation ----------

    public static function trainings(): array
    {
        return Db::all(
            'SELECT * FROM trainings WHERE company_id = ?
             ORDER BY category COLLATE NOCASE, title COLLATE NOCASE',
            [self::ici()]
        );
    }

    public static function createTraining(array $data): int
    {
        $id = Db::insert(
            'INSERT INTO trainings (company_id, title, category, provider, description, duration_hours, cost)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                self::ici(), $data['title'], $data['category'] ?? '', $data['provider'] ?? '', $data['description'] ?? '',
                $data['durationHours'], $data['cost'],
            ]
        );
        Audit::log('formation.creee', 'trainings', $id, ['titre' => $data['title']]);
        return $id;
    }

    public static function deleteTraining(int $id): bool
    {
        if (Db::run('DELETE FROM trainings WHERE id = ? AND company_id = ?', [$id, self::ici()]) === 0) {
            return false;
        }

        Audit::log('formation.supprimee', 'trainings', $id);

        return true;
    }

    public static function sessions(): array
    {
        return Db::all(
            "SELECT s.*, t.title, t.category, t.provider, t.duration_hours, t.cost,
               (SELECT COUNT(*) FROM training_registrations r WHERE r.session_id = s.id AND r.status = 'Inscrite') AS taken,
               (SELECT COUNT(*) FROM training_registrations r WHERE r.session_id = s.id AND r.status = 'Demandée') AS pending
             FROM training_sessions s JOIN trainings t ON t.id = s.training_id
             ORDER BY s.start_date DESC"
        );
    }

    public static function sessionById(int $id): ?array
    {
        return Db::get(
            "SELECT s.*, t.title, t.category,
               (SELECT COUNT(*) FROM training_registrations r WHERE r.session_id = s.id AND r.status = 'Inscrite') AS taken
             FROM training_sessions s JOIN trainings t ON t.id = s.training_id
             WHERE s.id = ?",
            [$id]
        );
    }

    public static function createSession(array $data): int
    {
        return Db::insert(
            'INSERT INTO training_sessions (training_id, start_date, end_date, location, seats, status)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $data['trainingId'], $data['startDate'], $data['endDate'], $data['location'] ?? '',
                $data['seats'] ?? 0, $data['status'] ?? 'Planifiée',
            ]
        );
    }

    public static function setSessionStatus(int $id, string $status): bool
    {
        if (!\App\Core\Rattachees::visible('training_sessions', $id)) {
            return false;
        }
        if (!in_array($status, self::SESSION_STATUSES, true)) {
            return false;
        }
        return Db::run('UPDATE training_sessions SET status = ? WHERE id = ?', [$status, $id]) > 0;
    }

    public static function deleteSession(int $id): bool
    {
        if (!\App\Core\Rattachees::visible('training_sessions', $id)) {
            return false;
        }
        if (Db::run('DELETE FROM training_sessions WHERE id = ?', [$id]) === 0) {
            return false;
        }

        return true;
    }

    /** Un salarié demande sa place ; les RH la confirment ou la refusent. */
    public static function requestSeat(int $sessionId, int $employeeId): array
    {
        $session = self::sessionById($sessionId);
        if ($session === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if (in_array($session['status'], ['Terminée', 'Annulée'], true)) {
            return ['ok' => false, 'reason' => 'closed'];
        }
        $existing = Db::get('SELECT * FROM training_registrations WHERE session_id = ? AND employee_id = ?', [$sessionId, $employeeId]);
        if ($existing !== null) {
            return ['ok' => false, 'reason' => 'already-registered'];
        }
        Db::insert('INSERT INTO training_registrations (session_id, employee_id) VALUES (?, ?)', [$sessionId, $employeeId]);
        return ['ok' => true];
    }

    public static function reviewRegistration(int $id, string $status, int $reviewerId): array
    {
        if (!\App\Core\Rattachees::visible('training_registrations', $id)) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if (!in_array($status, self::REGISTRATION_STATUSES, true)) {
            return ['ok' => false, 'reason' => 'bad-status'];
        }
        $registration = Db::get('SELECT * FROM training_registrations WHERE id = ?', [$id]);
        if ($registration === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        // Une session pleine ne prend pas d'inscrit de plus : la limite tient
        // côté serveur, pas seulement dans l'affichage.
        if ($status === 'Inscrite') {
            $session = self::sessionById((int) $registration['session_id']);
            if ($session !== null && (int) $session['seats'] > 0 && (int) $session['taken'] >= (int) $session['seats']) {
                return ['ok' => false, 'reason' => 'full'];
            }
        }
        Db::run(
            'UPDATE training_registrations SET status = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?',
            [$status, $reviewerId, gmdate('c'), $id]
        );
        return ['ok' => true];
    }

    public static function registrations(?int $sessionId = null, ?int $employeeId = null): array
    {
        $clauses = [];
        $params = [];
        if ($sessionId !== null) {
            $clauses[] = 'r.session_id = ?';
            $params[] = $sessionId;
        }
        if ($employeeId !== null) {
            $clauses[] = 'r.employee_id = ?';
            $params[] = $employeeId;
        }
        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);
        return Db::all(
            "SELECT r.*, u.first_name, u.last_name, t.title, s.start_date, s.end_date, s.location, s.status AS session_status
             FROM training_registrations r
             JOIN training_sessions s ON s.id = r.session_id
             JOIN trainings t ON t.id = s.training_id
             JOIN users u ON u.id = r.employee_id
             $where
             ORDER BY s.start_date DESC",
            $params
        );
    }

    public static function cancelOwnRegistration(int $id, int $employeeId): bool
    {
        return Db::run(
            "DELETE FROM training_registrations WHERE id = ? AND employee_id = ? AND status = 'Demandée'",
            [$id, $employeeId]
        ) > 0;
    }

    // ---------- Entretiens annuels ----------

    public static function reviews(?int $employeeId = null): array
    {
        $where = $employeeId === null ? '' : 'AND r.employee_id = ?';
        return Db::all(
            "SELECT r.*, u.first_name, u.last_name,
                    m.first_name AS reviewer_first_name, m.last_name AS reviewer_last_name
             FROM reviews r
             JOIN users u ON u.id = r.employee_id
             LEFT JOIN users m ON m.id = r.reviewer_id
             WHERE r.company_id = ? $where
             ORDER BY r.period DESC, u.last_name COLLATE NOCASE",
            $employeeId === null ? [self::ici()] : [self::ici(), $employeeId]
        );
    }

    public static function reviewById(int $id): ?array
    {
        return Db::get('SELECT * FROM reviews WHERE id = ? AND company_id = ?', [$id, self::ici()]);
    }

    public static function createReview(array $data): int
    {
        $id = Db::insert(
            'INSERT INTO reviews (company_id, employee_id, reviewer_id, period, scheduled_on)
             VALUES (?, ?, ?, ?, ?)',
            [self::ici(), $data['employeeId'], $data['reviewerId'], $data['period'], $data['scheduledOn']]
        );
        Audit::log('entretien.planifie', 'reviews', $id);
        return $id;
    }

    public static function completeReview(int $id, array $data): array
    {
        $review = self::reviewById($id);
        if ($review === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if ($review['status'] === 'Annulé') {
            return ['ok' => false, 'reason' => 'cancelled'];
        }
        $rating = $data['rating'];
        if ($rating !== null && ($rating < 1 || $rating > 5)) {
            return ['ok' => false, 'reason' => 'bad-rating'];
        }
        Db::run(
            "UPDATE reviews SET strengths = ?, improvements = ?, objectives = ?, rating = ?, status = 'Réalisé', completed_at = ?
             WHERE id = ? AND company_id = ?",
            [
                $data['strengths'] ?? '', $data['improvements'] ?? '', $data['objectives'] ?? '',
                $rating, gmdate('c'), $id, self::ici(),
            ]
        );
        Audit::log('entretien.realise', 'reviews', $id);
        return ['ok' => true];
    }

    /** Le salarié ajoute son propre commentaire, et rien d'autre, sur son entretien. */
    public static function addEmployeeComment(int $id, int $employeeId, string $comment): bool
    {
        return Db::run(
            'UPDATE reviews SET employee_comment = ? WHERE id = ? AND employee_id = ? AND company_id = ?',
            [$comment, $id, $employeeId, self::ici()]
        ) > 0;
    }

    public static function cancelReview(int $id): bool
    {
        // Même raison qu'ailleurs : annuler un entretien déjà annulé ne change
        // aucune ligne sur MySQL, et ce n'est pas un entretien introuvable.
        if (Db::get('SELECT id FROM reviews WHERE id = ? AND company_id = ?', [$id, self::ici()]) === null) {
            return false;
        }

        Db::run("UPDATE reviews SET status = 'Annulé' WHERE id = ? AND company_id = ?", [$id, self::ici()]);

        return true;
    }

    public static function deleteReview(int $id): bool
    {
        if (Db::run('DELETE FROM reviews WHERE id = ? AND company_id = ?', [$id, self::ici()]) === 0) {
            return false;
        }

        return true;
    }

    // ---------- Recrutement ----------

    public static function openings(): array
    {
        return Db::all(
            "SELECT o.*, d.name AS department_name, t.name AS team_name,
               (SELECT COUNT(*) FROM candidates c WHERE c.opening_id = o.id) AS candidate_count,
               (SELECT COUNT(*) FROM candidates c WHERE c.opening_id = o.id AND c.stage NOT IN ('Recruté','Refusé')) AS active_count
             FROM job_openings o
             LEFT JOIN departments d ON d.id = o.department_id
             LEFT JOIN teams t ON t.id = o.team_id
             WHERE o.company_id = ?
             ORDER BY o.opened_on DESC",
            [self::ici()]
        );
    }

    public static function openingById(int $id): ?array
    {
        return Db::get('SELECT * FROM job_openings WHERE id = ? AND company_id = ?', [$id, self::ici()]);
    }

    public static function createOpening(array $data): int
    {
        return Db::insert(
            'INSERT INTO job_openings (company_id, title, department_id, team_id, contract_type, description, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                self::ici(), $data['title'], $data['departmentId'] ?? null, $data['teamId'] ?? null,
                $data['contractType'] ?? '', $data['description'] ?? '', $data['createdBy'] ?? null,
            ]
        );
    }

    public static function setOpeningStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::OPENING_STATUSES, true)) {
            return false;
        }
        $closed = in_array($status, ['Pourvu', 'Annulé'], true);
        return Db::run(
            'UPDATE job_openings SET status = ?, closed_on = ? WHERE id = ? AND company_id = ?',
            [$status, $closed ? gmdate('Y-m-d') : null, $id, self::ici()]
        ) > 0;
    }

    public static function deleteOpening(int $id): bool
    {
        if (Db::run('DELETE FROM job_openings WHERE id = ? AND company_id = ?', [$id, self::ici()]) === 0) {
            return false;
        }

        return true;
    }

    public static function candidates(int $openingId): array
    {
        return Db::all('SELECT * FROM candidates WHERE opening_id = ? ORDER BY created_at DESC', [$openingId]);
    }

    public static function createCandidate(array $data): array
    {
        $opening = self::openingById((int) $data['openingId']);
        if ($opening === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if (in_array($opening['status'], ['Pourvu', 'Annulé'], true)) {
            return ['ok' => false, 'reason' => 'closed'];
        }

        Db::insert(
            'INSERT INTO candidates (opening_id, first_name, last_name, email, phone, source, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $data['openingId'], $data['firstName'], $data['lastName'], $data['email'] ?? '',
                $data['phone'] ?? '', $data['source'] ?? '', $data['notes'] ?? '',
            ]
        );
        return ['ok' => true];
    }

    public static function setCandidateStage(int $id, string $stage): bool
    {
        if (!\App\Core\Rattachees::visible('candidates', $id)) {
            return false;
        }
        if (!in_array($stage, self::CANDIDATE_STAGES, true)) {
            return false;
        }
        return Db::run('UPDATE candidates SET stage = ? WHERE id = ?', [$stage, $id]) > 0;
    }

    public static function deleteCandidate(int $id): bool
    {
        if (!\App\Core\Rattachees::visible('candidates', $id)) {
            return false;
        }
        if (Db::run('DELETE FROM candidates WHERE id = ?', [$id]) === 0) {
            return false;
        }

        return true;
    }
}
