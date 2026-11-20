<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\View;
use App\Modules\AssistanceEditeur;
use App\Modules\Licence;
use App\Modules\LicenceCle;

/**
 * L'écran de licence.
 *
 * Il dit ce qui est couvert, jusqu'à quand, et ce qu'il faut faire quand ça ne
 * l'est plus. C'est aussi le seul endroit où l'empreinte de cette installation
 * se lit : le client la communique à l'éditeur pour obtenir un jeton hors
 * ligne, ou pour faire délier son ancienne machine après un déménagement.
 *
 * Il reste ouvert même quand l'instance est restreinte — c'est la sortie, on
 * ne la ferme pas.
 */
final class LicenceController
{
    /** Le libellé d'un refus. La clé s'écrit d'un bloc : le contrôle des
     *  traductions lit le code source, et une concaténation lui échapperait. */
    private static function motif(string $raison): string
    {
        return t("licence.refus.$raison");
    }

    /** Le libellé d'un palier, et celui d'un mode. Traduits ici pour la même
     *  raison : une vue qui concatène une clé la rend invisible au contrôle. */
    private static function palier(string $phase): string
    {
        return t("licence.phase.$phase");
    }

    private static function mode(string $mode): string
    {
        return t("licence.mode.$mode");
    }

    private static function retour(string $type, string $message): Response
    {
        Flash::set($type, $message);
        return Response::redirect('/licence');
    }

    public static function index(Request $request): Response
    {
        $etat = Licence::etat();
        $config = Licence::config();

        return Response::html(View::page('licence/index', [
            'title' => t('licence.title') . ' — ' . t('app.name'),
            'panelLabel' => t('licence.title'),
            'headerTitle' => t('licence.title'),
            'headerSubtitle' => t('licence.headerSub'),
            'navItems' => [
                ['tab' => 'etat', 'label' => t('licence.tabState')],
                ['tab' => 'horsligne', 'label' => t('licence.tabOffline')],
                ['tab' => 'assistance', 'label' => t('licence.assist.tab')],
            ],
            'etat' => $etat,
            'palier' => self::palier((string) $etat['phase']),
            'modeLibelle' => isset($etat['licence']['mode']) ? self::mode((string) $etat['licence']['mode']) : '—',
            'refusLibelle' => $config['dernier_refus'] !== '' ? self::motif($config['dernier_refus']) : '',
            'empreinte' => Licence::empreinte(),
            'adresse' => $config['adresse'],
            'dernierRefus' => $config['dernier_refus'],
            'derniereVerif' => $config['derniere_verif'],
            'editeurConfigure' => $config['url'] !== '' && $config['clef'] !== '',
            'distribuee' => LicenceCle::distribuee(),
            'effectif' => (int) Db::value("SELECT COUNT(*) FROM users WHERE active = 1 AND role <> 'admin'"),
            // Ce que l'instance coûte par mois. Affiché, jamais facturé ici :
            // elle sait traduire son plafond en montant, rien de plus.
            'prixMensuel' => Licence::prixMensuel((int) $etat['plafond_membres']),
            'gratuiteSieges' => Licence::GRATUITE_SIEGES,
            'prixSalarie' => Licence::PRIX_SALARIE,

            /*
             * L'assistance de l'éditeur.
             *
             * Le dernier relevé, et rien de plus : aucun appel réseau sur un
             * simple affichage. Cet écran est la sortie de secours quand la
             * licence coince, et un panneau injoignable ne doit pas le faire
             * attendre quinze secondes.
             */
            'assistConfigure' => AssistanceEditeur::configure(),
            'assistSujets' => AssistanceEditeur::SUJETS,
            'assistReleve' => AssistanceEditeur::memoire(),
        ]));
    }

    /** Demander un bail tout de suite, sans attendre le balayage de la nuit. */
    public static function renew(Request $request): Response
    {
        $bail = Licence::renouveler();
        if ($bail['ok']) {
            return self::retour('success', t('licence.renewed'));
        }
        $explication = (string) ($bail['explication'] ?? '');
        return self::retour('error', $explication !== '' ? $explication : self::motif((string) $bail['raison']));
    }

    /** Poser un jeton hors ligne, collé à la main. */
    public static function apply(Request $request): Response
    {
        $pose = Licence::poser($request->input('jeton'));
        return $pose['ok']
            ? self::retour('success', t('licence.posed'))
            : self::retour('error', self::motif((string) $pose['raison']));
    }

    /** L'adresse publique de cette instance, telle que le panneau la verra. */
    public static function address(Request $request): Response
    {
        Settings::set('licence_adresse', rtrim(trim($request->input('adresse')), '/'));
        return self::retour('success', t('licence.addressSaved'));
    }
    // ---------- Écrire à l'éditeur ----------

    /*
     * Trois gestes, et un relevé.
     *
     * L'instance porte déjà la clé qui lui sert à obtenir son bail : c'est elle
     * qui l'authentifie auprès de l'éditeur. Aucun mot de passe de l'espace
     * client ne transite par le CMS, et l'administrateur n'a pas de second
     * compte à tenir.
     *
     * Après chaque geste on relève : celui qui vient d'écrire veut voir son
     * message dans le fil, pas un écran inchangé qui laisse douter que l'envoi
     * soit parti.
     */
    private static function direRefus(array $verdict): string
    {
        $dit = t('licence.assist.refus.' . (string) ($verdict['raison'] ?? 'refus'));
        return $dit !== '' ? $dit : (string) ($verdict['raison'] ?? '');
    }

    private static function versAssistance(): Response
    {
        return Response::redirect('/licence#assistance');
    }

    private static function releverEtRediriger(?string $message): Response
    {
        $vu = AssistanceEditeur::relever();
        if (!empty($vu['ok'])) {
            AssistanceEditeur::memoriser($vu['demandes']);
        }
        if ($message !== null) {
            Flash::set('success', $message);
        } elseif (empty($vu['ok'])) {
            Flash::set('error', self::direRefus($vu));
        }
        return self::versAssistance();
    }

    public static function fetchAssistance(Request $request): Response
    {
        $vu = AssistanceEditeur::relever();
        if (empty($vu['ok'])) {
            Flash::set('error', self::direRefus($vu));
            return self::versAssistance();
        }
        AssistanceEditeur::memoriser($vu['demandes']);
        Flash::set('success', t('licence.assist.fetched', ['n' => count($vu['demandes'])]));
        return self::versAssistance();
    }

    public static function openAssistance(Request $request): Response
    {
        $verdict = AssistanceEditeur::ouvrir(
            $request->input('sujet'),
            $request->input('type'),
            $request->input('corps'),
        );
        if (empty($verdict['ok'])) {
            Flash::set('error', self::direRefus($verdict));
            return self::versAssistance();
        }
        Audit::log('assistance.editeur_ouverte', 'settings', null,
            ['reference' => $verdict['donnees']['reference'] ?? null]);
        return self::releverEtRediriger(t('licence.assist.opened', [
            'reference' => (string) ($verdict['donnees']['reference'] ?? ''),
        ]));
    }

    public static function replyAssistance(Request $request): Response
    {
        $reference = $request->input('reference');
        $verdict = AssistanceEditeur::repondre($reference, $request->input('corps'));
        if (empty($verdict['ok'])) {
            Flash::set('error', self::direRefus($verdict));
            return self::versAssistance();
        }
        Audit::log('assistance.editeur_relancee', 'settings', null, ['reference' => $reference]);
        return self::releverEtRediriger(t('licence.assist.replied'));
    }
}
