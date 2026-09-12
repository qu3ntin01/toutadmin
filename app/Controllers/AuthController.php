<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Db;
use App\Core\Flash;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Totp;
use App\Core\View;
use App\Modules\Users;

/**
 * Entrée et sortie.
 *
 * L'écran de connexion ne dit jamais ce qui a échoué : adresse inconnue, mot de
 * passe faux, compte fermé donnent le même message et, autant que possible, le
 * même temps de réponse. Sans quoi la page de connexion devient un annuaire.
 */
final class AuthController
{
    /** Au-delà, une authentification restée en suspens est abandonnée. */
    private const PENDING_TOTP_SECONDS = 300;

    public static function home(Request $request): Response
    {
        $user = Session::get('user');
        if (!is_array($user)) {
            return Response::redirect('/connexion');
        }
        return Response::redirect(self::homeFor($user['role']));
    }

    public static function homeFor(string $role): string
    {
        return $role === 'admin' ? '/mon-espace' : '/mon-espace';
    }

    public static function loginForm(Request $request): Response
    {
        $user = Session::get('user');
        if (is_array($user)) {
            return Response::redirect(self::homeFor($user['role']));
        }
        return Response::html(View::render('auth/login', ['title' => t('auth.title') . ' — ' . t('app.name')]));
    }

    public static function login(Request $request): Response
    {
        if (Security::tooManyAttempts('login', (int) Config::get('login_rate_limit', 10), 900)) {
            Flash::set('error', 'Trop de tentatives de connexion depuis cette adresse. Merci de réessayer dans 15 minutes.');
            return Response::redirect('/connexion');
        }

        $email = strtolower($request->input('email'));
        $password = (string) ($request->body['password'] ?? '');
        $refuse = static function (): Response {
            Flash::set('error', 'Identifiants incorrects.');
            return Response::redirect('/connexion');
        };

        $user = Users::byEmail($email);
        if ($user === null) {
            // Temps constant : sans cela, la durée de la réponse dirait quelles
            // adresses existent.
            Security::burnTime($password);
            Audit::log('connexion.echec', 'users', null, ['email' => $email, 'motif' => 'compte inconnu']);
            return $refuse();
        }

        if (Security::isLocked($user)) {
            Audit::log('connexion.refusee', 'users', (int) $user['id'], ['motif' => 'compte verrouillé']);
            Flash::set('error', 'Compte temporairement verrouillé suite à plusieurs échecs. Réessayez dans '
                . Security::LOCKOUT_MINUTES . ' minutes.');
            return Response::redirect('/connexion');
        }

        $today = date('Y-m-d');
        $contractOver = !empty($user['contract_end_date']) && $user['contract_end_date'] < $today;
        if ($contractOver && (int) $user['active'] === 1) {
            Db::run('UPDATE users SET active = 0 WHERE id = ?', [$user['id']]);
        }

        // Le mot de passe est vérifié avant toute chose, y compris pour un
        // compte fermé : sans cela, l'écran de connexion dirait qui est parti.
        $passwordOk = Security::verifyPassword($password, (string) $user['password_hash']);
        $closed = $contractOver || (int) $user['active'] !== 1;

        if (!$passwordOk) {
            if (!$closed) {
                Security::registerFailedAttempt($user);
            }
            Audit::log('connexion.echec', 'users', (int) $user['id'], [
                'motif' => $closed ? 'compte fermé' : 'mot de passe incorrect',
                'tentative' => (int) $user['failed_attempts'] + 1,
            ]);
            return $refuse();
        }

        if ($closed) {
            Audit::log('connexion.refusee', 'users', (int) $user['id'], [
                'motif' => $contractOver ? 'contrat échu' : 'compte désactivé',
            ]);
            Flash::set('error', "Ce compte est fermé. Si vous cherchez vos bulletins de paie, demandez un code d'accès à votre ancien employeur.");
            return Response::redirect('/connexion');
        }

        Security::resetFailedAttempts((int) $user['id']);

        // Second facteur : le mot de passe seul n'ouvre pas encore de session.
        // On ne retient que l'identité en attente, sans aucun droit attaché.
        if ((int) ($user['totp_enabled'] ?? 0) === 1) {
            Session::regenerate();
            Session::set('pending_totp', ['user_id' => (int) $user['id'], 'since' => time()]);
            Audit::log('connexion.second_facteur_demande', 'users', (int) $user['id']);
            return Response::redirect('/connexion/code');
        }

        return self::openSession($user);
    }

    public static function totpForm(Request $request): Response
    {
        $pending = Session::get('pending_totp');
        if (!is_array($pending) || time() - (int) $pending['since'] > self::PENDING_TOTP_SECONDS) {
            Session::forget('pending_totp');
            return Response::redirect('/connexion');
        }
        return Response::html(View::render('auth/totp', ['title' => t('auth.title') . ' — ' . t('app.name')]));
    }

    public static function totpSubmit(Request $request): Response
    {
        $pending = Session::get('pending_totp');
        if (!is_array($pending) || time() - (int) $pending['since'] > self::PENDING_TOTP_SECONDS) {
            Session::forget('pending_totp');
            Flash::set('error', 'Demande expirée. Recommencez la connexion.');
            return Response::redirect('/connexion');
        }
        if (Security::tooManyAttempts('totp', 10, 900)) {
            Flash::set('error', 'Trop de tentatives. Merci de réessayer dans 15 minutes.');
            return Response::redirect('/connexion');
        }

        $user = Users::byId((int) $pending['user_id']);
        if ($user === null) {
            Session::forget('pending_totp');
            return Response::redirect('/connexion');
        }

        $code = $request->input('code');
        $secret = (string) ($user['totp_secret'] ?? '');
        $ok = Totp::verify($secret, $code);

        if (!$ok) {
            // Code de secours : usage unique, consommé à la première réussite.
            $hash = Totp::hashRecoveryCode($code);
            $row = Db::get(
                'SELECT id FROM totp_recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
                [$user['id'], $hash]
            );
            if ($row !== null) {
                Db::run("UPDATE totp_recovery_codes SET used_at = datetime('now') WHERE id = ?", [$row['id']]);
                Audit::log('second_facteur.code_secours', 'users', (int) $user['id']);
                $ok = true;
            }
        }

        if (!$ok) {
            Audit::log('connexion.second_facteur_echec', 'users', (int) $user['id']);
            Flash::set('error', 'Code incorrect.');
            return Response::redirect('/connexion/code');
        }

        Session::forget('pending_totp');
        return self::openSession($user);
    }

    private static function openSession(array $user): Response
    {
        Db::run("UPDATE users SET last_login_at = datetime('now') WHERE id = ?", [$user['id']]);
        // Nouvel identifiant de session au changement de droits : sans cela, un
        // identifiant obtenu avant la connexion vaudrait encore après.
        Session::regenerate();
        Session::set('opened_at', time());
        Session::set('user', Users::forSession($user));
        Session::set('locale', $user['locale'] ?: Settings::get('default_locale'));
        Audit::log('connexion.reussie', 'users', (int) $user['id']);

        if ((int) $user['must_change_password'] === 1) {
            return Response::redirect('/mot-de-passe');
        }
        return Response::redirect(self::homeFor((string) $user['role']));
    }

    public static function logout(Request $request): Response
    {
        $user = Session::get('user');
        if (is_array($user)) {
            Audit::log('deconnexion', 'users', (int) $user['id']);
        }
        Session::destroy();
        return Response::redirect('/connexion');
    }

    public static function passwordForm(Request $request): Response
    {
        if (!is_array(Session::get('user'))) {
            return Response::redirect('/connexion');
        }
        return Response::html(View::render('auth/password', ['title' => t('profile.password') . ' — ' . t('app.name')]));
    }

    public static function passwordSubmit(Request $request): Response
    {
        $session = Session::get('user');
        if (!is_array($session)) {
            return Response::redirect('/connexion');
        }
        $user = Users::byId((int) $session['id']);
        if ($user === null) {
            return Response::redirect('/connexion');
        }

        $current = (string) ($request->body['current'] ?? '');
        $next = (string) ($request->body['password'] ?? '');
        $confirm = (string) ($request->body['confirm'] ?? '');

        if (!Security::verifyPassword($current, (string) $user['password_hash'])) {
            Audit::log('mot_de_passe.echec', 'users', (int) $user['id']);
            Flash::set('error', 'Mot de passe actuel incorrect.');
            return Response::redirect('/mot-de-passe');
        }
        if ($next !== $confirm) {
            Flash::set('error', 'Les deux saisies ne correspondent pas.');
            return Response::redirect('/mot-de-passe');
        }
        $problem = Users::passwordProblem($next);
        if ($problem !== null) {
            Flash::set('error', $problem);
            return Response::redirect('/mot-de-passe');
        }

        Users::setPassword((int) $user['id'], $next);
        // Les autres sessions tombent : c'est ce qui rend le changement utile
        // quand le mot de passe a fuité.
        $sid = Session::id();
        Session::destroyAllFor((int) $user['id']);
        Session::start($sid);
        Session::set('opened_at', time());
        Session::set('user', Users::forSession(array_merge($user, ['must_change_password' => 0])));

        Flash::set('success', 'Mot de passe modifié.');
        return Response::redirect(self::homeFor((string) $user['role']));
    }

    /** Changer de langue depuis n'importe quelle page, connecté ou non. */
    public static function switchLocale(Request $request): Response
    {
        $code = $request->input('locale');
        if (I18n::isSupported($code)) {
            Session::set('locale', $code);
            $session = Session::get('user');
            if (is_array($session)) {
                Db::run('UPDATE users SET locale = ? WHERE id = ?', [$code, $session['id']]);
            }
        }
        $back = $request->input('returnTo', '/');
        // Jamais de redirection hors du site : une URL entière ouvrirait une
        // redirection ouverte, commode pour l'hameçonnage.
        if (!str_starts_with($back, '/') || str_starts_with($back, '//')) {
            $back = '/';
        }
        return Response::redirect($back);
    }
}
