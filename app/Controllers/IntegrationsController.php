<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\ApiTokens;
use App\Modules\Webhooks;

/**
 * Intégrations : jetons d'API et webhooks sortants.
 *
 * Ouvrir une porte d'entrée sur les données de l'entreprise relève de
 * l'administration seule, jamais d'un droit délégué.
 */
final class IntegrationsController
{
    private static function back(string $anchor): Response
    {
        return Response::redirect('/integrations#' . $anchor);
    }

    private static function fail(string $anchor, string $message): Response
    {
        Flash::set('error', $message);
        return self::back($anchor);
    }

    private static function render(array $extra = []): Response
    {
        $tokens = ApiTokens::all();
        $stats = Webhooks::summary();
        $usable = count(array_filter($tokens, static fn (array $row): bool => !$row['revoked'] && !$row['expired']));

        return Response::html(View::page('integrations/index', array_merge([
            'title' => t('nav.integrations') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.integrations'),
            'headerTitle' => t('nav.integrations'),
            'headerSubtitle' => t('api.headerSub'),
            'navItems' => [
                ['tab' => 'jetons', 'label' => t('api.tabTokens'), 'badge' => $usable ?: null],
                ['tab' => 'webhooks', 'label' => t('api.tabWebhooks'), 'badge' => $stats['active'] ?: null],
                ['tab' => 'livraisons', 'label' => t('api.tabDeliveries'), 'badge' => $stats['waiting'] ?: null],
                ['tab' => 'documentation', 'label' => t('api.tabDocs')],
            ],
            'footLinks' => [
                ['href' => '/admin', 'label' => t('nav.adminConsole')],
                ['href' => '/securite', 'label' => t('nav.security')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'tokenList' => $tokens,
            'scopes' => ApiTokens::SCOPES,
            'defaultDays' => ApiTokens::DEFAULT_DAYS,
            'webhookList' => Webhooks::all(),
            'events' => Webhooks::EVENTS,
            'deliveries' => Webhooks::deliveries(null, 40),
            'stats' => $stats,
            'maxAttempts' => Webhooks::MAX_ATTEMPTS,
            'failureLimit' => Webhooks::FAILURE_LIMIT,
            'created' => null,
        ], $extra)));
    }

    public static function index(Request $request): Response
    {
        return self::render();
    }

    // ---------- Jetons d'API ----------

    public static function createToken(Request $request): Response
    {
        $days = trim($request->input('days'));
        $verdict = ApiTokens::create([
            'label' => $request->input('label'),
            'scopes' => $request->inputs('scopes'),
            'days' => (string) (int) $days === $days ? (int) $days : null,
            'createdBy' => (int) Session::get('user')['id'],
        ]);
        if (!$verdict['ok']) {
            return self::fail('jetons', $verdict['message']);
        }

        Audit::log('api.jeton_cree', 'api_tokens', $verdict['id'], [
            'intitule' => $request->input('label'), 'portees' => implode(',', $verdict['scopes']),
        ]);

        // La valeur en clair n'existe qu'ici : elle est rendue avec la page, jamais
        // déposée en session ni écrite au journal.
        return self::render([
            'created' => [
                'kind' => 'token', 'label' => $request->input('label'),
                'value' => $verdict['token'], 'expiresAt' => $verdict['expiresAt'],
            ],
            'flash' => ['type' => 'success', 'message' => 'Jeton créé. Copiez-le maintenant : il ne sera plus affiché.'],
        ]);
    }

    public static function revokeToken(Request $request, array $params): Response
    {
        if (!ApiTokens::revoke((int) $params['id'])) {
            return self::fail('jetons', 'Jeton déjà révoqué ou introuvable.');
        }
        Audit::log('api.jeton_revoque', 'api_tokens', (int) $params['id'], []);
        Flash::set('success', 'Jeton révoqué : il ne répond plus, immédiatement.');
        return self::back('jetons');
    }

    public static function deleteToken(Request $request, array $params): Response
    {
        ApiTokens::remove((int) $params['id']);
        Audit::log('api.jeton_supprime', 'api_tokens', (int) $params['id'], []);
        Flash::set('success', 'Jeton supprimé.');
        return self::back('jetons');
    }

    // ---------- Webhooks ----------

    public static function createWebhook(Request $request): Response
    {
        $verdict = Webhooks::create([
            'label' => $request->input('label'),
            'url' => $request->input('url'),
            'events' => $request->inputs('events'),
            'allowPrivate' => $request->input('allow_private') === '1',
            'createdBy' => (int) Session::get('user')['id'],
        ]);
        if (!$verdict['ok']) {
            return self::fail('webhooks', $verdict['message']);
        }

        Audit::log('webhook.cree', 'webhooks', $verdict['id'], [
            'intitule' => $request->input('label'), 'url' => $request->input('url'),
        ]);

        return self::render([
            'created' => ['kind' => 'secret', 'label' => $request->input('label'), 'value' => $verdict['secret']],
            'flash' => ['type' => 'success',
                        'message' => 'Webhook enregistré. Copiez le secret de signature : il ne sera plus affiché.'],
        ]);
    }

    public static function setWebhookState(Request $request, array $params): Response
    {
        $active = $request->input('active') === '1';
        if (!Webhooks::setActive((int) $params['id'], $active)) {
            return self::fail('webhooks', 'Webhook introuvable.');
        }

        Audit::log('webhook.etat', 'webhooks', (int) $params['id'], ['actif' => $active]);
        Flash::set('success', $active
            ? "Webhook réactivé, compteur d'échecs remis à zéro."
            : 'Webhook suspendu.');
        return self::back('webhooks');
    }

    public static function deleteWebhook(Request $request, array $params): Response
    {
        Webhooks::remove((int) $params['id']);
        Audit::log('webhook.supprime', 'webhooks', (int) $params['id'], []);
        Flash::set('success', 'Webhook supprimé, avec son journal de livraisons.');
        return self::back('webhooks');
    }

    /** Envoi d'essai : c'est la seule façon de savoir que le tuyau est branché. */
    public static function testWebhook(Request $request, array $params): Response
    {
        $hook = Webhooks::byId((int) $params['id']);
        if ($hook === null) {
            return self::fail('webhooks', 'Webhook introuvable.');
        }

        Webhooks::emit($hook['eventList'][0], ['essai' => true, 'envoye_par' => (int) Session::get('user')['id']]);
        $result = Webhooks::flush(10);

        Audit::log('webhook.teste', 'webhooks', (int) $hook['id'], [
            'livres' => $result['delivered'], 'echecs' => $result['failed'],
        ]);
        Flash::set($result['failed'] > 0 ? 'error' : 'success', $result['failed'] > 0
            ? 'Essai en échec : ' . (Webhooks::byId((int) $hook['id'])['last_status'] ?? '')
            : 'Essai livré.');
        return self::back('webhooks');
    }
}
