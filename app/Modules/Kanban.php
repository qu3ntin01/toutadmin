<?php

declare(strict_types=1);

namespace App\Modules;

/**
 * La vue kanban : la même liste, rangée en colonnes.
 *
 * Le pendant de « src/kanban.js » de l'édition Node.
 *
 * Ce n'est pas un écran de plus, et c'est tout le propos. Une vue kanban qui
 * aurait ses propres requêtes, ses propres droits et sa propre façon de changer
 * un statut serait un second produit à maintenir, qui divergerait du premier au
 * premier correctif. Celle-ci reprend la liste que l'écran affiche déjà, la
 * répartit en colonnes selon un champ, et déplace une carte en appelant la
 * fonction à l'unité — la même que le formulaire de la fiche.
 *
 * Trois conséquences :
 *
 *   - les colonnes sont les valeurs du statut, dans leur ordre déclaré. Pas de
 *     colonnes réglables : l'ordre d'un statut est une règle du métier, pas une
 *     préférence d'affichage, et une colonne « en attente » placée après « clos »
 *     mentirait sur le cycle ;
 *   - une carte ne se déplace que là où le statut l'autorise, et le droit est
 *     vérifié sur cette ligne-là, comme à l'unité ;
 *   - le déplacement est un POST avec un champ de choix et un bouton. Pas de
 *     glisser-déposer : il demanderait du script pour fonctionner du tout, et une
 *     vue qui ne marche pas sans script n'est pas une vue, c'est une option.
 *
 * Le tableau des tâches d'un projet est antérieur à ce module et garde sa carte,
 * plus riche. Il n'est pas réécrit ici : un écran qui marche ne se refait pas pour
 * l'uniformité.
 */
final class Kanban
{
    /**
     * Ce qui se range en colonnes, objet par objet.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function objets(): array
    {
        return [
            'ticket' => [
                'etiquette' => 'kanban.objet.ticket',
                'colonnes' => Support::STATUSES,
                'lister' => static fn (array $filtres): array => Support::list($filtres),
                'colonneDe' => static fn (array $ticket): string => (string) $ticket['status'],
                'carte' => static fn (array $ticket): array => [
                    'id' => (int) $ticket['id'],
                    'lien' => '/support/tickets/' . (int) $ticket['id'],
                    'titre' => (string) $ticket['subject'],
                    'reference' => (string) ($ticket['reference'] ?? ''),
                    'lignes' => array_values(array_filter([
                        trim(($ticket['requester_first_name'] ?? '') . ' ' . ($ticket['requester_last_name'] ?? '')),
                        trim(($ticket['assignee_first_name'] ?? '') . ' ' . ($ticket['assignee_last_name'] ?? '')),
                        (string) $ticket['category'],
                    ], static fn (string $v): bool => $v !== '')),
                    'alerte' => Support::isOverdue($ticket) ? 'kanban.overdue' : null,
                    'etiquette' => in_array($ticket['priority'], ['Haute', 'Critique'], true)
                        ? (string) $ticket['priority'] : null,
                ],
                'lire' => static fn (int $id): ?array => Support::byId($id),
                'autorise' => static function (array $user, array $ticket): bool {
                    if (in_array($ticket['category'], Support::categoriesFor($user), true)) {
                        return true;
                    }
                    return Org::isManager((int) $user['id'])
                        && $ticket['category'] !== Support::RESTRICTED_CATEGORY;
                },
                'deplacer' => static fn (array $ticket, string $colonne): bool
                    => Support::setStatus((int) $ticket['id'], $colonne),
            ],
        ];
    }

    /** @return list<string>|null */
    public static function colonnesDe(string $objet): ?array
    {
        $objets = self::objets();
        return $objets[$objet]['colonnes'] ?? null;
    }

    /**
     * Le tableau : les colonnes dans leur ordre, chacune avec ses cartes.
     *
     * Une ligne dont le statut n'est pas une colonne déclarée n'est pas perdue :
     * elle est rendue à part, sous « hors colonnes ». Un statut ajouté à la base
     * sans être déclaré ferait sinon disparaître des lignes de l'écran sans un
     * mot, et c'est le genre de défaut qu'on ne remarque qu'en comptant.
     *
     * @param array<string, mixed> $filtres
     * @return array{objet: string, colonnes: list<array{valeur: string, cartes: list<array<string, mixed>>}>, horsColonnes: list<array<string, mixed>>}|null
     */
    public static function tableau(string $objet, array $filtres = []): ?array
    {
        $objets = self::objets();
        if (!isset($objets[$objet])) {
            return null;
        }
        $def = $objets[$objet];

        $colonnes = [];
        foreach ($def['colonnes'] as $valeur) {
            $colonnes[$valeur] = ['valeur' => $valeur, 'cartes' => []];
        }
        $horsColonnes = [];

        foreach (($def['lister'])($filtres) as $ligne) {
            $ou = ($def['colonneDe'])($ligne);
            if (isset($colonnes[$ou])) {
                $colonnes[$ou]['cartes'][] = ($def['carte'])($ligne);
            } else {
                $horsColonnes[] = ($def['carte'])($ligne);
            }
        }

        return ['objet' => $objet, 'colonnes' => array_values($colonnes), 'horsColonnes' => $horsColonnes];
    }

    /**
     * Déplacer une ligne dans une autre colonne.
     *
     * Mêmes refus que l'action à l'unité, et dans le même ordre : la colonne doit
     * exister, la ligne doit se lire dans le périmètre, et le droit se vérifie sur
     * cette ligne.
     *
     * @param array<string, mixed>|null $user
     * @return array{ok: bool, raison?: string, id?: int, colonne?: string}
     */
    public static function deplacer(string $objet, int $id, string $colonne, ?array $user): array
    {
        $objets = self::objets();
        if (!isset($objets[$objet])) {
            return ['ok' => false, 'raison' => 'kanban.err.objet'];
        }
        $def = $objets[$objet];
        if (!in_array($colonne, $def['colonnes'], true)) {
            return ['ok' => false, 'raison' => 'kanban.err.colonne'];
        }
        if ($user === null) {
            return ['ok' => false, 'raison' => 'kanban.err.droit'];
        }

        $ligne = ($def['lire'])($id);
        // Hors périmètre, hors du tableau : le même refus qu'une ligne effacée.
        if ($ligne === null) {
            return ['ok' => false, 'raison' => 'kanban.err.introuvable'];
        }
        if (!($def['autorise'])($user, $ligne)) {
            return ['ok' => false, 'raison' => 'kanban.err.droit'];
        }
        if (($def['deplacer'])($ligne, $colonne) === false) {
            return ['ok' => false, 'raison' => 'kanban.err.refus'];
        }
        return ['ok' => true, 'id' => (int) $ligne['id'], 'colonne' => $colonne];
    }
}
