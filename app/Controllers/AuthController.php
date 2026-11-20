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
use App\Modules\TwoFactor;
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
        return Response::html(View::render('auth/login', [
            'title' => t('auth.title') . ' — ' . t('app.name'),
            'sso' => \App\Modules\Oidc::bouton(),
            'samlSso' => \App\Modules\Saml::bouton(),
        ]));
    }

    // ---------- Connexion unique (OpenID Connect) ----------

    private static function retourSso(Request $request): string
    {
        return \App\Modules\Webauthn::site($request)['origine'] . '/connexion/sso/retour';
    }

    public static function ssoStart(Request $request): Response
    {
        $user = Session::get('user');
        if (is_array($user)) {
            return Response::redirect(self::homeFor($user['role']));
        }
        try {
            $depart = \App\Modules\Oidc::demarrer(self::retourSso($request));
        } catch (\RuntimeException) {
            Flash::set('error', "Le fournisseur d'identité ne répond pas. Réessayez, ou connectez-vous avec votre mot de passe.");
            return Response::redirect('/connexion');
        }
        if (!$depart['ok']) {
            return Response::redirect('/connexion');
        }
        Session::set('oidc', $depart['etat']);
        return Response::redirect($depart['url']);
    }

    public static function ssoReturn(Request $request): Response
    {
        $etat = Session::get('oidc');
        Session::forget('oidc');
        if ($request->input('error') !== '') {
            return self::ouvrirParSso(['ok' => false, 'reason' => 'fournisseur: ' . mb_substr($request->input('error'), 0, 60)], 'oidc');
        }
        try {
            $verdict = \App\Modules\Oidc::terminer($request->input('code'), $request->input('state'), is_array($etat) ? $etat : null, self::retourSso($request));
        } catch (\RuntimeException) {
            $verdict = ['ok' => false, 'reason' => 'injoignable'];
        }
        return self::ouvrirParSso($verdict, 'oidc');
    }

    // ---------- Connexion unique (SAML 2.0) ----------

    private static function origine(Request $request): string
    {
        return \App\Modules\Webauthn::site($request)['origine'];
    }

    public static function samlMetadata(Request $request): Response
    {
        return new Response(200, \App\Modules\Saml::metadonnees(self::origine($request)),
            ['Content-Type' => 'application/samlmetadata+xml; charset=UTF-8']);
    }

    public static function samlStart(Request $request): Response
    {
        $user = Session::get('user');
        if (is_array($user)) {
            return Response::redirect(self::homeFor($user['role']));
        }
        $depart = \App\Modules\Saml::demarrer(self::origine($request));
        return Response::redirect($depart['ok'] ? $depart['url'] : '/connexion');
    }

    public static function samlAcs(Request $request): Response
    {
        $verdict = \App\Modules\Saml::valider((string) ($request->body['SAMLResponse'] ?? ''), self::origine($request));
        return self::ouvrirParSso($verdict, 'saml');
    }

    /**
     * Ce qui suit un verdict de connexion unique, quel que soit le protocole.
     * Le compte se trouve par son adresse ; la connexion unique n'en crée jamais.
     */
    private static function ouvrirParSso(array $verdict, string $protocole): Response
    {
        $refuser = static function (string $motif, ?string $email = null) use ($protocole): Response {
            Audit::log('connexion.sso_refusee', 'users', null, ['motif' => $motif, 'email' => $email, 'protocole' => $protocole]);
            Flash::set('error', $motif === 'no-account'
                ? "Aucun compte ouvert ici ne porte cette adresse. Demandez à l'administration de vous en créer un."
                : "La connexion unique n'a pas abouti. Réessayez, ou connectez-vous avec votre mot de passe.");
            return Response::redirect('/connexion');
        };
        if (!$verdict['ok']) {
            return $refuser((string) $verdict['reason']);
        }
        $user = Users::byEmail($verdict['email']);
        if ($user === null) {
            return $refuser('no-account', $verdict['email']);
        }
        $ferme = (int) $user['active'] !== 1 || (!empty($user['contract_end_date']) && $user['contract_end_date'] < date('Y-m-d'));
        if ($ferme || Security::isLocked($user)) {
            return $refuser($ferme ? 'compte fermé' : 'compte verrouillé', $verdict['email']);
        }
        Audit::log('connexion.sso_reussie', 'users', (int) $user['id'], ['protocole' => $protocole, 'sub' => $verdict['sub'] ?? null]);
        // Un second facteur local se garde : le fournisseur ne le remplace pas.
        if ((int) ($user['totp_enabled'] ?? 0) === 1 || \App\Modules\Webauthn::aDesCles((int) $user['id'])) {
            Session::regenerate();
            Session::set('pending_totp', ['user_id' => (int) $user['id'], 'since' => time()]);
            return Response::redirect('/connexion/code');
        }
        return self::openSession($user);
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
        if ((int) ($user['totp_enabled'] ?? 0) === 1 || \App\Modules\Webauthn::aDesCles((int) $user['id'])) {
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
        $user = Users::byId((int) $pending['user_id']);
        if ($user === null) {
            Session::forget('pending_totp');
            return Response::redirect('/connexion');
        }
        // Le défi d'une clé de sécurité est émis ici, gardé par la session en
        // attente : il ne vaut que pour cette personne, et pour cinq minutes.
        $webauthnOptions = null;
        if (\App\Modules\Webauthn::aDesCles((int) $user['id'])) {
            $o = \App\Modules\Webauthn::optionsConnexion((int) $user['id'], \App\Modules\Webauthn::site($request));
            Session::set('pending_totp', array_merge($pending, ['defi' => $o['defi']]));
            $webauthnOptions = $o['options'];
        }
        return Response::html(View::render('auth/totp', [
            'title' => t('auth.title') . ' — ' . t('app.name'),
            'totpActif' => (int) ($user['totp_enabled'] ?? 0) === 1,
            'webauthnOptions' => $webauthnOptions,
        ]));
    }

    /** Le second facteur par clé de sécurité. */
    public static function keySubmit(Request $request): Response
    {
        $pending = Session::get('pending_totp');
        if (!is_array($pending) || time() - (int) $pending['since'] > self::PENDING_TOTP_SECONDS) {
            Session::forget('pending_totp');
            Flash::set('error', 'Demande expirée. Recommencez la connexion.');
            return Response::redirect('/connexion');
        }
        $user = Users::byId((int) $pending['user_id']);
        if ($user === null) {
            Session::forget('pending_totp');
            return Response::redirect('/connexion');
        }
        $verdict = \App\Modules\Webauthn::verifierConnexion(
            (int) $user['id'], $request->input('credential_id'), $request->input('client_data'),
            $request->input('authenticator_data'), $request->input('signature'),
            is_array($pending['defi'] ?? null) ? $pending['defi'] : null, \App\Modules\Webauthn::site($request)
        );
        // Le défi ne sert qu'une fois, réussite ou échec.
        unset($pending['defi']);
        Session::set('pending_totp', $pending);
        if (!$verdict['ok']) {
            Security::registerFailedAttempt($user);
            Audit::log('connexion.cle_refusee', 'users', (int) $user['id'], ['motif' => $verdict['reason']]);
            Flash::set('error', "La clé de sécurité n'a pas été reconnue.");
            return Response::redirect('/connexion/code');
        }
        Session::forget('pending_totp');
        Audit::log('connexion.second_facteur_valide', 'users', (int) $user['id'], ['cle' => $verdict['cleId']]);
        return self::openSession($user);
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

        // Sans code temporaire en service, il n'y a pas de code à accepter : le
        // second facteur de ce compte est sa clé, et seulement elle. Sans ce
        // refus, verifyLogin — qui laisse passer un compte sans code — ouvrirait
        // la session de qui n'a qu'une clé, avec n'importe quelle saisie.
        if ((int) ($user['totp_enabled'] ?? 0) !== 1) {
            Flash::set('error', 'Ce compte se vérifie par sa clé de sécurité.');
            return Response::redirect('/connexion/code');
        }

        // Le code du téléphone, ou à défaut un code de secours — consommé à
        // la première réussite. La règle vit dans le module, pas ici.
        $verdict = TwoFactor::verifyLogin($user, $request->input('code'));
        $ok = $verdict['ok'];
        if ($ok && $verdict['usedRecovery']) {
            Audit::log('second_facteur.code_secours', 'users', (int) $user['id']);
        }

        if (!$ok) {
            Audit::log('connexion.second_facteur_echec', 'users', (int) $user['id']);
            Flash::set('error', 'Code incorrect.');
            return Response::redirect('/connexion/code');
        }

        Session::forget('pending_totp');
        if ($verdict['usedRecovery']) {
            Flash::set('success', 'Code de secours utilisé : il ne resservira pas. Pensez à en régénérer.');
        }
        return self::openSession($user);
    }

    /**
     * Ferme toutes les autres sessions du compte : le geste qu'on fait après un
     * doute, un voyage, ou un ordinateur laissé ouvert ailleurs. La session
     * courante tombe avec les autres — c'est la seule façon d'être sûr — et
     * l'on se reconnecte.
     */
    public static function closeSessions(Request $request): Response
    {
        $session = Session::get('user');
        if (!is_array($session)) {
            return Response::redirect('/connexion');
        }

        $closed = Session::destroyAllFor((int) $session['id']);
        Audit::log('sessions.revoquees', 'users', (int) $session['id'], ['fermees' => $closed]);
        // destroy() referme la session courante et en ouvre une vide : le
        // message ci-dessous voyage donc dans celle-là.
        Session::destroy();
        Flash::set('success', $closed . ' session(s) fermée(s). Reconnectez-vous.');
        return Response::redirect('/connexion');
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

    /**
     * Revenir d'un emprunt d'identité.
     *
     * Hors de « /admin », et c'est volontaire : pendant l'emprunt, la session est
     * celle d'un salarié, et « /admin » lui est fermé. Enfermer la sortie
     * derrière un droit que l'on vient de quitter serait enfermer
     * l'administrateur dans l'identité qu'il a emprunté.
     */
    public static function endImpersonation(Request $request): Response
    {
        if (!is_array(Session::get('user'))) {
            return Response::redirect('/connexion');
        }
        $verdict = \App\Modules\Emprunt::revenir();
        if (!$verdict['ok']) {
            if ($verdict['deconnecte'] ?? false) {
                return Response::redirect('/connexion');
            }
            Flash::set('error', 'Aucun emprunt en cours.');
            return Response::redirect('/mon-espace');
        }
        Flash::set('success', 'Emprunt terminé : vous êtes de nouveau vous-même.');
        return Response::redirect('/admin#personnel');
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
            Flash::set('error', t($problem, ['min' => Users::PASSWORD_MIN]));
            return Response::redirect('/mot-de-passe');
        }

        Users::setPassword((int) $user['id'], $next);
        // Toutes les sessions tombent, la courante comprise : c'est ce qui rend
        // le changement utile quand le mot de passe a fuité, et l'on ne peut
        // pas savoir laquelle des sessions ouvertes est celle de l'intrus.
        Session::destroyAllFor((int) $user['id']);
        Session::destroy();
        Audit::log('mot_de_passe.change', 'users', (int) $user['id']);
        Flash::set('success', 'Mot de passe mis à jour. Les autres sessions ont été fermées : reconnectez-vous.');
        return Response::redirect('/connexion');
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
