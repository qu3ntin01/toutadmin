<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Org;
use App\Modules\Support;
use App\Modules\Users;

/**
 * Base de connaissances.
 *
 * Écrivent : l'administration, les RH et les managers. Tout le monde lit —
 * mais chacun dans sa portée : un article de service ne sort pas du service,
 * et « Administration » couvre les procédures internes qu'un salarié n'a pas
 * à voir.
 */
final class KnowledgeController
{
    public static function canWrite(?array $user): bool
    {
        return $user !== null && (
            $user['role'] === 'admin'
            || (int) ($user['is_hr'] ?? 0) === 1
            || Org::isManager((int) $user['id'])
        );
    }

    private static function user(): array
    {
        return (array) Users::byId((int) Session::get('user')['id']);
    }

    private static function fail(string $target, string $message): Response
    {
        Flash::set('error', $message);
        return Response::redirect($target);
    }

    private static function error(string $message, int $status): Response
    {
        return Response::html(View::page('error', ['title' => $message, 'message' => $message]), $status);
    }

    public static function index(Request $request): Response
    {
        $user = self::user();
        $write = self::canWrite($user);
        $query = trim($request->input('q'));
        $category = trim($request->input('categorie'));

        return Response::html(View::page('knowledge/index', [
            'title' => t('nav.knowledge') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.knowledge'),
            'headerTitle' => t('sup.tabKb'),
            'headerSubtitle' => t('kb.headerSub'),
            'navItems' => array_values(array_filter([
                ['tab' => 'articles', 'label' => t('stk.tabItems')],
                $write ? ['tab' => 'rediger', 'label' => t('kb.tabWrite')] : null,
            ])),
            'footLinks' => [['href' => '/support', 'label' => t('nav.support')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'articleList' => Support::articles($category, $query, $user),
            'categoryList' => Support::categories(),
            'query' => $query,
            'category' => $category,
            'canWrite' => $write,
            'visibilities' => Support::KB_VISIBILITIES,
            'departments' => Org::departments(),
            'teams' => Org::teams(),
        ]));
    }

    public static function show(Request $request, array $params): Response
    {
        $user = self::user();
        $write = self::canWrite($user);
        $article = Support::articleById((int) $params['id']);

        if ($article === null) {
            return self::error('Article introuvable.', 404);
        }
        if (!Support::canRead($article, $user)) {
            return self::error("Cet article n'est pas ouvert à votre périmètre.", 403);
        }
        // Un brouillon n'existe pas encore pour ses lecteurs.
        if ((int) $article['published'] === 0 && !$write) {
            return self::error('Article introuvable.', 404);
        }

        Support::noteRead((int) $article['id']);

        return Response::html(View::page('knowledge/show', [
            'title' => $article['title'] . ' — ' . t('app.name'),
            'panelLabel' => t('nav.knowledge'),
            'headerTitle' => $article['title'],
            'headerSubtitle' => $article['category'] . ' · ' . $article['visibility'],
            'navItems' => array_values(array_filter([
                ['tab' => 'lecture', 'label' => t('stk.item')],
                $write ? ['tab' => 'edition', 'label' => t('common.edit')] : null,
            ])),
            'footLinks' => [['href' => '/base-de-connaissances', 'label' => t('art.allArticles')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'article' => $article,
            'canWrite' => $write,
            'visibilities' => Support::KB_VISIBILITIES,
            'departments' => Org::departments(),
            'teams' => Org::teams(),
        ]));
    }

    public static function create(Request $request): Response
    {
        $back = '/base-de-connaissances#rediger';
        $title = trim($request->input('title'));
        if ($title === '' || mb_strlen($title) > 200) {
            return self::fail($back, 'Titre invalide.');
        }

        $visibility = $request->input('visibility');
        if (!in_array($visibility, Support::KB_VISIBILITIES, true)) {
            return self::fail($back, 'Portée invalide.');
        }

        $limited = in_array($visibility, ['Service', 'Équipe'], true);
        $scopeId = $limited ? ((int) $request->input('scope_id') ?: null) : null;
        if ($limited && $scopeId === null) {
            return self::fail($back, "Une portée de service ou d'équipe demande de choisir laquelle.");
        }

        $id = Support::createArticle([
            'title' => $title,
            'category' => mb_substr(trim($request->input('category')) ?: 'Général', 0, 60),
            'body' => mb_substr($request->input('body'), 0, 60000),
            'visibility' => $visibility,
            'scopeId' => $scopeId,
            'authorId' => (int) Session::get('user')['id'],
        ]);

        Audit::log('article.publie', 'kb_articles', $id, ['titre' => $title, 'portee' => $visibility]);
        Flash::set('success', 'Article publié.');
        return Response::redirect('/base-de-connaissances/' . $id);
    }

    public static function update(Request $request, array $params): Response
    {
        $article = Support::articleById((int) $params['id']);
        if ($article === null) {
            return self::fail('/base-de-connaissances#articles', 'Article introuvable.');
        }

        $target = '/base-de-connaissances/' . (int) $article['id'];
        $title = trim($request->input('title'));
        if ($title === '') {
            return self::fail($target, 'Titre invalide.');
        }
        $visibility = $request->input('visibility');
        if (!in_array($visibility, Support::KB_VISIBILITIES, true)) {
            return self::fail($target, 'Portée invalide.');
        }

        $limited = in_array($visibility, ['Service', 'Équipe'], true);
        Support::updateArticle((int) $article['id'], [
            'title' => mb_substr($title, 0, 200),
            'category' => mb_substr(trim($request->input('category')) ?: 'Général', 0, 60),
            'body' => mb_substr($request->input('body'), 0, 60000),
            'visibility' => $visibility,
            'scopeId' => $limited ? ((int) $request->input('scope_id') ?: null) : null,
            'published' => $request->input('published') === '1',
        ]);
        Flash::set('success', 'Article mis à jour.');
        return Response::redirect($target);
    }

    public static function delete(Request $request, array $params): Response
    {
        $article = Support::articleById((int) $params['id']);
        if ($article === null) {
            return self::fail('/base-de-connaissances#articles', 'Article introuvable.');
        }

        Support::deleteArticle((int) $article['id']);
        Audit::log('article.supprime', 'kb_articles', (int) $article['id'], ['titre' => $article['title']]);
        Flash::set('success', 'Article supprimé.');
        return Response::redirect('/base-de-connaissances#articles');
    }
}
