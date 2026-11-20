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
use App\Modules\Privacy;
use App\Modules\Users;

/**
 * Données personnelles.
 *
 * Le registre des traitements, ce que l'instance détient sur une personne, et
 * l'effacement — qui distingue ce qui se supprime de ce qui doit être conservé.
 */
final class PrivacyController
{
    private static function back(string $anchor, string $type, string $message, ?int $personId = null): Response
    {
        Flash::set($type, $message);
        $query = $personId === null ? '' : '?personne=' . $personId;
        return Response::redirect('/rgpd' . $query . '#' . $anchor);
    }

    public static function index(Request $request): Response
    {
        $targetId = (int) $request->input('personne') ?: null;
        $target = $targetId === null ? null : Users::byId($targetId);

        // Compte rendu du dernier effacement, affiché une fois puis oublié :
        // retiré de la session avant le rendu, jamais après.
        $eraseReport = Session::get('erase_report');
        Session::forget('erase_report');

        return Response::html(View::page('privacy/index', [
            'title' => t('nav.privacy') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.privacy'),
            'headerTitle' => t('nav.privacy'),
            'headerSubtitle' => t('gdpr.headerSub'),
            'navItems' => [
                ['tab' => 'registre', 'label' => t('gdpr.tabRegister')],
                ['tab' => 'acces', 'label' => t('gdpr.tabAccess')],
            ],
            'footLinks' => [
                ['href' => '/securite', 'label' => t('nav.security')],
                ['href' => '/admin', 'label' => t('admin.title')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'recordList' => Privacy::records(),
            'legalBases' => Privacy::LEGAL_BASES,
            'people' => Db::all(
                'SELECT id, first_name, last_name, email, active FROM users ORDER BY last_name COLLATE NOCASE'
            ),
            'target' => $target,
            'held' => $target === null ? null : Privacy::collectFor((int) $target['id']),
            'eraseReport' => $eraseReport,
        ]));
    }

    // ---------- Registre ----------

    public static function createRecord(Request $request): Response
    {
        $name = $request->input('name');
        if ($name === '' || mb_strlen($name) > 160) {
            return self::back('registre', 'error', 'Intitulé invalide.');
        }
        $basis = $request->input('legal_basis');
        if (!in_array($basis, Privacy::LEGAL_BASES, true)) {
            return self::back('registre', 'error', 'Base légale invalide.');
        }

        $id = Privacy::createRecord([
            'name' => $name,
            'purpose' => mb_substr($request->input('purpose'), 0, 1000),
            'legalBasis' => $basis,
            'dataCategories' => mb_substr($request->input('data_categories'), 0, 1000),
            'recipients' => mb_substr($request->input('recipients'), 0, 1000),
            'retention' => mb_substr($request->input('retention'), 0, 300),
            'measures' => mb_substr($request->input('measures'), 0, 1000),
        ]);
        Audit::log('rgpd.traitement_ajoute', 'processing_records', $id, ['nom' => $name]);
        return self::back('registre', 'success', 'Traitement inscrit au registre.');
    }

    public static function deleteRecord(Request $request, array $params): Response
    {
        $record = Privacy::recordById((int) $params['id']);
        if ($record === null) {
            return self::back('registre', 'error', 'Traitement introuvable.');
        }
        Privacy::deleteRecord((int) $record['id']);
        Audit::log('rgpd.traitement_supprime', 'processing_records', (int) $record['id'], ['nom' => $record['name']]);
        return self::back('registre', 'success', 'Traitement retiré du registre.');
    }

    public static function seedRecords(Request $request): Response
    {
        $created = Privacy::seedRecords();
        return self::back('registre', $created > 0 ? 'success' : 'error', $created > 0
            ? "$created traitement(s) préremplis. À relire et à compléter : ils décrivent ce que le CMS fait, pas ce que fait votre entreprise."
            : "Le registre contient déjà des traitements : rien n'a été ajouté.");
    }

    // ---------- Droit d'accès ----------

    public static function exportJson(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $data = Privacy::exportFor($id);
        if ($data === null) {
            return Response::html(View::page('error', [
                'title' => 'Personne introuvable.', 'message' => 'Personne introuvable.',
            ]), 404);
        }

        Audit::log('rgpd.export_donnees', 'users', $id, ['email' => $data['personne']['email']]);
        return Response::text((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->withHeaders([
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => "attachment; filename=\"donnees-personnelles-$id.json\"",
            ]);
    }

    public static function erase(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $target = Users::byId($id);
        if ($target === null) {
            return self::back('acces', 'error', 'Personne introuvable.');
        }

        // Un administrateur ne s'efface pas lui-même : il perdrait la main en
        // cours de route.
        if ($id === (int) Session::get('user')['id']) {
            return self::back('acces', 'error',
                'Vous ne pouvez pas effacer votre propre compte depuis cet écran.', $id);
        }
        if ($target['role'] === 'admin') {
            return self::back('acces', 'error',
                "Rétrogradez d'abord ce compte : un administrateur ne s'efface pas tel quel.", $id);
        }
        if (mb_strtolower($request->input('confirmation')) !== mb_strtolower((string) $target['email'])) {
            return self::back('acces', 'error',
                "Saisissez l'adresse exacte du compte pour confirmer l'effacement.", $id);
        }

        $result = (array) Privacy::eraseFor($id);
        Session::destroyAllFor($id);
        Audit::log('rgpd.effacement', 'users', $id, [
            'email' => $result['email'],
            'efface' => implode(', ', array_map(
                static fn (array $e): string => $e['label'] . ':' . $e['count'],
                $result['erased']
            )),
            'conserve' => implode(', ', array_map(
                static fn (array $k): string => $k['label'] . ':' . $k['count'],
                $result['kept']
            )),
        ]);

        Session::set('erase_report', $result);
        return self::back('acces', 'success',
            'Effacement effectué. Le détail de ce qui a été conservé figure ci-dessous.', $id);
    }
}
