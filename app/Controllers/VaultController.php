<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Hr;
use App\Modules\Users;
use App\Modules\Vault;

/**
 * Coffre-fort numérique.
 *
 * Deux écrans : le sien, que chacun consulte, et celui de la gestion RH, qui
 * dépose, émet les codes d'accès et surveille l'intégrité. Un ancien salarié
 * entre par un code : sa session ne voit que son coffre.
 */
final class VaultController
{
    public static function canManage(?array $user): bool
    {
        // Une session ouverte par code d'accès n'est jamais une session de
        // gestion, quel que soit le compte derrière.
        return !Session::get('vault_only', false) && HrController::canAccess($user);
    }

    private static function back(string $anchor, string $type, string $message, ?int $personId = null): Response
    {
        Flash::set($type, $message);
        $query = $personId === null ? '' : '?personne=' . $personId;
        return Response::redirect('/coffre-fort/gestion' . $query . '#' . $anchor);
    }

    private static function error(string $message, int $status): Response
    {
        return Response::html(View::page('error', ['title' => $message, 'message' => $message]), $status);
    }

    // ---------- Mon coffre ----------

    public static function mine(Request $request): Response
    {
        $user = (array) Users::byId((int) Session::get('user')['id']);
        $restricted = (bool) Session::get('vault_only', false);

        return Response::html(View::page('vault/mine', [
            'title' => t('nav.vault') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.vault'),
            'headerTitle' => t('nav.vault'),
            'headerSubtitle' => t('cf.headerSub', ['years' => Vault::RETENTION_YEARS]),
            // Une session de coffre n'a pas d'autre page à proposer.
            'navItems' => $restricted ? [] : [['href' => '/mon-espace', 'label' => t('nav.mySpace')]],
            'footLinks' => [],
            'holder' => $user,
            'documents' => Vault::documentsFor((int) $user['id']),
            'payslips' => Hr::payslipsFor((int) $user['id'], 200),
            'retentionYears' => Vault::RETENTION_YEARS,
            'restricted' => $restricted,
        ]));
    }

    /**
     * Téléchargement. L'empreinte est recalculée avant l'envoi : un document
     * dont le contenu a bougé n'est pas servi, et l'anomalie est consignée.
     */
    public static function download(Request $request, array $params): Response
    {
        $document = Vault::byId((int) $params['id']);
        if ($document === null || !empty($document['removed_at'])) {
            return self::error('Document introuvable.', 404);
        }

        $user = (array) Users::byId((int) Session::get('user')['id']);
        $isOwner = (int) $document['user_id'] === (int) $user['id'];
        if (!$isOwner && !self::canManage($user)) {
            return self::error("Ce document n'est pas le vôtre.", 403);
        }

        $verdict = Vault::verify($document);
        if (!$verdict['ok']) {
            Audit::log('coffre.integrite_rompue', 'vault_documents', (int) $document['id'], ['motif' => $verdict['reason']]);
            return self::error(
                "L'intégrité de ce document ne peut pas être confirmée : il n'est pas servi. Prévenez l'administration.",
                500
            );
        }

        Audit::log(
            $isOwner ? 'coffre.document_telecharge' : 'coffre.document_consulte_par_rh',
            'vault_documents',
            (int) $document['id'],
            ['titulaire' => (int) $document['user_id'], 'empreinte' => substr((string) $document['sha256'], 0, 16)]
        );

        $suggested = $document['original_name']
            ?: preg_replace('/[^\w.-]+/u', '-', (string) $document['title']) . '.bin';
        return Response::text((string) file_get_contents(Vault::pathOf($document)))->withHeaders([
            'Content-Type' => (string) $document['mime_type'],
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', (string) $suggested) . '"',
        ]);
    }

    // ---------- Gestion : dépôt, codes d'accès, intégrité ----------

    private static function people(): array
    {
        return Db::all(
            'SELECT u.id, u.first_name, u.last_name, u.email, u.active, u.contract_end_date,
                    (SELECT COUNT(*) FROM vault_documents WHERE user_id = u.id AND removed_at IS NULL) AS documents
             FROM users u ORDER BY u.active DESC, u.last_name COLLATE NOCASE'
        );
    }

    public static function manage(Request $request): Response
    {
        $targetId = (int) $request->input('personne') ?: null;
        $target = $targetId === null ? null : Users::byId($targetId);

        // Affiché une seule fois, juste après l'émission : retiré de la session
        // avant le rendu, jamais après.
        $issuedCode = Session::get('vault_issued_code');
        Session::forget('vault_issued_code');

        return Response::html(View::page('vault/manage', [
            'title' => t('nav.vaultManage') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.vaultManage'),
            'headerTitle' => t('nav.vaultManage'),
            'headerSubtitle' => t('cfg.headerSub'),
            'navItems' => [
                ['tab' => 'documents', 'label' => t('cfg.documents')],
                ['tab' => 'depot', 'label' => t('cfg.tabDeposit')],
                ['tab' => 'acces', 'label' => t('cfg.tabAccess')],
                ['tab' => 'integrite', 'label' => t('cfg.tabIntegrity')],
            ],
            'footLinks' => [
                ['href' => '/coffre-fort', 'label' => t('nav.vault')],
                ['href' => '/rh', 'label' => t('nav.hrSpace')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'people' => self::people(),
            'target' => $target,
            'documents' => $target === null ? [] : Vault::documentsFor((int) $target['id'], true),
            'payslips' => $target === null ? [] : Hr::payslipsFor((int) $target['id'], 200),
            'grants' => $target === null ? [] : Vault::grantsFor((int) $target['id']),
            'categories' => Vault::CATEGORIES,
            'summary' => Vault::summary(),
            'integrity' => Vault::integrityAudit(),
            'retentionYears' => Vault::RETENTION_YEARS,
            'defaultGrantDays' => Vault::DEFAULT_GRANT_DAYS,
            'maxGrantDays' => Vault::MAX_GRANT_DAYS,
            'issuedCode' => $issuedCode,
        ]));
    }

    public static function deposit(Request $request): Response
    {
        $userId = (int) $request->input('user_id');
        $holder = Users::byId($userId);
        if ($holder === null) {
            return self::back('depot', 'error', 'Personne introuvable.');
        }

        $title = $request->input('title');
        if ($title === '' || mb_strlen($title) > 200) {
            return self::back('depot', 'error', 'Intitulé invalide.', $userId);
        }
        $period = $request->input('period');
        if ($period !== '' && preg_match('/^\d{4}(-\d{2})?$/', $period) !== 1) {
            return self::back('depot', 'error', 'Période invalide : attendu AAAA ou AAAA-MM.', $userId);
        }

        // Rattachement facultatif à une fiche de paie déjà enregistrée.
        $payslipId = (int) $request->input('payslip_id') ?: null;
        if ($payslipId !== null
            && Db::get('SELECT * FROM payslips WHERE id = ? AND employee_id = ?', [$payslipId, $userId]) === null) {
            return self::back('depot', 'error', 'Fiche de paie introuvable pour cette personne.', $userId);
        }

        $verdict = Vault::deposit([
            'userId' => $userId,
            'category' => $request->input('category'),
            'title' => mb_substr($title, 0, 200),
            'period' => $period,
            'file' => $request->file('document'),
            'depositedBy' => (int) Session::get('user')['id'],
            'payslipId' => $payslipId,
        ]);
        if (!$verdict['ok']) {
            return self::back('depot', 'error', $verdict['message'], $userId);
        }

        Audit::log('coffre.document_depose', 'vault_documents', $verdict['id'], [
            'titulaire' => $userId,
            'categorie' => $request->input('category'),
            'empreinte' => substr($verdict['sha256'], 0, 16),
        ]);
        return self::back('documents', 'success', 'Document déposé au coffre de '
            . trim($holder['first_name'] . ' ' . $holder['last_name'])
            . ", conservé jusqu'en " . ((int) gmdate('Y') + Vault::RETENTION_YEARS) . '.', $userId);
    }

    public static function removeDocument(Request $request, array $params): Response
    {
        $document = Vault::byId((int) $params['id']);
        if ($document === null) {
            return self::back('documents', 'error', 'Document introuvable.');
        }

        // Un retrait engage : il est réservé à l'administration et laisse un motif.
        $user = (array) Users::byId((int) Session::get('user')['id']);
        if ($user['role'] !== 'admin') {
            return self::error("Le retrait d'un document du coffre est réservé à l'administration.", 403);
        }

        $reason = $request->input('reason');
        $verdict = Vault::remove((int) $document['id'], (int) $user['id'], $reason);
        if (!$verdict['ok']) {
            return self::back('documents', 'error', $verdict['message'], (int) $document['user_id']);
        }

        Audit::log('coffre.document_retire', 'vault_documents', (int) $document['id'], [
            'titulaire' => (int) $document['user_id'], 'motif' => mb_substr(trim($reason), 0, 300),
        ]);
        return self::back('documents', 'success',
            'Document retiré. La trace du retrait, son auteur et son motif restent au coffre.',
            (int) $document['user_id']);
    }

    public static function issueGrant(Request $request, array $params): Response
    {
        $holder = Users::byId((int) $params['id']);
        if ($holder === null) {
            return self::back('acces', 'error', 'Personne introuvable.');
        }
        $holderId = (int) $holder['id'];
        if (!Vault::hasDocuments($holderId)) {
            return self::back('acces', 'error',
                "Le coffre de cette personne est vide : un code n'ouvrirait rien.", $holderId);
        }

        $days = (int) $request->input('days') ?: Vault::DEFAULT_GRANT_DAYS;
        if ($days < 1 || $days > Vault::MAX_GRANT_DAYS) {
            return self::back('acces', 'error',
                'La validité tient entre 1 et ' . Vault::MAX_GRANT_DAYS . ' jours.', $holderId);
        }

        $grant = Vault::issueGrant($holderId, $days, (int) Session::get('user')['id']);
        Session::set('vault_issued_code', [
            'code' => $grant['code'], 'expiresAt' => $grant['expiresAt'], 'email' => $holder['email'],
        ]);
        Audit::log('coffre.code_emis', 'users', $holderId, ['validite_jours' => $days]);
        return self::back('acces', 'success',
            "Code émis. Il n'est affiché qu'une fois : transmettez-le à la personne.", $holderId);
    }

    public static function revokeGrants(Request $request, array $params): Response
    {
        $holder = Users::byId((int) $params['id']);
        if ($holder === null) {
            return self::back('acces', 'error', 'Personne introuvable.');
        }
        $revoked = Vault::revokeGrants((int) $holder['id']);
        Audit::log('coffre.codes_revoques', 'users', (int) $holder['id'], ['revoques' => $revoked]);
        return self::back('acces', 'success', "$revoked code(s) révoqué(s).", (int) $holder['id']);
    }

    // ---------- Accès par code, pour qui a oublié son mot de passe ----------

    public static function accessForm(Request $request): Response
    {
        if (Session::get('user') !== null) {
            return Response::redirect('/coffre-fort');
        }
        return Response::html(View::render('vault/access', [
            'title' => t('va.title') . ' — ' . t('app.name'),
        ]));
    }

    public static function redeem(Request $request): Response
    {
        $user = Vault::redeem($request->input('email'), $request->input('code'));
        if ($user === null || !Vault::hasDocuments((int) $user['id'])) {
            Audit::log('coffre.code_refuse', 'users', $user === null ? null : (int) $user['id'],
                ['email' => mb_substr($request->input('email'), 0, 120)]);
            Flash::set('error',
                'Adresse ou code invalide, ou code expiré. Rapprochez-vous de votre ancien employeur.');
            return Response::redirect('/coffre-fort/acces');
        }

        // Un code ouvre toujours une session restreinte, même pour un compte
        // encore actif : il ne sert qu'à retrouver ses documents.
        Session::regenerate();
        Session::set('opened_at', time());
        Session::set('vault_only', true);
        Session::set('user', ['id' => (int) $user['id'], 'role' => $user['role'], 'email' => $user['email']]);
        Audit::log('coffre.acces_ancien_salarie', 'users', (int) $user['id'], ['moyen' => "code d'accès"]);
        return Response::redirect('/coffre-fort');
    }
}
