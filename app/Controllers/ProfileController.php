<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
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
}
