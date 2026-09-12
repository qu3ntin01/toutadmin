<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Avatars;
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
        return Response::html(View::page('profile/index', [
            'title' => t('profile.title') . ' — ' . t('app.name'),
            'panelLabel' => t('app.portal'),
            'headerTitle' => t('profile.title'),
            'headerSubtitle' => t('profile.subtitle'),
            'member' => $user,
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
}
