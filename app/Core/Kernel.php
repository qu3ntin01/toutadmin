<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\AuthController;
use App\Controllers\DirectoryController;
use App\Controllers\InstallController;
use App\Controllers\MemberController;
use App\Controllers\ProfileController;
use App\Modules\Users;

/**
 * Le passage obligé de chaque requête.
 *
 * Session, langue, jeton anti-falsification, en-têtes de sécurité, plafond de
 * requêtes, expiration : tout est fait ici, avant la route. Une route ne peut
 * donc pas oublier un contrôle — elle n'a pas le moyen de le sauter.
 */
final class Kernel
{
    private Router $router;

    public function __construct()
    {
        $this->router = new Router();
        $this->routes();
    }

    public function router(): Router
    {
        return $this->router;
    }

    private function routes(): void
    {
        $router = $this->router;

        // Installation : ouverte tant qu'aucun compte n'existe, fermée après.
        $router->get('/installation', InstallController::form(...));
        $router->post('/installation', InstallController::submit(...));

        $router->get('/', AuthController::home(...));
        $router->get('/connexion', AuthController::loginForm(...));
        $router->post('/connexion', AuthController::login(...));
        $router->get('/connexion/code', AuthController::totpForm(...));
        $router->post('/connexion/code', AuthController::totpSubmit(...));
        $router->post('/deconnexion', AuthController::logout(...));
        $router->get('/mot-de-passe', AuthController::passwordForm(...));
        $router->post('/mot-de-passe', AuthController::passwordSubmit(...));
        $router->post('/langue', AuthController::switchLocale(...));

        $router->get('/mon-espace', MemberController::home(...));
        $router->get('/annuaire', DirectoryController::index(...));
        $router->get('/mon-profil', ProfileController::show(...));
        $router->post('/mon-profil', ProfileController::update(...));
    }

    public function handle(Request $request): Response
    {
        Session::start();

        // Plafond global : une adresse qui martèle le site est ralentie avant
        // même qu'on regarde ce qu'elle demande.
        if (Security::tooManyAttempts('global', (int) Config::get('global_rate_limit', 300), 60)) {
            return Response::text("Trop de requêtes. Merci de réessayer dans une minute.", 429);
        }

        $installed = Users::count() > 0;
        if (!$installed && !str_starts_with($request->path, '/installation')) {
            return Response::redirect('/installation');
        }
        if ($installed && str_starts_with($request->path, '/installation')) {
            return Response::redirect('/connexion');
        }

        $user = $this->currentUser();
        $this->prepareLocale($request, $user);

        if ($request->isPost() && !Csrf::matches($request->input('_csrf'))) {
            return $this->refuse();
        }

        $guard = $this->guard($request, $user);
        if ($guard !== null) {
            return $guard;
        }

        $nonce = base64_encode(random_bytes(16));
        $this->share($request, $user, $nonce);

        $match = $this->router->match($request);
        if ($match === null) {
            return $this->error(t('err.notAccessible'), 404, $nonce);
        }
        if ($match['handler'] === null) {
            return $this->error(t('err.notAccessible'), 405, $nonce);
        }

        $response = ($match['handler'])($request, $match['params']);
        if (!$response instanceof Response) {
            throw new \RuntimeException('Une route doit renvoyer une réponse.');
        }
        return $response->withHeaders(Security::headers($nonce));
    }

    /**
     * La session est revalidée à chaque requête : un compte fermé, un mot de
     * passe changé ailleurs ou une session trop vieille ne doivent pas survivre
     * parce que le cookie, lui, est encore là.
     */
    private function currentUser(): ?array
    {
        $session = Session::get('user');
        if (!is_array($session)) {
            return null;
        }
        $user = Users::byId((int) $session['id']);
        if ($user === null || (int) $user['active'] !== 1) {
            Session::destroy();
            return null;
        }
        $openedAt = (int) Session::get('opened_at', 0);
        $maxHours = (int) Config::get('session_max_hours', 12);
        if ($openedAt > 0 && time() - $openedAt > $maxHours * 3600) {
            Session::destroy();
            return null;
        }
        // Une session ouverte avant le dernier changement de mot de passe n'a
        // plus lieu d'être : c'est ce qui rend le changement utile en cas de vol.
        $changedAt = $user['password_changed_at'] ?? null;
        if ($changedAt && strtotime((string) $changedAt) > (int) Session::get('opened_at', 0)) {
            Session::destroy();
            return null;
        }
        Session::save();
        return $user;
    }

    private function prepareLocale(Request $request, ?array $user): void
    {
        $chosen = Session::get('locale');
        I18n::use(I18n::negotiate(
            is_string($chosen) ? $chosen : ($user['locale'] ?? null),
            Settings::get('default_locale'),
            $request->header('accept-language')
        ));
    }

    /** Les portes : qui peut atteindre quoi. */
    private function guard(Request $request, ?array $user): ?Response
    {
        $public = ['/connexion', '/connexion/code', '/installation', '/langue'];
        if (in_array($request->path, $public, true)) {
            return null;
        }
        if ($user === null) {
            return Response::redirect('/connexion');
        }
        // Mot de passe à changer : aucune autre page tant que ce n'est pas fait.
        if ((int) $user['must_change_password'] === 1 && $request->path !== '/mot-de-passe' && $request->path !== '/deconnexion') {
            return Response::redirect('/mot-de-passe');
        }
        return null;
    }

    private function share(Request $request, ?array $user, string $nonce): void
    {
        $locale = I18n::info();
        View::share([
            'nonce' => $nonce,
            'locale' => $locale['code'],
            'localeDir' => $locale['dir'],
            'locales' => I18n::LOCALES,
            'csrfToken' => Csrf::token(),
            'user' => $user,
            'sessionUser' => $user === null ? null : Users::forSession($user),
            'companyName' => Settings::get('company_name'),
            'brandInitials' => mb_strtoupper(mb_substr(Settings::get('company_name'), 0, 2)),
            'palette' => Settings::get('theme_palette'),
            'flash' => Flash::take(),
            'path' => $request->path,
        ]);
    }

    private function refuse(): Response
    {
        return $this->error('Session expirée ou requête invalide. Merci de recharger la page et de réessayer.', 403, base64_encode(random_bytes(16)));
    }

    public function error(string $message, int $status, string $nonce): Response
    {
        View::share(['nonce' => $nonce, 'csrfToken' => Csrf::token()]);
        $html = View::page('error', ['message' => $message, 'title' => $message]);
        return Response::html($html, $status)->withHeaders(Security::headers($nonce));
    }
}
