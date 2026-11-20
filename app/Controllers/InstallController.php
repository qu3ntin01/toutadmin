<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Flash;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Sections;
use App\Core\SectionsReglages;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;
use App\Modules\Installation;
use App\Modules\Users;

/**
 * Installation, en six étapes.
 *
 * Langue, prérequis, base de données, organisation, administrateur, puis la pose.
 * L'assistant n'est atteignable que tant qu'aucun compte n'existe — après quoi
 * la route se ferme d'elle-même, sans fichier à supprimer à la main.
 *
 * La dernière étape ne fait pas semblant : elle appelle quatre fois le serveur,
 * une par palier réellement accompli. Une barre qui avance toute seule pendant
 * qu'on attend est un mensonge poli, et elle ment surtout quand ça échoue.
 */
final class InstallController
{
    /** Le jeton attendu : celui de la configuration, ou celui de l'environnement. */
    private static function jetonAttendu(): string
    {
        $configure = (string) Config::get('install_token', '');
        return $configure !== '' ? $configure : (string) (getenv('INSTALL_TOKEN') ?: '');
    }

    public static function tokenRequired(): bool
    {
        return self::jetonAttendu() !== '';
    }

    /**
     * Comparaison à temps constant : une comparaison ordinaire rend la longueur
     * du préfixe commun, et un jeton se devine alors caractère par caractère.
     */
    public static function tokenMatches(?string $candidat): bool
    {
        $attendu = self::jetonAttendu();
        return $attendu === '' || hash_equals($attendu, (string) $candidat);
    }

    /**
     * L'adresse nue mène là où l'on en était.
     *
     * Reprendre une installation interrompue au premier écran obligerait à
     * refaire cinq clics pour retrouver sa place : le brouillon sait où elle
     * s'est arrêtée, autant s'en servir.
     */
    public static function home(Request $request): Response
    {
        $derniere = Installation::ETAPES[count(Installation::ETAPES) - 1];
        return Response::redirect('/install/' . Installation::etapePermise($derniere));
    }

    public static function form(Request $request, array $params = []): Response
    {
        $demandee = (string) ($params['etape'] ?? 'langue');
        $permise = Installation::etapePermise($demandee);
        if ($permise !== $demandee) {
            return Response::redirect('/install/' . $permise);
        }

        $brouillon = Installation::brouillon();
        $controles = array_map(Installation::dire(...), Installation::verifications());

        return Response::html(View::render('install', [
            'title' => t('install.title') . ' — ' . t('app.name'),
            'etape' => $demandee,
            'etapes' => Installation::ETAPES,
            'rang' => (int) array_search($demandee, Installation::ETAPES, true),
            'brouillon' => $brouillon,
            'controles' => $controles,
            'pret' => Installation::manques() === [],
            'configInscriptible' => Installation::configInscriptible(),
            'tokenRequired' => self::tokenRequired(),
            'version' => trim((string) @file_get_contents(APP_ROOT . '/VERSION')) ?: '1.0.0',
        ]));
    }

    /**
     * Éprouver la licence saisie.
     *
     * Elle est appelée dès qu'une clé est posée dans le champ, collée à la main
     * ou rapportée par la fenêtre de l'espace client — il n'y a plus de bouton
     * à presser.
     *
     * Répond en JSON. Rien n'est engagé : ni côté panneau, dont la route
     * d'essai n'active aucune licence, ni côté instance, qui n'existe pas
     * encore. Le résultat n'ouvre rien non plus — c'est « submit » qui refait
     * la vérification et décide.
     */
    public static function verifierLicence(Request $request): Response
    {
        $essai = Installation::eprouverLaLicence(
            trim((string) $request->input('licence_cle')),
            trim((string) $request->input('licence_jeton')),
            Settings::adresseEditeur(),
        );
        return Response::text(
            (string) json_encode($essai, JSON_UNESCAPED_UNICODE),
        )->withHeaders(['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * Y a-t-il plus récent ? Pour le bouton de l'étape « Mises à jour ».
     *
     * Aucune clé n'est demandée : le numéro de la dernière version est écrit
     * sur la page de téléchargement du site, le refuser à un installeur serait
     * absurde. Télécharger, en revanche, en demande une — c'est « poserLaMaj »
     * en dessous.
     */
    public static function verifierMaj(Request $request): Response
    {
        $vu = Installation::versionDisponible(Settings::adresseEditeur());
        return self::enJson($vu);
    }

    /**
     * Poser la dernière version, tout seul. Le serveur doit être rechargé
     * ensuite : les fichiers viennent de changer sous ses pieds.
     */
    public static function poserLaMaj(Request $request): Response
    {
        $cle = trim((string) $request->input('licence_cle'));
        $pose = Installation::poserLaMiseAJour(Settings::adresseEditeur(), $cle);

        if (!empty($pose['ok'])) {
            // La clé a servi ici : elle est reportée à l'étape « Licence », qui
            // n'a donc pas à la redemander. Et l'étape des mises à jour repart
            // à zéro — la version posée n'est pas encore celle qui tourne.
            Installation::noter([
                'licence_cle' => $cle,
                'maj_vue' => false,
            ]);
        }
        return self::enJson($pose);
    }

    private static function enJson(array $corps): Response
    {
        return Response::text((string) json_encode($corps, JSON_UNESCAPED_UNICODE))
            ->withHeaders(['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public static function submit(Request $request, array $params = []): Response
    {
        $etape = (string) ($params['etape'] ?? 'langue');
        $suivante = static function (string $depuis): Response {
            $rang = (int) array_search($depuis, Installation::ETAPES, true);
            return Response::redirect('/install/' . Installation::ETAPES[min($rang + 1, count(Installation::ETAPES) - 1)]);
        };
        $refus = static function (string $message, string $etape): Response {
            Flash::set('error', $message);
            return Response::redirect('/install/' . $etape);
        };

        switch ($etape) {
            case 'langue':
                $locale = (string) $request->input('locale', 'fr');
                $locale = I18n::isSupported($locale) ? $locale : 'fr';
                Installation::noter(['locale' => $locale]);
                Session::set('locale', $locale);
                return $suivante('langue');

            case 'prerequis':
                if (!self::tokenMatches($request->input('install_token'))) {
                    Audit::logSystem('installation.jeton_refuse', 'settings');
                    return $refus(t('install.error.token'), 'prerequis');
                }
                $manques = Installation::manques();
                if ($manques !== []) {
                    return $refus(t('install.error.requirements', ['list' => implode(', ', $manques)]), 'prerequis');
                }
                Installation::noter(['prerequis' => true]);
                return $suivante('prerequis');

            case 'mises-a-jour':
                /*
                 * Le contrôle est refait ici, côté serveur : le bouton grisé
                 * est du confort, et un formulaire s'envoie sans navigateur.
                 * Une version en retard ne passe pas ; un éditeur injoignable
                 * passe, avec la trace de ce qui s'est produit.
                 */
                $vu = Installation::versionDisponible(Settings::adresseEditeur());
                if (!empty($vu['ok']) && empty($vu['aJour'])) {
                    return $refus(t('install.error.majRequise', [
                        'derniere' => (string) ($vu['derniere'] ?? ''),
                        'courante' => (string) ($vu['courante'] ?? ''),
                    ]), 'mises-a-jour');
                }

                Installation::noter([
                    'maj_vue' => true,
                    'maj_etat' => !empty($vu['ok']) ? 'a-jour' : (string) ($vu['raison'] ?? 'injoignable'),
                ]);
                return $suivante('mises-a-jour');

            case 'licence':
                // Rien n'est activé ici : la clé est mise de côté dans le
                // brouillon, et l'activation attend le dernier palier, quand la
                // base existe enfin pour porter l'empreinte de l'installation.
                // Valider une clé maintenant obligerait à lier l'instance avant
                // même qu'elle existe, et une installation abandonnée en cours
                // de route consommerait la licence d'un client.
                if ($request->input('passer') === '1') {
                    Installation::noter([
                        'licence_vue' => true, 'licence_cle' => '', 'licence_jeton' => '',
                        'licence_verifiee' => false, 'licence_membres' => 0,
                    ]);
                    return $suivante('licence');
                }
                $cle = trim((string) $request->input('licence_cle'));
                $jeton = trim((string) $request->input('licence_jeton'));
                if ($cle === '' && $jeton === '') {
                    return $refus(t('install.error.licenceEmpty'), 'licence');
                }

                /*
                 * La même vérification qu'au bouton, refaite ici. Le bouton
                 * grisé est du confort : il dit à l'utilisateur où il en est.
                 * Ce n'est pas un contrôle — un attribut « disabled » s'enlève
                 * en trois secondes dans un navigateur, et un formulaire
                 * s'envoie sans navigateur du tout. Le contrôle est celui-ci,
                 * et il est du côté qui décide.
                 */
                $essai = Installation::eprouverLaLicence($cle, $jeton, Settings::adresseEditeur());
                if (!$essai['ok']) {
                    Installation::noter(['licence_verifiee' => false]);
                    $pourquoi = (string) ($essai['explication'] ?? '');
                    if ($pourquoi === '') {
                        $pourquoi = t('licence.refus.' . (string) ($essai['raison'] ?? ''));
                    }
                    return $refus(t('install.error.licenceInvalid', ['raison' => $pourquoi]), 'licence');
                }

                // Marquées vues et vérifiées seulement ici : une saisie refusée
                // ne doit pas déverrouiller l'étape suivante.
                // Le nombre de salariés couverts est retenu pour qu'un retour
                // en arrière retrouve l'écran tel qu'on l'avait laissé.
                Installation::noter([
                    'licence_vue' => true,
                    'licence_verifiee' => true,
                    'licence_membres' => (int) ($essai['membres'] ?? 0),
                    'licence_cle' => $cle,
                    'licence_jeton' => $jeton,
                    'licence_adresse' => rtrim(trim((string) $request->input('licence_adresse')), '/'),
                ]);
                return $suivante('licence');

            case 'base':
                return self::base($request, $refus, $suivante);

            case 'entreprise':
                $nom = trim((string) $request->input('company_name'));
                $conges = str_replace(',', '.', (string) $request->input('annual_leave_days', '25'));
                if ($nom === '') {
                    return $refus(t('install.error.company'), 'entreprise');
                }
                if (!is_numeric($conges) || (float) $conges < 0 || (float) $conges > 365) {
                    return $refus(t('install.error.leave'), 'entreprise');
                }
                Installation::noter(['company_name' => $nom, 'annual_leave_days' => (int) $conges]);
                return $suivante('entreprise');

            /*
             * L'étape « Sections ». Ce que le client coche ici décide de ce que
             * son rail portera. Rien n'est définitif : une section se repose
             * ensuite depuis la console, et ses données l'attendent.
             *
             * Le choix n'est pas écrit maintenant : il dort dans le brouillon
             * jusqu'au dernier palier, comme la licence et le compte
             * administrateur. Sur MySQL, la base qui le recevra n'est même pas
             * encore celle de ce processus.
             */
            case 'sections':
                $connues = Sections::clesDesGroupes();
                $retenues = array_values(array_filter(
                    $request->inputs('sections'),
                    static fn (string $cle): bool => in_array($cle, $connues, true)
                ));
                Installation::noter([
                    'sections' => array_values(array_unique(array_merge($retenues, SectionsReglages::OBLIGATOIRES))),
                    'sections_vues' => true,
                ]);
                return $suivante('sections');

            case 'administrateur':
                return self::administrateur($request, $refus, $suivante);
        }

        return Response::redirect('/install');
    }

    /** L'étape de la base : SQLite sans rien demander, ou MySQL que l'on essaie. */
    private static function base(Request $request, callable $refus, callable $suivante): Response
    {
        if ($request->input('db_driver') !== 'mysql') {
            Installation::noter(['db_driver' => 'sqlite', 'base' => true]);
            return $suivante('base');
        }

        $champs = [
            'host' => trim((string) $request->input('db_host', '127.0.0.1')),
            'port' => (int) ($request->input('db_port', '3306') ?: 3306),
            'name' => trim((string) $request->input('db_name')),
            'user' => trim((string) $request->input('db_user')),
            'password' => (string) ($request->body['db_password'] ?? ''),
        ];

        // On essaie avant de s'engager : une adresse qui ne répond pas doit se
        // dire ici, et non trois étapes plus loin.
        $essai = Installation::essayerMysql($champs);
        if (!$essai['ok']) {
            Installation::noter([
                'db_driver' => 'mysql', 'db_host' => $champs['host'], 'db_port' => $champs['port'],
                'db_name' => $champs['name'], 'db_user' => $champs['user'], 'base' => false,
            ]);
            return $refus(t('install.db.failed', ['message' => $essai['message']]), 'base');
        }

        Installation::noter([
            'db_driver' => 'mysql', 'db_host' => $champs['host'], 'db_port' => $champs['port'],
            'db_name' => $champs['name'], 'db_user' => $champs['user'],
            'db_password' => $champs['password'], 'base' => true,
        ]);
        return $suivante('base');
    }

    private static function administrateur(Request $request, callable $refus, callable $suivante): Response
    {
        $courriel = strtolower(trim((string) $request->input('email')));
        $motDePasse = (string) ($request->body['password'] ?? '');
        $confirmation = (string) ($request->body['confirm'] ?? '');

        if (!filter_var($courriel, FILTER_VALIDATE_EMAIL)) {
            return $refus(t('install.error.email'), 'administrateur');
        }
        if ($motDePasse !== $confirmation) {
            return $refus(t('install.error.confirm'), 'administrateur');
        }
        $souci = Users::passwordProblem($motDePasse);
        if ($souci !== null) {
            return $refus(t($souci, ['min' => Users::PASSWORD_MIN]), 'administrateur');
        }

        Installation::noter([
            'email' => $courriel,
            'password' => $motDePasse,
            'first_name' => trim((string) $request->input('first_name')),
            'last_name' => trim((string) $request->input('last_name')),
        ]);
        return $suivante('administrateur');
    }

    /**
     * Un palier de l'installation.
     *
     * Quatre appels, quatre choses faites, dans l'ordre du risque : on vérifie,
     * on pose le schéma, on ouvre l'instance, et on écrit la configuration en
     * dernier. Tant que ce dernier palier n'a pas eu lieu, une installation
     * interrompue n'a rien cassé — le site repart sur sa base d'amorçage et
     * l'assistant se réaffiche.
     *
     * L'avancement montré à l'écran est celui-là : rien n'avance tant que rien
     * n'est fait, et un échec s'arrête là où il a lieu, en le disant.
     */
    public static function executer(Request $request, array $params = []): Response
    {
        $json = static fn (array $corps, int $code = 200): Response => Response::text(
            (string) json_encode($corps, JSON_UNESCAPED_UNICODE),
            $code
        )->withHeaders(['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']);

        $brouillon = Installation::brouillon();
        if (!isset($brouillon['email'], $brouillon['company_name'])) {
            return $json(['ok' => false, 'message' => t('install.error.incomplete')], 409);
        }

        try {
            switch ((string) ($params['palier'] ?? '')) {
                case 'controle':
                    Installation::controler($brouillon);
                    return $json(['ok' => true, 'message' => t('install.run.controlDone')]);

                case 'schema':
                    $tables = Installation::poserLeSchema($brouillon);
                    return $json(['ok' => true, 'message' => t('install.run.schemaDone', ['count' => $tables])]);

                case 'instance':
                    Installation::ouvrirLInstance($brouillon);
                    return $json(['ok' => true, 'message' => t('install.run.instanceDone')]);

                case 'configuration':
                    Installation::verrouiller($brouillon);
                    // La licence, maintenant que la base porte des réglages. Un
                    // échec n'arrête pas l'installation : l'instance s'installe
                    // et le dira sur son écran « Licence ». Laisser un client à
                    // moitié installé parce que le service de licences ne répondait
                    // pas serait la pire des façons de le perdre.
                    $licenceDite = Installation::poserLaLicence($brouillon);
                    // La session de l'assistant vivait dans la base d'amorçage :
                    // elle n'a plus lieu d'être, et l'écran suivant est celui
                    // d'une instance installée.
                    Session::destroy();
                    return $json([
                        'ok' => true,
                        'message' => t('install.run.configDone'),
                        'licence' => $licenceDite,
                        'suite' => '/connexion',
                    ]);
            }
        } catch (\Throwable $erreur) {
            Audit::logSystem('installation.palier_echoue', 'settings', null, [
                'palier' => (string) ($params['palier'] ?? ''),
            ]);
            return $json(['ok' => false, 'message' => $erreur->getMessage()], 500);
        }

        return $json(['ok' => false, 'message' => t('install.error.unknownStage')], 404);
    }
}
