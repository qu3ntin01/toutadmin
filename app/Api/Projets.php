<?php

declare(strict_types=1);

namespace App\Api;

use App\Core\Db;
use App\Core\Request;
use App\Modules\Projects;
use App\Modules\Societes;

/** Projets, tâches, jalons, temps passé. */
final class Projets
{
    /**
     * Le périmètre courant.
     *
     * Le jeton n'a pas de session : hors contexte, « courant » rend la société
     * qui mène. Voir Societes::perimetreParDefaut().
     */
    private static function ici(): ?int
    {
        return Societes::courant();
    }

    private static function nomDe(?string $prenom, ?string $nom): ?string
    {
        $entier = trim(($prenom ?? '') . ' ' . ($nom ?? ''));
        return $entier === '' ? null : $entier;
    }

    /**
     * Les compteurs d'une liste de projets, en une requête.
     *
     * Projects::list() ne les porte pas. Les demander projet par projet aurait
     * fait cent cinquante allers-retours pour une page de cinquante.
     */
    private static function compteurs(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $trous = implode(',', array_fill(0, count($ids), '?'));
        $rows = Db::all(
            "SELECT p.id,
                    (SELECT COUNT(*) FROM project_tasks t WHERE t.project_id = p.id) AS taches,
                    (SELECT COUNT(*) FROM project_tasks t WHERE t.project_id = p.id AND t.status = 'Terminée') AS faites,
                    (SELECT COALESCE(SUM(hours), 0) FROM project_time pt WHERE pt.project_id = p.id) AS heures
             FROM projects p WHERE p.company_id = ? AND p.id IN ($trous)",
            [self::ici(), ...$ids]
        );
        $par = [];
        foreach ($rows as $row) {
            $par[(int) $row['id']] = $row;
        }
        return $par;
    }

    private static function projet(array $p, ?array $compte): array
    {
        return [
            'id' => (int) $p['id'],
            'code' => $p['code'],
            'nom' => $p['name'],
            'statut' => $p['status'],
            'budget' => $p['budget_amount'] === null ? null : (float) $p['budget_amount'],
            'taux_horaire' => $p['hourly_rate'] === null ? null : (float) $p['hourly_rate'],
            'debut' => $p['start_date'],
            'echeance' => $p['due_date'],
            'responsable' => self::nomDe($p['lead_first_name'] ?? null, $p['lead_last_name'] ?? null),
            'tiers' => $p['partner_name'] ?? null,
            'taches' => (int) ($compte['taches'] ?? 0),
            'taches_terminees' => (int) ($compte['faites'] ?? 0),
            'heures' => (float) ($compte['heures'] ?? 0),
        ];
    }

    public static function groupe(): array
    {
        return [
            'cle' => 'projets',
            'titre' => 'Projets',
            'resume' => 'Projets, tâches, jalons, temps passé et rentabilité.',
            'fonctions' => [
                [
                    'methode' => 'GET',
                    'chemin' => '/projets',
                    'portee' => 'projets',
                    'resume' => 'Les projets en cours.',
                    'parametres' => [
                        ['nom' => 'statut', 'dans' => 'requete', 'type' => 'texte', 'description' => 'Cadrage, En cours, Livré…'],
                        ['nom' => 'archives', 'dans' => 'requete', 'type' => 'booleen', 'description' => 'À 1, inclut les projets archivés.'],
                        ['nom' => 'limite', 'dans' => 'requete', 'type' => 'entier', 'defaut' => 50],
                        ['nom' => 'depuis', 'dans' => 'requete', 'type' => 'entier', 'defaut' => 0],
                    ],
                    'rend' => ['id', 'code', 'nom', 'statut', 'budget', 'taux_horaire', 'debut', 'echeance', 'responsable', 'tiers', 'taches', 'taches_terminees', 'heures'],
                    'faire' => static function (Request $request): array {
                        $page = Aide::page($request);
                        $statut = $request->input('statut');

                        $tous = Projects::list($request->input('archives') === '1');
                        if ($statut !== '') {
                            $tous = array_values(array_filter($tous, static fn (array $p): bool => $p['status'] === $statut));
                        }
                        $tranche = array_slice($tous, $page['offset'], $page['limit']);
                        $compte = self::compteurs(array_map(static fn (array $p): int => (int) $p['id'], $tranche));

                        return Aide::ok(Aide::wrap(array_map(
                            static fn (array $p): array => self::projet($p, $compte[(int) $p['id']] ?? null),
                            $tranche
                        ), $page, count($tous)));
                    },
                ],
                [
                    'methode' => 'GET',
                    'chemin' => '/projets/{id}',
                    'portee' => 'projets',
                    'resume' => 'Un projet, avec ses membres, ses jalons et sa rentabilité.',
                    'parametres' => [['nom' => 'id', 'dans' => 'chemin', 'type' => 'entier', 'requis' => true]],
                    'rend' => ['id', 'code', 'nom', 'statut', 'budget', 'taux_horaire', 'debut', 'echeance', 'responsable', 'tiers', 'taches', 'taches_terminees', 'heures', 'membres', 'jalons', 'rentabilite'],
                    'faire' => static function (Request $request, array $params): array {
                        $id = Aide::entier($params['id'] ?? null);
                        $p = $id === null ? null : Projects::byId($id);
                        if ($p === null) {
                            return Aide::absent('Projet');
                        }

                        $compte = self::compteurs([$id]);
                        return Aide::ok(self::projet($p, $compte[$id] ?? null) + [
                            'membres' => array_map(static fn (array $m): array => [
                                'id' => (int) $m['user_id'],
                                'nom' => self::nomDe($m['first_name'] ?? null, $m['last_name'] ?? null),
                                'role' => $m['role'] ?? '',
                            ], Projects::members($id)),
                            'jalons' => Projects::milestones($id),
                            'rentabilite' => Projects::profitability($p),
                        ]);
                    },
                ],
                [
                    'methode' => 'GET',
                    'chemin' => '/projets/{id}/taches',
                    'portee' => 'projets',
                    'resume' => "Les tâches d'un projet.",
                    'parametres' => [
                        ['nom' => 'id', 'dans' => 'chemin', 'type' => 'entier', 'requis' => true],
                        ['nom' => 'statut', 'dans' => 'requete', 'type' => 'texte', 'description' => 'À faire, En cours, Terminée…'],
                    ],
                    'rend' => ['id', 'titre', 'statut', 'priorite', 'assignee', 'echeance', 'estimation'],
                    'faire' => static function (Request $request, array $params): array {
                        $id = Aide::entier($params['id'] ?? null);
                        if ($id === null || Projects::byId($id) === null) {
                            return Aide::absent('Projet');
                        }

                        $statut = $request->input('statut');
                        $taches = Projects::tasks($id);
                        if ($statut !== '') {
                            $taches = array_values(array_filter($taches, static fn (array $t): bool => $t['status'] === $statut));
                        }

                        return Aide::ok(Aide::wrap(array_map(static fn (array $t): array => [
                            'id' => (int) $t['id'], 'titre' => $t['title'], 'statut' => $t['status'],
                            'priorite' => $t['priority'],
                            'assignee' => self::nomDe($t['first_name'] ?? null, $t['last_name'] ?? null),
                            'echeance' => $t['due_date'],
                            'estimation' => $t['estimate_hours'] === null ? null : (float) $t['estimate_hours'],
                        ], $taches), ['limit' => Aide::MAX_PAGE, 'offset' => 0], count($taches)));
                    },
                ],
                [
                    'methode' => 'GET',
                    'chemin' => '/projets/{id}/temps',
                    'portee' => 'projets',
                    'resume' => 'Le temps passé sur un projet, par membre.',
                    'parametres' => [['nom' => 'id', 'dans' => 'chemin', 'type' => 'entier', 'requis' => true]],
                    'rend' => ['total', 'par_membre', 'saisies'],
                    'faire' => static function (Request $request, array $params): array {
                        $id = Aide::entier($params['id'] ?? null);
                        if ($id === null || Projects::byId($id) === null) {
                            return Aide::absent('Projet');
                        }

                        $parMembre = array_map(static fn (array $m): array => [
                            'id' => (int) $m['id'],
                            'nom' => self::nomDe($m['first_name'] ?? null, $m['last_name'] ?? null),
                            'heures' => (float) $m['hours'],
                        ], Projects::timeByMember($id));

                        return Aide::ok([
                            'total' => array_sum(array_column($parMembre, 'heures')),
                            'par_membre' => $parMembre,
                            'saisies' => array_map(static fn (array $e): array => [
                                'le' => $e['spent_on'], 'heures' => (float) $e['hours'],
                                'personne' => self::nomDe($e['first_name'] ?? null, $e['last_name'] ?? null),
                                'tache' => $e['task_title'] ?? null, 'facturable' => (bool) $e['billable'],
                            ], Projects::timeEntries($id)),
                        ]);
                    },
                ],

                // ---------- Écriture ----------

                [
                    'methode' => 'POST',
                    'chemin' => '/projets',
                    'portee' => 'projets:ecriture',
                    'resume' => 'Crée un projet.',
                    'journal' => 'api.projet_cree',
                    'parametres' => [
                        ['nom' => 'nom', 'dans' => 'corps', 'type' => 'texte', 'requis' => true],
                        ['nom' => 'code', 'dans' => 'corps', 'type' => 'texte'],
                        ['nom' => 'statut', 'dans' => 'corps', 'type' => 'texte', 'description' => 'Par défaut : Cadrage.'],
                        ['nom' => 'debut', 'dans' => 'corps', 'type' => 'date'],
                        ['nom' => 'echeance', 'dans' => 'corps', 'type' => 'date'],
                        ['nom' => 'budget', 'dans' => 'corps', 'type' => 'nombre'],
                        ['nom' => 'tiers', 'dans' => 'corps', 'type' => 'entier'],
                    ],
                    'rend' => ['id', 'nom', 'statut'],
                    'faire' => static function (Request $request): array {
                        $corps = Aide::corps($request);
                        $nom = Aide::texte($corps, 'nom', 160);
                        if ($nom === '') {
                            return Aide::erreur(422, 'Un nom est requis.');
                        }

                        $statut = Aide::texte($corps, 'statut', 30);
                        if ($statut !== '' && !in_array($statut, Projects::STATUSES, true)) {
                            return Aide::erreur(422, 'Statut inconnu.', ['attendus' => Projects::STATUSES]);
                        }

                        $budget = null;
                        if (isset($corps['budget']) && $corps['budget'] !== '') {
                            if (!is_numeric($corps['budget'])) {
                                return Aide::erreur(422, 'Budget illisible.');
                            }
                            $budget = (float) $corps['budget'];
                        }

                        $id = Projects::create([
                            'name' => $nom, 'code' => Aide::texte($corps, 'code', 40),
                            'status' => $statut !== '' ? $statut : 'Cadrage',
                            'startDate' => Aide::date($corps['debut'] ?? null) ?: null,
                            'dueDate' => Aide::date($corps['echeance'] ?? null) ?: null,
                            'budgetAmount' => $budget, 'partnerId' => Aide::entier($corps['tiers'] ?? null),
                            'description' => Aide::texte($corps, 'description', 2000),
                        ]);

                        return Aide::ok(['id' => $id, 'nom' => $nom, 'statut' => $statut !== '' ? $statut : 'Cadrage'], 201);
                    },
                ],
                [
                    'methode' => 'POST',
                    'chemin' => '/projets/{id}/taches',
                    'portee' => 'projets:ecriture',
                    'resume' => 'Ajoute une tâche à un projet.',
                    'journal' => 'api.tache_creee',
                    'parametres' => [
                        ['nom' => 'id', 'dans' => 'chemin', 'type' => 'entier', 'requis' => true],
                        ['nom' => 'titre', 'dans' => 'corps', 'type' => 'texte', 'requis' => true],
                        ['nom' => 'assignee', 'dans' => 'corps', 'type' => 'entier', 'description' => "L'identifiant du responsable."],
                        ['nom' => 'priorite', 'dans' => 'corps', 'type' => 'texte'],
                        ['nom' => 'echeance', 'dans' => 'corps', 'type' => 'date'],
                        ['nom' => 'estimation', 'dans' => 'corps', 'type' => 'nombre', 'description' => 'En heures.'],
                    ],
                    'rend' => ['id', 'titre', 'statut'],
                    'faire' => static function (Request $request, array $params): array {
                        $projet = Aide::entier($params['id'] ?? null);
                        if ($projet === null || Projects::byId($projet) === null) {
                            return Aide::absent('Projet');
                        }

                        $corps = Aide::corps($request);
                        $titre = Aide::texte($corps, 'titre', 200);
                        if ($titre === '') {
                            return Aide::erreur(422, 'Un titre est requis.');
                        }

                        $priorite = Aide::texte($corps, 'priorite', 30);
                        if ($priorite !== '' && !in_array($priorite, Projects::PRIORITIES, true)) {
                            return Aide::erreur(422, 'Priorité inconnue.', ['attendus' => Projects::PRIORITIES]);
                        }

                        $id = Projects::createTask([
                            'projectId' => $projet, 'title' => $titre,
                            'description' => Aide::texte($corps, 'description', 2000),
                            'assigneeId' => Aide::entier($corps['assignee'] ?? null),
                            'priority' => $priorite !== '' ? $priorite : 'Normale',
                            'dueDate' => Aide::date($corps['echeance'] ?? null) ?: null,
                            'estimateHours' => is_numeric($corps['estimation'] ?? null) ? (float) $corps['estimation'] : null,
                        ]);

                        return Aide::ok(['id' => $id, 'titre' => $titre, 'statut' => 'À faire'], 201);
                    },
                ],
                [
                    'methode' => 'POST',
                    'chemin' => '/projets/{id}/temps',
                    'portee' => 'projets:ecriture',
                    'resume' => 'Pointe du temps sur un projet.',
                    'details' => "Le plafond par saisie est celui de l'écran : au-delà, c'est une erreur de frappe plus souvent qu'une journée de travail.",
                    'journal' => 'api.temps_pointe',
                    'parametres' => [
                        ['nom' => 'id', 'dans' => 'chemin', 'type' => 'entier', 'requis' => true],
                        ['nom' => 'personne', 'dans' => 'corps', 'type' => 'entier', 'requis' => true],
                        ['nom' => 'le', 'dans' => 'corps', 'type' => 'date', 'requis' => true],
                        ['nom' => 'heures', 'dans' => 'corps', 'type' => 'nombre', 'requis' => true],
                        ['nom' => 'tache', 'dans' => 'corps', 'type' => 'entier'],
                        ['nom' => 'note', 'dans' => 'corps', 'type' => 'texte'],
                        ['nom' => 'facturable', 'dans' => 'corps', 'type' => 'booleen', 'description' => 'Vrai par défaut.'],
                    ],
                    'rend' => ['projet', 'heures', 'total'],
                    'faire' => static function (Request $request, array $params): array {
                        $projet = Aide::entier($params['id'] ?? null);
                        if ($projet === null || Projects::byId($projet) === null) {
                            return Aide::absent('Projet');
                        }

                        $corps = Aide::corps($request);
                        $personne = Aide::entier($corps['personne'] ?? null);
                        $le = Aide::date($corps['le'] ?? null);
                        $heures = is_numeric($corps['heures'] ?? null) ? (float) $corps['heures'] : 0.0;

                        if ($personne === null) {
                            return Aide::erreur(422, 'Une personne est requise.');
                        }
                        if ($le === '') {
                            return Aide::erreur(422, 'Une date au format AAAA-MM-JJ est requise.');
                        }
                        if (Db::get('SELECT id FROM users WHERE id = ? AND active = 1', [$personne]) === null) {
                            return Aide::absent('Personne');
                        }

                        $verdict = Projects::logTime([
                            'projectId' => $projet, 'taskId' => Aide::entier($corps['tache'] ?? null),
                            'userId' => $personne, 'spentOn' => $le, 'hours' => $heures,
                            'note' => Aide::texte($corps, 'note', 400),
                            'billable' => !array_key_exists('facturable', $corps) || (bool) $corps['facturable'],
                        ]);
                        if (empty($verdict['ok'])) {
                            return Aide::erreur(422, (string) ($verdict['message'] ?? 'Saisie refusée.'));
                        }

                        $total = array_sum(array_map(
                            static fn (array $m): float => (float) $m['hours'],
                            Projects::timeByMember($projet)
                        ));

                        return Aide::ok(['projet' => $projet, 'heures' => $heures, 'total' => $total], 201);
                    },
                ],
            ],
        ];
    }
}
