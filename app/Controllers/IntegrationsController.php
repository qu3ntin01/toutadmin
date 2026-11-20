<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Ai;
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
                ['tab' => 'paiement', 'label' => t('strp.tab')],
                ['tab' => 'assistance', 'label' => t('ia.set.tab')],
            ],
            'footLinks' => [
                ['href' => '/admin', 'label' => t('nav.adminConsole')],
                ['href' => '/securite', 'label' => t('nav.security')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'tokenList' => $tokens,
            'scopes' => ApiTokens::SCOPES,
            'writeScopes' => ApiTokens::WRITE_SCOPES,
            'writeSuffix' => ApiTokens::WRITE_SUFFIX,
            'defaultDays' => ApiTokens::DEFAULT_DAYS,
            'webhookList' => Webhooks::all(),
            'events' => Webhooks::EVENTS,
            'deliveries' => Webhooks::deliveries(null, 40),
            'stats' => $stats,
            'maxAttempts' => Webhooks::MAX_ATTEMPTS,
            'failureLimit' => Webhooks::FAILURE_LIMIT,
            'created' => null,
            'stripeConfig' => array_diff_key(\App\Modules\Stripe::config(), ['secretKey' => true, 'webhookSecret' => true]),
            'stripeWebhook' => \App\Modules\Webauthn::site(new Request('GET', '/'))['origine'] . '/stripe/webhook',
            'aiConfig' => Ai::displayConfig(),
            'aiFonctions' => Ai::fonctionsAffichees(),
            'aiProviders' => Ai::PROVIDERS,
            'aiEfforts' => Ai::EFFORTS,
            'aiStatus' => Ai::status(),
            'aiReady' => Ai::isReady(),
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

        $ecrit = (bool) array_filter(
            $verdict['scopes'],
            static fn (string $portee): bool => str_ends_with($portee, ApiTokens::WRITE_SUFFIX)
        );
        // Un jeton qui écrit n'est pas un jeton comme un autre : le journal le
        // dit, pour qu'une relecture ne les confonde pas.
        Audit::log($ecrit ? 'api.jeton_ecriture_cree' : 'api.jeton_cree', 'api_tokens', $verdict['id'], [
            'intitule' => $request->input('label'),
            'portees' => implode(',', $verdict['scopes']),
            'ecrit' => $ecrit,
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
        if (!ApiTokens::remove((int) $params['id'])) {
            return self::fail('jetons', 'Jeton introuvable.');
        }

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
        if (!Webhooks::remove((int) $params['id'])) {
            return self::fail('webhooks', 'Webhook introuvable.');
        }

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
    // ---------- Assistance par modèle ----------

    /*
     * Le réglage a sa place ici, et non dans un onglet comptable.
     *
     * Il a commencé sa vie dans « Pièces reçues », du temps où lire une facture
     * était la seule chose qu'un modèle faisait ici. Il en sert quatre — la
     * comptabilité, le support, la direction, la rédaction — et un réglage qui
     * décide de ce qui sort de l'entreprise appartient au même écran que les
     * jetons d'API et les webhooks : celui des portes vers le dehors.
     */
    /** Le paiement en ligne : deux clés, chiffrées en base, jamais réaffichées. */
    public static function savePaiement(Request $request): Response
    {
        $result = \App\Modules\Stripe::enregistrer(
            $request->input('enabled') !== '',
            (string) ($request->body['secret_key'] ?? ''),
            (string) ($request->body['webhook_secret'] ?? '')
        );
        if (!$result['ok']) {
            return self::fail('paiement', $result['reason'] === 'secret-key'
                ? 'La clé secrète de Stripe est attendue (sk_live_…, sk_test_… ou une clé restreinte rk_…).'
                : 'Le secret de signature du webhook est attendu (whsec_…) : Stripe le donne quand on déclare le point d’arrivée.');
        }
        // Le journal dit qui a changé quoi, jamais les clés.
        Audit::log('stripe.modifie', 'settings', null, ['active' => $request->input('enabled') !== '']);
        Flash::set('success', 'Paiement en ligne enregistré.');
        return self::back('paiement');
    }

    public static function saveAssistance(Request $request): Response
    {
        $verdict = Ai::setConfig([
            'provider' => $request->input('provider'),
            'model' => $request->input('model'),
            'baseUrl' => $request->input('base_url'),
            'effort' => $request->input('effort'),
            'key' => $request->input('key'),
            'enabled' => $request->input('enabled') === '1',
        ]);
        if (empty($verdict['ok'])) {
            return self::fail('assistance', $verdict['message']);
        }

        /*
         * Les interrupteurs, un par fonction.
         *
         * Une case décochée éteint : c'est un formulaire HTML, une case non cochée
         * n'est pas envoyée, et l'absence doit donc valoir « non ». Le contraire
         * rendrait une fonction impossible à éteindre depuis l'écran.
         */
        $demandees = array_map('strval', $request->inputs('fonctions'));
        $allumees = [];
        foreach (Ai::FONCTIONS as $fonction) {
            $active = in_array($fonction['cle'], $demandees, true);
            Ai::activerFonction($fonction['cle'], $active);
            if ($active) {
                $allumees[] = $fonction['cle'];
            }
        }

        Audit::log('ia.service_configure', 'settings', null, [
            'service' => $request->input('provider'), 'modele' => $request->input('model'),
            'actif' => $request->input('enabled') === '1', 'fonctions' => $allumees,
        ]);
        Flash::set('success', $request->input('enabled') === '1' ? t('ia.set.savedOn') : t('ia.set.savedOff'));
        return self::back('assistance');
    }

    public static function testAssistance(Request $request): Response
    {
        $verdict = Ai::test();
        Audit::log('ia.service_teste', 'settings', null, ['ok' => !empty($verdict['ok'])]);
        Flash::set(!empty($verdict['ok']) ? 'success' : 'error', $verdict['message']);
        return self::back('assistance');
    }
}
