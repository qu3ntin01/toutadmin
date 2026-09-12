<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Qualité : non-conformités, actions correctives et audits internes.
 *
 * La boucle est toujours la même — constater, traiter, vérifier que ça a servi.
 * C'est la dernière étape qu'on saute : une action « faite » dont personne n'a
 * mesuré l'effet laisse le même écart revenir six mois plus tard. Le module
 * réclame donc une vérification d'efficacité, à une date qu'on se fixe, et
 * compte séparément ce qui est fait de ce qui est efficace.
 */
final class Quality
{
    public const SOURCES = ['Interne', 'Client', 'Fournisseur', 'Audit', 'Réglementaire'];
    public const SEVERITIES = ['Mineure', 'Majeure', 'Critique'];
    public const NC_STATUSES = ['Ouverte', 'En traitement', 'Clôturée'];
    public const ACTION_KINDS = ['Corrective', 'Préventive', 'Amélioration'];
    public const ACTION_STATUSES = ['À faire', 'En cours', 'Faite'];
    public const EFFECTIVENESS = ['Non vérifiée', 'Efficace', 'Inefficace'];
    public const AUDIT_STATUSES = ['Planifié', 'Réalisé', 'Clos'];
    public const FINDING_KINDS = ['Non-conformité', 'Remarque', 'Point fort'];

    /** Une référence lisible, séquentielle par année : NC-2026-014. */
    public static function nextReference(string $prefix, string $table): string
    {
        $year = (int) gmdate('Y');
        $count = (int) Db::value("SELECT COUNT(*) FROM $table WHERE reference LIKE ?", ["$prefix-$year-%"]);
        return sprintf('%s-%d-%03d', $prefix, $year, $count + 1);
    }

    // ---------- Non-conformités ----------

    public static function nonconformities(?string $status = null, int $limit = 300): array
    {
        $clause = $status !== null ? 'WHERE n.status = ?' : '';
        $params = $status !== null ? [$status, $limit] : [$limit];

        return Db::all(
            "SELECT n.*, u.first_name, u.last_name,
                    (SELECT COUNT(*) FROM quality_actions a WHERE a.nonconformity_id = n.id) AS action_count,
                    (SELECT COUNT(*) FROM quality_actions a WHERE a.nonconformity_id = n.id AND a.status != 'Faite') AS open_actions
             FROM nonconformities n
             LEFT JOIN users u ON u.id = n.detected_by
             $clause
             ORDER BY n.detected_on DESC, n.id DESC LIMIT ?",
            $params
        );
    }

    public static function nonconformityById(int $id): ?array
    {
        return Db::get('SELECT * FROM nonconformities WHERE id = ?', [$id]);
    }

    public static function createNonconformity(array $fields): int
    {
        return Db::insert(
            'INSERT INTO nonconformities (reference, title, description, source, severity, detected_on, detected_by,
                                          subject, immediate_action, root_cause, cost)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                ($fields['reference'] ?? '') ?: self::nextReference('NC', 'nonconformities'),
                $fields['title'],
                $fields['description'] ?? '',
                $fields['source'],
                $fields['severity'],
                $fields['detectedOn'],
                $fields['detectedBy'] ?? null,
                $fields['subject'] ?? '',
                $fields['immediateAction'] ?? '',
                $fields['rootCause'] ?? '',
                $fields['cost'] ?? 0,
            ]
        );
    }

    /**
     * Clôturer n'est possible qu'une fois les actions faites : une non-conformité
     * fermée avec du travail en cours est une fermeture de façade.
     */
    public static function closeNonconformity(int $id): array
    {
        $open = (int) Db::value(
            "SELECT COUNT(*) FROM quality_actions WHERE nonconformity_id = ? AND status != 'Faite'",
            [$id]
        );
        if ($open > 0) {
            return ['ok' => false, 'message' => $open . " action(s) encore en cours : la non-conformité ne peut pas être clôturée."];
        }

        Db::run("UPDATE nonconformities SET status = 'Clôturée', closed_on = date('now') WHERE id = ?", [$id]);
        return ['ok' => true];
    }

    public static function setNonconformityStatus(int $id, string $status): array
    {
        if (!in_array($status, self::NC_STATUSES, true)) {
            return ['ok' => false, 'message' => 'Statut inconnu.'];
        }
        if ($status === 'Clôturée') {
            return self::closeNonconformity($id);
        }
        Db::run('UPDATE nonconformities SET status = ?, closed_on = NULL WHERE id = ?', [$status, $id]);
        return ['ok' => true];
    }

    public static function setRootCause(int $id, string $rootCause): void
    {
        Db::run('UPDATE nonconformities SET root_cause = ? WHERE id = ?', [$rootCause, $id]);
    }

    public static function deleteNonconformity(int $id): void
    {
        Db::run('DELETE FROM nonconformities WHERE id = ?', [$id]);
    }

    // ---------- Actions ----------

    public static function actions(array $filters = []): array
    {
        $clauses = [];
        $params = [];
        if (!empty($filters['nonconformityId'])) {
            $clauses[] = 'a.nonconformity_id = ?';
            $params[] = (int) $filters['nonconformityId'];
        }
        if (!empty($filters['auditId'])) {
            $clauses[] = 'a.audit_id = ?';
            $params[] = (int) $filters['auditId'];
        }
        if (!empty($filters['ownerId'])) {
            $clauses[] = 'a.owner_id = ?';
            $params[] = (int) $filters['ownerId'];
        }
        if (!empty($filters['openOnly'])) {
            $clauses[] = "a.status != 'Faite'";
        }
        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);

        return Db::all(
            "SELECT a.*, u.first_name, u.last_name, n.reference AS nc_reference, n.title AS nc_title,
                    i.reference AS audit_reference
             FROM quality_actions a
             LEFT JOIN users u ON u.id = a.owner_id
             LEFT JOIN nonconformities n ON n.id = a.nonconformity_id
             LEFT JOIN internal_audits i ON i.id = a.audit_id
             $where
             ORDER BY a.due_date IS NULL, a.due_date, a.id",
            $params
        );
    }

    public static function createAction(array $fields): int
    {
        return Db::insert(
            'INSERT INTO quality_actions (nonconformity_id, audit_id, kind, label, owner_id, due_date)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $fields['nonconformityId'] ?? null,
                $fields['auditId'] ?? null,
                $fields['kind'],
                $fields['label'],
                $fields['ownerId'] ?? null,
                $fields['dueDate'] ?? null,
            ]
        );
    }

    public static function setActionStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::ACTION_STATUSES, true)) {
            return false;
        }
        $done = $status === 'Faite' ? gmdate('Y-m-d') : null;
        return Db::run('UPDATE quality_actions SET status = ?, done_on = ? WHERE id = ?', [$status, $done, $id]) > 0;
    }

    /** La vérification d'efficacité : ce qui distingue une action d'une intention. */
    public static function verifyAction(int $id, string $effectiveness, ?int $verifiedBy = null): array
    {
        if (!in_array($effectiveness, self::EFFECTIVENESS, true) || $effectiveness === 'Non vérifiée') {
            return ['ok' => false, 'message' => 'Verdict attendu : efficace ou inefficace.'];
        }
        $action = Db::get('SELECT * FROM quality_actions WHERE id = ?', [$id]);
        if ($action === null) {
            return ['ok' => false, 'message' => 'Action introuvable.'];
        }
        if ($action['status'] !== 'Faite') {
            return ['ok' => false, 'message' => 'Une action encore en cours ne peut pas être jugée efficace.'];
        }

        Db::run(
            "UPDATE quality_actions SET effectiveness = ?, verified_on = date('now'), verified_by = ? WHERE id = ?",
            [$effectiveness, $verifiedBy, $id]
        );
        return ['ok' => true];
    }

    public static function deleteAction(int $id): void
    {
        Db::run('DELETE FROM quality_actions WHERE id = ?', [$id]);
    }

    // ---------- Audits internes ----------

    public static function audits(): array
    {
        return Db::all(
            "SELECT a.*, u.first_name, u.last_name,
                    (SELECT COUNT(*) FROM audit_findings f WHERE f.audit_id = a.id) AS finding_count,
                    (SELECT COUNT(*) FROM audit_findings f WHERE f.audit_id = a.id AND f.kind = 'Non-conformité') AS nc_count
             FROM internal_audits a LEFT JOIN users u ON u.id = a.auditor_id
             ORDER BY COALESCE(a.done_on, a.planned_on) DESC, a.id DESC"
        );
    }

    public static function auditById(int $id): ?array
    {
        return Db::get('SELECT * FROM internal_audits WHERE id = ?', [$id]);
    }

    public static function createAudit(array $fields): int
    {
        return Db::insert(
            'INSERT INTO internal_audits (reference, scope, standard, planned_on, auditor_id, summary)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                ($fields['reference'] ?? '') ?: self::nextReference('AUD', 'internal_audits'),
                $fields['scope'],
                $fields['standard'] ?? '',
                $fields['plannedOn'] ?? null,
                $fields['auditorId'] ?? null,
                $fields['summary'] ?? '',
            ]
        );
    }

    public static function completeAudit(int $id, string $doneOn, string $summary = ''): void
    {
        Db::run(
            "UPDATE internal_audits SET status = 'Réalisé', done_on = ?, summary = ? WHERE id = ?",
            [$doneOn, $summary, $id]
        );
    }

    public static function deleteAudit(int $id): void
    {
        Db::run('DELETE FROM internal_audits WHERE id = ?', [$id]);
    }

    public static function findings(int $auditId): array
    {
        return Db::all('SELECT * FROM audit_findings WHERE audit_id = ? ORDER BY id', [$auditId]);
    }

    public static function addFinding(int $auditId, string $kind, string $clause, string $statement): int
    {
        return Db::insert(
            'INSERT INTO audit_findings (audit_id, kind, clause, statement) VALUES (?, ?, ?, ?)',
            [$auditId, $kind, $clause, $statement]
        );
    }

    public static function deleteFinding(int $id): void
    {
        Db::run('DELETE FROM audit_findings WHERE id = ?', [$id]);
    }

    /**
     * Un constat d'audit qui reste un constat ne sert à rien : il se transforme en
     * non-conformité, avec sa référence propre, et les deux restent liés par le
     * texte du constat.
     */
    public static function promoteFinding(int $findingId, ?int $detectedBy = null): array
    {
        $finding = Db::get(
            'SELECT f.*, a.reference AS audit_reference, a.scope
             FROM audit_findings f JOIN internal_audits a ON a.id = f.audit_id WHERE f.id = ?',
            [$findingId]
        );
        if ($finding === null) {
            return ['ok' => false, 'message' => 'Constat introuvable.'];
        }
        if ($finding['kind'] !== 'Non-conformité') {
            return ['ok' => false, 'message' => 'Seul un constat de non-conformité se transforme ainsi.'];
        }

        $id = self::createNonconformity([
            'title' => mb_substr($finding['statement'], 0, 200),
            'description' => sprintf(
                "Constat de l'audit %s%s — %s.",
                $finding['audit_reference'],
                $finding['clause'] !== '' ? ' (exigence ' . $finding['clause'] . ')' : '',
                $finding['scope']
            ),
            'source' => 'Audit',
            'severity' => 'Majeure',
            'detectedOn' => gmdate('Y-m-d'),
            'detectedBy' => $detectedBy,
            'subject' => $finding['scope'],
        ]);
        return ['ok' => true, 'id' => $id];
    }

    // ---------- Indicateurs ----------

    public static function summary(): array
    {
        return [
            'open' => (int) Db::value("SELECT COUNT(*) FROM nonconformities WHERE status != 'Clôturée'"),
            'critical' => (int) Db::value(
                "SELECT COUNT(*) FROM nonconformities WHERE status != 'Clôturée' AND severity = 'Critique'"
            ),
            'openActions' => (int) Db::value("SELECT COUNT(*) FROM quality_actions WHERE status != 'Faite'"),
            'awaitingVerification' => (int) Db::value(
                "SELECT COUNT(*) FROM quality_actions WHERE status = 'Faite' AND effectiveness = 'Non vérifiée'"
            ),
            'ineffective' => (int) Db::value("SELECT COUNT(*) FROM quality_actions WHERE effectiveness = 'Inefficace'"),
            'cost' => round((float) Db::value(
                "SELECT COALESCE(SUM(cost), 0) FROM nonconformities WHERE detected_on >= date('now', '-1 year')"
            ), 2),
        ];
    }
}
