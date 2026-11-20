<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Importer;
use App\Modules\Users;

/**
 * Import de données en masse.
 *
 * Importer, c'est écrire en masse : l'accès suit le droit qu'on aurait besoin
 * d'avoir pour saisir ces lignes une par une.
 */
final class ImportController
{
    public static function canAccess(?array $user): bool
    {
        return $user !== null && ($user['role'] === 'admin' || (int) ($user['is_finance'] ?? 0) === 1);
    }

    private static function user(): array
    {
        return (array) Users::byId((int) Session::get('user')['id']);
    }

    private static function allowed(string $key): ?array
    {
        foreach (Importer::availableFor(self::user()) as $entity) {
            if ($entity['key'] === $key) {
                return $entity;
            }
        }
        return null;
    }

    private static function error(string $message, int $status): Response
    {
        return Response::html(View::page('error', ['title' => $message, 'message' => $message]), $status);
    }

    private static function render(array $extra = []): Response
    {
        $entities = array_map(static fn (array $entity): array => [
            'key' => $entity['key'],
            'label' => $entity['label'],
            'hint' => $entity['hint'],
            'columns' => $entity['columns'],
            'template' => Importer::template($entity),
        ], Importer::availableFor(self::user()));

        return Response::html(View::page('import/index', array_merge([
            'title' => t('nav.dataImport') . ' — ' . t('app.name'),
            'panelLabel' => t('imp.panel'),
            'headerTitle' => t('nav.dataImport'),
            'headerSubtitle' => t('imp.headerSub'),
            'footLinks' => [
                ['href' => '/admin', 'label' => t('nav.adminConsole')],
                ['href' => '/gestion', 'label' => t('status.stageManagement')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'entities' => $entities,
            'maxBytes' => Importer::MAX_BYTES,
            'preview' => null,
            'content' => '',
            'selected' => '',
            'result' => null,
        ], $extra)));
    }

    public static function index(Request $request): Response
    {
        return self::render();
    }

    /** Le modèle : les en-têtes attendus, dans l'ordre, prêts à remplir au tableur. */
    public static function template(Request $request, array $params): Response
    {
        $entity = self::allowed((string) $params['key']);
        if ($entity === null) {
            return self::error('Type de données inconnu.', 404);
        }

        // La marque d'ordre des octets évite qu'Excel n'affiche les accents en charabia.
        return Response::text("\xEF\xBB\xBF" . Importer::template($entity) . "\n")->withHeaders([
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="modele-' . $entity['key'] . '.csv"',
        ]);
    }

    private static function contentOf(Request $request): string
    {
        $file = $request->file('fichier');
        return $file !== null ? $file['bytes'] : $request->input('content');
    }

    /** Aperçu : tout est contrôlé, rien n'est écrit. */
    public static function preview(Request $request): Response
    {
        $entity = self::allowed(trim($request->input('entity')));
        if ($entity === null) {
            Flash::set('error', 'Type de données inconnu.');
            return Response::redirect('/import');
        }

        $content = self::contentOf($request);
        if (trim($content) === '') {
            Flash::set('error', 'Déposez un fichier ou collez son contenu.');
            return Response::redirect('/import');
        }
        if (strlen($content) > Importer::MAX_BYTES) {
            Flash::set('error', 'Contenu trop volumineux : ' . (int) round(Importer::MAX_BYTES / 1048576) . ' Mo maximum.');
            return Response::redirect('/import');
        }

        // Le message est rendu avec la page, pas déposé en session : la page
        // suivante n'a pas à le répéter.
        $verdict = Importer::preview($entity['key'], $content);
        return self::render([
            'preview' => $verdict['ok'] ? $verdict : null,
            'content' => $content,
            'selected' => $entity['key'],
            'flash' => $verdict['ok'] ? null : ['type' => 'error', 'message' => $verdict['message']],
        ]);
    }

    /** Écriture : tout ou rien, dans une seule transaction. */
    public static function commit(Request $request): Response
    {
        $entity = self::allowed(trim($request->input('entity')));
        if ($entity === null) {
            Flash::set('error', 'Type de données inconnu.');
            return Response::redirect('/import');
        }

        $content = $request->input('content');
        $verdict = Importer::commit($entity['key'], $content);
        if (!$verdict['ok']) {
            Audit::log('import.refuse', $entity['key'], null, ['motif' => $verdict['message']]);
            return self::render([
                'preview' => $verdict['preview'] ?? null,
                'content' => $content,
                'selected' => $entity['key'],
                'flash' => ['type' => 'error', 'message' => $verdict['message']],
            ]);
        }

        Audit::log('import.realise', $entity['key'], null, ['lignes' => $verdict['imported']]);
        return self::render([
            'selected' => $entity['key'],
            'result' => $verdict,
            'flash' => ['type' => 'success',
                        'message' => $verdict['imported'] . ' ligne(s) importée(s) dans « ' . $entity['label'] . ' ».'],
        ]);
    }
}
