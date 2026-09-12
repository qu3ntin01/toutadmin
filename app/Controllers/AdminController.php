<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Validate;
use App\Core\View;
use App\Modules\Announcements;
use App\Modules\Catalogue;
use App\Modules\Org;
use App\Modules\Themes;
use App\Modules\Tools;
use App\Modules\Users;

/**
 * Console d'administration.
 *
 * Tout ce qui se décide pour l'entreprise et pour personne en particulier :
 * l'organisation, le personnel, les outils, les droits transverses, les
 * modules, l'apparence. Chaque route d'ici exige le rôle « admin » — la
 * vérification est dans le noyau, pas laissée à la bonne volonté de chaque
 * route.
 */
final class AdminController
{
    public static function home(Request $request): Response
    {
        Users::deactivateExpiredContracts();

        $employees = Users::employees();
        $tools = Tools::all();
        $assignments = Tools::assignments();

        $toolsByEmployee = [];
        $employeesByTool = [];
        foreach ($assignments as $row) {
            $toolsByEmployee[(int) $row['employee_id']][] = $row;
            $employeesByTool[(int) $row['tool_id']][] = $row;
        }

        $departments = Org::departments();
        $teams = Org::teams();
        $withFlag = static fn (array $list, string $flag, bool $on): array => array_values(array_filter(
            $list,
            static fn (array $e): bool => ((int) $e[$flag] === 1) === $on
        ));

        return Response::html(View::page('admin/index', [
            'title' => t('admin.title') . ' — ' . t('app.name'),
            'panelLabel' => t('common.adminRole'),
            'headerTitle' => t('admin.title'),
            'headerSubtitle' => t('admin.subtitle'),
            'navItems' => self::tabs((int) Db::value("SELECT COUNT(*) FROM hr_requests WHERE status = 'En attente'")),
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'footLinks' => [
                ['href' => '/organigramme', 'label' => t('nav.orgChart')],
                ['href' => '/annuaire', 'label' => t('nav.directory')],
                ['href' => '/mon-profil', 'label' => t('nav.profile')],
            ],
            'employees' => $employees,
            'employeesById' => array_column($employees, null, 'id'),
            'tools' => $tools,
            'toolsById' => array_column($tools, null, 'id'),
            'toolsByEmployee' => $toolsByEmployee,
            'employeesByTool' => $employeesByTool,
            'departments' => $departments,
            'teams' => $teams,
            'departmentManagers' => array_combine(
                array_column($departments, 'id'),
                array_map(static fn (array $d): array => Org::managersOf('department', (int) $d['id']), $departments)
            ) ?: [],
            'teamManagers' => array_combine(
                array_column($teams, 'id'),
                array_map(static fn (array $t): array => Org::managersOf('team', (int) $t['id']), $teams)
            ) ?: [],
            'flagMembers' => array_combine(
                array_keys(Users::ROLE_FLAGS),
                array_map(static fn (string $flag): array => $withFlag($employees, $flag, true), array_keys(Users::ROLE_FLAGS))
            ),
            'flagEligible' => array_combine(
                array_keys(Users::ROLE_FLAGS),
                array_map(static fn (string $flag): array => $withFlag($employees, $flag, false), array_keys(Users::ROLE_FLAGS))
            ),
            'news' => Announcements::all(),
            'modules' => Catalogue::list(),
            'palettes' => Themes::list(),
            'grades' => Users::GRADES,
            'contractTypes' => Users::CONTRACT_TYPES,
            'annualLeaveDays' => self::annualLeaveDays(),
            'pendingRequestCount' => (int) Db::value("SELECT COUNT(*) FROM hr_requests WHERE status = 'En attente'"),
            'stats' => [
                'employeeCount' => count($employees),
                'toolCount' => count($tools),
                'assignedCount' => count($assignments),
                'availableCount' => count(array_filter($tools, static fn (array $t): bool => empty($employeesByTool[(int) $t['id']]))),
            ],
        ]));
    }

    /** Les onglets de la console : un par domaine de décision. */
    public static function tabs(int $pendingRequests = 0): array
    {
        return [
            ['tab' => 'personnel', 'label' => t('admin.staffMembers')],
            ['tab' => 'outils', 'label' => t('nav.tools')],
            ['tab' => 'affectations', 'label' => t('nav.assignments')],
            ['tab' => 'organisation', 'label' => t('org.departments')],
            ['tab' => 'actualites', 'label' => t('home.companyNews')],
            ['tab' => 'droits', 'label' => t('admin.accessConfigured')],
            ['tab' => 'modules', 'label' => t('nav.modules')],
            ['tab' => 'apparence', 'label' => t('admin.instancePalette')],
        ];
    }

    public static function nav(): array
    {
        return [
            ['href' => '/admin', 'label' => t('admin.title')],
            ['href' => '/organigramme', 'label' => t('nav.orgChart')],
            ['href' => '/annuaire', 'label' => t('nav.directory')],
            ['href' => '/mon-profil', 'label' => t('nav.profile')],
        ];
    }

    /** Quota annuel posé à l'installation, avec repli sur la valeur du module RH. */
    private static function annualLeaveDays(): int
    {
        $configured = Settings::get('annual_leave_days');
        return is_numeric($configured) ? (int) $configured : 25;
    }

    private static function back(string $anchor, string $type, string $message): Response
    {
        Flash::set($type, $message);
        return Response::redirect('/admin#' . $anchor);
    }

    // ---------- Organisation ----------

    public static function createDepartment(Request $request): Response
    {
        $name = mb_substr($request->input('name'), 0, 120);
        if ($name === '') {
            return self::back('organisation', 'error', 'Le nom du service est obligatoire.');
        }
        foreach (Org::departments() as $department) {
            if (mb_strtolower($department['name']) === mb_strtolower($name)) {
                return self::back('organisation', 'error', 'Un service porte déjà ce nom.');
            }
        }
        Org::createDepartment($name, mb_substr($request->input('description'), 0, 500));
        Audit::log('service.cree', 'departments', null, ['nom' => $name]);
        return self::back('organisation', 'success', "Service « $name » créé.");
    }

    public static function updateDepartment(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        if (Org::departmentById($id) === null) {
            return self::back('organisation', 'error', 'Service introuvable.');
        }
        $name = mb_substr($request->input('name'), 0, 120);
        if ($name === '') {
            return self::back('organisation', 'error', 'Le nom du service est obligatoire.');
        }
        foreach (Org::departments() as $department) {
            if ((int) $department['id'] !== $id && mb_strtolower($department['name']) === mb_strtolower($name)) {
                return self::back('organisation', 'error', 'Un autre service porte déjà ce nom.');
            }
        }
        Org::updateDepartment($id, $name, mb_substr($request->input('description'), 0, 500));
        Audit::log('service.modifie', 'departments', $id);
        return self::back('organisation', 'success', 'Service mis à jour.');
    }

    public static function deleteDepartment(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        if (Org::departmentById($id) === null) {
            return self::back('organisation', 'error', 'Service introuvable.');
        }
        // Les membres et les équipes sont détachés, jamais supprimés avec le service.
        Org::deleteDepartment($id);
        Audit::log('service.supprime', 'departments', $id);
        return self::back('organisation', 'success', 'Service supprimé. Ses membres et ses équipes en ont été détachés.');
    }

    public static function createTeam(Request $request): Response
    {
        $name = mb_substr($request->input('name'), 0, 120);
        $departmentId = (int) $request->input('department_id') ?: null;
        if ($name === '') {
            return self::back('organisation', 'error', "Le nom de l'équipe est obligatoire.");
        }
        if ($departmentId !== null && Org::departmentById($departmentId) === null) {
            return self::back('organisation', 'error', 'Service introuvable.');
        }
        foreach (Org::teams() as $team) {
            $sameDepartment = ($team['department_id'] === null ? null : (int) $team['department_id']) === $departmentId;
            if ($sameDepartment && mb_strtolower($team['name']) === mb_strtolower($name)) {
                return self::back('organisation', 'error', 'Une équipe porte déjà ce nom dans ce service.');
            }
        }
        Org::createTeam($name, $departmentId, mb_substr($request->input('description'), 0, 500));
        Audit::log('equipe.creee', 'teams', null, ['nom' => $name]);
        return self::back('organisation', 'success', "Équipe « $name » créée.");
    }

    public static function updateTeam(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        if (Org::teamById($id) === null) {
            return self::back('organisation', 'error', 'Équipe introuvable.');
        }
        $name = mb_substr($request->input('name'), 0, 120);
        $departmentId = (int) $request->input('department_id') ?: null;
        if ($name === '') {
            return self::back('organisation', 'error', "Le nom de l'équipe est obligatoire.");
        }
        if ($departmentId !== null && Org::departmentById($departmentId) === null) {
            return self::back('organisation', 'error', 'Service introuvable.');
        }
        Org::updateTeam($id, $name, $departmentId, mb_substr($request->input('description'), 0, 500));
        Audit::log('equipe.modifiee', 'teams', $id);
        return self::back('organisation', 'success', 'Équipe mise à jour.');
    }

    public static function deleteTeam(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        if (Org::teamById($id) === null) {
            return self::back('organisation', 'error', 'Équipe introuvable.');
        }
        Org::deleteTeam($id);
        Audit::log('equipe.supprimee', 'teams', $id);
        return self::back('organisation', 'success', 'Équipe supprimée. Ses membres en ont été détachés.');
    }

    public static function addManager(Request $request): Response
    {
        $result = Org::addManager($request->input('scope'), (int) $request->input('scope_id'), (int) $request->input('user_id'));
        if (!$result['ok']) {
            $messages = [
                'bad-scope' => 'Périmètre invalide.',
                'not-found' => 'Service ou équipe introuvable.',
                'no-user' => 'Membre introuvable.',
            ];
            return self::back('organisation', 'error', $messages[$result['reason']] ?? 'Encadrement impossible.');
        }
        Audit::log('encadrement.ajoute', 'org_managers', (int) $result['user']['id'], [
            'perimetre' => $request->input('scope') . ':' . $request->input('scope_id'),
        ]);
        $name = $result['user']['first_name'] . ' ' . $result['user']['last_name'];
        return self::back('organisation', 'success', "$name encadre désormais ce périmètre.");
    }

    public static function removeManager(Request $request): Response
    {
        Org::removeManager($request->input('scope'), (int) $request->input('scope_id'), (int) $request->input('user_id'));
        Audit::log('encadrement.retire', 'org_managers', (int) $request->input('user_id'));
        return self::back('organisation', 'success', 'Encadrement retiré.');
    }

    public static function assignMembership(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $employee = Users::employeeById($id);
        if ($employee === null) {
            return self::back('organisation', 'error', 'Membre introuvable.');
        }
        $result = Org::assignMembership(
            $id,
            (int) $request->input('department_id') ?: null,
            (int) $request->input('team_id') ?: null
        );
        if (!$result['ok']) {
            $messages = ['no-team' => 'Équipe introuvable.', 'no-department' => 'Service introuvable.'];
            return self::back('organisation', 'error', $messages[$result['reason']] ?? 'Rattachement impossible.');
        }
        Audit::log('membre.rattache', 'users', $id);
        $name = $employee['first_name'] . ' ' . $employee['last_name'];
        return self::back('organisation', 'success', "Rattachement de $name mis à jour.");
    }

    /** Le retrait de l'annuaire appartient à l'administration, pas au membre. */
    public static function toggleDirectory(Request $request, array $params): Response
    {
        $employee = Users::employeeById((int) $params['id']);
        if ($employee === null) {
            return self::back('organisation', 'error', 'Membre introuvable.');
        }
        $wasHidden = (int) $employee['directory_hidden'] === 1;
        Users::toggleDirectory((int) $employee['id'], $wasHidden);
        $name = $employee['first_name'] . ' ' . $employee['last_name'];
        return self::back('organisation', 'success',
            $name . ($wasHidden ? " apparaît de nouveau dans l'annuaire." : " n'apparaît plus dans l'annuaire."));
    }

    // ---------- Personnel ----------

    public static function createEmployee(Request $request): Response
    {
        $fields = [
            'first_name' => mb_substr($request->input('first_name'), 0, 100),
            'last_name' => mb_substr($request->input('last_name'), 0, 100),
            'email' => mb_substr(mb_strtolower($request->input('email')), 0, 254),
            'grade' => $request->input('grade'),
            'contract_type' => $request->input('contract_type'),
            'contract_end_date' => $request->input('contract_end_date'),
            'daily_rate' => null,
        ];
        $departmentId = (int) $request->input('department_id') ?: null;
        $teamId = (int) $request->input('team_id') ?: null;
        $rate = Validate::dailyRate($request->input('daily_rate'));

        if ($fields['first_name'] === '' || $fields['last_name'] === '' || $fields['email'] === ''
            || $fields['grade'] === '' || $fields['contract_type'] === '') {
            return self::back('personnel', 'error', "Merci de renseigner le prénom, le nom, l'email, le grade et le type de contrat.");
        }
        if (!Validate::email($fields['email'])) {
            return self::back('personnel', 'error', 'Adresse email invalide.');
        }
        if (!in_array($fields['grade'], Users::GRADES, true)) {
            return self::back('personnel', 'error', 'Grade invalide.');
        }
        if (!in_array($fields['contract_type'], Users::CONTRACT_TYPES, true)) {
            return self::back('personnel', 'error', 'Type de contrat invalide.');
        }
        if ($fields['contract_end_date'] !== '' && !Validate::date($fields['contract_end_date'])) {
            return self::back('personnel', 'error', 'Date de fin de contrat invalide.');
        }
        if (!$rate['ok']) {
            return self::back('personnel', 'error', 'TJM invalide.');
        }
        if ($departmentId !== null && Org::departmentById($departmentId) === null) {
            return self::back('personnel', 'error', 'Service introuvable.');
        }
        if ($teamId !== null && Org::teamById($teamId) === null) {
            return self::back('personnel', 'error', 'Équipe introuvable.');
        }
        if (Users::byEmail($fields['email']) !== null) {
            return self::back('personnel', 'error', 'Un compte existe déjà avec cet email.');
        }

        $fields['contract_end_date'] = $fields['contract_end_date'] ?: null;
        $fields['daily_rate'] = $rate['value'];
        $leave = $fields['contract_type'] === 'Freelance' ? 0 : self::annualLeaveDays();
        $created = Users::createEmployee($fields, $leave);
        Org::assignMembership($created['id'], $departmentId, $teamId);

        return self::back('personnel', 'success',
            "Membre ajouté. Identifiant : {$fields['email']} — Mot de passe temporaire : {$created['password']}");
    }

    public static function editEmployee(Request $request, array $params): Response
    {
        $employee = Users::employeeById((int) $params['id']);
        if ($employee === null) {
            return self::back('personnel', 'error', 'Membre introuvable.');
        }
        return Response::html(View::page('admin/employee-edit', [
            'title' => t('emp.profile') . ' — ' . t('app.name'),
            'panelLabel' => t('common.adminRole'),
            'headerTitle' => trim($employee['first_name'] . ' ' . $employee['last_name']),
            'headerSubtitle' => t('emp.profile'),
            'navItems' => self::nav(),
            'employee' => $employee,
            'grades' => Users::GRADES,
            'contractTypes' => Users::CONTRACT_TYPES,
            'departments' => Org::departments(),
            'teams' => Org::teams(),
        ]));
    }

    public static function updateEmployee(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $employee = Users::employeeById($id);
        if ($employee === null) {
            return self::back('personnel', 'error', 'Membre introuvable.');
        }
        $grade = $request->input('grade');
        $contractType = $request->input('contract_type');
        $endDate = $request->input('contract_end_date');
        $rate = Validate::dailyRate($request->input('daily_rate'));
        $departmentId = (int) $request->input('department_id') ?: null;
        $teamId = (int) $request->input('team_id') ?: null;

        $fail = static function (string $message) use ($id): Response {
            Flash::set('error', $message);
            return Response::redirect("/admin/employes/$id/modifier");
        };

        if (!in_array($grade, Users::GRADES, true) || !in_array($contractType, Users::CONTRACT_TYPES, true)) {
            return $fail('Grade ou type de contrat invalide.');
        }
        if ($endDate !== '' && !Validate::date($endDate)) {
            return $fail('Date de fin de contrat invalide.');
        }
        if (!$rate['ok']) {
            return $fail('TJM invalide.');
        }
        if ($departmentId !== null && Org::departmentById($departmentId) === null) {
            return $fail('Service introuvable.');
        }
        if ($teamId !== null && Org::teamById($teamId) === null) {
            return $fail('Équipe introuvable.');
        }

        Users::updateEmployee($id, [
            'grade' => $grade,
            'contract_type' => $contractType,
            'contract_end_date' => $endDate ?: null,
            'daily_rate' => $rate['value'],
        ]);
        Org::assignMembership($id, $departmentId, $teamId);
        $name = $employee['first_name'] . ' ' . $employee['last_name'];
        return self::back('personnel', 'success', "Profil de $name mis à jour.");
    }

    public static function toggleEmployee(Request $request, array $params): Response
    {
        $employee = Users::employeeById((int) $params['id']);
        if ($employee === null) {
            return self::back('personnel', 'error', 'Membre introuvable.');
        }
        $wasActive = (int) $employee['active'] === 1;
        Users::toggleActive((int) $employee['id'], $wasActive);
        // Un compte fermé ne doit pas garder de session ouverte.
        if ($wasActive) {
            \App\Core\Session::destroyAllFor((int) $employee['id']);
        }
        $name = $employee['first_name'] . ' ' . $employee['last_name'];
        return self::back('personnel', 'success',
            "$name est désormais " . ($wasActive ? 'désactivé(e).' : 'actif(ve).'));
    }

    public static function resetEmployeePassword(Request $request, array $params): Response
    {
        $employee = Users::employeeById((int) $params['id']);
        if ($employee === null) {
            return self::back('personnel', 'error', 'Membre introuvable.');
        }
        $password = Users::resetPassword((int) $employee['id']);
        return self::back('personnel', 'success', "Nouveau mot de passe pour {$employee['email']} : $password");
    }

    public static function deleteEmployee(Request $request, array $params): Response
    {
        Users::deleteEmployee((int) $params['id']);
        return self::back('personnel', 'success', 'Membre supprimé.');
    }

    /** Messagerie du membre : adresse et serveurs, réservés à l'administration. */
    public static function updateMailbox(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $employee = Users::employeeById($id);
        if ($employee === null) {
            return self::back('organisation', 'error', 'Membre introuvable.');
        }
        $address = mb_substr($request->input('mail_address'), 0, 254);
        $imapPort = Validate::port($request->input('mail_imap_port'));
        $smtpPort = Validate::port($request->input('mail_smtp_port'));

        $fail = static function (string $message) use ($id): Response {
            Flash::set('error', $message);
            return Response::redirect("/admin/employes/$id/modifier");
        };
        if ($address !== '' && !Validate::email($address)) {
            return $fail('Adresse de messagerie invalide.');
        }
        if ($imapPort === -1 || $smtpPort === -1) {
            return $fail('Port invalide (1 à 65535).');
        }

        Users::setMailbox($id, [
            'mail_address' => $address,
            'mail_imap_host' => mb_substr($request->input('mail_imap_host'), 0, 200),
            'mail_imap_port' => $imapPort,
            'mail_smtp_host' => mb_substr($request->input('mail_smtp_host'), 0, 200),
            'mail_smtp_port' => $smtpPort,
        ]);
        Flash::set('success', "Messagerie de {$employee['first_name']} {$employee['last_name']} mise à jour.");
        return Response::redirect("/admin/employes/$id/modifier");
    }

    // ---------- Actualités ----------

    public static function createNews(Request $request): Response
    {
        $title = mb_substr($request->input('title'), 0, 150);
        $body = mb_substr($request->input('body'), 0, 2000);
        if ($title === '') {
            return self::back('actualites', 'error', "Le titre de l'actualité est obligatoire.");
        }

        // « company », ou un périmètre précis : « department:3 », « team:7 ».
        $target = $request->input('target', 'company');
        $scope = 'company';
        $scopeId = null;
        if ($target !== 'company') {
            [$rawScope, $rawId] = array_pad(explode(':', $target, 2), 2, null);
            $scopeId = (int) $rawId;
            if (!in_array($rawScope, ['department', 'team'], true) || $scopeId === 0) {
                return self::back('actualites', 'error', 'Destinataire invalide.');
            }
            $exists = $rawScope === 'team' ? Org::teamById($scopeId) : Org::departmentById($scopeId);
            if ($exists === null) {
                return self::back('actualites', 'error', 'Service ou équipe introuvable.');
            }
            $scope = $rawScope;
        }

        $user = \App\Core\Session::get('user');
        Announcements::create(is_array($user) ? (int) $user['id'] : null, $scope, $scopeId, $title, $body);
        Audit::log('actualite.publiee', 'announcements', null, ['perimetre' => $scope]);
        return self::back('actualites', 'success', $scope === 'company'
            ? "Actualité publiée pour toute l'entreprise."
            : 'Actualité publiée pour ce périmètre.');
    }

    public static function deleteNews(Request $request, array $params): Response
    {
        Announcements::remove((int) $params['id']);
        Audit::log('actualite.supprimee', 'announcements', (int) $params['id']);
        return self::back('actualites', 'success', 'Actualité supprimée.');
    }

    // ---------- Outils et affectations ----------

    private static function toolForm(Request $request): array
    {
        return [
            'name' => mb_substr($request->input('name'), 0, 150),
            'category' => mb_substr($request->input('category'), 0, 100),
            'reference' => mb_substr($request->input('reference'), 0, 100),
            'description' => mb_substr($request->input('description'), 0, 1000),
            'login_url' => mb_substr($request->input('login_url'), 0, 500),
        ];
    }

    public static function createTool(Request $request): Response
    {
        $tool = self::toolForm($request);
        if ($tool['name'] === '') {
            return self::back('outils', 'error', "Merci de renseigner le nom de l'outil.");
        }
        if ($tool['login_url'] !== '' && !Validate::url($tool['login_url'])) {
            return self::back('outils', 'error', 'URL de connexion invalide (http:// ou https:// requis).');
        }
        Tools::create($tool);
        Audit::log('outil.ajoute', 'tools', null, ['nom' => $tool['name']]);
        return self::back('outils', 'success', "Outil « {$tool['name']} » ajouté au catalogue.");
    }

    public static function editTool(Request $request, array $params): Response
    {
        $tool = Tools::byId((int) $params['id']);
        if ($tool === null) {
            return self::back('outils', 'error', 'Outil introuvable.');
        }
        return Response::html(View::page('admin/tool-edit', [
            'title' => $tool['name'] . ' — ' . t('app.name'),
            'panelLabel' => t('common.adminRole'),
            'headerTitle' => $tool['name'],
            'headerSubtitle' => t('nav.tools'),
            'navItems' => self::nav(),
            'tool' => $tool,
        ]));
    }

    public static function updateTool(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        if (Tools::byId($id) === null) {
            return self::back('outils', 'error', 'Outil introuvable.');
        }
        $tool = self::toolForm($request);
        if ($tool['name'] === '') {
            Flash::set('error', "Merci de renseigner le nom de l'outil.");
            return Response::redirect("/admin/outils/$id/modifier");
        }
        if ($tool['login_url'] !== '' && !Validate::url($tool['login_url'])) {
            Flash::set('error', 'URL de connexion invalide (http:// ou https:// requis).');
            return Response::redirect("/admin/outils/$id/modifier");
        }
        Tools::update($id, $tool);
        Audit::log('outil.modifie', 'tools', $id);
        return self::back('outils', 'success', "Outil « {$tool['name']} » mis à jour.");
    }

    public static function deleteTool(Request $request, array $params): Response
    {
        Tools::remove((int) $params['id']);
        Audit::log('outil.supprime', 'tools', (int) $params['id']);
        return self::back('outils', 'success', 'Outil supprimé du catalogue.');
    }

    public static function assign(Request $request): Response
    {
        $employee = Users::employeeById((int) $request->input('employee_id'));
        $tool = Tools::byId((int) $request->input('tool_id'));
        if ($employee === null || $tool === null) {
            return self::back('affectations', 'error', 'Membre ou outil introuvable.');
        }
        $ok = Tools::assign(
            (int) $employee['id'],
            (int) $tool['id'],
            mb_substr($request->input('note'), 0, 300),
            mb_substr($request->input('username'), 0, 150)
        );
        if (!$ok) {
            return self::back('affectations', 'error', 'Cet outil est déjà affecté à ce membre.');
        }
        Audit::log('affectation.creee', 'assignments', (int) $tool['id'], ['membre' => (int) $employee['id']]);
        return self::back('affectations', 'success',
            "« {$tool['name']} » affecté à {$employee['first_name']} {$employee['last_name']}.");
    }

    public static function unassign(Request $request, array $params): Response
    {
        Tools::unassign((int) $params['id']);
        Audit::log('affectation.retiree', 'assignments', (int) $params['id']);
        return self::back('affectations', 'success', 'Affectation retirée.');
    }

    // ---------- Droits, modules, apparence ----------

    public static function grantFlag(Request $request, array $params): Response
    {
        $flag = (string) $params['flag'];
        $employee = Users::employeeById((int) $request->input('employee_id'));
        if ($employee === null) {
            return self::back('droits', 'error', 'Membre introuvable.');
        }
        if (!Users::setRoleFlag((int) $employee['id'], $flag, true)) {
            return self::back('droits', 'error', 'Droit inconnu.');
        }
        $label = Users::ROLE_FLAGS[$flag];
        return self::back('droits', 'success',
            "{$employee['first_name']} {$employee['last_name']} a désormais : $label.");
    }

    public static function revokeFlag(Request $request, array $params): Response
    {
        $flag = (string) $params['flag'];
        if (!Users::setRoleFlag((int) $params['id'], $flag, false)) {
            return self::back('droits', 'error', 'Droit inconnu.');
        }
        return self::back('droits', 'success', 'Droit retiré : ' . Users::ROLE_FLAGS[$flag] . '.');
    }

    public static function setModule(Request $request, array $params): Response
    {
        $key = (string) $params['key'];
        $module = Catalogue::byKey($key);
        if ($module === null) {
            return self::back('modules', 'error', 'Module inconnu.');
        }
        $enable = $request->input('enabled') === 'on';
        Catalogue::setEnabled($key, $enable);
        // Un module qui s'ouvre sur une page vide ne s'utilise pas : le plan
        // comptable et les barèmes de paie sont posés à l'activation, et
        // restent modifiables. Le semis ne duplique rien s'il a déjà eu lieu.
        if ($enable && $key === 'comptabilite') {
            \App\Modules\Accounting::seedDefaults();
        }
        if ($enable && $key === 'paie') {
            \App\Modules\Payroll::seedDefaults();
        }
        Audit::log($enable ? 'module.active' : 'module.desactive', 'settings', null, ['module' => $key]);
        return self::back('modules', 'success', $enable
            ? "Module « {$module['label']} » activé."
            : "Module « {$module['label']} » désactivé. Ses données sont conservées.");
    }

    /** La palette engage l'identité de l'entreprise : donc l'administration. */
    public static function setPalette(Request $request): Response
    {
        $key = $request->input('palette');
        if (!Themes::set($key)) {
            return self::back('apparence', 'error', 'Palette inconnue.');
        }
        Audit::log('apparence.palette_changee', 'settings', null, ['palette' => $key]);
        return self::back('apparence', 'success',
            "Palette « " . Themes::byKey($key)['label'] . " » appliquée à toute l'instance.");
    }

    public static function setCompany(Request $request): Response
    {
        $name = mb_substr($request->input('company_name'), 0, 120);
        if ($name === '') {
            return self::back('apparence', 'error', "Le nom de l'entreprise est obligatoire.");
        }
        $leave = $request->input('annual_leave_days');
        Settings::setMany(array_filter([
            'company_name' => $name,
            'annual_leave_days' => is_numeric($leave) ? (string) max(0, min(60, (int) $leave)) : null,
        ], static fn ($value): bool => $value !== null));
        Audit::log('instance.reglages_modifies', 'settings');
        return self::back('apparence', 'success', 'Réglages enregistrés.');
    }
}
