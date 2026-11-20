<?php

declare(strict_types=1);

namespace App\Api;

use App\Core\Db;
use App\Core\Request;
use App\Modules\Hr;
use App\Modules\Societes;

/**
 * Ressources humaines.
 *
 * Deux choses ne sortent pas d'ici, et c'est délibéré :
 *
 *   — le **motif** d'une absence. Il regarde la personne et son responsable,
 *     pas l'outil de planification qui lit l'API ;
 *   — les **bulletins de paie**. Un jeton qui circule dans un fichier de
 *     configuration n'a pas à donner les salaires de l'entreprise. Ils restent
 *     derrière l'écran, qui demande une session, un rôle et une trace.
 */
final class Rh
{
    private const APPROUVEE = 'Approuvée';

    public static function groupe(): array
    {
        return [
            'cle' => 'rh',
            'titre' => 'Ressources humaines',
            'resume' => 'Absences, demandes, effectifs. Sans les motifs et sans la paie.',
            'fonctions' => [
                [
                    'methode' => 'GET',
                    'chemin' => '/absences',
                    'portee' => 'rh',
                    'resume' => "Les absences approuvées, à partir d'une date.",
                    'details' => "Le motif n'est pas rendu : il n'a pas à sortir de l'entreprise.",
                    'parametres' => [
                        ['nom' => 'depuis_le', 'dans' => 'requete', 'type' => 'date', 'description' => 'Ne garder que ce qui finit après cette date.'],
                        ['nom' => 'limite', 'dans' => 'requete', 'type' => 'entier', 'defaut' => 50],
                        ['nom' => 'depuis', 'dans' => 'requete', 'type' => 'entier', 'defaut' => 0],
                    ],
                    'rend' => ['id', 'type', 'du', 'au', 'jours', 'personne', 'personne_id'],
                    'faire' => static function (Request $request): array {
                        $page = Aide::page($request);
                        $depuis = Aide::date($request->input('depuis_le')) ?: '0000-01-01';

                        /* Le jeton n'a pas de session : hors contexte, « courant »
                           rend la société qui mène. Voir Societes::perimetreParDefaut(). */
                        $total = Aide::compter(
                            'SELECT COUNT(*) FROM hr_requests
                             WHERE company_id = ? AND status = ? AND end_date >= ?',
                            [Societes::courant(), self::APPROUVEE, $depuis]
                        );
                        $rows = Db::all(
                            "SELECT r.id, r.type, r.start_date, r.end_date, r.days, r.employee_id,
                                    u.first_name, u.last_name
                             FROM hr_requests r JOIN users u ON u.id = r.employee_id
                             WHERE r.company_id = ? AND r.status = ? AND r.end_date >= ?
                             ORDER BY r.start_date DESC LIMIT ? OFFSET ?",
                            [Societes::courant(), self::APPROUVEE, $depuis, $page['limit'], $page['offset']]
                        );

                        return Aide::ok(Aide::wrap(array_map(static fn (array $r): array => [
                            'id' => (int) $r['id'],
                            'type' => $r['type'],
                            'du' => $r['start_date'],
                            'au' => $r['end_date'],
                            'jours' => (int) $r['days'],
                            'personne' => trim($r['first_name'] . ' ' . $r['last_name']),
                            'personne_id' => (int) $r['employee_id'],
                        ], $rows), $page, $total));
                    },
                ],
                [
                    'methode' => 'GET',
                    'chemin' => '/demandes',
                    'portee' => 'rh',
                    'resume' => "Les demandes d'absence, quel que soit leur sort.",
                    'details' => "Celles qui attendent, celles qui ont été refusées, celles qui ont été retirées. Le motif reste absent, comme partout ici.",
                    'parametres' => [
                        ['nom' => 'statut', 'dans' => 'requete', 'type' => 'texte', 'description' => 'En attente, Approuvée, Refusée…'],
                        ['nom' => 'limite', 'dans' => 'requete', 'type' => 'entier', 'defaut' => 50],
                        ['nom' => 'depuis', 'dans' => 'requete', 'type' => 'entier', 'defaut' => 0],
                    ],
                    'rend' => ['id', 'type', 'du', 'au', 'jours', 'statut', 'personne', 'personne_id', 'deposee_le'],
                    'faire' => static function (Request $request): array {
                        $page = Aide::page($request);
                        $statut = $request->input('statut');

                        $toutes = Hr::allRequests($statut !== '' ? $statut : null);
                        $tranche = array_slice($toutes, $page['offset'], $page['limit']);

                        return Aide::ok(Aide::wrap(array_map(static fn (array $r): array => [
                            'id' => (int) $r['id'],
                            'type' => $r['type'],
                            'du' => $r['start_date'],
                            'au' => $r['end_date'],
                            'jours' => (int) $r['days'],
                            'statut' => $r['status'],
                            'personne' => trim($r['first_name'] . ' ' . $r['last_name']),
                            'personne_id' => (int) $r['employee_id'],
                            'deposee_le' => $r['created_at'] ?? null,
                        ], $tranche), $page, count($toutes)));
                    },
                ],
                [
                    'methode' => 'GET',
                    'chemin' => '/effectifs',
                    'portee' => 'rh',
                    'resume' => "L'effectif, par service et par type de contrat.",
                    'rend' => ['total', 'par_service', 'par_contrat'],
                    'faire' => static function (): array {
                        return Aide::ok([
                            'total' => Aide::compter("SELECT COUNT(*) FROM users WHERE active = 1 AND role = 'employee'"),
                            'par_service' => Db::all(
                                "SELECT COALESCE(d.name, 'Sans service') AS nom, COUNT(*) AS effectif
                                 FROM users u LEFT JOIN departments d ON d.id = u.department_id
                                 WHERE u.active = 1 AND u.role = 'employee'
                                 GROUP BY COALESCE(d.name, 'Sans service') ORDER BY effectif DESC"
                            ),
                            'par_contrat' => Db::all(
                                "SELECT CASE WHEN u.contract_type IS NULL OR u.contract_type = '' THEN 'Non renseigné' ELSE u.contract_type END AS nom,
                                        COUNT(*) AS effectif
                                 FROM users u WHERE u.active = 1 AND u.role = 'employee'
                                 GROUP BY nom ORDER BY effectif DESC"
                            ),
                        ]);
                    },
                ],

                // ---------- Écriture ----------

                [
                    'methode' => 'POST',
                    'chemin' => '/absences',
                    'portee' => 'rh:ecriture',
                    'resume' => "Dépose une demande d'absence pour un salarié.",
                    'details' => "Elle entre « En attente », comme si elle avait été saisie à l'écran : l'API dépose, elle n'approuve pas. Approuver reste un geste de responsable, devant une session et un nom.",
                    'journal' => 'api.absence_deposee',
                    'parametres' => [
                        ['nom' => 'personne', 'dans' => 'corps', 'type' => 'entier', 'requis' => true, 'description' => "L'identifiant du salarié."],
                        ['nom' => 'type', 'dans' => 'corps', 'type' => 'texte', 'requis' => true, 'description' => 'Congés payés, RTT, Maladie…'],
                        ['nom' => 'du', 'dans' => 'corps', 'type' => 'date', 'requis' => true],
                        ['nom' => 'au', 'dans' => 'corps', 'type' => 'date', 'requis' => true],
                    ],
                    'rend' => ['id', 'statut', 'jours'],
                    'faire' => static function (Request $request): array {
                        $corps = Aide::corps($request);
                        $personne = Aide::entier($corps['personne'] ?? null);
                        $type = Aide::texte($corps, 'type', 60);
                        $du = Aide::date($corps['du'] ?? null);
                        $au = Aide::date($corps['au'] ?? null);

                        if ($personne === null) {
                            return Aide::erreur(422, 'Un salarié est requis.');
                        }
                        if ($type === '') {
                            return Aide::erreur(422, 'Un type est requis.');
                        }
                        if ($du === '' || $au === '') {
                            return Aide::erreur(422, 'Deux dates au format AAAA-MM-JJ sont requises.');
                        }
                        if ($au < $du) {
                            return Aide::erreur(422, 'La fin précède le début.');
                        }
                        if (Db::get("SELECT id FROM users WHERE id = ? AND active = 1 AND role = 'employee'", [$personne]) === null) {
                            return Aide::absent('Salarié');
                        }

                        $jours = Hr::countBusinessDays($du, $au) ?? 0;
                        $id = Hr::createRequest($personne, $type, $du, $au, $jours, '');

                        return Aide::ok(['id' => $id, 'statut' => 'En attente', 'jours' => $jours], 201);
                    },
                ],
            ],
        ];
    }
}
