<?php

declare(strict_types=1);

namespace App\Api;

use App\Core\Db;
use App\Core\Request;
use App\Modules\Billing;
use App\Modules\Currency;
use App\Modules\Finance;
use App\Modules\Partners;
use App\Modules\Purchasing;
use App\Modules\Treasury;
use App\Modules\Societes;

/**
 * Gestion : tiers, factures, abonnements, achats, trésorerie.
 *
 * Les montants sortent toujours accompagnés de leur devise **et** de leur
 * contre-valeur en devise de référence, au taux figé à l'émission. Un total
 * qui mêle des devises ne veut rien dire, et un appelant qui reconvertirait
 * lui-même au taux du jour donnerait un autre chiffre que l'écran.
 */
final class Gestion
{
    private static function facture(array $i): array
    {
        return [
            'id' => (int) $i['id'],
            'reference' => $i['reference'],
            'libelle' => $i['label'],
            'sens' => $i['direction'],
            'statut' => $i['status'],
            'emise_le' => $i['issue_date'],
            'echeance' => $i['due_date'],
            'en_retard' => (bool) ($i['overdue'] ?? false),
            'montant_ht' => (float) $i['amount_ht'],
            'tva' => (float) $i['vat_rate'],
            'montant_ttc' => (float) $i['amount_ttc'],
            'devise' => $i['currency'],
            'taux' => (float) $i['exchange_rate'],
            'montant_ttc_reference' => (float) $i['amount_base_ttc'],
            'devise_reference' => Currency::base(),
            'tiers' => $i['partner_name'] ?? null,
            'tiers_id' => $i['partner_id'] === null ? null : (int) $i['partner_id'],
        ];
    }

    public static function groupe(): array
    {
        return [
            'cle' => 'gestion',
            'titre' => 'Gestion et facturation',
            'resume' => 'Tiers, factures, abonnements, bons de commande, comptes bancaires.',
            'fonctions' => [
                [
                    'methode' => 'GET',
                    'chemin' => '/tiers',
                    'portee' => 'gestion',
                    'resume' => 'Les clients et fournisseurs.',
                    'parametres' => [
                        ['nom' => 'type', 'dans' => 'requete', 'type' => 'texte', 'description' => 'Client, Fournisseur…'],
                        ['nom' => 'limite', 'dans' => 'requete', 'type' => 'entier', 'defaut' => 50],
                        ['nom' => 'depuis', 'dans' => 'requete', 'type' => 'entier', 'defaut' => 0],
                    ],
                    'rend' => ['id', 'type', 'nom', 'identifiant', 'email', 'telephone', 'actif'],
                    'faire' => static function (Request $request): array {
                        $page = Aide::page($request);
                        $type = $request->input('type');
                        $where = $type === '' ? '' : 'AND kind = ?';
                        /* Le jeton n'a pas de session : hors contexte, « courant »
                           rend la société qui mène. Voir Societes::perimetreParDefaut(). */
                        $args = $type === '' ? [Societes::courant()] : [Societes::courant(), $type];

                        $total = Aide::compter("SELECT COUNT(*) FROM partners WHERE company_id = ? $where", $args);
                        $rows = Db::all(
                            "SELECT id, kind, name, registration, email, phone, active FROM partners
                             WHERE company_id = ? $where
                             ORDER BY name LIMIT ? OFFSET ?",
                            [...$args, $page['limit'], $page['offset']]
                        );

                        return Aide::ok(Aide::wrap(array_map(static fn (array $r): array => [
                            'id' => (int) $r['id'], 'type' => $r['kind'], 'nom' => $r['name'],
                            'identifiant' => $r['registration'], 'email' => $r['email'],
                            'telephone' => $r['phone'], 'actif' => (bool) $r['active'],
                        ], $rows), $page, $total));
                    },
                ],
                [
                    'methode' => 'GET',
                    'chemin' => '/tiers/{id}',
                    'portee' => 'gestion',
                    'resume' => "La fiche d'un tiers : contacts, pièces, évaluations.",
                    'parametres' => [['nom' => 'id', 'dans' => 'chemin', 'type' => 'entier', 'requis' => true]],
                    'rend' => ['id', 'nom', 'type', 'contacts', 'documents', 'evaluations'],
                    'faire' => static function (Request $request, array $params): array {
                        $id = Aide::entier($params['id'] ?? null);
                        $tiers = $id === null ? null : Finance::partnerById($id);
                        if ($tiers === null) {
                            return Aide::absent('Tiers');
                        }
                        return Aide::ok([
                            'id' => (int) $tiers['id'], 'nom' => $tiers['name'], 'type' => $tiers['kind'],
                            'identifiant' => $tiers['registration'], 'email' => $tiers['email'],
                            'telephone' => $tiers['phone'], 'adresse' => $tiers['address'],
                            'actif' => (bool) $tiers['active'],
                            'contacts' => Partners::contacts($id),
                            'documents' => Partners::documents($id),
                            'evaluations' => Partners::reviews($id),
                        ]);
                    },
                ],
                [
                    'methode' => 'GET',
                    'chemin' => '/factures',
                    'portee' => 'gestion',
                    'resume' => 'Les factures, dans les deux sens.',
                    'parametres' => [
                        ['nom' => 'sens', 'dans' => 'requete', 'type' => 'texte', 'description' => 'Client ou Fournisseur.'],
                        ['nom' => 'limite', 'dans' => 'requete', 'type' => 'entier', 'defaut' => 50],
                        ['nom' => 'depuis', 'dans' => 'requete', 'type' => 'entier', 'defaut' => 0],
                    ],
                    'rend' => ['id', 'reference', 'libelle', 'sens', 'statut', 'emise_le', 'echeance', 'en_retard', 'montant_ht', 'tva', 'montant_ttc', 'devise', 'taux', 'montant_ttc_reference', 'devise_reference', 'tiers', 'tiers_id'],
                    'faire' => static function (Request $request): array {
                        $page = Aide::page($request);
                        $sens = $request->input('sens');
                        $sens = in_array($sens, Finance::INVOICE_DIRECTIONS, true) ? $sens : null;

                        $toutes = Finance::invoices($sens);
                        $tranche = array_slice($toutes, $page['offset'], $page['limit']);

                        return Aide::ok(Aide::wrap(array_map(self::facture(...), $tranche), $page, count($toutes)));
                    },
                ],
                [
                    'methode' => 'GET',
                    'chemin' => '/factures/{id}',
                    'portee' => 'gestion',
                    'resume' => 'Une facture, par son identifiant.',
                    'parametres' => [['nom' => 'id', 'dans' => 'chemin', 'type' => 'entier', 'requis' => true]],
                    'rend' => ['id', 'reference', 'libelle', 'sens', 'statut', 'emise_le', 'echeance', 'en_retard', 'montant_ht', 'tva', 'montant_ttc', 'devise', 'taux', 'montant_ttc_reference', 'devise_reference', 'tiers', 'tiers_id'],
                    'faire' => static function (Request $request, array $params): array {
                        $id = Aide::entier($params['id'] ?? null);
                        $facture = $id === null ? null : Finance::invoiceById($id);
                        if ($facture === null) {
                            return Aide::absent('Facture');
                        }
                        // invoiceById() ne calcule pas le retard : la liste le fait.
                        $facture['overdue'] = $facture['status'] === 'Émise'
                            && !empty($facture['due_date'])
                            && $facture['due_date'] < gmdate('Y-m-d');
                        return Aide::ok(self::facture($facture));
                    },
                ],
                [
                    'methode' => 'GET',
                    'chemin' => '/abonnements',
                    'portee' => 'gestion',
                    'resume' => 'Les facturations récurrentes et leur prochaine échéance.',
                    'rend' => ['id', 'libelle', 'sens', 'tiers', 'montant_ht', 'devise', 'periodicite', 'prochaine_emission', 'fin', 'actif'],
                    'faire' => static function (): array {
                        $rows = array_map(static fn (array $a): array => [
                            'id' => (int) $a['id'], 'libelle' => $a['label'], 'sens' => $a['direction'],
                            'tiers' => $a['partner_name'] ?? null, 'montant_ht' => (float) $a['amount_ht'],
                            'devise' => $a['currency'], 'periodicite' => $a['period'],
                            'prochaine_emission' => $a['next_issue'], 'fin' => $a['end_date'],
                            'actif' => (bool) $a['active'],
                        ], Billing::list());

                        return Aide::ok(Aide::wrap($rows, ['limit' => Aide::MAX_PAGE, 'offset' => 0]));
                    },
                ],
                [
                    'methode' => 'GET',
                    'chemin' => '/commandes',
                    'portee' => 'gestion',
                    'resume' => 'Les bons de commande fournisseurs.',
                    'parametres' => [['nom' => 'ouvertes', 'dans' => 'requete', 'type' => 'booleen', 'description' => 'À 1, masque les commandes reçues ou annulées.']],
                    'rend' => ['id', 'reference', 'tiers', 'statut', 'montant_ht', 'devise', 'passee_le', 'attendue_le'],
                    'faire' => static function (Request $request): array {
                        $ouvertes = $request->input('ouvertes') === '1';
                        $rows = array_map(static fn (array $o): array => [
                            'id' => (int) $o['id'], 'reference' => $o['reference'],
                            'tiers' => $o['partner_name'] ?? null, 'statut' => $o['status'],
                            'montant_ht' => (float) ($o['amount_ht'] ?? 0),
                            'devise' => $o['currency'] ?? Currency::base(),
                            'passee_le' => $o['ordered_on'] ?? null, 'attendue_le' => $o['expected_on'] ?? null,
                        ], Purchasing::orders(!$ouvertes));

                        return Aide::ok(Aide::wrap($rows, ['limit' => Aide::MAX_PAGE, 'offset' => 0]));
                    },
                ],
                [
                    'methode' => 'GET',
                    'chemin' => '/comptes',
                    'portee' => 'gestion',
                    'resume' => 'Les comptes bancaires et leur solde.',
                    'details' => "Le solde est calculé depuis les mouvements enregistrés dans l'instance, non lu chez la banque : c'est le solde comptable, pas le solde bancaire.",
                    'rend' => ['id', 'libelle', 'banque', 'iban_masque', 'devise', 'solde', 'actif'],
                    'faire' => static function (): array {
                        $rows = array_map(static fn (array $c): array => [
                            'id' => (int) $c['id'], 'libelle' => $c['label'], 'banque' => $c['bank'] ?? null,
                            'devise' => $c['currency'] ?? Currency::base(), 'solde' => (float) ($c['balance'] ?? 0),
                            // L'IBAN complet ne sort pas : il suffit à initier un prélèvement.
                            'iban_masque' => empty($c['iban']) ? '' : '…' . mb_substr((string) $c['iban'], -4),
                            'actif' => (bool) $c['active'],
                        ], Treasury::accounts(true));

                        return Aide::ok(Aide::wrap($rows, ['limit' => Aide::MAX_PAGE, 'offset' => 0]));
                    },
                ],

                // ---------- Écriture ----------

                [
                    'methode' => 'POST',
                    'chemin' => '/tiers',
                    'portee' => 'gestion:ecriture',
                    'resume' => 'Crée un client ou un fournisseur.',
                    'journal' => 'api.tiers_cree',
                    'parametres' => [
                        ['nom' => 'type', 'dans' => 'corps', 'type' => 'texte', 'requis' => true, 'description' => 'Client, Fournisseur…'],
                        ['nom' => 'nom', 'dans' => 'corps', 'type' => 'texte', 'requis' => true],
                        ['nom' => 'identifiant', 'dans' => 'corps', 'type' => 'texte', 'description' => 'SIRET, numéro de TVA…'],
                        ['nom' => 'email', 'dans' => 'corps', 'type' => 'texte'],
                        ['nom' => 'telephone', 'dans' => 'corps', 'type' => 'texte'],
                        ['nom' => 'adresse', 'dans' => 'corps', 'type' => 'texte'],
                    ],
                    'rend' => ['id', 'nom', 'type'],
                    'faire' => static function (Request $request): array {
                        $corps = Aide::corps($request);
                        $type = Aide::texte($corps, 'type', 40);
                        $nom = Aide::texte($corps, 'nom', 160);

                        if (!in_array($type, Finance::PARTNER_KINDS, true)) {
                            return Aide::erreur(422, 'Type inconnu.', ['attendus' => Finance::PARTNER_KINDS]);
                        }
                        if ($nom === '') {
                            return Aide::erreur(422, 'Un nom est requis.');
                        }

                        $id = Finance::createPartner([
                            'kind' => $type, 'name' => $nom,
                            'registration' => Aide::texte($corps, 'identifiant', 60),
                            'email' => Aide::texte($corps, 'email', 160),
                            'phone' => Aide::texte($corps, 'telephone', 40),
                            'address' => Aide::texte($corps, 'adresse', 400),
                        ]);

                        return Aide::ok(['id' => $id, 'nom' => $nom, 'type' => $type], 201);
                    },
                ],
                [
                    'methode' => 'POST',
                    'chemin' => '/factures',
                    'portee' => 'gestion:ecriture',
                    'resume' => 'Émet une facture.',
                    'details' => "Le taux de change est figé à l'émission, comme à l'écran : la contre-valeur ne bougera plus.",
                    'journal' => 'api.facture_creee',
                    'parametres' => [
                        ['nom' => 'sens', 'dans' => 'corps', 'type' => 'texte', 'requis' => true, 'description' => 'Client ou Fournisseur.'],
                        ['nom' => 'libelle', 'dans' => 'corps', 'type' => 'texte', 'requis' => true],
                        ['nom' => 'montant_ht', 'dans' => 'corps', 'type' => 'nombre', 'requis' => true],
                        ['nom' => 'tva', 'dans' => 'corps', 'type' => 'nombre', 'requis' => true, 'description' => 'Le taux, en pourcentage.'],
                        ['nom' => 'emise_le', 'dans' => 'corps', 'type' => 'date', 'requis' => true],
                        ['nom' => 'echeance', 'dans' => 'corps', 'type' => 'date'],
                        ['nom' => 'tiers', 'dans' => 'corps', 'type' => 'entier'],
                        ['nom' => 'reference', 'dans' => 'corps', 'type' => 'texte'],
                        ['nom' => 'devise', 'dans' => 'corps', 'type' => 'texte', 'description' => 'Par défaut, la devise de référence.'],
                    ],
                    'rend' => ['id', 'reference', 'montant_ttc', 'devise'],
                    'faire' => static function (Request $request): array {
                        $corps = Aide::corps($request);
                        $sens = Aide::texte($corps, 'sens', 20);
                        $libelle = Aide::texte($corps, 'libelle', 200);
                        $montant = is_numeric($corps['montant_ht'] ?? null) ? (float) $corps['montant_ht'] : null;
                        $tva = is_numeric($corps['tva'] ?? null) ? (float) $corps['tva'] : null;
                        $emise = Aide::date($corps['emise_le'] ?? null);

                        if (!in_array($sens, Finance::INVOICE_DIRECTIONS, true)) {
                            return Aide::erreur(422, 'Sens inconnu.', ['attendus' => Finance::INVOICE_DIRECTIONS]);
                        }
                        if ($libelle === '') {
                            return Aide::erreur(422, 'Un libellé est requis.');
                        }
                        if ($montant === null || $montant < 0) {
                            return Aide::erreur(422, 'Un montant hors taxes positif est requis.');
                        }
                        if ($tva === null || $tva < 0 || $tva > 100) {
                            return Aide::erreur(422, 'Un taux de TVA entre 0 et 100 est requis.');
                        }
                        if ($emise === '') {
                            return Aide::erreur(422, "Une date d'émission au format AAAA-MM-JJ est requise.");
                        }

                        $tiers = Aide::entier($corps['tiers'] ?? null);
                        if ($tiers !== null && Finance::partnerById($tiers) === null) {
                            return Aide::erreur(422, "Ce tiers n'existe pas.");
                        }

                        $devise = strtoupper(Aide::texte($corps, 'devise', 3));
                        $id = Finance::createInvoice([
                            'direction' => $sens, 'partnerId' => $tiers, 'label' => $libelle,
                            'reference' => Aide::texte($corps, 'reference', 60),
                            'issueDate' => $emise, 'dueDate' => Aide::date($corps['echeance'] ?? null) ?: null,
                            'amountHt' => $montant, 'vatRate' => $tva,
                            'currency' => $devise !== '' ? $devise : null,
                            'createdBy' => null,
                        ]);
                        // createInvoice rend null quand la devise demandée n'a pas de taux connu.
                        if ($id === null) {
                            return Aide::erreur(422, "Devise inconnue : aucun taux de change n'est enregistré pour elle.");
                        }

                        $creee = Finance::invoiceById($id);
                        return Aide::ok([
                            'id' => $id, 'reference' => $creee['reference'],
                            'montant_ttc' => (float) $creee['amount_ttc'], 'devise' => $creee['currency'],
                        ], 201);
                    },
                ],
                [
                    'methode' => 'PATCH',
                    'chemin' => '/factures/{id}',
                    'portee' => 'gestion:ecriture',
                    'resume' => "Change le statut d'une facture — la marquer payée, par exemple.",
                    'journal' => 'api.facture_statut',
                    'parametres' => [
                        ['nom' => 'id', 'dans' => 'chemin', 'type' => 'entier', 'requis' => true],
                        ['nom' => 'statut', 'dans' => 'corps', 'type' => 'texte', 'requis' => true, 'description' => 'Émise, Payée, Annulée…'],
                    ],
                    'rend' => ['id', 'statut'],
                    'faire' => static function (Request $request, array $params): array {
                        $id = Aide::entier($params['id'] ?? null);
                        $statut = Aide::texte(Aide::corps($request), 'statut', 30);

                        if ($id === null || Finance::invoiceById($id) === null) {
                            return Aide::absent('Facture');
                        }
                        if (!in_array($statut, Finance::INVOICE_STATUSES, true)) {
                            return Aide::erreur(422, 'Statut inconnu.', ['attendus' => Finance::INVOICE_STATUSES]);
                        }
                        if (!Finance::setInvoiceStatus($id, $statut)) {
                            return Aide::erreur(409, 'Statut inchangé.');
                        }

                        return Aide::ok(['id' => $id, 'statut' => $statut]);
                    },
                ],
            ],
        ];
    }
}
