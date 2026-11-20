<?php

declare(strict_types=1);

namespace App\Core;

use App\Modules\Societes;

/**
 * Une ligne rattachée est-elle dans le périmètre courant ?
 *
 * Les tables rattachées n'ont pas de « company_id » : leurs lignes
 * appartiennent à une ligne d'une autre table, et la règle était de ne les
 * atteindre qu'en nommant ce parent. Une vingtaine de gestes prenaient
 * l'identifiant de la ligne rattachée elle-même, sans remonter au parent : un
 * compte qui tenait la gestion d'une filiale pouvait agir sur les lignes d'une
 * autre entité du groupe en devinant un numéro.
 *
 * Cette classe remonte la chaîne jusqu'à une table cloisonnée et y vérifie le
 * périmètre. Le plan des colonnes parentes est celui de l'édition Node, et un
 * test le confronte au schéma.
 */
final class Rattachees
{
    /** La colonne qui désigne le parent, table par table. */
    public const PARENT = [
        'asset_assignments' => 'asset_id',
        'audit_findings' => 'audit_id',
        'bank_transactions' => 'account_id',
        'candidates' => 'opening_id',
        'checklist_items' => 'checklist_id',
        'checklist_template_items' => 'template_id',
        'crm_activities' => 'partner_id',
        'crm_contacts' => 'partner_id',
        'cse_ballots' => 'election_id',
        'cse_candidacies' => 'election_id',
        'cse_voters' => 'election_id',
        'custom_values' => 'field_id',
        'document_acks' => 'document_id',
        'dunning_notices' => 'invoice_id',
        'delivery_note_lines' => 'note_id',
        'sales_invoice_lines' => 'invoice_id',
        'stripe_sessions' => 'invoice_id',
        'sales_order_lines' => 'order_id',
        'entry_lines' => 'entry_id',
        'event_registrations' => 'event_id',
        'key_results' => 'objective_id',
        'meeting_attendees' => 'meeting_id',
        'meeting_resolutions' => 'meeting_id',
        'opening_criteria' => 'opening_id',
        'partner_contacts' => 'partner_id',
        'partner_contracts' => 'partner_id',
        'partner_documents' => 'partner_id',
        'partner_reviews' => 'partner_id',
        'payslip_lines' => 'payslip_id',
        'ppe_assignments' => 'ppe_id',
        'project_members' => 'project_id',
        'project_milestones' => 'project_id',
        'project_tasks' => 'project_id',
        'project_time' => 'project_id',
        'purchase_order_lines' => 'order_id',
        'purchase_receipts' => 'line_id',
        'releases' => 'service_id',
        'request_steps' => 'form_id',
        'room_bookings' => 'room_id',
        'share_movements' => 'shareholder_id',
        'signature_signers' => 'request_id',
        'software_accesses' => 'licence_id',
        'stock_movements' => 'item_id',
        'support_group_categories' => 'group_id',
        'support_group_members' => 'group_id',
        'survey_answers' => 'question_id',
        'survey_participations' => 'survey_id',
        'survey_questions' => 'survey_id',
        'ticket_messages' => 'ticket_id',
        'training_registrations' => 'session_id',
        'training_sessions' => 'training_id',
        'vehicle_events' => 'vehicle_id',
        'webhook_deliveries' => 'webhook_id',
        'whistleblow_access_log' => 'report_id',
        'whistleblow_messages' => 'report_id',
        'workflow_decisions' => 'request_id',
        'workflow_requests' => 'form_id',
    ];

    /**
     * Vrai si la ligne appartient, par ses parents, à la société courante. Faux
     * si elle n'existe pas ou si elle est d'une autre société — sans dire
     * laquelle des deux raisons l'emporte.
     */
    public static function visible(string $table, int $id): bool
    {
        $courante = $table;
        $ligne = $id;
        for ($etage = 0; $etage < 5 && $ligne > 0; $etage++) {
            if (preg_match('/^[a-z_]+$/', $courante) !== 1) {
                return false;
            }
            if (in_array($courante, Cloison::CLOISONNEES, true)) {
                return Db::get(
                    "SELECT 1 AS ok FROM $courante WHERE id = ? AND company_id = ?",
                    [$ligne, Societes::courant()]
                ) !== null;
            }
            $parent = Cloison::RATTACHEES[$courante] ?? null;
            $colonne = self::PARENT[$courante] ?? null;
            if ($parent === null || $colonne === null) {
                return false;
            }
            $row = Db::get("SELECT $colonne AS p FROM $courante WHERE id = ?", [$ligne]);
            if ($row === null || $row['p'] === null) {
                return false;
            }
            $courante = $parent;
            $ligne = (int) $row['p'];
        }
        return false;
    }
}
