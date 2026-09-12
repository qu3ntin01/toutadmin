<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Conformité au règlement sur les données personnelles.
 *
 * Deux obligations distinctes : tenir le registre des traitements, et savoir
 * répondre à une personne qui demande ce qu'on détient sur elle, ou son
 * effacement. L'export rassemble ce que les vingt tables savent de quelqu'un ;
 * l'effacement distingue ce qui se supprime de ce qui doit être conservé —
 * un bulletin de paie ne s'efface pas sur demande.
 */
final class Privacy
{
    public const LEGAL_BASES = [
        'Exécution du contrat',
        'Obligation légale',
        'Intérêt légitime',
        'Consentement',
        'Sauvegarde des intérêts vitaux',
        "Mission d'intérêt public",
    ];

    /**
     * Le registre livré à l'installation : les traitements qu'un CMS de gestion
     * du personnel opère nécessairement. À compléter, pas à croire complet.
     */
    public const STARTER_RECORDS = [
        [
            'name' => 'Gestion administrative du personnel',
            'purpose' => 'Tenue du dossier du salarié, contrat, rattachement, coordonnées professionnelles.',
            'legalBasis' => 'Exécution du contrat',
            'dataCategories' => 'Identité, coordonnées, contrat, grade, service et équipe.',
            'recipients' => 'Administration, ressources humaines, manager du périmètre.',
            'retention' => 'Durée du contrat, puis cinq ans à compter du départ.',
            'measures' => 'Accès par rôle, journal d\'audit, chiffrement du transport, mots de passe hachés.',
        ],
        [
            'name' => 'Gestion des congés et des absences',
            'purpose' => 'Instruction des demandes, décompte des soldes, planification des équipes.',
            'legalBasis' => 'Exécution du contrat',
            'dataCategories' => 'Dates, type de demande, motif facultatif, solde.',
            'recipients' => 'Ressources humaines et manager du périmètre. Le motif n\'est jamais partagé à l\'équipe.',
            'retention' => 'Cinq ans.',
            'measures' => 'Agenda partagé sans motif ni type d\'absence ; cloisonnement par périmètre.',
        ],
        [
            'name' => 'Paie et rémunération',
            'purpose' => 'Établissement des bulletins, suivi des rémunérations et des pointages.',
            'legalBasis' => 'Obligation légale',
            'dataCategories' => 'Salaire, taux journalier, heures pointées, bulletins.',
            'recipients' => 'Ressources humaines, gestion, expert-comptable.',
            'retention' => 'Cinq ans pour les éléments de paie ; les bulletins doivent rester accessibles cinquante ans.',
            'measures' => 'Accès réservé aux rôles RH et gestion, tracé au journal d\'audit.',
        ],
        [
            'name' => 'Recrutement',
            'purpose' => 'Instruction des candidatures, analyse des CV et suivi des étapes.',
            'legalBasis' => 'Intérêt légitime',
            'dataCategories' => 'Identité, coordonnées, CV et son texte extrait, expérience, score de filtrage.',
            'recipients' => 'Ressources humaines uniquement.',
            'retention' => 'Deux ans après le dernier contact, sauf opposition de la personne.',
            'measures' => 'CV stockés hors du dépôt en 0600, servis par une route authentifiée, jamais en statique. Le score aide à trier, il ne décide de rien.',
        ],
        [
            'name' => 'Santé et sécurité au travail',
            'purpose' => 'Registre des accidents, suivi des visites médicales et des protections remises.',
            'legalBasis' => 'Obligation légale',
            'dataCategories' => 'Circonstances d\'accident, jours d\'arrêt, avis d\'aptitude, équipements remis.',
            'recipients' => 'Ressources humaines, membres du comité social et économique le cas échéant.',
            'retention' => 'Cinq ans pour le registre ; la durée réglementaire pour le suivi médical.',
            'measures' => 'Seul l\'avis d\'aptitude est consigné : aucune donnée de santé n\'est saisie dans l\'outil.',
        ],
        [
            'name' => 'Journalisation et sécurité',
            'purpose' => 'Traçabilité des actions, détection des accès anormaux, preuve en cas d\'incident.',
            'legalBasis' => 'Intérêt légitime',
            'dataCategories' => 'Identifiant, action, objet visé, adresse IP, horodatage.',
            'recipients' => 'Administration.',
            'retention' => 'Un an par défaut, réglable dans la console de sécurité.',
            'measures' => 'Journal non modifiable depuis l\'interface, purge automatique au-delà de la durée retenue.',
        ],
    ];

    /**
     * Chaque entrée dit d'où vient la donnée, et si elle peut être effacée sur
     * demande. Ce qui relève d'une obligation légale de conservation ne le peut
     * pas.
     */
    public const PERSONAL_SOURCES = [
        ['key' => 'compte', 'table' => 'users', 'column' => 'id',
         'label' => 'Compte', 'erasable' => false,
         'query' => 'SELECT id, email, first_name, last_name, grade, contract_type, contract_end_date, phone, bio, locale, created_at, last_login_at FROM users WHERE id = ?'],
        ['key' => 'demandes', 'table' => 'hr_requests', 'column' => 'employee_id',
         'label' => 'Demandes de congés et absences', 'erasable' => true,
         'query' => 'SELECT id, type, start_date, end_date, days, reason, status, created_at FROM hr_requests WHERE employee_id = ?'],
        ['key' => 'ajustements', 'table' => 'leave_adjustments', 'column' => 'employee_id',
         'label' => 'Ajustements de solde', 'erasable' => true,
         'query' => 'SELECT id, amount, reason, created_at FROM leave_adjustments WHERE employee_id = ?'],
        ['key' => 'bulletins', 'table' => 'payslips', 'column' => 'employee_id',
         'label' => 'Bulletins de paie', 'erasable' => false,
         'query' => 'SELECT id, period, gross_amount, net_amount, status, created_at FROM payslips WHERE employee_id = ?'],
        ['key' => 'pointages', 'table' => 'time_entries', 'column' => 'employee_id',
         'label' => 'Pointages', 'erasable' => true,
         'query' => 'SELECT id, clock_in, clock_out, created_at FROM time_entries WHERE employee_id = ?'],
        ['key' => 'affectations', 'table' => 'assignments', 'column' => 'employee_id',
         'label' => 'Outils affectés', 'erasable' => true,
         'query' => 'SELECT id, tool_id, username, assigned_at FROM assignments WHERE employee_id = ?'],
        ['key' => 'equipements', 'table' => 'asset_assignments', 'column' => 'employee_id',
         'label' => 'Équipements affectés', 'erasable' => true,
         'query' => 'SELECT id, asset_id, assigned_at, returned_at FROM asset_assignments WHERE employee_id = ?'],
        ['key' => 'messages', 'table' => 'messages', 'column' => 'sender_id',
         'label' => 'Messagerie interne', 'erasable' => true,
         'query' => 'SELECT id, sender_id, recipient_id, subject, created_at FROM messages WHERE sender_id = ? OR recipient_id = ?'],
        ['key' => 'agenda', 'table' => 'calendar_events', 'column' => 'user_id',
         'label' => 'Agenda personnel', 'erasable' => true,
         'query' => 'SELECT id, title, start_date, end_date, visibility FROM calendar_events WHERE user_id = ?'],
        ['key' => 'competences', 'table' => 'user_skills', 'column' => 'user_id',
         'label' => 'Compétences et habilitations', 'erasable' => false,
         'query' => 'SELECT us.id, s.name, us.level, us.obtained_on, us.expires_on FROM user_skills us JOIN skills s ON s.id = us.skill_id WHERE us.user_id = ?'],
        ['key' => 'visites', 'table' => 'medical_visits', 'column' => 'user_id',
         'label' => 'Visites médicales', 'erasable' => false,
         'query' => 'SELECT id, kind, scheduled_on, done_on, verdict, next_due FROM medical_visits WHERE user_id = ?'],
        ['key' => 'accidents', 'table' => 'workplace_incidents', 'column' => 'user_id',
         'label' => 'Registre des accidents', 'erasable' => false,
         'query' => 'SELECT id, occurred_on, kind, location, days_off FROM workplace_incidents WHERE user_id = ?'],
        ['key' => 'protections', 'table' => 'ppe_assignments', 'column' => 'user_id',
         'label' => 'Équipements de protection remis', 'erasable' => true,
         'query' => 'SELECT id, ppe_id, issued_on, expires_on, returned_on FROM ppe_assignments WHERE user_id = ?'],
        ['key' => 'temps_projet', 'table' => 'project_time', 'column' => 'user_id',
         'label' => 'Temps passé sur les projets', 'erasable' => true,
         'query' => 'SELECT id, project_id, spent_on, hours, note FROM project_time WHERE user_id = ?'],
        ['key' => 'taches', 'table' => 'project_tasks', 'column' => 'assignee_id',
         'label' => 'Tâches assignées', 'erasable' => true,
         'query' => 'SELECT id, project_id, title, status, due_date FROM project_tasks WHERE assignee_id = ?'],
        ['key' => 'tickets', 'table' => 'tickets', 'column' => 'requester_id',
         'label' => 'Tickets ouverts', 'erasable' => true,
         'query' => 'SELECT id, reference, subject, status, created_at FROM tickets WHERE requester_id = ?'],
        ['key' => 'frais', 'table' => 'expense_claims', 'column' => 'employee_id',
         'label' => 'Notes de frais', 'erasable' => false,
         'query' => 'SELECT id, spent_on, category, description, amount, status FROM expense_claims WHERE employee_id = ?'],
        ['key' => 'parcours', 'table' => 'checklists', 'column' => 'user_id',
         'label' => 'Parcours d\'arrivée et de départ', 'erasable' => true,
         'query' => 'SELECT id, kind, reference_date, completed_at FROM checklists WHERE user_id = ?'],
        ['key' => 'notifications', 'table' => 'notifications', 'column' => 'user_id',
         'label' => 'Notifications', 'erasable' => true,
         'query' => 'SELECT id, title, body, created_at, read_at FROM notifications WHERE user_id = ?'],
        ['key' => 'evenements', 'table' => 'event_registrations', 'column' => 'user_id',
         'label' => 'Inscriptions aux événements', 'erasable' => true,
         'query' => 'SELECT id, event_id, status, registered_at FROM event_registrations WHERE user_id = ?'],
        ['key' => 'points_individuels', 'table' => 'one_on_ones', 'column' => 'employee_id',
         'label' => 'Points individuels', 'erasable' => true,
         'query' => 'SELECT id, scheduled_on, held_on, topics, shared_note, private_note, mood, status FROM one_on_ones WHERE employee_id = ?'],
        ['key' => 'acces_logiciels', 'table' => 'software_accesses', 'column' => 'user_id',
         'label' => 'Accès aux logiciels', 'erasable' => false,
         'query' => 'SELECT id, licence_id, level, granted_on, revoked_on FROM software_accesses WHERE user_id = ?'],
        ['key' => 'declarations_interets', 'table' => 'interest_declarations', 'column' => 'user_id',
         'label' => 'Déclarations d\'intérêts', 'erasable' => false,
         'query' => 'SELECT id, kind, entity, description, declared_on, ends_on, status, measure FROM interest_declarations WHERE user_id = ?'],
        ['key' => 'cadeaux', 'table' => 'gift_records', 'column' => 'user_id',
         'label' => 'Cadeaux et invitations déclarés', 'erasable' => false,
         'query' => 'SELECT id, direction, kind, third_party, occurred_on, value, description, status FROM gift_records WHERE user_id = ?'],
        ['key' => 'journal', 'table' => 'audit_log', 'column' => 'actor_id',
         'label' => 'Journal d\'audit', 'erasable' => false,
         'query' => 'SELECT id, occurred_at, action, entity, entity_id, ip FROM audit_log WHERE actor_id = ?'],
    ];

    // ---------- Registre des traitements ----------

    public static function records(): array
    {
        return Db::all('SELECT * FROM processing_records ORDER BY name COLLATE NOCASE');
    }

    public static function recordById(int $id): ?array
    {
        return Db::get('SELECT * FROM processing_records WHERE id = ?', [$id]);
    }

    public static function createRecord(array $fields): int
    {
        return Db::insert(
            'INSERT INTO processing_records (name, purpose, legal_basis, data_categories, recipients, retention, measures)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['name'], $fields['purpose'] ?? '', $fields['legalBasis'], $fields['dataCategories'] ?? '',
                $fields['recipients'] ?? '', $fields['retention'] ?? '', $fields['measures'] ?? '',
            ]
        );
    }

    public static function updateRecord(int $id, array $fields): void
    {
        Db::run(
            "UPDATE processing_records SET name = ?, purpose = ?, legal_basis = ?, data_categories = ?,
                    recipients = ?, retention = ?, measures = ?, updated_at = datetime('now') WHERE id = ?",
            [
                $fields['name'], $fields['purpose'] ?? '', $fields['legalBasis'], $fields['dataCategories'] ?? '',
                $fields['recipients'] ?? '', $fields['retention'] ?? '', $fields['measures'] ?? '', $id,
            ]
        );
    }

    public static function deleteRecord(int $id): void
    {
        Db::run('DELETE FROM processing_records WHERE id = ?', [$id]);
    }

    public static function seedRecords(): int
    {
        if ((int) Db::value('SELECT COUNT(*) FROM processing_records') > 0) {
            return 0;
        }
        $created = 0;
        foreach (self::STARTER_RECORDS as $record) {
            self::createRecord($record);
            $created++;
        }
        return $created;
    }

    // ---------- Droit d'accès : ce que l'instance détient sur une personne ----------

    public static function collectFor(int $userId): array
    {
        $out = [];
        foreach (self::PERSONAL_SOURCES as $source) {
            try {
                // Deux sources portent l'identifiant deux fois (émetteur ou
                // destinataire) : autant de paramètres que de points
                // d'interrogation dans la requête.
                $placeholders = substr_count($source['query'], '?');
                $rows = Db::all($source['query'], array_fill(0, $placeholders, $userId));
            } catch (\Throwable) {
                // Une table absente (module jamais installé) n'est pas une
                // erreur d'export.
                $rows = [];
            }
            $out[] = $source + ['rows' => $rows];
        }
        return $out;
    }

    public static function exportFor(int $userId): ?array
    {
        $user = Db::get('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            return null;
        }
        $data = [];
        foreach (self::collectFor($userId) as $source) {
            $data[$source['label']] = $source['rows'];
        }
        return [
            'genere_le' => gmdate('c'),
            'personne' => [
                'id' => (int) $user['id'],
                'email' => $user['email'],
                'nom' => trim($user['first_name'] . ' ' . $user['last_name']),
            ],
            'donnees' => $data,
        ];
    }

    /**
     * Efface ce qui peut l'être et rend le détail de ce qui a été gardé, avec le
     * motif. Le compte lui-même est anonymisé plutôt que supprimé : les
     * écritures qui le référencent doivent rester cohérentes.
     */
    public static function eraseFor(int $userId): ?array
    {
        $user = Db::get('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            return null;
        }

        $erased = [];
        $kept = [];
        Db::transaction(static function () use ($userId, &$erased, &$kept): void {
            foreach (self::collectFor($userId) as $source) {
                if ($source['rows'] === []) {
                    continue;
                }
                if (!$source['erasable']) {
                    $kept[] = ['label' => $source['label'], 'count' => count($source['rows'])];
                    continue;
                }
                // Table et colonne sont déclarées avec la source, jamais
                // déduites de la requête : ce qui entre dans le SQL doit venir
                // d'une liste fermée.
                $removed = $source['key'] === 'messages'
                    ? Db::run('DELETE FROM messages WHERE sender_id = ? OR recipient_id = ?', [$userId, $userId])
                    : Db::run('DELETE FROM ' . $source['table'] . ' WHERE ' . $source['column'] . ' = ?', [$userId]);
                $erased[] = ['label' => $source['label'], 'count' => $removed];
            }

            // Le compte est anonymisé : identité effacée, identifiant conservé
            // pour que les traces obligatoires restent rattachables sans nommer
            // personne.
            Db::run(
                "UPDATE users SET first_name = 'Compte', last_name = 'anonymisé', email = ?, phone = '', bio = '',
                        avatar_file = NULL, mail_address = '', totp_secret = NULL, totp_enabled = 0,
                        active = 0, directory_hidden = 1
                 WHERE id = ?",
                ["anonyme-$userId@invalide.local", $userId]
            );
        });

        return ['erased' => $erased, 'kept' => $kept, 'email' => $user['email']];
    }
}
