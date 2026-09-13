<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Flash;
use App\Core\I18n;
use App\Core\QrCode;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;
use App\Modules\Avatars;
use App\Modules\TwoFactor;
use App\Modules\Users;

/**
 * Mon profil.
 *
 * Ce qu'une personne modifie elle-même : son nom d'usage, son téléphone, sa
 * présentation, sa langue, son mot de passe. **Pas** son adresse de courrier
 * interne ni les réglages de serveur de messagerie — ceux-là appartiennent à
 * l'administration, et le formulaire ne les propose donc pas du tout, plutôt
 * que de les proposer et les refuser ensuite.
 */
final class ProfileController
{
    public static function show(Request $request): Response
    {
        $session = Session::get('user');
        $user = Users::byId((int) $session['id']);
        if ($user === null) {
            return Response::redirect('/connexion');
        }
        $state = TwoFactor::stateOf($user);
        // Les codes de secours ne s'affichent qu'une fois, juste après leur
        // création : ils sont retirés de la session avant même le rendu.
        $codes = Session::get('recovery_codes');
        Session::forget('recovery_codes');

        return Response::html(View::page('profile/index', [
            'title' => t('profile.title') . ' — ' . t('app.name'),
            'panelLabel' => t('app.portal'),
            'headerTitle' => t('profile.title'),
            'headerSubtitle' => t('profile.subtitle'),
            'member' => $user,
            'twoFactorState' => $state,
            'twoFactorRequired' => TwoFactor::requiredFor($user),
            'twoFactorSecret' => $state['pending'] ? (string) $user['totp_secret'] : null,
            // Le code QR porte le secret : il est dessiné dans la page, jamais
            // écrit dans un fichier ni demandé à un service distant.
            'twoFactorQr' => $state['pending']
                ? QrCode::svg(TwoFactor::uri($user, Settings::get('company_name')), 220, t('prf.qrAlt'))
                : null,
            'recoveryCodes' => is_array($codes) ? $codes : null,
        ]));
    }

    public static function update(Request $request): Response
    {
        $session = Session::get('user');
        $user = Users::byId((int) $session['id']);
        if ($user === null) {
            return Response::redirect('/connexion');
        }

        $locale = $request->input('locale', (string) $user['locale']);
        Users::updateSelf((int) $user['id'], [
            'first_name' => mb_substr($request->input('first_name'), 0, 80),
            'last_name' => mb_substr($request->input('last_name'), 0, 80),
            'phone' => mb_substr($request->input('phone'), 0, 40),
            'bio' => mb_substr($request->input('bio'), 0, 2000),
            'locale' => I18n::isSupported($locale) ? $locale : $user['locale'],
        ]);

        Session::set('locale', I18n::isSupported($locale) ? $locale : $user['locale']);
        $fresh = Users::byId((int) $user['id']);
        if ($fresh !== null) {
            Session::set('user', Users::forSession($fresh));
        }
        Flash::set('success', t('common.save') . ' ✓');
        return Response::redirect('/mon-profil');
    }

    /**
     * Photo de profil. Le fichier est reçu en mémoire puis contrôlé : le type
     * annoncé par le navigateur ne vaut rien tant que le contenu ne le confirme
     * pas, et rien n'est écrit avant cette vérification.
     */
    public static function uploadPhoto(Request $request): Response
    {
        $user = Users::byId((int) Session::get('user')['id']);
        if ($user === null) {
            return Response::redirect('/connexion');
        }

        $file = $request->file('avatar');
        if ($file === null) {
            Flash::set('error', t('profile.photoHelp'));
            return Response::redirect('/mon-profil');
        }

        $fileName = Avatars::save($file);
        if ($fileName === null) {
            Flash::set('error', "Ce fichier n'est pas une image : son contenu ne correspond pas au format annoncé.");
            return Response::redirect('/mon-profil');
        }

        // La photo précédente est remplacée, pas accumulée.
        Avatars::remove($user['avatar_file']);
        Users::updateSelf((int) $user['id'], ['avatar_file' => $fileName]);

        $fresh = Users::byId((int) $user['id']);
        if ($fresh !== null) {
            Session::set('user', Users::forSession($fresh));
        }
        Flash::set('success', t('profile.photo') . ' ✓');
        return Response::redirect('/mon-profil');
    }

    public static function deletePhoto(Request $request): Response
    {
        $user = Users::byId((int) Session::get('user')['id']);
        if ($user === null) {
            return Response::redirect('/connexion');
        }

        Avatars::remove($user['avatar_file']);
        \App\Core\Db::run('UPDATE users SET avatar_file = NULL WHERE id = ?', [(int) $user['id']]);

        $fresh = Users::byId((int) $user['id']);
        if ($fresh !== null) {
            Session::set('user', Users::forSession($fresh));
        }
        Flash::set('success', t('profile.removePhoto') . ' ✓');
        return Response::redirect('/mon-profil');
    }

    /**
     * Le fichier d'une photo. Il ne sort pas du dossier public : cette route
     * demande une session, et ne sert que des noms que nous avons écrits.
     */
    public static function photo(Request $request, array $params): Response
    {
        $name = (string) $params['name'];
        $path = Avatars::pathOf($name);
        if ($path === null || !is_file($path)) {
            return Response::text('', 404);
        }

        return Response::text((string) file_get_contents($path))->withHeaders([
            'Content-Type' => Avatars::mimeOf($name),
            'Cache-Control' => 'private, max-age=604800',
            'Content-Disposition' => 'inline',
        ]);
    }

    // ---------- Double authentification ----------

    private static function current(): ?array
    {
        $session = Session::get('user');
        return is_array($session) ? Users::byId((int) $session['id']) : null;
    }

    public static function prepareTwoFactor(Request $request): Response
    {
        $user = self::current();
        if ($user === null) {
            return Response::redirect('/connexion');
        }
        if ((int) $user['totp_enabled'] === 1) {
            Flash::set('error', 'La double authentification est déjà active.');
            return Response::redirect('/mon-profil');
        }

        TwoFactor::beginEnrolment((int) $user['id']);
        Flash::set('success', 'Scannez le QR code, puis saisissez le code affiché pour confirmer.');
        return Response::redirect('/mon-profil#securite');
    }

    public static function enableTwoFactor(Request $request): Response
    {
        $user = self::current();
        if ($user === null) {
            return Response::redirect('/connexion');
        }

        $verdict = TwoFactor::confirmEnrolment((int) $user['id'], $request->input('code'));
        if (!$verdict['ok']) {
            Flash::set('error', $verdict['message']);
            return Response::redirect('/mon-profil#securite');
        }

        Session::set('recovery_codes', $verdict['recoveryCodes']);
        Audit::log('2fa.activee', 'users', (int) $user['id']);
        Flash::set('success', 'Double authentification activée. Conservez les codes de secours ci-dessous.');
        return Response::redirect('/mon-profil#securite');
    }

    public static function disableTwoFactor(Request $request): Response
    {
        $user = self::current();
        if ($user === null) {
            return Response::redirect('/connexion');
        }

        // Le mot de passe est redemandé : retirer le second facteur depuis une
        // session déjà ouverte serait sinon gratuit pour qui passe derrière un
        // écran resté déverrouillé.
        if (!Security::verifyPassword((string) ($request->body['current_password'] ?? ''), (string) $user['password_hash'])) {
            Flash::set('error', 'Mot de passe incorrect.');
            return Response::redirect('/mon-profil#securite');
        }
        if (TwoFactor::requiredFor($user)) {
            Flash::set('error', "La double authentification est exigée par l'entreprise pour votre rôle.");
            return Response::redirect('/mon-profil#securite');
        }

        TwoFactor::disable((int) $user['id']);
        Audit::log('2fa.desactivee', 'users', (int) $user['id']);
        Flash::set('success', 'Double authentification désactivée.');
        return Response::redirect('/mon-profil#securite');
    }

    public static function newRecoveryCodes(Request $request): Response
    {
        $user = self::current();
        if ($user === null) {
            return Response::redirect('/connexion');
        }
        if ((int) $user['totp_enabled'] !== 1) {
            return Response::redirect('/mon-profil#securite');
        }

        Session::set('recovery_codes', TwoFactor::regenerateRecoveryCodes((int) $user['id']));
        Audit::log('2fa.codes_regeneres', 'users', (int) $user['id']);
        Flash::set('success', 'Nouveaux codes de secours. Les précédents ne valent plus.');
        return Response::redirect('/mon-profil#securite');
    }
}
