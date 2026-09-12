<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Échéances de l'entreprise, rassemblées en un seul endroit.
 *
 * Chaque espace sait ce qui arrive à terme chez lui ; personne ne voit
 * l'ensemble. Ce module interroge toutes les sources, rend une liste homogène,
 * et en tire les notifications. Une seule règle : un objet, une date, un
 * destinataire — le reste est de la mise en forme.
 */
final class Deadlines
{
    public const HORIZON_DAYS = 45;

    private static function today(): string
    {
        return gmdate('Y-m-d');
    }

    private static function shift(int $days): string
    {
        return gmdate('Y-m-d', (int) strtotime("$days days"));
    }

    public static function shiftFrom(string $iso, int $days): string
    {
        return gmdate('Y-m-d', (int) strtotime($iso . " UTC $days days"));
    }

    private static function ids(string $sql): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], Db::all($sql));
    }

    /** Les administrateurs et les RH : destinataires par défaut de ce qui n'a pas de porteur. */
    private static function stewards(): array
    {
        return self::ids("SELECT id FROM users WHERE active = 1 AND (role = 'admin' OR is_hr = 1)");
    }

    /** Le service informatique : destinataire de ce qui touche au parc logiciel. */
    private static function itStewards(): array
    {
        return self::ids("SELECT id FROM users WHERE active = 1 AND (role = 'admin' OR is_it = 1)");
    }

    /** L'administration seule : la vie sociale de la société ne se délègue pas. */
    private static function admins(): array
    {
        return self::ids("SELECT id FROM users WHERE active = 1 AND role = 'admin'");
    }

    private static function financeStewards(): array
    {
        return self::ids("SELECT id FROM users WHERE active = 1 AND (role = 'admin' OR is_finance = 1)");
    }

    private static function audience(array $people): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $people))));
    }

    /**
     * Toutes les échéances à l'horizon, chacune sous la même forme :
     * ['source', 'label', 'detail', 'due', 'overdue', 'link', 'audience'].
     */
    public static function collect(int $withinDays = self::HORIZON_DAYS): array
    {
        $limit = self::shift($withinDays);
        $now = self::today();
        $rows = [];

        $add = static function (string $source, string $label, string $detail, ?string $due, string $link, array $audience) use (&$rows, $now): void {
            if ($due === null || $due === '') {
                return;
            }
            $rows[] = [
                'source' => $source, 'label' => $label, 'detail' => $detail,
                'due' => $due, 'overdue' => $due < $now, 'link' => $link, 'audience' => $audience,
            ];
        };

        // --- Contrats de travail arrivant à terme
        foreach (Db::all(
            'SELECT id, first_name, last_name, contract_type, contract_end_date FROM users
             WHERE active = 1 AND contract_end_date IS NOT NULL AND contract_end_date <= ?',
            [$limit]
        ) as $user) {
            $add('Contrat de travail', $user['first_name'] . ' ' . $user['last_name'],
                'Fin de ' . ($user['contract_type'] ?: 'contrat'), $user['contract_end_date'],
                '/admin#personnel', self::stewards());
        }

        // --- Contrats fournisseurs et clients, avec leur préavis
        foreach (Db::all(
            "SELECT id, title, end_date, notice_days, owner_id FROM partner_contracts
             WHERE status = 'Actif' AND end_date IS NOT NULL
               AND date(end_date, '-' || COALESCE(notice_days, 0) || ' days') <= ?",
            [$limit]
        ) as $contract) {
            $notice = (int) ($contract['notice_days'] ?? 0);
            // C'est la date limite de dénonciation qui compte, pas la fin du contrat :
            // passé le préavis, la reconduction est acquise.
            $deadline = $notice > 0 ? self::shiftFrom($contract['end_date'], -$notice) : $contract['end_date'];
            $add('Contrat', $contract['title'], $notice > 0 ? "Préavis de $notice jours" : 'Échéance',
                $deadline, '/gestion#contrats',
                self::audience(array_merge([$contract['owner_id']], self::financeStewards())));
        }

        // --- Factures non réglées
        foreach (Db::all(
            "SELECT id, reference, label, direction, due_date FROM invoices
             WHERE status != 'Payée' AND due_date IS NOT NULL AND due_date <= ?",
            [$limit]
        ) as $invoice) {
            $add('Facture', $invoice['reference'] ?: $invoice['label'], $invoice['direction'],
                $invoice['due_date'], '/gestion#factures', self::financeStewards());
        }

        // --- Habilitations
        foreach (Db::all(
            'SELECT us.expires_on, s.name, u.id AS user_id, u.first_name, u.last_name
             FROM user_skills us JOIN skills s ON s.id = us.skill_id JOIN users u ON u.id = us.user_id
             WHERE u.active = 1 AND us.expires_on IS NOT NULL AND us.expires_on <= ?',
            [$limit]
        ) as $held) {
            $add('Habilitation', $held['first_name'] . ' ' . $held['last_name'], $held['name'],
                $held['expires_on'], '/parcours#echeances',
                self::audience(array_merge([$held['user_id']], self::stewards())));
        }

        // --- Visites médicales : la personne est prévenue de la sienne, c'est elle qui s'y rend.
        foreach (Db::all(
            'SELECT v.next_due, v.kind, u.id AS user_id, u.first_name, u.last_name
             FROM medical_visits v JOIN users u ON u.id = v.user_id
             WHERE u.active = 1 AND v.next_due IS NOT NULL AND v.next_due <= ?',
            [$limit]
        ) as $visit) {
            $add('Visite médicale', $visit['first_name'] . ' ' . $visit['last_name'], $visit['kind'],
                $visit['next_due'], '/sante-securite#echeances',
                self::audience(array_merge([$visit['user_id']], self::stewards())));
        }

        // --- Équipements de protection
        foreach (Db::all(
            'SELECT a.expires_on, p.name, u.id AS user_id, u.first_name, u.last_name
             FROM ppe_assignments a JOIN ppe_items p ON p.id = a.ppe_id JOIN users u ON u.id = a.user_id
             WHERE a.returned_on IS NULL AND a.expires_on IS NOT NULL AND a.expires_on <= ?',
            [$limit]
        ) as $ppe) {
            $add('Protection', $ppe['first_name'] . ' ' . $ppe['last_name'], $ppe['name'],
                $ppe['expires_on'], '/sante-securite#echeances',
                self::audience(array_merge([$ppe['user_id']], self::stewards())));
        }

        // --- Véhicules : trois échéances par véhicule
        foreach (Db::all("SELECT * FROM vehicles WHERE status != 'Cédé'") as $vehicle) {
            foreach ([
                ['insurance_due', 'Assurance'],
                ['inspection_due', 'Contrôle technique'],
                ['service_due', 'Entretien'],
            ] as [$field, $label]) {
                if (!empty($vehicle[$field]) && $vehicle[$field] <= $limit) {
                    $add('Véhicule', $vehicle['registration'], $label, $vehicle[$field],
                        '/flotte/' . (int) $vehicle['id'],
                        self::audience(array_merge([$vehicle['assigned_to']], self::financeStewards())));
                }
            }
        }

        // --- Revue du document unique
        foreach (Db::all(
            'SELECT * FROM risk_assessments WHERE next_review IS NOT NULL AND next_review <= ?',
            [$limit]
        ) as $risk) {
            $add('Document unique', $risk['hazard'], $risk['unit'], $risk['next_review'],
                '/sante-securite#risques', self::stewards());
        }

        // --- Tâches de projet
        foreach (Db::all(
            "SELECT t.id, t.title, t.due_date, t.assignee_id, p.id AS project_id, p.name AS project_name
             FROM project_tasks t JOIN projects p ON p.id = t.project_id
             WHERE p.archived = 0 AND t.status != 'Terminée' AND t.due_date IS NOT NULL AND t.due_date <= ?",
            [$limit]
        ) as $task) {
            $add('Tâche', $task['title'], $task['project_name'], $task['due_date'],
                '/projets/' . (int) $task['project_id'] . '#taches',
                $task['assignee_id'] !== null ? [(int) $task['assignee_id']] : []);
        }

        // --- Actions décidées en réunion
        foreach (Db::all(
            "SELECT a.id, a.label, a.due_date, a.assignee_id, m.title AS meeting_title
             FROM meeting_actions a LEFT JOIN meetings m ON m.id = a.meeting_id
             WHERE a.status NOT IN ('Faite','Abandonnée') AND a.due_date IS NOT NULL AND a.due_date <= ?",
            [$limit]
        ) as $action) {
            $add('Action de direction', $action['label'], $action['meeting_title'] ?: 'Hors réunion',
                $action['due_date'], '/direction#actions',
                $action['assignee_id'] !== null ? [(int) $action['assignee_id']] : self::stewards());
        }

        // --- Décisions à réexaminer
        foreach (Db::all(
            "SELECT id, title, scope, review_on FROM decisions
             WHERE status = 'En vigueur' AND review_on IS NOT NULL AND review_on <= ?",
            [$limit]
        ) as $decision) {
            $add('Décision', $decision['title'], 'À réexaminer — ' . $decision['scope'],
                $decision['review_on'], '/direction#decisions', self::stewards());
        }

        // --- Revue des risques de l'entreprise
        foreach (Db::all(
            "SELECT id, title, category, next_review, owner_id FROM enterprise_risks
             WHERE status != 'Clos' AND next_review IS NOT NULL AND next_review <= ?",
            [$limit]
        ) as $risk) {
            $add('Risque', $risk['title'], 'Revue — ' . $risk['category'], $risk['next_review'],
                '/direction#risques', self::audience(array_merge([$risk['owner_id']], self::stewards())));
        }

        // --- Actions qualité
        foreach (Db::all(
            "SELECT a.id, a.label, a.due_date, a.owner_id, a.kind, n.reference
             FROM quality_actions a LEFT JOIN nonconformities n ON n.id = a.nonconformity_id
             WHERE a.status != 'Faite' AND a.due_date IS NOT NULL AND a.due_date <= ?",
            [$limit]
        ) as $action) {
            $add('Qualité', $action['label'],
                'Action ' . mb_strtolower((string) $action['kind'])
                    . ($action['reference'] !== null ? ' — ' . $action['reference'] : ''),
                $action['due_date'], '/qualite#actions',
                $action['owner_id'] !== null ? [(int) $action['owner_id']] : self::stewards());
        }

        // --- Points de parcours d'arrivée ou de départ
        foreach (Db::all(
            'SELECT i.id, i.label, i.due_date, c.kind, u.first_name, u.last_name
             FROM checklist_items i JOIN checklists c ON c.id = i.checklist_id JOIN users u ON u.id = c.user_id
             WHERE i.done_at IS NULL AND i.due_date IS NOT NULL AND i.due_date <= ?',
            [$limit]
        ) as $item) {
            $add('Parcours', $item['label'],
                $item['kind'] . ' — ' . $item['first_name'] . ' ' . $item['last_name'],
                $item['due_date'], '/parcours#parcours', self::stewards());
        }

        // --- Renouvellement des licences logicielles
        foreach (Db::all(
            "SELECT id, name, billing_period, renewal_date, owner_id FROM software_licences
             WHERE status != 'Retiré' AND renewal_date IS NOT NULL AND renewal_date <= ?",
            [$limit]
        ) as $licence) {
            $add('Licence', $licence['name'], 'Renouvellement — ' . $licence['billing_period'],
                $licence['renewal_date'], '/informatique#logiciels',
                self::audience(array_merge([$licence['owner_id']], self::itStewards())));
        }

        // --- Pièces de conformité d'un tiers : une attestation périmée engage le
        //     donneur d'ordre, c'est donc une échéance et non une pièce jointe.
        foreach (Db::all(
            'SELECT d.kind, d.expires_on, p.name FROM partner_documents d
             JOIN partners p ON p.id = d.partner_id
             WHERE d.expires_on IS NOT NULL AND d.expires_on <= ?',
            [$limit]
        ) as $document) {
            $add('Conformité tiers', $document['name'], $document['kind'], $document['expires_on'],
                '/gestion#tiers', self::financeStewards());
        }

        // --- Évaluations de tiers à refaire. Seule la dernière évaluation compte :
        //     sans cela, un tiers évalué dix fois ferait dix échéances.
        foreach (Db::all(
            'SELECT r.next_review, p.id, p.name FROM partner_reviews r
             JOIN partners p ON p.id = r.partner_id
             WHERE r.id = (SELECT r2.id FROM partner_reviews r2 WHERE r2.partner_id = p.id
                           ORDER BY r2.reviewed_on DESC, r2.id DESC LIMIT 1)
               AND r.next_review IS NOT NULL AND r.next_review <= ?',
            [$limit]
        ) as $review) {
            $add('Évaluation tiers', $review['name'], 'Revue à refaire', $review['next_review'],
                '/partenaires/' . (int) $review['id'] . '#evaluations', self::financeStewards());
        }

        // --- Clôture des inscriptions à un événement : c'est l'organisateur qui
        //     arrête la liste, et lui seul est prévenu.
        foreach (Db::all(
            "SELECT id, title, registration_closes_on, organizer_id FROM company_events
             WHERE status = 'Ouvert' AND registration_closes_on IS NOT NULL AND registration_closes_on <= ?",
            [$limit]
        ) as $event) {
            $add('Événement', $event['title'], 'Clôture des inscriptions', $event['registration_closes_on'],
                '/evenements/' . (int) $event['id'],
                $event['organizer_id'] !== null ? [(int) $event['organizer_id']] : self::stewards());
        }

        // --- Points individuels à tenir
        foreach (Db::all(
            "SELECT o.id, o.scheduled_on, o.manager_id, u.first_name, u.last_name
             FROM one_on_ones o JOIN users u ON u.id = o.employee_id
             WHERE o.status = 'Planifié' AND o.scheduled_on <= ?",
            [$limit]
        ) as $point) {
            $add('Point individuel', $point['first_name'] . ' ' . $point['last_name'], 'À tenir',
                $point['scheduled_on'], '/mon-equipe#points', [(int) $point['manager_id']]);
        }

        // --- Délais du dispositif d'alerte : accusé de réception sous 7 jours,
        //     retour sur les suites sous 3 mois. Adressés aux seuls référents, et
        //     désignant la référence du signalement, jamais son objet.
        $referents = self::ids('SELECT id FROM users WHERE active = 1 AND is_referent = 1');
        if ($referents !== []) {
            foreach (Db::all(
                "SELECT id, reference, submitted_at, acknowledged_at FROM whistleblow_reports
                 WHERE status != 'Clôturée'"
            ) as $report) {
                $filed = substr((string) $report['submitted_at'], 0, 10);
                if ($report['acknowledged_at'] === null) {
                    $due = self::shiftFrom($filed, 7);
                    if ($due <= $limit) {
                        $add('Alerte', $report['reference'], 'Accusé de réception', $due,
                            '/alertes/signalements/' . (int) $report['id'], $referents);
                    }
                }
                $outcome = self::shiftFrom($filed, 90);
                if ($outcome <= $limit) {
                    $add('Alerte', $report['reference'], 'Retour sur les suites', $outcome,
                        '/alertes/signalements/' . (int) $report['id'], $referents);
                }
            }
        }

        // --- Mandats sociaux et délégations de pouvoir arrivant à terme. Un mandat
        //     échu qui continue d'être exercé engage la société sur des actes que
        //     personne n'avait le pouvoir de signer : c'est l'échéance qu'on oublie
        //     parce qu'elle ne se rappelle à personne.
        $boards = self::admins();
        if ($boards !== []) {
            foreach (Db::all(
                "SELECT id, holder_name, role, ends_on FROM corporate_mandates
                 WHERE status = 'En cours' AND ends_on IS NOT NULL AND ends_on <= ?",
                [$limit]
            ) as $mandate) {
                $add('Mandat social', $mandate['holder_name'], 'Fin de mandat — ' . $mandate['role'],
                    $mandate['ends_on'], '/juridique#mandats', $boards);
            }

            foreach (Db::all(
                "SELECT d.id, d.scope, d.ends_on, u.first_name, u.last_name
                 FROM power_delegations d JOIN users u ON u.id = d.holder_id
                 WHERE d.status = 'En vigueur' AND d.ends_on IS NOT NULL AND d.ends_on <= ?",
                [$limit]
            ) as $delegation) {
                $add('Délégation de pouvoir', $delegation['first_name'] . ' ' . $delegation['last_name'],
                    $delegation['scope'], $delegation['ends_on'], '/juridique#delegations', $boards);
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['due'], $b['due']));
        return $rows;
    }

    /**
     * Transforme les échéances en notifications. Rejouable à volonté : la clé de
     * déduplication empêche qu'une même échéance alerte deux fois.
     */
    public static function notify(int $withinDays = 15): int
    {
        $created = 0;
        foreach (self::collect($withinDays) as $row) {
            $key = $row['source'] . ':' . $row['label'] . ':' . $row['due'];
            foreach ($row['audience'] as $userId) {
                $done = Notifications::push((int) $userId, $row['source'] . ' — ' . $row['label'], [
                    'kind' => 'echeance',
                    'body' => trim($row['detail'] . ($row['overdue'] ? ' · échéance dépassée' : '') . ' (' . $row['due'] . ')'),
                    'link' => $row['link'],
                    'dedupeKey' => $key,
                ]);
                if ($done) {
                    $created++;
                }
            }
        }
        return $created;
    }

    /** Le compte de ce qui presse, par source : c'est ce qu'affiche le tableau de bord. */
    public static function summary(int $withinDays = self::HORIZON_DAYS): array
    {
        $rows = self::collect($withinDays);
        $bySource = [];
        foreach ($rows as $row) {
            $bySource[$row['source']] ??= ['total' => 0, 'overdue' => 0];
            $bySource[$row['source']]['total']++;
            if ($row['overdue']) {
                $bySource[$row['source']]['overdue']++;
            }
        }
        return [
            'total' => count($rows),
            'overdue' => count(array_filter($rows, static fn (array $row): bool => $row['overdue'])),
            'bySource' => $bySource,
            'rows' => $rows,
        ];
    }
}
