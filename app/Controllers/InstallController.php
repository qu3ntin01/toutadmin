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
 * Un seul écran : le nom de l'entreprise, la langue, et le compte
 * d'administration. Il n'est atteignable que tant qu'aucun compte n'existe —
 * après quoi la route se ferme d'elle-même, sans fichier à supprimer à la main.
 */
final class InstallController
{
    public static function form(Request $request): Response
    {
        return Response::html(View::render('install', [
            'title' => 'Installation — Toutadmin',
            'checks' => self::checks(),
        ]));
    }

    public static function submit(Request $request): Response
    {
        $company = $request->input('company_name');
        $locale = $request->input('locale', 'fr');
        $email = strtolower($request->input('email'));
        $password = (string) ($request->body['password'] ?? '');
        $confirm = (string) ($request->body['confirm'] ?? '');

        $problem = null;
        if ($company === '') {
            $problem = "Le nom de l'entreprise est obligatoire.";
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

        Db::transaction(static function () use ($company, $locale, $email, $password): void {
            Settings::setMany([
                'company_name' => $company,
                'default_locale' => I18n::isSupported($locale) ? $locale : 'fr',
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

    /** Ce que l'hébergement doit offrir, vérifié plutôt que supposé. */
    public static function checks(): array
    {
        $dataDir = (string) Config::get('data_dir');
        return [
            ['PHP 8.1 ou plus récent', PHP_VERSION_ID >= 80100, PHP_VERSION],
            ['Extension PDO SQLite', extension_loaded('pdo_sqlite'), extension_loaded('pdo_sqlite') ? 'présente' : 'absente'],
            ['Extension mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'présente' : 'absente'],
            ['Dossier data/ inscriptible', is_dir($dataDir) && is_writable($dataDir), $dataDir],
            ['Hachage bcrypt', defined('PASSWORD_BCRYPT'), 'password_hash()'],
        ];
    }
}
