<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Store;

/**
 * La boutique d'applications.
 *
 * Réservée à l'administration, parce qu'on y allume des modules. La regarder
 * sans pouvoir rien allumer n'aurait pas grand sens : le rail ne la montre
 * qu'aux administrateurs, et le garde du noyau dit la même chose au serveur.
 */
final class StoreController
{
    /**
     * Répond à la page, ou à la fenêtre.
     *
     * Le code qui allume est le même des deux côtés : seule la façon de rendre
     * le résultat change. Sans JavaScript, les formulaires partent comme avant.
     */
    private static function enFenetre(Request $request): bool
    {
        return $request->header('x-fenetre') === '1';
    }

    private static function verdict(
        Request $request,
        bool $ok,
        string $message,
        string $vers = '/applications',
        array $extra = []
    ): Response {
        if (self::enFenetre($request)) {
            return Response::text(
                (string) json_encode(array_merge(['ok' => $ok, 'message' => $message, 'vers' => $vers], $extra), JSON_UNESCAPED_UNICODE),
                $ok ? 200 : 409
            )->withHeaders(['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']);
        }
        Flash::set($ok ? 'success' : 'error', $message);
        return Response::redirect($vers);
    }

    public static function index(Request $request): Response
    {
        $vue = Store::ecran($request->input('actualiser') === '1');

        return Response::html(View::page('store/index', [
            'title' => t('store.title') . ' — ' . t('app.name'),
            'scripts' => ['/js/admin.js', '/js/confirm.js', '/js/fenetre.js', '/js/boutique.js'],
            // Allumer une application ouvre une fenêtre : elle remontre la
            // réserve de la fiche juste avant, parce que c'est la partie qu'on
            // est tenté de ne pas lire.
            'mainId' => 'applications',
            'mainData' => ['csrf' => \App\Core\Csrf::token()],
            'panelLabel' => t('store.title'),
            'headerTitle' => t('store.title'),
            'headerSubtitle' => t('store.headerSub'),
            // Vide quand l'éditeur n'a pas répondu : la vue n'affiche alors
            // aucun bouton d'achat, ce qui vaut mieux qu'un lien vers nulle part.
            'espace_client' => (string) ($vue['espace_client'] ?? ''),
            'navItems' => array_map(
                static fn (array $rayon): array => ['tab' => 'rayon-' . $rayon['cle'], 'label' => $rayon['titre']],
                $vue['rayons'],
            ),
        ] + $vue));
    }

    /**
     * Récupérer ses achats.
     *
     * Le client vient d'acheter dans son espace client. Sans ce bouton il
     * reviendrait ici, ne verrait rien de changé, et ouvrirait une demande
     * d'assistance : le catalogue a une minute de répit, et l'achat s'est fait
     * ailleurs.
     */
    public static function fetch(Request $request): Response
    {
        $issue = Store::recuperer();
        if (!$issue['ok']) {
            return self::verdict($request, false, self::reason($issue['reason']), '/applications', ['motif' => $issue['reason']]);
        }
        if ($issue['allumes'] === []) {
            return self::verdict($request, true, t('store.fetchedNothing'), '/applications', ['allumes' => []]);
        }
        return self::verdict($request, true, t('store.fetched', ['liste' => implode(', ', $issue['allumes'])]),
            '/applications', ['allumes' => $issue['allumes']]);
    }

    public static function enable(Request $request, array $params): Response
    {
        $issue = Store::allumer((string) $params['cle']);
        if (!$issue['ok']) {
            // La clé s'écrit d'un bloc : le contrôle des traductions lit le code
            // source, et une concaténation lui échapperait.
            return self::verdict($request, false, self::reason($issue['reason']), '/applications', ['motif' => $issue['reason']]);
        }

        // Droit à l'écran qu'on vient d'ouvrir : c'est ce qu'on voulait, pas la
        // boutique une seconde fois.
        return self::verdict($request, true, t('store.enabled'),
            $issue['href'] !== '' ? $issue['href'] : '/applications');
    }

    private static function reason(string $reason): string
    {
        return t("store.error.$reason");
    }
}
