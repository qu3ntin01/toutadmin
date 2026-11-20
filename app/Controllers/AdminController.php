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
use App\Modules\Corbeille;
use App\Modules\CustomFields;
use App\Modules\Announcements;
use App\Modules\Societes;
use App\Modules\Catalogue;
use App\Modules\MemberMail;
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
            'navItems' => self::tabs((int) Db::value(
                "SELECT COUNT(*) FROM hr_requests WHERE company_id = ? AND status = 'En attente'",
                [Societes::courant()]
            )),
            'scripts' => ['/js/admin.js', '/js/confirm.js', '/js/fenetre.js', '/js/modules.js'],
            // Activer ou retirer un module ouvre une fenêtre : ce que le module
            // ajoute, sa réserve, et — quand on le retire — la phrase qui
            // compte, que ses données restent.
            'mainId' => 'console-admin',
            'mainData' => ['csrf' => \App\Core\Csrf::token()],
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
            'champsObjets' => CustomFields::OBJETS,
            'champsTypes' => CustomFields::TYPES,
            'champsParObjet' => array_combine(
                array_keys(CustomFields::OBJETS),
                array_map(
                    static fn (string $o): array => CustomFields::champs($o, true),
                    array_keys(CustomFields::OBJETS),
                ),
            ),
            // Le nombre de valeurs par champ : l'écran doit pouvoir dire
            // « vous allez effacer 340 valeurs » avant de demander
            // confirmation. « Supprimer ce champ ? » n'est pas la même question.
            'champsValeurs' => self::compterLesValeurs(),
            'champsOrphelines' => CustomFields::orphelines(),
            'societesToutes' => Societes::toutes(),
            'societesMembres' => array_combine(
                array_map(static fn (array $c): int => (int) $c['id'], Societes::toutes()),
                array_map(static fn (array $c): array => Societes::membres((int) $c['id']), Societes::toutes()),
            ),
            'societesCourante' => Societes::courantId(),
            // Tout compte actif peut être rattaché, administration comprise :
            // c'est l'appartenance qui dit où quelqu'un travaille, pas son rôle.
            'societesRattachables' => Db::all(
                'SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE'
            ),
            'corbeilleLignes' => Corbeille::lister(),
            'corbeilleObjets' => Corbeille::OBJETS,
            'pendingRequestCount' => (int) Db::value(
                "SELECT COUNT(*) FROM hr_requests WHERE company_id = ? AND status = 'En attente'",
                [Societes::courant()]
            ),
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
            ['tab' => 'champs', 'label' => t('champs.title')],
            ['tab' => 'corbeille', 'label' => t('corbeille.title')],
            ['tab' => 'societes', 'label' => t('societes.title')],
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

    /** @return array<int, int> */
    private static function compterLesValeurs(): array
    {
        $comptes = [];
        foreach (array_keys(CustomFields::OBJETS) as $objet) {
            foreach (CustomFields::champs($objet, true) as $champ) {
                $comptes[(int) $champ['id']] = CustomFields::compterValeurs((int) $champ['id']);
            }
        }
        return $comptes;
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
        if (!Org::removeManager($request->input('scope'), (int) $request->input('scope_id'), (int) $request->input('user_id'))) {
            return self::back('organisation', 'error', 'Encadrement introuvable.');
        }

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
        /*
         * Et la société : un compte créé depuis une société appartient à cette
         * société. Sans ce rattachement, la personne ouvrirait un produit vide,
         * et le message parlerait de périmètre à quelqu'un qui n'a rien demandé.
         */
        Societes::rattacher(Societes::courantId(), (int) $created['id']);
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
            // Le mot de passe n'est jamais rendu : displaySettings en donne la présence.
            'mailbox' => MemberMail::displaySettings((int) $employee['id']),
            'champsPerso' => CustomFields::valeurs('employe', (int) $employee['id']),
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

        /*
         * Les champs personnalisés passent avant l'écriture du reste.
         *
         * Un champ refusé doit laisser la fiche telle qu'elle était :
         * enregistrer le grade puis échouer sur un champ personnalisé donnerait
         * une fiche à moitié modifiée, et l'utilisateur ne verrait que l'erreur.
         */
        $refus = CustomFields::refus('employe', $id, $request->body);
        if ($refus !== null) {
            return $fail($refus);
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

    /**
     * « Se connecter en tant que ».
     *
     * Toute la prudence est dans le module : les refus, la durée, ce que
     * l'emprunt n'ouvre pas, et le journal qui nomme les deux personnes. Ici, il
     * ne reste qu'à dire où l'on arrive — l'espace du membre, puisque c'est ce
     * qu'on vient voir.
     */
    public static function impersonate(Request $request, array $params): Response
    {
        $verdict = \App\Modules\Emprunt::commencer((int) $params['id']);
        if (!$verdict['ok']) {
            return self::back('personnel', 'error', t((string) $verdict['raison']));
        }
        $cible = $verdict['cible'];
        Flash::set('success', t('emprunt.started', [
            'nom' => trim($cible['first_name'] . ' ' . $cible['last_name']),
            'minutes' => \App\Modules\Emprunt::DUREE_MINUTES,
        ]));
        return Response::redirect('/mon-espace');
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
        $id = (int) $params['id'];
        if (!Users::deleteEmployee($id)) {
            return self::back('personnel', 'error', 'Membre introuvable.');
        }

        /*
         * Les valeurs des champs personnalisés s'en vont avec la fiche.
         *
         * Aucune clé étrangère ne peut le faire : la table visée dépend de
         * l'objet, et une contrainte ne sait pas dire « selon la valeur d'une
         * autre colonne ». Oublier cet appel laisserait des valeurs derrière,
         * qui reviendraient à l'écran le jour où l'identifiant serait
         * réattribué.
         */
        CustomFields::oublier('employe', $id);

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

        $imapHost = mb_substr($request->input('mail_imap_host'), 0, 200);
        Users::setMailbox($id, [
            'mail_address' => $address,
            'mail_imap_host' => $imapHost,
            'mail_imap_port' => $imapPort,
            'mail_smtp_host' => mb_substr($request->input('mail_smtp_host'), 0, 200),
            'mail_smtp_port' => $smtpPort,
        ]);

        // Mot de passe et relève : le reste du formulaire, tenu par le module qui
        // chiffre le secret et refuse d'allumer une relève qui n'aurait rien à lire.
        $saved = MemberMail::setCredentials($id, [
            'host' => $imapHost,
            'port' => $request->input('mail_imap_port'),
            'secure' => $request->input('mail_imap_secure') === '' ? '0' : '1',
            'password' => $request->input('mail_password'),
            'enabled' => $request->input('mail_sync_enabled') === '' ? null : '1',
        ]);
        if (empty($saved['ok'])) {
            return $fail((string) $saved['message']);
        }

        // Ce qui est journalisé, c'est le branchement — jamais le mot de passe.
        Audit::log('messagerie.reglages', 'users', $id, [
            'adresse' => $address,
            'serveur' => $imapHost,
            'releve' => $request->input('mail_sync_enabled') !== '' ? 'allumée' : 'éteinte',
        ]);

        Flash::set('success', "Messagerie de {$employee['first_name']} {$employee['last_name']} mise à jour.");
        return Response::redirect("/admin/employes/$id/modifier");
    }

    /**
     * Essai de connexion. Le seul moyen de savoir qu'un mot de passe passe est de
     * s'en servir une fois — mieux vaut ici que dans le balayage de trois heures.
     */
    public static function testMailbox(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        if (Users::employeeById($id) === null) {
            return self::back('organisation', 'error', 'Membre introuvable.');
        }

        $verdict = MemberMail::test($id);
        Flash::set(empty($verdict['ok']) ? 'error' : 'success', (string) $verdict['message']);
        return Response::redirect("/admin/employes/$id/modifier");
    }

    /**
     * Relève tout de suite, sans attendre le balayage. L'administration déclenche
     * le passage ; elle ne voit pas pour autant ce qui est rapporté — les messages
     * n'apparaissent que dans l'espace de leur destinataire.
     */
    public static function fetchMailbox(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        if (Users::employeeById($id) === null) {
            return self::back('organisation', 'error', 'Membre introuvable.');
        }

        $verdict = MemberMail::fetchFor($id, ['audit' => true]);
        Flash::set(empty($verdict['ok']) ? 'error' : 'success', (string) $verdict['message']);
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
        if (!Announcements::remove((int) $params['id'])) {
            return self::back('actualites', 'error', 'Actualité introuvable.');
        }

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
        if (!Tools::remove((int) $params['id'])) {
            return self::back('outils', 'error', 'Outil introuvable.');
        }

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
        if (!Tools::unassign((int) $params['id'])) {
            return self::back('affectations', 'error', 'Affectation introuvable.');
        }

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
        // La fenêtre attend du JSON ; la page attend une redirection. Même code,
        // deux rendus : sans JavaScript, le formulaire part comme avant.
        $enFenetre = $request->header('x-fenetre') === '1';

        $module = Catalogue::byKey($key);
        if ($module === null) {
            if ($enFenetre) {
                return self::enJson(['ok' => false, 'message' => 'Module inconnu.'], 404);
            }
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
        // Le nom vient du dictionnaire : le catalogue n'en garde plus.
        $nom = t('mod.' . $key . '.label');
        $message = $enable
            ? "Module « {$nom} » activé."
            : "Module « {$nom} » désactivé. Ses données sont conservées.";

        if ($enFenetre) {
            return self::enJson([
                'ok' => true,
                'message' => $message,
                'actif' => $enable,
                'vers' => $enable ? (string) ($module['href'] ?? '') : '',
            ]);
        }
        return self::back('modules', 'success', $message);
    }

    /** Une réponse que la fenêtre sait lire. */
    private static function enJson(array $payload, int $statut = 200): Response
    {
        return Response::text((string) json_encode($payload, JSON_UNESCAPED_UNICODE), $statut)
            ->withHeaders(['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']);
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

    // ---------- Champs personnalisés ----------

    /*
     * Les champs que l'entreprise ajoute elle-même aux fiches du produit.
     *
     * Tout retombe sur l'onglet, et non sur une page par champ : on en pose
     * trois d'affilée ou on n'en pose aucun, et faire changer de page entre
     * chaque rendrait le geste plus long que le champ ne vaut.
     */
    private static function direErreurChamp(string $cle): string
    {
        return match ($cle) {
            'champs.err.objet' => "Cet objet n'accepte pas de champ personnalisé.",
            'champs.err.type' => 'Type de champ inconnu.',
            'champs.err.libelle' => 'Un champ porte un nom.',
            'champs.err.listeVide' => 'Une liste sans choix ne propose rien : donnez-lui au moins une ligne.',
            default => 'Champ refusé.',
        };
    }

    public static function createField(Request $request): Response
    {
        try {
            $champ = CustomFields::creer([
                'objet' => trim((string) $request->input('objet')),
                'libelle' => mb_substr(trim((string) $request->input('libelle')), 0, 120),
                'type' => trim((string) $request->input('type')),
                'choix' => mb_substr((string) ($request->body['choix'] ?? ''), 0, 4000),
                'obligatoire' => $request->input('obligatoire') === '1',
                'aide' => mb_substr(trim((string) $request->input('aide')), 0, 300),
                'rang' => (int) $request->input('rang'),
            ]);
        } catch (\RuntimeException $erreur) {
            return self::back('champs', 'error', self::direErreurChamp($erreur->getMessage()));
        }
        Audit::log('champ.cree', 'custom_fields', (int) $champ['id'],
            ['objet' => $champ['objet'], 'cle' => $champ['cle'], 'type' => $champ['type']]);
        return self::back('champs', 'success', 'Champ « ' . $champ['libelle'] . ' » ajouté.');
    }

    public static function updateField(Request $request, array $params): Response
    {
        $champ = CustomFields::champ((int) $params['id']);
        if ($champ === null) {
            return self::back('champs', 'error', 'Champ introuvable.');
        }
        try {
            CustomFields::modifier((int) $champ['id'], [
                'libelle' => mb_substr(trim((string) $request->input('libelle')), 0, 120),
                'choix' => mb_substr((string) ($request->body['choix'] ?? ''), 0, 4000),
                'obligatoire' => $request->input('obligatoire') === '1',
                'aide' => mb_substr(trim((string) $request->input('aide')), 0, 300),
                'rang' => (int) $request->input('rang'),
            ]);
        } catch (\RuntimeException $erreur) {
            return self::back('champs', 'error', self::direErreurChamp($erreur->getMessage()));
        }
        Audit::log('champ.modifie', 'custom_fields', (int) $champ['id'],
            ['objet' => $champ['objet'], 'cle' => $champ['cle']]);
        return self::back('champs', 'success', 'Champ mis à jour.');
    }

    public static function toggleField(Request $request, array $params): Response
    {
        $champ = CustomFields::champ((int) $params['id']);
        if ($champ === null) {
            return self::back('champs', 'error', 'Champ introuvable.');
        }
        $allume = empty($champ['actif']);
        CustomFields::basculer((int) $champ['id'], $allume);
        Audit::log($allume ? 'champ.rallume' : 'champ.eteint', 'custom_fields', (int) $champ['id'],
            ['objet' => $champ['objet'], 'cle' => $champ['cle']]);
        return self::back('champs', 'success', $allume
            ? 'Champ « ' . $champ['libelle'] . ' » rallumé : ses valeurs reviennent.'
            : 'Champ « ' . $champ['libelle'] . ' » éteint. Ses valeurs sont gardées.');
    }

    public static function deleteField(Request $request, array $params): Response
    {
        $parti = CustomFields::supprimer((int) $params['id']);
        if ($parti === null) {
            return self::back('champs', 'error', 'Champ introuvable.');
        }
        Audit::log('champ.supprime', 'custom_fields', (int) $parti['champ']['id'],
            ['objet' => $parti['champ']['objet'], 'cle' => $parti['champ']['cle'], 'valeurs' => $parti['valeurs']]);
        return self::back('champs', 'success', $parti['valeurs'] > 0
            ? 'Champ « ' . $parti['champ']['libelle'] . ' » supprimé, avec ' . $parti['valeurs'] . ' valeur(s).'
            : 'Champ « ' . $parti['champ']['libelle'] . ' » supprimé.');
    }

    /*
     * Le balayage des valeurs orphelines.
     *
     * Elles naissent d'un module qui supprime un enregistrement sans prévenir
     * les champs personnalisés. Elles ne gênent personne tant qu'elles dorment,
     * mais reviendraient à l'écran si l'identifiant était réattribué.
     */
    public static function sweepFields(Request $request): Response
    {
        $n = CustomFields::orphelines(true);
        if ($n > 0) {
            Audit::log('champ.balaye', 'custom_values', null, ['effacees' => $n]);
        }
        return self::back('champs', 'success', $n > 0
            ? $n . ' valeur(s) sans enregistrement effacée(s).'
            : 'Aucune valeur orpheline.');
    }

    /*
     * La corbeille.
     *
     * Remettre en place est une écriture, pas une lecture : elle repose un
     * enregistrement et ses pièces, et c'est pour cela qu'elle est réservée à
     * l'administration et inscrite au journal. L'écran ne montre jamais la
     * charge sauvegardée — seulement ce qu'elle était.
     */
    public static function restoreTrash(Request $request, array $params): Response
    {
        $ligne = Corbeille::ligne((int) $params['id']);
        if ($ligne === null) {
            return self::back('corbeille', 'error', t('corbeille.err.introuvable'));
        }

        $verdict = Corbeille::restaurer((int) $ligne['id']);
        if (!$verdict['ok']) {
            return self::back('corbeille', 'error', t((string) $verdict['raison']));
        }

        Audit::log('corbeille.restauree', (string) $ligne['objet'], (int) $ligne['record_id'],
            ['libelle' => $ligne['libelle']]);
        return self::back('corbeille', 'success',
            t('corbeille.restored', ['libelle' => (string) $ligne['libelle']]));
    }

    public static function dropTrash(Request $request, array $params): Response
    {
        $ligne = Corbeille::ligne((int) $params['id']);
        if ($ligne === null) {
            return self::back('corbeille', 'error', t('corbeille.err.introuvable'));
        }

        Corbeille::vider((int) $ligne['id']);
        Audit::log('corbeille.videe', (string) $ligne['objet'], (int) $ligne['record_id'],
            ['libelle' => $ligne['libelle']]);
        return self::back('corbeille', 'success', t('corbeille.dropped'));
    }
}
