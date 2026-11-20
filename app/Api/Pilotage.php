<?php

declare(strict_types=1);

namespace App\Api;

use App\Core\Request;
use App\Modules\Currency;
use App\Modules\Projects;
use App\Modules\Steering;
use App\Modules\Societes;

/**
 * Indicateurs de direction.
 *
 * Les mêmes chiffres que le tableau de bord, calculés par les mêmes fonctions.
 * Les recopier ici en SQL aurait donné deux vérités : celle de l'écran et celle
 * de l'API, qui finissent toujours par diverger le jour où l'une des deux est
 * corrigée.
 */
final class Pilotage
{
    public static function groupe(): array
    {
        return [
            'cle' => 'pilotage',
            'titre' => 'Indicateurs de direction',
            'resume' => 'Les chiffres consolidés du tableau de bord : activité, retards, projets.',
            'fonctions' => [
                [
                    'methode' => 'GET',
                    'chemin' => '/indicateurs',
                    'portee' => 'pilotage',
                    'resume' => "Chiffre d'affaires, achats, marge et impayés, pour une année.",
                    'parametres' => [['nom' => 'annee', 'dans' => 'requete', 'type' => 'entier', 'description' => "Par défaut, l'année en cours."]],
                    'rend' => ['annee', 'devise', 'effectif', 'chiffre_affaires', 'achats', 'marge', 'factures_impayees'],
                    'faire' => static function (Request $request): array {
                        $annee = (int) $request->input('annee') ?: (int) gmdate('Y');
                        $revenus = Steering::revenue($annee);
                        $impayes = Steering::unpaid();

                        return Aide::ok([
                            'annee' => $annee,
                            'devise' => Currency::base(),
                            'effectif' => Aide::compter("SELECT COUNT(*) FROM users WHERE active = 1 AND role = 'employee'"),
                            'chiffre_affaires' => $revenus['sales'],
                            'achats' => $revenus['purchases'],
                            'marge' => $revenus['margin'],
                            'factures_impayees' => ['nombre' => $impayes['count'], 'total' => $impayes['total']],
                        ]);
                    },
                ],
                [
                    'methode' => 'GET',
                    'chemin' => '/indicateurs/projets',
                    'portee' => 'pilotage',
                    'resume' => "L'état du portefeuille de projets.",
                    'rend' => ['ouverts', 'en_retard', 'taches_ouvertes', 'heures_30_jours'],
                    'faire' => static function (): array {
                        // summary() parle anglais comme le reste du module ; l'API parle
                        // français comme le reste de l'API. La traduction se fait ici, une fois.
                        $chiffres = Projects::summary();
                        return Aide::ok([
                            'ouverts' => $chiffres['open'],
                            'en_retard' => $chiffres['late'],
                            'taches_ouvertes' => $chiffres['openTasks'],
                            'heures_30_jours' => $chiffres['hours'],
                        ]);
                    },
                ],
                [
                    'methode' => 'GET',
                    'chemin' => '/indicateurs/rh',
                    'portee' => 'pilotage',
                    'resume' => "Effectif, absences du jour, demandes en attente.",
                    'details' => "Aucun nom ici : ce sont des compteurs, et un compteur ne désigne personne.",
                    'rend' => ['effectif', 'absents_aujourdhui', 'demandes_en_attente', 'contrats_a_echeance'],
                    'faire' => static function (): array {
                        return Aide::ok([
                            'effectif' => Aide::compter("SELECT COUNT(*) FROM users WHERE active = 1 AND role = 'employee'"),
                            /* Le jeton n'a pas de session : hors contexte, « courant »
                               rend la société qui mène. Voir Societes::perimetreParDefaut(). */
                            'absents_aujourdhui' => Aide::compter(
                                "SELECT COUNT(*) FROM hr_requests
                                 WHERE company_id = ? AND status = 'Approuvée'
                                   AND ? BETWEEN start_date AND end_date",
                                [Societes::courant(), gmdate('Y-m-d')]
                            ),
                            'demandes_en_attente' => Aide::compter(
                                "SELECT COUNT(*) FROM hr_requests
                                 WHERE company_id = ? AND status = 'En attente'",
                                [Societes::courant()]
                            ),
                            'contrats_a_echeance' => Aide::compter(
                                "SELECT COUNT(*) FROM users
                                 WHERE active = 1 AND contract_end_date IS NOT NULL AND contract_end_date != ''
                                   AND contract_end_date BETWEEN ? AND ?",
                                [gmdate('Y-m-d'), gmdate('Y-m-d', strtotime('+90 days'))]
                            ),
                        ]);
                    },
                ],
            ],
        ];
    }
}
