<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validate;
use App\Core\View;
use App\Modules\People;

/**
 * Parcours d'arrivée et de départ, compétences et habilitations.
 *
 * Une embauche et un départ sont des suites de gestes à faire par plusieurs
 * services : l'espace appartient aux ressources humaines, qui les tiennent.
 */
final class JourneysController
{
    private static function back(string $anchor): Response
    {
        return Response::redirect('/parcours#' . $anchor);
    }

    private static function fail(string $anchor, string $message): Response
    {
        Flash::set('error', $message);
        return self::back($anchor);
    }

    private static function error(string $message, int $status): Response
    {
        return Response::html(View::page('error', ['title' => $message, 'message' => $message]), $status);
    }

    private static function employees(): array
    {
        return Db::all(
            'SELECT id, first_name, last_name, email FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE'
        );
    }

    public static function index(Request $request): Response
    {
        $expiring = People::expiringSkills();
        $missing = People::missingMandatory();

        return Response::html(View::page('journeys/index', [
            'title' => t('nav.careerPaths') . ' — ' . t('app.name'),
            'panelLabel' => t('par.panel'),
            'headerTitle' => t('nav.careerPaths'),
            'headerSubtitle' => t('par.headerSub'),
            'navItems' => [
                ['tab' => 'parcours', 'label' => t('par.tabArrivals')],
                ['tab' => 'modeles', 'label' => t('par.tabTemplates')],
                ['tab' => 'competences', 'label' => t('par.tabSkills')],
                ['tab' => 'echeances', 'label' => t('sst.deadlines'),
                 'badge' => (count($expiring) + count($missing)) ?: null],
            ],
            'footLinks' => [['href' => '/rh', 'label' => t('nav.hrSpace')]],
            'scripts' => ['/js/admin.js', '/js/meter.js', '/js/confirm.js'],
            'templateList' => People::templates(),
            'checklistList' => People::checklists(),
            'late' => People::lateItems(),
            'skillList' => People::skills(),
            'matrix' => People::matrix(),
            'expiring' => $expiring,
            'missing' => $missing,
            'kinds' => People::CHECKLIST_KINDS,
            'ownerRoles' => People::OWNER_ROLES,
            'levels' => People::SKILL_LEVELS,
            'employees' => self::employees(),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    public static function showChecklist(Request $request, array $params): Response
    {
        $checklist = People::checklistById((int) $params['id']);
        if ($checklist === null) {
            return self::error('Parcours introuvable.', 404);
        }
        $person = Db::get('SELECT * FROM users WHERE id = ?', [(int) $checklist['user_id']]);

        return Response::html(View::page('journeys/checklist', [
            'title' => t('plst.tabItems') . ' — ' . t('app.name'),
            'panelLabel' => t('par.panel'),
            'headerTitle' => trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? '')),
            'headerSubtitle' => $checklist['kind'] . ' · '
                . t('plst.pivotDay', ['date' => (string) $checklist['reference_date']]),
            'navItems' => [['tab' => 'points', 'label' => t('plst.tabItems')]],
            'footLinks' => [['href' => '/parcours', 'label' => t('par.panel')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'checklist' => $checklist,
            'person' => $person,
            'items' => People::checklistItems((int) $checklist['id']),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    // ---------- Modèles ----------

    public static function createTemplate(Request $request): Response
    {
        $name = trim($request->input('name'));
        if ($name === '' || mb_strlen($name) > 120) {
            return self::fail('modeles', 'Nom de modèle invalide.');
        }
        if (!in_array($request->input('kind'), People::CHECKLIST_KINDS, true)) {
            return self::fail('modeles', 'Type invalide.');
        }

        People::createTemplate($name, $request->input('kind'));
        Flash::set('success', 'Modèle créé.');
        return self::back('modeles');
    }

    public static function addTemplateItem(Request $request, array $params): Response
    {
        $template = People::templateById((int) $params['id']);
        if ($template === null) {
            return self::fail('modeles', 'Modèle introuvable.');
        }

        $label = trim($request->input('label'));
        if ($label === '' || mb_strlen($label) > 200) {
            return self::fail('modeles', 'Intitulé invalide.');
        }
        if (!in_array($request->input('owner_role'), People::OWNER_ROLES, true)) {
            return self::fail('modeles', 'Responsable invalide.');
        }

        $raw = trim($request->input('offset_days'));
        $offset = (int) $raw;
        if ($raw === '' || (string) $offset !== $raw || $offset < -365 || $offset > 365) {
            return self::fail('modeles', "L'écart au jour pivot doit tenir dans l'année.");
        }

        People::addTemplateItem((int) $template['id'], [
            'label' => $label, 'ownerRole' => $request->input('owner_role'), 'offsetDays' => $offset,
        ]);
        Flash::set('success', 'Point ajouté au modèle.');
        return self::back('modeles');
    }

    public static function deleteTemplateItem(Request $request, array $params): Response
    {
        People::deleteTemplateItem((int) $params['id']);
        Flash::set('success', 'Point retiré.');
        return self::back('modeles');
    }

    public static function deleteTemplate(Request $request, array $params): Response
    {
        People::deleteTemplate((int) $params['id']);
        Flash::set('success', 'Modèle supprimé. Les parcours déjà lancés restent en place.');
        return self::back('modeles');
    }

    // ---------- Parcours ----------

    public static function startChecklist(Request $request): Response
    {
        $templateId = (int) $request->input('template_id');
        $userId = (int) $request->input('user_id');
        $reference = trim($request->input('reference_date'));

        if (People::templateById($templateId) === null) {
            return self::fail('parcours', 'Modèle introuvable.');
        }
        if (Db::get('SELECT 1 AS ok FROM users WHERE id = ?', [$userId]) === null) {
            return self::fail('parcours', 'Membre introuvable.');
        }
        if (!Validate::date($reference)) {
            return self::fail('parcours', 'Date pivot invalide.');
        }

        $id = People::startChecklist(['templateId' => $templateId, 'userId' => $userId, 'referenceDate' => $reference]);
        Audit::log('parcours.lance', 'checklists', $id, ['membre' => $userId]);
        Flash::set('success', 'Parcours lancé : les échéances sont calculées à partir de la date pivot.');
        return Response::redirect('/parcours/listes/' . $id);
    }

    public static function toggleItem(Request $request, array $params): Response
    {
        $checklistId = People::toggleItem((int) $params['id'], (int) Session::get('user')['id']);
        if ($checklistId === null) {
            return self::fail('parcours', 'Point introuvable.');
        }
        return Response::redirect('/parcours/listes/' . $checklistId);
    }

    public static function deleteChecklist(Request $request, array $params): Response
    {
        People::deleteChecklist((int) $params['id']);
        Flash::set('success', 'Parcours supprimé.');
        return self::back('parcours');
    }

    // ---------- Compétences ----------

    public static function createSkill(Request $request): Response
    {
        $name = trim($request->input('name'));
        if ($name === '' || mb_strlen($name) > 120) {
            return self::fail('competences', 'Intitulé invalide.');
        }
        if (Db::get('SELECT 1 AS ok FROM skills WHERE name = ?', [$name]) !== null) {
            return self::fail('competences', 'Cette compétence existe déjà.');
        }

        $validity = trim($request->input('validity_months'));
        $months = $validity === '' ? null : (int) $validity;
        if ($validity !== '' && ((string) $months !== $validity || $months < 1 || $months > 600)) {
            return self::fail('competences', 'Durée de validité invalide.');
        }

        People::createSkill([
            'name' => $name,
            'category' => mb_substr(trim($request->input('category')) ?: 'Générale', 0, 60),
            'validityMonths' => $months,
            'mandatory' => $request->input('mandatory') === '1',
        ]);
        Flash::set('success', 'Compétence ajoutée.');
        return self::back('competences');
    }

    public static function deleteSkill(Request $request, array $params): Response
    {
        People::deleteSkill((int) $params['id']);
        Flash::set('success', 'Compétence supprimée, avec les attributions correspondantes.');
        return self::back('competences');
    }

    public static function grantSkill(Request $request): Response
    {
        $userId = (int) $request->input('user_id');
        $skillId = (int) $request->input('skill_id');
        $obtained = trim($request->input('obtained_on'));
        $level = (int) $request->input('level');

        if (Db::get('SELECT 1 AS ok FROM users WHERE id = ?', [$userId]) === null) {
            return self::fail('competences', 'Membre introuvable.');
        }
        if (People::skillById($skillId) === null) {
            return self::fail('competences', 'Compétence introuvable.');
        }
        if (!in_array($level, People::SKILL_LEVELS, true)) {
            return self::fail('competences', 'Niveau invalide.');
        }
        if ($obtained !== '' && !Validate::date($obtained)) {
            return self::fail('competences', "Date d'obtention invalide.");
        }

        People::grantSkill([
            'userId' => $userId, 'skillId' => $skillId, 'level' => $level,
            'obtainedOn' => $obtained !== '' ? $obtained : null,
            'reference' => mb_substr(trim($request->input('reference')), 0, 120),
        ]);
        Flash::set('success', "Compétence attribuée. L'échéance découle de sa durée de validité.");
        return self::back('competences');
    }

    public static function revokeSkill(Request $request, array $params): Response
    {
        People::revokeSkill((int) $params['userId'], (int) $params['skillId']);
        Flash::set('success', 'Attribution retirée.');
        return self::back('competences');
    }
}
