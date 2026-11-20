<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Sections;
use App\Core\SectionsReglages;
use App\Core\View;

/**
 * Une section : son tableau de bord, son assistant de première ouverture, et
 * ses réglages.
 *
 * Le rail dit où l'on peut aller ; il ne dit pas ce qu'on y fait. Replié, il ne
 * montre qu'un nom de métier ; déplié, sept mots par ligne. Le tableau de bord
 * ouvre le métier : chaque service y a sa tuile, son icône — la même que dans
 * le rail, pour qu'on la reconnaisse — et une ligne qui dit ce qu'il sert à
 * faire. C'est la disposition d'un panneau d'hébergeur, et pour la même raison :
 * on cherche « ce que je veux faire », pas « où c'est rangé ».
 */
final class SectionController
{
    /** Les trois temps de l'assistant : on découvre, on règle, on récapitule. */
    private const ETAPES = ['decouverte', 'reglages', 'fin'];

    /**
     * La section demandée, ou null.
     *
     * Trois refus qu'il faut distinguer : une section inventée n'existe pas ;
     * une section que le client n'a pas installée n'existe pas non plus pour
     * lui — le dire autrement lui apprendrait ce qu'il a refusé ; une section
     * dont ce compte ne verrait aucun service se refuse comme une adresse
     * inventée, plutôt que par un « accès refusé » qui apprendrait son
     * existence.
     *
     * @return array{groupe:array<string,mixed>,services:list<array<string,mixed>>}|null
     */
    private static function sectionDe(string $cle): ?array
    {
        $groupe = Sections::groupe($cle);
        if ($groupe === null || !SectionsReglages::estActive($cle)) {
            return null;
        }
        $droits = View::shared()['droits'] ?? ['role' => null];
        $services = Sections::servicesDe($cle, $droits);
        return $services === [] ? null : ['groupe' => $groupe, 'services' => $services];
    }

    private static function introuvable(): Response
    {
        $message = t('sections.unknown');
        return Response::html(View::page('error', ['title' => $message, 'message' => $message]), 404);
    }

    /** Régler une section est une décision d'instance : elle appartient à l'administration. */
    private static function estAdmin(): bool
    {
        $user = View::shared()['user'] ?? null;
        return is_array($user) && ($user['role'] ?? '') === 'admin';
    }

    private static function refusAdmin(): Response
    {
        $message = t('sec.adminOnly');
        return Response::html(View::page('error', ['title' => $message, 'message' => $message]), 403);
    }

    public static function index(Request $request, array $params): Response
    {
        $trouve = self::sectionDe((string) ($params['cle'] ?? ''));
        if ($trouve === null) {
            return self::introuvable();
        }
        $groupe = $trouve['groupe'];
        $peutRegler = self::estAdmin();

        return Response::html(View::page('section/index', [
            'title' => t($groupe['titre']) . ' — ' . t('app.name'),
            'headerTitle' => t($groupe['titre']),
            'headerSubtitle' => t('sections.headerSub'),
            'groupe' => $groupe,
            'services' => $trouve['services'],
            // Le lien vers les réglages ne s'affiche que pour qui peut les ouvrir.
            'peutRegler' => $peutRegler,
            'champs' => $peutRegler ? SectionsReglages::champsDe((string) $groupe['cle']) : [],
        ]));
    }

    /**
     * L'assistant de première ouverture.
     *
     * Trois temps : ce que la section contient, ce qu'elle demande, et ce qu'on
     * vient de poser. Le premier n'est pas du remplissage — c'est le seul
     * moment où quelqu'un lit ce qu'un métier regroupe, avant d'avoir à le
     * deviner d'un rail replié.
     *
     * Il reste accessible ensuite : « Revoir l'assistant » y ramène, il repart
     * des valeurs en place et n'efface rien.
     */
    public static function assistant(Request $request, array $params): Response
    {
        if (!self::estAdmin()) {
            return self::refusAdmin();
        }
        $trouve = self::sectionDe((string) ($params['cle'] ?? ''));
        if ($trouve === null) {
            return self::introuvable();
        }
        $groupe = $trouve['groupe'];
        $demandee = $request->query['etape'] ?? '';
        $etape = in_array($demandee, self::ETAPES, true) ? (string) $demandee : self::ETAPES[0];

        return Response::html(View::page('section/assistant', [
            'title' => t('sec.wizard.title', ['section' => t($groupe['titre'])]) . ' — ' . t('app.name'),
            'headerTitle' => t('sec.wizard.title', ['section' => t($groupe['titre'])]),
            'headerSubtitle' => t('sec.wizard.sub'),
            'groupe' => $groupe,
            'services' => $trouve['services'],
            'champs' => SectionsReglages::champsDe((string) $groupe['cle']),
            'etapes' => self::ETAPES,
            'etape' => $etape,
            'etapeIndex' => array_search($etape, self::ETAPES, true),
        ]));
    }

    public static function repondre(Request $request, array $params): Response
    {
        if (!self::estAdmin()) {
            return self::refusAdmin();
        }
        $trouve = self::sectionDe((string) ($params['cle'] ?? ''));
        if ($trouve === null) {
            return self::introuvable();
        }
        $cle = (string) $trouve['groupe']['cle'];

        /*
         * « Garder les valeurs par défaut » n'est pas un abandon : c'est une
         * réponse, et elle vaut pour toujours. On marque donc la section
         * configurée — sans quoi l'assistant se rouvrirait à chaque visite, et
         * l'administrateur qui a dit « ça me va » devrait le redire tous les
         * jours.
         */
        if ($request->input('passer') === '1') {
            SectionsReglages::marquerConfiguree($cle);
            Flash::set('success', t('sec.wizard.skipped'));
            return Response::redirect('/section/' . $cle);
        }

        $refuses = SectionsReglages::enregistrer($cle, $request->body);
        if ($refuses !== []) {
            Flash::set('error', t('sec.settings.rejected', [
                'champs' => implode(', ', array_map(static fn (string $c): string => t('sec.' . $cle . '.' . $c), $refuses)),
            ]));
            return Response::redirect('/section/' . $cle . '/assistant?etape=reglages');
        }

        SectionsReglages::marquerConfiguree($cle);
        return Response::redirect('/section/' . $cle . '/assistant?etape=fin');
    }

    public static function reglages(Request $request, array $params): Response
    {
        if (!self::estAdmin()) {
            return self::refusAdmin();
        }
        $trouve = self::sectionDe((string) ($params['cle'] ?? ''));
        if ($trouve === null) {
            return self::introuvable();
        }
        $groupe = $trouve['groupe'];

        return Response::html(View::page('section/reglages', [
            'title' => t('sec.settings.title') . ' — ' . t($groupe['titre']) . ' — ' . t('app.name'),
            'headerTitle' => t($groupe['titre']) . ' — ' . t('sec.settings.title'),
            'headerSubtitle' => t('sec.settings.sub'),
            'groupe' => $groupe,
            'champs' => SectionsReglages::champsDe((string) $groupe['cle']),
        ]));
    }

    public static function enregistrer(Request $request, array $params): Response
    {
        if (!self::estAdmin()) {
            return self::refusAdmin();
        }
        $trouve = self::sectionDe((string) ($params['cle'] ?? ''));
        if ($trouve === null) {
            return self::introuvable();
        }
        $cle = (string) $trouve['groupe']['cle'];

        $refuses = SectionsReglages::enregistrer($cle, $request->body);
        if ($refuses !== []) {
            Flash::set('error', t('sec.settings.rejected', [
                'champs' => implode(', ', array_map(static fn (string $c): string => t('sec.' . $cle . '.' . $c), $refuses)),
            ]));
        } else {
            Flash::set('success', t('sec.settings.saved'));
        }
        return Response::redirect('/section/' . $cle . '/reglages');
    }

    /** Rouvrir l'assistant : il relit les valeurs en place, il ne les efface pas. */
    public static function rouvrir(Request $request, array $params): Response
    {
        if (!self::estAdmin()) {
            return self::refusAdmin();
        }
        $trouve = self::sectionDe((string) ($params['cle'] ?? ''));
        if ($trouve === null) {
            return self::introuvable();
        }
        $cle = (string) $trouve['groupe']['cle'];
        SectionsReglages::rouvrir($cle);
        return Response::redirect('/section/' . $cle . '/assistant');
    }
}
