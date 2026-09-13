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
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;
use App\Modules\Users;

/**
 * Installation.
 *
 * Un seul écran : le nom de l'entreprise, la langue, les congés annuels et le
 * compte d'administration. Il n'est atteignable que tant qu'aucun compte
 * n'existe — après quoi la route se ferme d'elle-même, sans fichier à
 * supprimer à la main.
 *
 * Entre le moment où les fichiers sont déposés sur l'hébergement et celui où
 * quelqu'un lance l'assistant, l'instance est à qui la trouve. D'où le jeton
 * d'installation : posé dans la configuration avant la mise en ligne, il est
 * demandé ici, et sans lui personne ne crée le compte d'administration.
 */
final class InstallController
{
    /** Le jeton attendu : celui de la configuration, ou celui de l'environnement. */
    private static function expectedToken(): string
    {
        $configured = (string) Config::get('install_token', '');
        return $configured !== '' ? $configured : (string) (getenv('INSTALL_TOKEN') ?: '');
    }

    public static function tokenRequired(): bool
    {
        return self::expectedToken() !== '';
    }

    /**
     * Comparaison à temps constant : une comparaison ordinaire rend la longueur
     * du préfixe commun, et un jeton se devine alors caractère par caractère.
     */
    public static function tokenMatches(?string $candidate): bool
    {
        $expected = self::expectedToken();
        return $expected === '' || hash_equals($expected, (string) $candidate);
    }

    public static function form(Request $request): Response
    {
        return Response::html(View::render('install', [
            'title' => 'Installation — Toutadmin',
            'checks' => self::checks(),
            'tokenRequired' => self::tokenRequired(),
        ]));
    }

    public static function submit(Request $request): Response
    {
        // Le jeton d'abord : rien n'est même examiné tant qu'il ne répond pas.
        if (!self::tokenMatches($request->input('install_token'))) {
            Audit::logSystem('installation.jeton_refuse', 'settings');
            Flash::set('error', "Jeton d'installation incorrect.");
            return Response::redirect('/installation');
        }

        // Un prérequis bloquant non satisfait : on ne pose pas une instance
        // qui ne pourra pas tourner.
        $blocking = array_values(array_filter(
            self::checks(),
            static fn (array $check): bool => $check[3] && !$check[1]
        ));
        if ($blocking !== []) {
            Flash::set('error', 'Prérequis non satisfait : '
                . implode(', ', array_column($blocking, 0)) . '.');
            return Response::redirect('/installation');
        }

        $company = $request->input('company_name');
        $locale = $request->input('locale', 'fr');
        $email = strtolower($request->input('email'));
        $password = (string) ($request->body['password'] ?? '');
        $confirm = (string) ($request->body['confirm'] ?? '');
        $leaveRaw = str_replace(',', '.', $request->input('annual_leave_days', '25'));
        $leaveDays = is_numeric($leaveRaw) ? (float) $leaveRaw : -1.0;

        $problem = null;
        if ($company === '') {
            $problem = "Le nom de l'entreprise est obligatoire.";
        } elseif ($leaveDays < 0 || $leaveDays > 365) {
            $problem = 'Nombre de jours de congés annuels invalide.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $problem = 'Adresse électronique invalide.';
        } elseif ($password !== $confirm) {
            $problem = 'Les deux saisies ne correspondent pas.';
        } else {
            $problem = Users::passwordProblem($password);
        }

        if ($problem !== null) {
            Flash::set('error', $problem);
            return Response::redirect('/installation');
        }

        Db::transaction(static function () use ($company, $locale, $email, $password, $leaveDays): void {
            Settings::setMany([
                'company_name' => $company,
                'default_locale' => I18n::isSupported($locale) ? $locale : 'fr',
                'annual_leave_days' => (string) (int) $leaveDays,
                'installed_at' => gmdate('Y-m-d H:i:s'),
                'installed_version' => trim((string) @file_get_contents(APP_ROOT . '/VERSION')) ?: '1.0.0-php',
            ]);
            Users::create([
                'role' => 'admin',
                'email' => $email,
                'password' => $password,
                'first_name' => 'Administrateur',
                'last_name' => 'Général',
                'locale' => I18n::isSupported($locale) ? $locale : 'fr',
            ]);
        });

        Audit::log('installation.terminee', 'settings', null, ['entreprise' => $company]);
        Session::destroy();
        Flash::set('success', 'Installation terminée. Connectez-vous avec le compte que vous venez de créer.');
        return Response::redirect('/connexion');
    }

    /**
     * Ce que l'hébergement doit offrir, vérifié plutôt que supposé. Chaque
     * ligne dit aussi si le manque est bloquant : sans SQLite rien ne tourne,
     * alors qu'openssl ne manque qu'aux secrets chiffrés.
     */
    public static function checks(): array
    {
        $dataDir = (string) Config::get('data_dir');
        return [
            ['PHP 8.1 ou plus récent', PHP_VERSION_ID >= 80100, PHP_VERSION, true],
            ['Extension PDO SQLite', extension_loaded('pdo_sqlite'),
             extension_loaded('pdo_sqlite') ? 'présente' : 'absente', true],
            ['Extension mbstring', extension_loaded('mbstring'),
             extension_loaded('mbstring') ? 'présente' : 'absente', true],
            ['Dossier data/ inscriptible', is_dir($dataDir) && is_writable($dataDir), $dataDir, true],
            ['Hachage bcrypt', defined('PASSWORD_BCRYPT'), 'password_hash()', true],
            ['Extension openssl', extension_loaded('openssl'),
             extension_loaded('openssl') ? 'présente' : 'absente (secrets non chiffrés)', false],
            ['Extension curl', extension_loaded('curl'),
             extension_loaded('curl') ? 'présente' : 'absente (webhooks et dépôt distant)', false],
        ];
    }
}
