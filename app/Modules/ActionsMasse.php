<?php

declare(strict_types=1);

namespace App\Modules;

/**
 * Les actions de masse : la même action, répétée, avec les mêmes refus.
 *
 * Le pendant de « src/actions-masse.js » de l'édition Node, et le choix de
 * dessin est le même — c'est lui qui fait tout le module.
 *
 * Le dessin écarté écrivait une requête par action : « UPDATE tickets SET status
 * = ? WHERE id IN (…) ». Une instruction, rapide, et fausse — elle contourne les
 * règles de l'action à l'unité. Clore un ticket pose une date de clôture ;
 * approuver une absence décrémente un solde de congés et prévient les outils de
 * planification. Un « UPDATE … IN » ne fait rien de cela, et le défaut ne se voit
 * pas tout de suite : il se voit sur le solde de congés d'un salarié, trois mois
 * plus tard.
 *
 * Celui-ci appelle la fonction à l'unité, une fois par ligne. C'est plus lent, et
 * c'est la seule façon d'obtenir le même résultat que si la personne avait ouvert
 * chaque fiche : mêmes effets, mêmes refus, mêmes entrées au journal. Une action
 * de masse n'est pas une fonction de plus, c'est un raccourci sur des fonctions
 * qui existent.
 *
 * Trois conséquences, qui sont les trois invariants que les tests tiennent :
 *
 *   - le droit se vérifie ligne par ligne. Un agent de support n'a la charge que
 *     de certaines catégories : cocher cinquante tickets ne lui en donne pas une
 *     de plus ;
 *   - une ligne hors périmètre ne se lit pas, donc ne se traite pas. Elle est
 *     refusée comme une ligne effacée, sans dire laquelle des deux raisons l'a
 *     emportée : un identifiant d'une autre société ne doit pas se distinguer
 *     d'un identifiant inexistant ;
 *   - une ligne qui échoue n'emporte pas les autres. Pas de transaction
 *     englobante, et c'est délibéré : sur cinquante lignes qu'on n'a pas relues
 *     une à une, tout annuler pour une seule qui n'était plus en attente serait
 *     le pire des deux mondes.
 */
final class ActionsMasse
{
    /**
     * Le plafond d'une sélection.
     *
     * Il n'est pas là pour la performance : une sélection de dix mille lignes
     * n'est plus une action de masse, c'est une migration — et une migration se
     * prépare, se sauvegarde et s'explique. Deux cents lignes est ce qu'une
     * personne peut encore relire dans une liste avant de valider.
     */
    public const PLAFOND = 200;

    /**
     * Ce qu'on peut traiter en masse, objet par objet.
     *
     * Déclaré par une méthode et non par une constante, parce que les valeurs
     * — statuts, priorités — et les fermetures viennent des modules.
     *
     * - « lire » rend la ligne dans le périmètre courant, ou null. C'est elle qui
     *   cloisonne : aucune requête de ce module ne touche la base.
     * - « autorise » reçoit la personne et la ligne, et répond pour cette ligne.
     * - « actions » : « faire » appelle la fonction à l'unité et rend faux si
     *   elle a refusé ; « parametre » nomme la valeur attendue, et « valeurs » la
     *   restreint quand elle est énumérée.
     *
     * La liste est courte exprès. Une action de masse n'a de sens que là où la
     * liste est longue et l'action répétitive.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function objets(): array
    {
        return [
            'ticket' => [
                'etiquette' => 'masse.objet.ticket',
                'retour' => '/support',
                'lire' => static fn (int $id): ?array => Support::byId($id),
                'autorise' => static function (array $user, array $ticket): bool {
                    if (in_array($ticket['category'], Support::categoriesFor($user), true)) {
                        return true;
                    }
                    // Un manager encadre : il traite les demandes de son périmètre, hors RH.
                    return Org::isManager((int) $user['id'])
                        && $ticket['category'] !== Support::RESTRICTED_CATEGORY;
                },
                'actions' => [
                    'statut' => [
                        'parametre' => 'statut',
                        'valeurs' => Support::STATUSES,
                        'faire' => static fn (array $ticket, ?string $valeur, array $user): bool
                            => Support::setStatus((int) $ticket['id'], (string) $valeur),
                    ],
                    'priorite' => [
                        'parametre' => 'priorite',
                        'valeurs' => Support::PRIORITIES,
                        'faire' => static fn (array $ticket, ?string $valeur, array $user): bool
                            => Support::setPriority((int) $ticket['id'], (string) $valeur),
                    ],
                    'affecter' => [
                        'parametre' => 'utilisateur',
                        'valeurs' => null,
                        'faire' => static function (array $ticket, ?string $valeur, array $user): bool {
                            Support::assign((int) $ticket['id'], ((int) $valeur) ?: null);
                            return true;
                        },
                    ],
                ],
            ],

            'demande' => [
                'etiquette' => 'masse.objet.demande',
                'retour' => '/rh#demandes',
                'lire' => static fn (int $id): ?array => Hr::requestById($id),
                // Traiter une demande interne appartient aux RH et à
                // l'administration. Le rôle suffit : il n'y a pas de catégorie à
                // comparer, contrairement aux tickets.
                'autorise' => static fn (array $user, array $demande): bool
                    => $user['role'] === 'admin' || (bool) ($user['is_hr'] ?? false),
                'actions' => [
                    'approuver' => [
                        'parametre' => null,
                        'valeurs' => null,
                        'faire' => static fn (array $demande, ?string $valeur, array $user): bool
                            => Hr::approve((int) $demande['id'], (int) $user['id'], '')['ok'] === true,
                    ],
                    'refuser' => [
                        'parametre' => null,
                        'valeurs' => null,
                        'faire' => static fn (array $demande, ?string $valeur, array $user): bool
                            => Hr::reject((int) $demande['id'], (int) $user['id'], '')['ok'] === true,
                    ],
                ],
            ],
        ];
    }

    /** @return list<string> */
    public static function actionsDe(string $objet): array
    {
        $objets = self::objets();
        return isset($objets[$objet]) ? array_keys($objets[$objet]['actions']) : [];
    }

    /**
     * Le paramètre qu'une action attend, ou null.
     *
     * L'écran nomme son champ d'après lui — « valeur_statut », « valeur_utilisateur »
     * — parce que la barre porte un contrôle par action et qu'un seul nom
     * « valeur » les ferait tous partir ensemble. Sans cela, la barre ne
     * marcherait qu'avec du script, et une action de masse qui demande du script
     * est une action de masse qu'on ne peut pas éprouver sans navigateur.
     */
    public static function parametreDe(string $objet, string $action): ?string
    {
        $acte = self::acte($objet, $action);
        return $acte === null ? null : $acte['parametre'];
    }

    /** @return list<string>|null */
    public static function valeursDe(string $objet, string $action): ?array
    {
        $acte = self::acte($objet, $action);
        return $acte === null ? null : $acte['valeurs'];
    }

    public static function retourDe(string $objet): string
    {
        $objets = self::objets();
        return $objets[$objet]['retour'] ?? '/';
    }

    /** @return array<string, mixed>|null */
    private static function acte(string $objet, string $action): ?array
    {
        $objets = self::objets();
        return $objets[$objet]['actions'][$action] ?? null;
    }

    /**
     * Appliquer une action à une sélection.
     *
     * Rend soit un refus global — l'objet, l'action, la sélection ou la valeur ne
     * tiennent pas —, soit le compte rendu ligne à ligne. Jamais une exception :
     * ce qui échoue sur une ligne est une ligne refusée, pas une page d'erreur.
     *
     * @param list<mixed> $ids
     * @param array<string, mixed>|null $user
     * @return array{ok: bool, raison?: string, faites?: list<int>, refusees?: list<array{id: int, raison: string}>}
     */
    public static function appliquer(string $objet, string $action, array $ids, ?string $valeur, ?array $user): array
    {
        $objets = self::objets();
        if (!isset($objets[$objet])) {
            return ['ok' => false, 'raison' => 'masse.err.objet'];
        }
        $acte = self::acte($objet, $action);
        if ($acte === null) {
            return ['ok' => false, 'raison' => 'masse.err.action'];
        }
        if ($user === null) {
            return ['ok' => false, 'raison' => 'masse.err.droit'];
        }

        $liste = [];
        foreach ($ids as $brut) {
            $id = (int) $brut;
            if ($id > 0 && !in_array($id, $liste, true)) {
                $liste[] = $id;
            }
        }
        if ($liste === []) {
            return ['ok' => false, 'raison' => 'masse.err.vide'];
        }
        if (count($liste) > self::PLAFOND) {
            return ['ok' => false, 'raison' => 'masse.err.plafond'];
        }

        if ($acte['parametre'] !== null && ($valeur === null || trim($valeur) === '')) {
            return ['ok' => false, 'raison' => 'masse.err.valeur'];
        }
        if ($acte['valeurs'] !== null && !in_array((string) $valeur, $acte['valeurs'], true)) {
            return ['ok' => false, 'raison' => 'masse.err.valeur'];
        }

        $faites = [];
        $refusees = [];
        foreach ($liste as $id) {
            $ligne = ($objets[$objet]['lire'])($id);
            if ($ligne === null) {
                $refusees[] = ['id' => $id, 'raison' => 'masse.err.introuvable'];
                continue;
            }
            if (!($objets[$objet]['autorise'])($user, $ligne)) {
                $refusees[] = ['id' => $id, 'raison' => 'masse.err.droit'];
                continue;
            }
            try {
                if (($acte['faire'])($ligne, $valeur, $user) === false) {
                    $refusees[] = ['id' => $id, 'raison' => 'masse.err.refus'];
                } else {
                    $faites[] = $id;
                }
            } catch (\Throwable) {
                // Une ligne qui échoue n'emporte pas les autres : c'est tout
                // l'intérêt d'une action de masse sur une liste qu'on n'a pas
                // relue ligne à ligne.
                $refusees[] = ['id' => $id, 'raison' => 'masse.err.refus'];
            }
        }

        return ['ok' => true, 'faites' => $faites, 'refusees' => $refusees];
    }
}
