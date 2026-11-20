<?php

declare(strict_types=1);

namespace App\Api;

use App\Core\Db;
use App\Core\Request;
use App\Modules\Org;

/**
 * Annuaire et organisation.
 *
 * L'API respecte l'annuaire : qui en est retiré n'en sort pas par une autre
 * porte. `directory_hidden` vaut ici exactement ce qu'il vaut à l'écran.
 */
final class Annuaire
{
    private const SELECT = "
        SELECT u.id, u.first_name, u.last_name, u.email, u.grade, u.contract_type, u.contract_end_date,
               d.name AS department, t.name AS team
        FROM users u
        LEFT JOIN departments d ON d.id = u.department_id
        LEFT JOIN teams t ON t.id = u.team_id
        WHERE u.active = 1 AND u.directory_hidden = 0";

    private static function personne(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'prenom' => $r['first_name'],
            'nom' => $r['last_name'],
            'email' => $r['email'],
            'grade' => $r['grade'],
            'contrat' => $r['contract_type'],
            'service' => $r['department'],
            'equipe' => $r['team'],
            'fin_contrat' => $r['contract_end_date'],
        ];
    }

    public static function groupe(): array
    {
        return [
            'cle' => 'annuaire',
            'titre' => 'Annuaire et organisation',
            'resume' => "Qui travaille ici, dans quel service, dans quelle équipe, et qui encadre.",
            'fonctions' => [
                [
                    'methode' => 'GET',
                    'chemin' => '/collaborateurs',
                    'portee' => 'annuaire',
                    'resume' => "La liste des collaborateurs visibles dans l'annuaire.",
                    'details' => "Les comptes désactivés et ceux qui se sont retirés de l'annuaire n'y figurent pas : l'API ne contourne pas un choix fait à l'écran.",
                    'parametres' => [
                        ['nom' => 'limite', 'dans' => 'requete', 'type' => 'entier', 'defaut' => 50, 'description' => 'De 1 à 200.'],
                        ['nom' => 'depuis', 'dans' => 'requete', 'type' => 'entier', 'defaut' => 0, 'description' => 'Rang du premier résultat.'],
                        ['nom' => 'service', 'dans' => 'requete', 'type' => 'entier', 'description' => "N'garder que ce service."],
                        ['nom' => 'recherche', 'dans' => 'requete', 'type' => 'texte', 'description' => 'Nom, prénom ou adresse.'],
                    ],
                    'rend' => ['id', 'prenom', 'nom', 'email', 'grade', 'contrat', 'service', 'equipe', 'fin_contrat'],
                    'faire' => static function (Request $request): array {
                        $page = Aide::page($request);
                        $service = Aide::entier($request->input('service'));
                        $q = Aide::texte(['recherche' => $request->input('recherche')], 'recherche', 60);

                        $filtres = [];
                        $args = [];
                        if ($service !== null) {
                            $filtres[] = 'u.department_id = ?';
                            $args[] = $service;
                        }
                        if ($q !== '') {
                            $filtres[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)';
                            array_push($args, "%$q%", "%$q%", "%$q%");
                        }
                        $where = $filtres === [] ? '' : ' AND ' . implode(' AND ', $filtres);

                        $total = Aide::compter(
                            "SELECT COUNT(*) FROM users u WHERE u.active = 1 AND u.directory_hidden = 0$where",
                            $args
                        );
                        $rows = Db::all(
                            self::SELECT . $where . ' ORDER BY u.last_name LIMIT ? OFFSET ?',
                            [...$args, $page['limit'], $page['offset']]
                        );

                        return Aide::ok(Aide::wrap(array_map(self::personne(...), $rows), $page, $total));
                    },
                ],
                [
                    'methode' => 'GET',
                    'chemin' => '/collaborateurs/{id}',
                    'portee' => 'annuaire',
                    'resume' => 'Une fiche, par son identifiant.',
                    'parametres' => [['nom' => 'id', 'dans' => 'chemin', 'type' => 'entier', 'requis' => true]],
                    'rend' => ['id', 'prenom', 'nom', 'email', 'grade', 'contrat', 'service', 'equipe', 'fin_contrat', 'encadre'],
                    'faire' => static function (Request $request, array $params): array {
                        $id = Aide::entier($params['id'] ?? null);
                        $row = $id === null ? null : Db::get(self::SELECT . ' AND u.id = ?', [$id]);
                        if ($row === null) {
                            return Aide::absent('Collaborateur');
                        }
                        $encadre = Org::scopesManagedBy((int) $row['id']);
                        return Aide::ok(self::personne($row) + [
                            'encadre' => ['services' => $encadre['departments'] ?? [], 'equipes' => $encadre['teams'] ?? []],
                        ]);
                    },
                ],
                [
                    'methode' => 'GET',
                    'chemin' => '/services',
                    'portee' => 'annuaire',
                    'resume' => 'Les services, leur effectif et leurs encadrants.',
                    'rend' => ['id', 'nom', 'effectif', 'equipes', 'encadrants'],
                    'faire' => static function (): array {
                        $rows = array_map(static fn (array $d): array => [
                            'id' => (int) $d['id'],
                            'nom' => $d['name'],
                            'effectif' => (int) ($d['member_count'] ?? 0),
                            'equipes' => (int) ($d['team_count'] ?? 0),
                            'encadrants' => array_map(
                                static fn (array $m): string => trim($m['first_name'] . ' ' . $m['last_name']),
                                Org::managersOf('department', (int) $d['id'])
                            ),
                        ], Org::departments());

                        return Aide::ok(Aide::wrap($rows, ['limit' => Aide::MAX_PAGE, 'offset' => 0]));
                    },
                ],
                [
                    'methode' => 'GET',
                    'chemin' => '/equipes',
                    'portee' => 'annuaire',
                    'resume' => 'Les équipes, leur service de rattachement et leurs encadrants.',
                    'rend' => ['id', 'nom', 'service', 'effectif', 'encadrants'],
                    'faire' => static function (): array {
                        $rows = array_map(static fn (array $t): array => [
                            'id' => (int) $t['id'],
                            'nom' => $t['name'],
                            'service' => $t['department_name'] ?? null,
                            'effectif' => (int) ($t['member_count'] ?? 0),
                            'encadrants' => array_map(
                                static fn (array $m): string => trim($m['first_name'] . ' ' . $m['last_name']),
                                Org::managersOf('team', (int) $t['id'])
                            ),
                        ], Org::teams());

                        return Aide::ok(Aide::wrap($rows, ['limit' => Aide::MAX_PAGE, 'offset' => 0]));
                    },
                ],

                [
                    'methode' => 'GET',
                    'chemin' => '/organigramme',
                    'portee' => 'annuaire',
                    'resume' => "L'organisation entière, services et équipes imbriqués.",
                    'rend' => ['departments', 'orphanTeams', 'unassigned', 'headcount', 'managerCount'],
                    'faire' => static fn (): array => Aide::ok(Org::chart()),
                ],

                // ---------- Écriture ----------

                [
                    'methode' => 'POST',
                    'chemin' => '/services',
                    'portee' => 'annuaire:ecriture',
                    'resume' => 'Crée un service.',
                    'journal' => 'api.service_cree',
                    'parametres' => [
                        ['nom' => 'nom', 'dans' => 'corps', 'type' => 'texte', 'requis' => true, 'description' => 'Le nom du service.'],
                        ['nom' => 'description', 'dans' => 'corps', 'type' => 'texte', 'description' => 'Ce que fait ce service.'],
                    ],
                    'rend' => ['id', 'nom'],
                    'faire' => static function (Request $request): array {
                        $corps = Aide::corps($request);
                        $nom = Aide::texte($corps, 'nom', 120);
                        if ($nom === '') {
                            return Aide::erreur(422, 'Un nom est requis.');
                        }
                        $id = Org::createDepartment($nom, Aide::texte($corps, 'description', 400));
                        return Aide::ok(['id' => $id, 'nom' => $nom], 201);
                    },
                ],
                [
                    'methode' => 'POST',
                    'chemin' => '/equipes',
                    'portee' => 'annuaire:ecriture',
                    'resume' => 'Crée une équipe dans un service.',
                    'journal' => 'api.equipe_creee',
                    'parametres' => [
                        ['nom' => 'nom', 'dans' => 'corps', 'type' => 'texte', 'requis' => true],
                        ['nom' => 'service', 'dans' => 'corps', 'type' => 'entier', 'requis' => true, 'description' => "Le service d'accueil."],
                        ['nom' => 'description', 'dans' => 'corps', 'type' => 'texte', 'description' => 'Ce que fait cette équipe.'],
                    ],
                    'rend' => ['id', 'nom', 'service'],
                    'faire' => static function (Request $request): array {
                        $corps = Aide::corps($request);
                        $nom = Aide::texte($corps, 'nom', 120);
                        $service = Aide::entier($corps['service'] ?? null);

                        if ($nom === '') {
                            return Aide::erreur(422, 'Un nom est requis.');
                        }
                        if ($service === null) {
                            return Aide::erreur(422, 'Un service est requis.');
                        }
                        if (Org::departmentById($service) === null) {
                            return Aide::erreur(422, "Ce service n'existe pas.");
                        }

                        $id = Org::createTeam($nom, $service, Aide::texte($corps, 'description', 400));
                        return Aide::ok(['id' => $id, 'nom' => $nom, 'service' => $service], 201);
                    },
                ],
            ],
        ];
    }
}
