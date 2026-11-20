<?php

declare(strict_types=1);

namespace App\Api;

use App\Core\Db;
use App\Core\Request;
use App\Modules\Announcements;
use App\Modules\Assets;
use App\Modules\Rooms;

/**
 * Documents et bases communes : annonces, salles, matériel.
 *
 * Ce qui est déposé dans le coffre-fort, le parapheur ou la base documentaire
 * n'est pas ici, et n'y sera pas : ces pièces portent des contrats, des fiches
 * de paie et des pièces d'identité. Elles se consultent derrière une session,
 * un rôle et une trace nominative, pas derrière un jeton posé dans un fichier.
 */
final class Documents
{
    private static function nomDe(?string $prenom, ?string $nom): ?string
    {
        $entier = trim(($prenom ?? '') . ' ' . ($nom ?? ''));
        return $entier === '' ? null : $entier;
    }

    public static function groupe(): array
    {
        return [
            'cle' => 'documents',
            'titre' => 'Documents et bases communes',
            'resume' => 'Annonces, salles et réservations, matériel et affectations.',
            'fonctions' => [
                [
                    'methode' => 'GET',
                    'chemin' => '/annonces',
                    'portee' => 'documents',
                    'resume' => 'Les annonces publiées, de la plus récente à la plus ancienne.',
                    'parametres' => [
                        ['nom' => 'limite', 'dans' => 'requete', 'type' => 'entier', 'defaut' => 50],
                        ['nom' => 'depuis', 'dans' => 'requete', 'type' => 'entier', 'defaut' => 0],
                    ],
                    'rend' => ['id', 'titre', 'corps', 'portee', 'portee_nom', 'auteur', 'publiee_le'],
                    'faire' => static function (Request $request): array {
                        $page = Aide::page($request);
                        // Le jeton n'a pas de session : hors contexte, « courant »
                        // rend la société qui mène. Voir Societes::perimetreParDefaut().
                        $toutes = Announcements::all(500);
                        $tranche = array_slice($toutes, $page['offset'], $page['limit']);

                        return Aide::ok(Aide::wrap(array_map(static fn (array $a): array => [
                            'id' => (int) $a['id'], 'titre' => $a['title'], 'corps' => $a['body'],
                            'portee' => $a['scope'], 'portee_nom' => $a['scope_name'] ?? null,
                            'auteur' => self::nomDe($a['first_name'] ?? null, $a['last_name'] ?? null),
                            'publiee_le' => $a['created_at'],
                        ], $tranche), $page, count($toutes)));
                    },
                ],
                [
                    'methode' => 'GET',
                    'chemin' => '/salles',
                    'portee' => 'documents',
                    'resume' => 'Les salles réservables.',
                    'rend' => ['id', 'nom', 'lieu', 'capacite', 'active'],
                    'faire' => static function (): array {
                        $rows = array_map(static fn (array $r): array => [
                            'id' => (int) $r['id'], 'nom' => $r['name'], 'lieu' => $r['location'] ?? null,
                            'capacite' => $r['capacity'] === null ? null : (int) $r['capacity'],
                            'active' => (bool) $r['active'],
                        ], Rooms::all());

                        return Aide::ok(Aide::wrap($rows, ['limit' => Aide::MAX_PAGE, 'offset' => 0]));
                    },
                ],
                [
                    'methode' => 'GET',
                    'chemin' => '/reservations',
                    'portee' => 'documents',
                    'resume' => 'Les réservations de salles, sur une période.',
                    'parametres' => [
                        ['nom' => 'du', 'dans' => 'requete', 'type' => 'date'],
                        ['nom' => 'au', 'dans' => 'requete', 'type' => 'date'],
                        ['nom' => 'salle', 'dans' => 'requete', 'type' => 'entier'],
                    ],
                    'rend' => ['id', 'salle', 'salle_id', 'lieu', 'titre', 'le', 'de', 'a', 'personne'],
                    'faire' => static function (Request $request): array {
                        $liste = Rooms::bookings(
                            Aide::date($request->input('du')) ?: null,
                            Aide::date($request->input('au')) ?: null,
                            Aide::entier($request->input('salle'))
                        );

                        return Aide::ok(Aide::wrap(array_map(static fn (array $b): array => [
                            'id' => (int) $b['id'], 'salle' => $b['room_name'] ?? null,
                            'salle_id' => (int) $b['room_id'], 'lieu' => $b['location'] ?? null,
                            'titre' => $b['title'], 'le' => $b['booking_date'],
                            'de' => $b['start_time'], 'a' => $b['end_time'],
                            'personne' => self::nomDe($b['first_name'] ?? null, $b['last_name'] ?? null),
                        ], $liste), ['limit' => Aide::MAX_PAGE, 'offset' => 0], count($liste)));
                    },
                ],
                [
                    'methode' => 'GET',
                    'chemin' => '/materiel',
                    'portee' => 'documents',
                    'resume' => "Le matériel, son état et qui le détient.",
                    'rend' => ['id', 'nom', 'categorie', 'reference', 'statut', 'detenteur', 'detenteur_id'],
                    'faire' => static function (): array {
                        $rows = array_map(static fn (array $a): array => [
                            'id' => (int) $a['id'], 'nom' => $a['name'], 'categorie' => $a['category'] ?? null,
                            'reference' => $a['serial'] ?? ($a['reference'] ?? ''), 'statut' => $a['status'],
                            'detenteur' => self::nomDe($a['holder_first_name'] ?? null, $a['holder_last_name'] ?? null),
                            'detenteur_id' => $a['holder_id'] === null ? null : (int) $a['holder_id'],
                        ], Assets::all());

                        return Aide::ok(Aide::wrap($rows, ['limit' => Aide::MAX_PAGE, 'offset' => 0]));
                    },
                ],

                // ---------- Écriture ----------

                [
                    'methode' => 'POST',
                    'chemin' => '/annonces',
                    'portee' => 'documents:ecriture',
                    'resume' => 'Publie une annonce.',
                    'details' => "L'auteur enregistré est le jeton, non une personne : une annonce signée d'un nom qui n'a rien écrit serait un faux.",
                    'journal' => 'api.annonce_publiee',
                    'parametres' => [
                        ['nom' => 'titre', 'dans' => 'corps', 'type' => 'texte', 'requis' => true],
                        ['nom' => 'corps', 'dans' => 'corps', 'type' => 'texte', 'requis' => true],
                        ['nom' => 'portee', 'dans' => 'corps', 'type' => 'texte', 'description' => 'company, department ou team. Par défaut : company.'],
                        ['nom' => 'portee_id', 'dans' => 'corps', 'type' => 'entier', 'description' => "Le service ou l'équipe visé."],
                    ],
                    'rend' => ['id', 'titre', 'portee'],
                    'faire' => static function (Request $request): array {
                        $corps = Aide::corps($request);
                        $titre = Aide::texte($corps, 'titre', 200);
                        $texte = Aide::texte($corps, 'corps', 8000);
                        $portee = Aide::texte($corps, 'portee', 20) ?: 'company';

                        if ($titre === '') {
                            return Aide::erreur(422, 'Un titre est requis.');
                        }
                        if ($texte === '') {
                            return Aide::erreur(422, 'Un corps est requis.');
                        }
                        if (!in_array($portee, Announcements::SCOPES, true)) {
                            return Aide::erreur(422, 'Portée inconnue.', ['attendus' => Announcements::SCOPES]);
                        }

                        $porteeId = Aide::entier($corps['portee_id'] ?? null);
                        if ($portee !== 'company') {
                            if ($porteeId === null) {
                                return Aide::erreur(422, 'Cette portée demande un identifiant.');
                            }
                            $table = $portee === 'team' ? 'teams' : 'departments';
                            if (Db::get("SELECT id FROM $table WHERE id = ?", [$porteeId]) === null) {
                                return Aide::erreur(422, 'Cette portée ne désigne rien.');
                            }
                        }

                        $id = Announcements::create(null, $portee, $porteeId, $titre, $texte);
                        return Aide::ok(['id' => $id, 'titre' => $titre, 'portee' => $portee], 201);
                    },
                ],
                [
                    'methode' => 'POST',
                    'chemin' => '/reservations',
                    'portee' => 'documents:ecriture',
                    'resume' => 'Réserve une salle.',
                    'details' => "Le chevauchement est refusé par la même règle qu'à l'écran, et le refus dit qui occupe déjà le créneau.",
                    'journal' => 'api.salle_reservee',
                    'parametres' => [
                        ['nom' => 'salle', 'dans' => 'corps', 'type' => 'entier', 'requis' => true],
                        ['nom' => 'personne', 'dans' => 'corps', 'type' => 'entier', 'requis' => true],
                        ['nom' => 'titre', 'dans' => 'corps', 'type' => 'texte', 'requis' => true],
                        ['nom' => 'le', 'dans' => 'corps', 'type' => 'date', 'requis' => true],
                        ['nom' => 'de', 'dans' => 'corps', 'type' => 'texte', 'requis' => true, 'description' => 'HH:MM'],
                        ['nom' => 'a', 'dans' => 'corps', 'type' => 'texte', 'requis' => true, 'description' => 'HH:MM'],
                    ],
                    'rend' => ['salle', 'le', 'de', 'a'],
                    'faire' => static function (Request $request): array {
                        $corps = Aide::corps($request);
                        $salle = Aide::entier($corps['salle'] ?? null);
                        $personne = Aide::entier($corps['personne'] ?? null);
                        $titre = Aide::texte($corps, 'titre', 160);
                        $le = Aide::date($corps['le'] ?? null);
                        $de = Aide::texte($corps, 'de', 5);
                        $a = Aide::texte($corps, 'a', 5);

                        if ($salle === null || $personne === null) {
                            return Aide::erreur(422, 'Une salle et une personne sont requises.');
                        }
                        if ($titre === '') {
                            return Aide::erreur(422, 'Un titre est requis.');
                        }
                        if ($le === '') {
                            return Aide::erreur(422, 'Une date au format AAAA-MM-JJ est requise.');
                        }

                        $verdict = Rooms::book($salle, $personne, $titre, $le, $de, $a);
                        if (empty($verdict['ok'])) {
                            $motifs = [
                                'not-found' => [404, 'Salle introuvable.'],
                                'inactive' => [409, "Cette salle n'est plus réservable."],
                                'bad-time' => [422, "Les horaires s'écrivent HH:MM."],
                                'bad-range' => [422, 'La fin précède le début.'],
                                'clash' => [409, 'Ce créneau est déjà pris.'],
                            ];
                            [$code, $message] = $motifs[$verdict['reason'] ?? ''] ?? [409, 'Réservation refusée.'];
                            $extra = [];
                            if (isset($verdict['clash'])) {
                                $extra = [
                                    'occupe_par' => self::nomDe($verdict['clash']['first_name'] ?? null, $verdict['clash']['last_name'] ?? null),
                                    'de' => $verdict['clash']['start_time'] ?? null,
                                    'a' => $verdict['clash']['end_time'] ?? null,
                                ];
                            }
                            return Aide::erreur($code, $message, $extra);
                        }

                        return Aide::ok(['salle' => $salle, 'le' => $le, 'de' => $de, 'a' => $a], 201);
                    },
                ],
                [
                    'methode' => 'POST',
                    'chemin' => '/materiel/{id}/affectation',
                    'portee' => 'documents:ecriture',
                    'resume' => 'Affecte un matériel à un salarié.',
                    'journal' => 'api.materiel_affecte',
                    'parametres' => [
                        ['nom' => 'id', 'dans' => 'chemin', 'type' => 'entier', 'requis' => true],
                        ['nom' => 'personne', 'dans' => 'corps', 'type' => 'entier', 'requis' => true],
                        ['nom' => 'note', 'dans' => 'corps', 'type' => 'texte'],
                    ],
                    'rend' => ['materiel', 'personne', 'statut'],
                    'faire' => static function (Request $request, array $params): array {
                        $id = Aide::entier($params['id'] ?? null);
                        $corps = Aide::corps($request);
                        $personne = Aide::entier($corps['personne'] ?? null);

                        if ($id === null) {
                            return Aide::absent('Matériel');
                        }
                        if ($personne === null) {
                            return Aide::erreur(422, 'Une personne est requise.');
                        }

                        $verdict = Assets::assign($id, $personne, Aide::texte($corps, 'note', 400));
                        if (empty($verdict['ok'])) {
                            $motifs = [
                                'not-found' => [404, 'Matériel introuvable.'],
                                'retired' => [409, 'Ce matériel est réformé.'],
                                'already-assigned' => [409, 'Ce matériel est déjà affecté.'],
                                'no-employee' => [422, "Ce salarié n'existe pas."],
                            ];
                            [$code, $message] = $motifs[$verdict['reason'] ?? ''] ?? [409, 'Affectation refusée.'];
                            return Aide::erreur($code, $message);
                        }

                        return Aide::ok(['materiel' => $id, 'personne' => $personne, 'statut' => 'Affecté'], 201);
                    },
                ],
            ],
        ];
    }
}
