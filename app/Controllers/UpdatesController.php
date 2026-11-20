<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;
use App\Modules\Updates;

/**
 * Mises à jour de l'instance.
 *
 * L'écran dit trois choses : en quelle version on tourne, ce que l'éditeur
 * propose, et ce qui s'est passé les fois précédentes. Le reste — l'adresse, la
 * clé, le canal, le mode — tient en un formulaire, parce qu'on ne le remplit
 * qu'une fois.
 */
final class UpdatesController
{
    /** Le libellé d'un refus. La clé s'écrit d'un bloc : le contrôle des
     *  traductions lit le code source, et une concaténation lui échapperait. */
    private static function reason(string $reason): string
    {
        return t("updates.error.$reason");
    }

    /**
     * Répond à la page, ou à la fenêtre.
     *
     * Les gestes sont les mêmes des deux côtés, et le code qui les exécute
     * aussi : seule la façon de rendre le résultat change. Deux routes
     * parallèles auraient fini par appliquer deux règles.
     *
     * Sans JavaScript, les formulaires continuent de fonctionner comme avant :
     * la fenêtre est un confort, pas une condition.
     */
    private static function enFenetre(Request $request): bool
    {
        return $request->header('x-fenetre') === '1';
    }

    private static function json(array $payload, int $statut = 200): Response
    {
        return Response::text((string) json_encode($payload, JSON_UNESCAPED_UNICODE), $statut)
            ->withHeaders(['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    private static function back(string $anchor, string $type, string $message): Response
    {
        Flash::set($type, $message);
        return Response::redirect('/mises-a-jour' . ($anchor === '' ? '' : '#' . $anchor));
    }

    /** Le même verdict, pour la page ou pour la fenêtre. */
    private static function verdict(
        Request $request,
        string $anchor,
        bool $ok,
        string $message,
        string $detail = '',
        array $extra = []
    ): Response {
        if (self::enFenetre($request)) {
            return self::json(array_merge(
                ['ok' => $ok, 'message' => $message, 'detail' => $detail],
                $extra
            ), $ok ? 200 : 409);
        }
        return self::back($anchor, $ok ? 'success' : 'error', $message . ($detail !== '' ? " — $detail" : ''));
    }

    /**
     * Où en est la pose, pour la fenêtre qui attend.
     *
     * Elle interroge pendant que l'autre requête travaille. L'étape vient de la
     * base, pas d'une variable : rafraîchir la page ne perd pas le fil.
     */
    public static function progress(Request $request): Response
    {
        return self::json([
            'etapes' => Updates::ETAPES,
            'enCours' => Updates::enCours(),
        ]);
    }

    public static function index(Request $request): Response
    {
        $config = Updates::config();

        return Response::html(View::page('updates/index', [
            'title' => t('updates.title') . ' — ' . t('app.name'),
            'panelLabel' => t('updates.title'),
            'headerTitle' => t('updates.title'),
            'headerSubtitle' => t('updates.headerSub'),
            'navItems' => [
                ['tab' => 'etat', 'label' => t('updates.tabState')],
                ['tab' => 'reglages', 'label' => t('updates.tabSettings')],
                ['tab' => 'historique', 'label' => t('updates.tabHistory')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js', '/js/fenetre.js', '/js/mises-a-jour.js'],
            // Les boutons de cet écran ouvrent une fenêtre plutôt que de faire
            // partir la page : poser une version prend du temps, et un onglet
            // blanc pendant ce temps-là ne dit rien de ce que l'instance fait.
            'mainId' => 'mises-a-jour',
            'mainData' => [
                'csrf' => \App\Core\Csrf::token(),
                'immuable' => Updates::codeImmuable() ? '1' : '0',
                'etapes' => (string) json_encode(Updates::ETAPES),
            ],
            'config' => $config,
            // La clé d'instance n'est jamais réaffichée : elle se remplace, elle
            // ne se relit pas. Le formulaire dit seulement si elle est posée.
            'keySet' => $config['key'] !== '',
            /*
             * Le code de cette instance s'écrit-il ? En image de conteneur,
             * non : la version se change en changeant d'image. L'écran garde
             * la vérification et remplace les boutons par le geste qui
             * convient.
             */
            'immuable' => Updates::codeImmuable(),
            'available' => Updates::available(),
            'lastCheck' => Settings::get('update_last_check'),
            'lastError' => Settings::get('update_last_error'),
            'history' => Updates::history(),
            // La fenêtre affiche ces étapes-là : celles que la pose traverse
            // vraiment.
            'etapes' => Updates::ETAPES,
        ]));
    }

    public static function save(Request $request): Response
    {
        Updates::setConfig([
            'url' => $request->input('url'),
            'key' => $request->input('cle'),
            'public_key' => $request->input('clePublique'),
            'channel' => $request->input('canal'),
            'mode' => $request->input('mode'),
            'name' => $request->input('nom'),
        ]);
        return self::back('reglages', 'success', t('updates.saved'));
    }

    public static function check(Request $request): Response
    {
        $result = Updates::check();
        if (!$result['ok']) {
            return self::verdict($request, '', false, self::reason($result['reason']));
        }
        if (!empty($result['available'])) {
            return self::verdict($request, '', true, t('updates.found', ['version' => $result['version']['numero']]), '', [
                'disponible' => [
                    'version' => $result['version']['numero'],
                    'notes' => $result['version']['notes'] ?? '',
                ],
            ]);
        }
        return self::verdict($request, '', true, t('updates.upToDate'), '', ['disponible' => null]);
    }

    public static function apply(Request $request): Response
    {
        $result = Updates::apply(['by' => Session::get('user')['id'] ?? null, 'mode' => 'manual']);
        if (!$result['ok']) {
            // Le détail est affiché tel quel à côté du motif traduit : il dit
            // souvent tout, et le traduire reviendrait à le perdre.
            return self::verdict($request, '', false, self::reason($result['reason']),
                (string) ($result['detail'] ?? ''), ['motif' => $result['reason']]);
        }
        return self::verdict($request, '', true, t('updates.applied', ['version' => $result['version']]), '', [
            'version' => $result['version'],
            // Ici, rien ne redémarre : le serveur relit les fichiers à la
            // requête suivante.
            'redemarrage' => false,
        ]);
    }

    public static function rollback(Request $request): Response
    {
        $result = Updates::rollback(['by' => Session::get('user')['id'] ?? null]);
        if (!$result['ok']) {
            return self::verdict($request, 'historique', false, self::reason($result['reason']), '', ['motif' => $result['reason']]);
        }
        return self::verdict($request, 'historique', true,
            t('updates.rolledBack', ['version' => $result['version']]), '', ['version' => $result['version']]);
    }
}
