<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Validate;

/**
 * Demandes internes et circuits d'approbation configurables.
 *
 * Les circuits déjà écrits — congés, notes de frais, demandes d'achat — sont
 * figés parce que la loi ou la comptabilité les fixe. Tout le reste varie d'une
 * entreprise à l'autre. Les figer dans le code obligerait à reprogrammer pour
 * ajouter une validation ; ils se décrivent donc ici, avec deux idées :
 *
 *   — **une étape désigne une fonction, pas une personne** (le manager, les RH,
 *     la gestion, la direction). Le circuit survit aux départs, et personne ne
 *     reste bloqué parce que le validateur nommé est parti ;
 *   — **un seuil** permet de n'appeler la direction qu'au-delà d'un montant.
 *     Une demande de 40 € et une de 40 000 € ne méritent pas le même nombre de
 *     signatures.
 *
 * Une demande avance étape par étape. Le premier des approbateurs d'une étape
 * qui se prononce engage l'étape : à deux managers, il n'en faut pas deux.
 */
final class Workflows
{
    public const FIELD_TYPES = [
        ['key' => 'texte', 'label' => 'Texte court'],
        ['key' => 'zone', 'label' => 'Texte long'],
        ['key' => 'nombre', 'label' => 'Nombre'],
        ['key' => 'montant', 'label' => 'Montant'],
        ['key' => 'date', 'label' => 'Date'],
        ['key' => 'choix', 'label' => 'Liste de choix'],
    ];

    public const APPROVERS = [
        ['key' => 'manager', 'label' => 'Le manager du demandeur'],
        ['key' => 'hr', 'label' => "L'équipe RH"],
        ['key' => 'finance', 'label' => 'La gestion financière'],
        ['key' => 'admin', 'label' => "L'administration"],
        ['key' => 'user', 'label' => 'Une personne désignée'],
    ];

    public const MAX_FIELDS = 15;
    public const MAX_STEPS = 6;

    // ---------- Types de demande ----------

    private static function parseFields(?string $raw): array
    {
        $parsed = json_decode((string) ($raw ?: '[]'), true);
        return is_array($parsed) ? $parsed : [];
    }

    private static function hydrate(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        return $row + ['fieldList' => self::parseFields($row['fields']), 'steps' => self::stepsOf((int) $row['id'])];
    }

    public static function forms(bool $activeOnly = false): array
    {
        $clause = $activeOnly ? 'WHERE active = 1' : '';
        return array_map(
            static fn (array $row): array => (array) self::hydrate($row),
            Db::all("SELECT * FROM request_forms $clause ORDER BY label COLLATE NOCASE")
        );
    }

    public static function formById(int $id): ?array
    {
        return self::hydrate(Db::get('SELECT * FROM request_forms WHERE id = ?', [$id]));
    }

    public static function stepsOf(int $formId): array
    {
        return Db::all(
            'SELECT s.*, u.first_name, u.last_name FROM request_steps s
             LEFT JOIN users u ON u.id = s.approver_id
             WHERE s.form_id = ? ORDER BY s.position, s.id',
            [$formId]
        );
    }

    public static function createForm(string $label, string $description, array $fields, string $amountField, ?int $createdBy): array
    {
        $name = mb_substr(trim($label), 0, 120);
        if ($name === '') {
            return ['ok' => false, 'message' => 'Un intitulé est requis.'];
        }
        if ($fields === []) {
            return ['ok' => false, 'message' => 'Décrivez au moins un champ à saisir.'];
        }
        if (count($fields) > self::MAX_FIELDS) {
            return ['ok' => false, 'message' => self::MAX_FIELDS . ' champs au maximum.'];
        }

        $types = array_column(self::FIELD_TYPES, 'key');
        $clean = [];
        foreach ($fields as $field) {
            $key = trim((string) preg_replace('/^_+|_+$/', '',
                (string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim((string) ($field['name'] ?? ''))))));
            if ($key === '') {
                return ['ok' => false, 'message' => "Chaque champ a besoin d'un nom technique."];
            }
            if (!in_array($field['type'] ?? '', $types, true)) {
                return ['ok' => false, 'message' => 'Type de champ inconnu : ' . ($field['type'] ?? '')];
            }
            foreach ($clean as $existing) {
                if ($existing['name'] === $key) {
                    return ['ok' => false, 'message' => "Deux champs portent le même nom : $key"];
                }
            }
            $clean[] = [
                'name' => $key,
                'label' => mb_substr(trim((string) ($field['label'] ?? $key)), 0, 120),
                'type' => $field['type'],
                'required' => !empty($field['required']),
                'options' => array_slice(array_values(array_filter(array_map(
                    static fn ($o): string => trim((string) $o),
                    $field['options'] ?? []
                ))), 0, 20),
            ];
        }

        // Le champ qui porte le montant doit exister : sans lui, les seuils ne
        // s'appliqueraient jamais et le circuit passerait toujours au plus court.
        $amount = trim($amountField);
        if ($amount !== '' && !in_array($amount, array_column($clean, 'name'), true)) {
            return ['ok' => false, 'message' => "Le champ de montant désigné n'existe pas."];
        }

        $id = Db::insert(
            'INSERT INTO request_forms (label, description, fields, amount_field, created_by) VALUES (?, ?, ?, ?, ?)',
            [$name, mb_substr($description, 0, 1000), json_encode($clean, JSON_UNESCAPED_UNICODE), $amount, $createdBy]
        );
        Audit::log('demande_type.cree', 'request_forms', $id, ['intitule' => $name]);
        return ['ok' => true, 'id' => $id];
    }

    public static function setFormActive(int $id, bool $active): bool
    {
        return Db::run('UPDATE request_forms SET active = ? WHERE id = ?', [$active ? 1 : 0, $id]) > 0;
    }

    public static function deleteForm(int $id): array
    {
        $running = (int) Db::value("SELECT COUNT(*) FROM workflow_requests WHERE form_id = ? AND status = 'En cours'", [$id]);
        if ($running > 0) {
            return ['ok' => false, 'message' => "$running demande(s) en cours sur ce type : suspendez-le plutôt que de l'effacer."];
        }
        Db::run('DELETE FROM request_forms WHERE id = ?', [$id]);
        Audit::log('demande_type.supprime', 'request_forms', $id);
        return ['ok' => true];
    }

    public static function addStep(int $formId, string $approver, ?int $approverId, string $label, float $threshold): array
    {
        $form = self::formById($formId);
        if ($form === null) {
            return ['ok' => false, 'message' => 'Type de demande inconnu.'];
        }
        if (!in_array($approver, array_column(self::APPROVERS, 'key'), true)) {
            return ['ok' => false, 'message' => 'Type de validateur inconnu.'];
        }
        if (count($form['steps']) >= self::MAX_STEPS) {
            return ['ok' => false, 'message' => self::MAX_STEPS . ' étapes au maximum.'];
        }
        if ($approver === 'user' && Db::get('SELECT id FROM users WHERE id = ? AND active = 1', [$approverId]) === null) {
            return ['ok' => false, 'message' => 'Personne désignée inconnue.'];
        }
        if ($threshold < 0) {
            return ['ok' => false, 'message' => 'Un seuil ne se saisit pas négatif.'];
        }

        $position = (int) Db::value('SELECT COALESCE(MAX(position), 0) + 1 FROM request_steps WHERE form_id = ?', [$formId]);
        Db::insert(
            'INSERT INTO request_steps (form_id, position, approver, approver_id, label, threshold) VALUES (?, ?, ?, ?, ?, ?)',
            [$formId, $position, $approver, $approver === 'user' ? $approverId : null, mb_substr($label, 0, 80), $threshold]
        );
        return ['ok' => true];
    }

    public static function deleteStep(int $formId, int $stepId): void
    {
        Db::run('DELETE FROM request_steps WHERE id = ? AND form_id = ?', [$stepId, $formId]);
    }

    // ---------- Résolution des validateurs ----------

    /**
     * Qui peut se prononcer sur une étape, compte tenu du demandeur. Le
     * demandeur en est toujours retiré : personne ne valide sa propre demande,
     * et une étape dont il serait le seul validateur bloquerait la demande pour
     * toujours.
     */
    public static function approversFor(array $step, array $requester): array
    {
        $list = match ($step['approver']) {
            'user' => $step['approver_id'] === null
                ? []
                : Db::all('SELECT * FROM users WHERE id = ? AND active = 1', [$step['approver_id']]),
            'manager' => Org::managersFor($requester),
            'hr' => Db::all("SELECT * FROM users WHERE active = 1 AND (is_hr = 1 OR role = 'admin')"),
            'finance' => Db::all("SELECT * FROM users WHERE active = 1 AND (is_finance = 1 OR role = 'admin')"),
            default => Db::all("SELECT * FROM users WHERE active = 1 AND role = 'admin'"),
        };
        return array_values(array_filter($list, static fn (array $a): bool => (int) $a['id'] !== (int) $requester['id']));
    }

    /**
     * Les étapes qui s'appliquent à ce montant. Une étape sans validateur
     * possible — un demandeur sans manager, par exemple — est sautée plutôt que
     * de bloquer la demande sur quelqu'un qui n'existe pas.
     */
    public static function applicableSteps(array $form, array $requester, float $amount): array
    {
        return array_values(array_filter(
            $form['steps'],
            static fn (array $step): bool => $amount >= (float) ($step['threshold'] ?? 0)
                && self::approversFor($step, $requester) !== []
        ));
    }

    // ---------- Demandes ----------

    public static function decorate(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $form = self::formById((int) $row['form_id']);
        $decisions = Db::all(
            'SELECT d.*, u.first_name, u.last_name FROM workflow_decisions d
             LEFT JOIN users u ON u.id = d.approver_id WHERE d.request_id = ? ORDER BY d.position, d.id',
            [$row['id']]
        );
        $requester = Db::get('SELECT * FROM users WHERE id = ?', [$row['requester_id']]);
        $steps = $requester !== null && $form !== null
            ? self::applicableSteps($form, $requester, (float) $row['amount'])
            : [];

        $stepIndex = -1;
        foreach ($steps as $index => $step) {
            if ((int) $step['id'] === (int) $row['current_step']) {
                $stepIndex = $index;
                break;
            }
        }
        $values = json_decode((string) $row['payload'], true);

        return $row + [
            'form' => $form,
            'values' => is_array($values) ? $values : [],
            'requester' => $requester,
            'requesterName' => $requester === null ? '—' : trim($requester['first_name'] . ' ' . $requester['last_name']),
            'decisions' => $decisions,
            'steps' => $steps,
            'stepIndex' => $stepIndex,
            'step' => $stepIndex >= 0 ? $steps[$stepIndex] : null,
            'pendingApprovers' => $stepIndex >= 0 && $requester !== null ? self::approversFor($steps[$stepIndex], $requester) : [],
        ];
    }

    public static function byId(int $id): ?array
    {
        return self::decorate(Db::get('SELECT * FROM workflow_requests WHERE id = ?', [$id]));
    }

    public static function list(?int $requesterId = null, ?string $status = null, int $limit = 200): array
    {
        $clauses = [];
        $params = [];
        if ($requesterId !== null) {
            $clauses[] = 'r.requester_id = ?';
            $params[] = $requesterId;
        }
        if ($status !== null) {
            $clauses[] = 'r.status = ?';
            $params[] = $status;
        }
        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);
        $params[] = $limit;

        // L'identifiant tranche les ex æquo : deux demandes déposées dans la
        // même seconde sortiraient sinon dans un ordre arbitraire.
        return array_map(
            static fn (array $row): array => (array) self::decorate($row),
            Db::all("SELECT r.* FROM workflow_requests r $where ORDER BY r.created_at DESC, r.id DESC LIMIT ?", $params)
        );
    }

    /** Les demandes qui attendent une décision de cette personne, maintenant. */
    public static function awaiting(int $userId): array
    {
        return array_values(array_filter(
            self::list(null, 'En cours'),
            static fn (array $request): bool => in_array($userId, array_map('intval', array_column($request['pendingApprovers'], 'id')), true)
        ));
    }

    /** Contrôle la saisie contre la description du formulaire. */
    public static function readValues(array $form, array $input): array
    {
        $values = [];
        foreach ($form['fieldList'] as $field) {
            $raw = trim((string) ($input['champ_' . $field['name']] ?? ''));
            if ($raw === '') {
                if (!empty($field['required'])) {
                    return ['ok' => false, 'message' => 'Champ obligatoire : ' . $field['label']];
                }
                $values[$field['name']] = '';
                continue;
            }
            if ($field['type'] === 'nombre' || $field['type'] === 'montant') {
                $number = str_replace([' ', ','], ['', '.'], $raw);
                if (!is_numeric($number)) {
                    return ['ok' => false, 'message' => $field['label'] . ' : nombre attendu.'];
                }
                $values[$field['name']] = round((float) $number, 2);
                continue;
            }
            if ($field['type'] === 'date' && !Validate::date($raw)) {
                return ['ok' => false, 'message' => $field['label'] . ' : date invalide.'];
            }
            if ($field['type'] === 'choix' && !in_array($raw, $field['options'], true)) {
                return ['ok' => false, 'message' => $field['label'] . ' : choix inconnu.'];
            }
            $values[$field['name']] = mb_substr($raw, 0, 2000);
        }
        return ['ok' => true, 'values' => $values];
    }

    /**
     * Dépose une demande et l'engage dans son circuit. Une demande sans aucune
     * étape applicable est approuvée d'emblée — et le dit, plutôt que de rester
     * en attente d'un validateur qui n'existe pas.
     */
    public static function submit(int $formId, int $requesterId, array $input): array
    {
        $form = self::formById($formId);
        if ($form === null || (int) $form['active'] !== 1) {
            return ['ok' => false, 'message' => "Ce type de demande n'est pas ouvert."];
        }
        $requester = Db::get('SELECT * FROM users WHERE id = ? AND active = 1', [$requesterId]);
        if ($requester === null) {
            return ['ok' => false, 'message' => 'Demandeur inconnu.'];
        }
        $read = self::readValues($form, $input);
        if (!$read['ok']) {
            return $read;
        }

        $amount = $form['amount_field'] !== '' ? (float) ($read['values'][$form['amount_field']] ?? 0) : 0.0;
        $steps = self::applicableSteps($form, $requester, $amount);

        $parts = [];
        foreach ($form['fieldList'] as $field) {
            $value = $read['values'][$field['name']] ?? '';
            if ($value !== '' && count($parts) < 3) {
                $parts[] = $field['label'] . ' : ' . $value;
            }
        }

        $id = Db::insert(
            'INSERT INTO workflow_requests (form_id, requester_id, payload, amount, summary, status, current_step, closed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $form['id'], $requester['id'], json_encode($read['values'], JSON_UNESCAPED_UNICODE), $amount,
                mb_substr(implode(' · ', $parts), 0, 300),
                $steps === [] ? 'Approuvée' : 'En cours',
                $steps === [] ? null : $steps[0]['id'],
                $steps === [] ? gmdate('c') : null,
            ]
        );
        Audit::log('demande_interne.deposee', 'workflow_requests', $id, ['type' => $form['label']]);
        if ($steps !== []) {
            self::notifyStep((array) self::byId($id));
        }
        return ['ok' => true, 'id' => $id, 'steps' => count($steps)];
    }

    private static function notifyStep(array $request): void
    {
        if (empty($request['step'])) {
            return;
        }
        foreach ($request['pendingApprovers'] as $approver) {
            Notifications::push((int) $approver['id'], 'Demande à valider — ' . $request['form']['label'], [
                'kind' => 'demande',
                'body' => mb_substr($request['requesterName'] . ' · ' . $request['summary'], 0, 300),
                'link' => '/demandes/' . $request['id'],
                'dedupeKey' => 'demande:' . $request['id'] . ':' . $request['step']['id'] . ':' . $approver['id'],
            ]);
        }
    }

    public static function canDecide(?array $request, int $userId): array
    {
        if ($request === null || $request['status'] !== 'En cours') {
            return ['ok' => false, 'message' => "Cette demande n'est plus en cours."];
        }
        if ((int) $request['requester_id'] === $userId) {
            return ['ok' => false, 'message' => 'On ne valide pas sa propre demande.'];
        }
        if (!in_array($userId, array_map('intval', array_column($request['pendingApprovers'], 'id')), true)) {
            return ['ok' => false, 'message' => 'Cette étape ne vous revient pas.'];
        }
        return ['ok' => true];
    }

    /** Approuver fait avancer d'une étape ; refuser referme la demande. */
    public static function decide(int $requestId, int $userId, string $decision, string $note = ''): array
    {
        $request = self::byId($requestId);
        $allowed = self::canDecide($request, $userId);
        if (!$allowed['ok']) {
            return $allowed;
        }
        if (!in_array($decision, ['Approuvée', 'Refusée'], true)) {
            return ['ok' => false, 'message' => 'Décision inconnue.'];
        }
        if ($decision === 'Refusée' && trim($note) === '') {
            return ['ok' => false, 'message' => 'Un refus se motive.'];
        }

        $outcome = Db::transaction(static function () use ($request, $userId, $decision, $note): string {
            Db::insert(
                'INSERT INTO workflow_decisions (request_id, step_id, position, approver_id, decision, note)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$request['id'], $request['step']['id'], $request['step']['position'], $userId, $decision, mb_substr($note, 0, 1000)]
            );
            if ($decision === 'Refusée') {
                Db::run("UPDATE workflow_requests SET status = 'Refusée', current_step = NULL, closed_at = ? WHERE id = ?",
                    [gmdate('c'), $request['id']]);
                return 'Refusée';
            }
            $next = $request['steps'][$request['stepIndex'] + 1] ?? null;
            if ($next !== null) {
                Db::run('UPDATE workflow_requests SET current_step = ? WHERE id = ?', [$next['id'], $request['id']]);
                return 'En cours';
            }
            Db::run("UPDATE workflow_requests SET status = 'Approuvée', current_step = NULL, closed_at = ? WHERE id = ?",
                [gmdate('c'), $request['id']]);
            return 'Approuvée';
        });

        Audit::log('demande_interne.decidee', 'workflow_requests', (int) $request['id'], ['issue' => $outcome]);
        $updated = (array) self::byId((int) $request['id']);
        if ($outcome === 'En cours') {
            self::notifyStep($updated);
        } else {
            Notifications::push((int) $request['requester_id'],
                'Demande ' . mb_strtolower($outcome) . ' — ' . $request['form']['label'], [
                    'kind' => 'demande',
                    'body' => mb_substr($note, 0, 300),
                    'link' => '/demandes/' . $request['id'],
                    'dedupeKey' => 'demande:' . $request['id'] . ':issue',
                ]);
        }
        return ['ok' => true, 'status' => $outcome];
    }

    /** Le demandeur retire sa demande tant que personne ne s'est prononcé. */
    public static function cancel(int $requestId, int $userId): array
    {
        $request = self::byId($requestId);
        if ($request === null) {
            return ['ok' => false, 'message' => 'Demande introuvable.'];
        }
        if ((int) $request['requester_id'] !== $userId) {
            return ['ok' => false, 'message' => 'Seul le demandeur retire sa demande.'];
        }
        if ($request['status'] !== 'En cours') {
            return ['ok' => false, 'message' => "Cette demande n'est plus en cours."];
        }
        if ($request['decisions'] !== []) {
            return ['ok' => false, 'message' => 'Une demande déjà examinée ne se retire plus.'];
        }
        Db::run("UPDATE workflow_requests SET status = 'Annulée', current_step = NULL, closed_at = ? WHERE id = ?",
            [gmdate('c'), $request['id']]);
        Audit::log('demande_interne.retiree', 'workflow_requests', (int) $request['id']);
        return ['ok' => true];
    }

    public static function summary(): array
    {
        return [
            'forms' => (int) Db::value('SELECT COUNT(*) FROM request_forms WHERE active = 1'),
            'running' => (int) Db::value("SELECT COUNT(*) FROM workflow_requests WHERE status = 'En cours'"),
            'approved' => (int) Db::value("SELECT COUNT(*) FROM workflow_requests WHERE status = 'Approuvée'"),
            'refused' => (int) Db::value("SELECT COUNT(*) FROM workflow_requests WHERE status = 'Refusée'"),
        ];
    }
}
