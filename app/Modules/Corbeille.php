<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Cloison;
use App\Core\Db;

/**
 * La corbeille : une suppression qui se défait.
 *
 * Deux dessins étaient possibles, et le choix compte plus que le code.
 *
 * Le premier — la « suppression douce » — pose une colonne « deleted_at » sur
 * chaque table et demande à toutes les lectures de l'écarter. Il restaure
 * parfaitement, puisque rien ne part. Mais il faut alors reprendre chaque
 * requête de lecture, pour les seuls sept objets concernés, dans les deux
 * éditions. Un seul oubli, et un projet supprimé réapparaît dans un rapport.
 *
 * Le second — celui-ci — met de côté ce que la suppression va emporter, puis
 * laisse la suppression se faire pour de bon. Aucune lecture ne change : ce qui
 * est supprimé est supprimé, et la corbeille est un dépôt à part. Le prix à
 * payer est qu'il faut savoir exactement ce qu'une suppression emporte — les
 * enfants en cascade, les liens mis à nul, les champs personnalisés — et le
 * déclarer. C'est ce que fait OBJETS ci-dessous, et un test vérifie que cette
 * déclaration colle au schéma : une table ajoutée demain avec une cascade vers
 * un objet de la corbeille fera échouer ce test, et non perdre des données en
 * silence.
 *
 * Ce que la corbeille ne couvre pas, et pourquoi : la fiche d'un salarié. Son
 * empreinte traverse tout le produit — quarante-sept tables en cascade,
 * soixante-quatre liens — et la ressusciter à moitié serait pire que la
 * suppression : des sessions, des jetons, des identifiants de messagerie, des
 * documents de coffre-fort reviendraient sans que personne ait décidé lesquels.
 * Le produit offre déjà le bon remède pour « je n'aurais pas dû » : désactiver
 * le compte, qui ne perd rien.
 */
final class Corbeille
{
    /**
     * Ce qu'une suppression emporte, objet par objet.
     *
     * - « enfants » : les lignes que la cascade détruit. L'ordre compte, et
     *   « parent » dit de quelle table viennent les identifiants à suivre : les
     *   activités d'une opportunité ne se retrouvent qu'après les opportunités.
     * - « liens » : les colonnes que la suppression met à nul ailleurs. La ligne
     *   survit, mais elle a perdu sa référence ; la corbeille note laquelle,
     *   pour la rendre si elle est encore libre.
     * - « etiquette » : la colonne, ou les colonnes, que l'écran montre. Jamais
     *   la charge.
     *
     * Les clés sont celles des champs personnalisés : la corbeille reprend donc
     * leurs valeurs sans table de correspondance, et emprunte aussi leurs
     * libellés — « Fiche tiers » ou « Demande interne » désignent la même chose
     * des deux côtés, et traduire deux fois le même mot finirait par le
     * traduire deux fois différemment.
     */
    public const OBJETS = [
        'projet' => [
            'table' => 'projects',
            'libelle' => 'champs.obj.projet',
            'etiquette' => ['name'],
            'enfants' => [
                ['table' => 'project_members', 'colonne' => 'project_id', 'parent' => 'projects'],
                ['table' => 'project_milestones', 'colonne' => 'project_id', 'parent' => 'projects'],
                ['table' => 'project_tasks', 'colonne' => 'project_id', 'parent' => 'projects'],
                ['table' => 'project_time', 'colonne' => 'project_id', 'parent' => 'projects'],
            ],
            'liens' => [['table' => 'app_services', 'colonne' => 'project_id']],
        ],
        'ticket' => [
            'table' => 'tickets',
            'libelle' => 'champs.obj.ticket',
            'etiquette' => ['reference', 'subject'],
            'enfants' => [['table' => 'ticket_messages', 'colonne' => 'ticket_id', 'parent' => 'tickets']],
            'liens' => [],
        ],
        'partenaire' => [
            'table' => 'partners',
            'libelle' => 'champs.obj.partenaire',
            'etiquette' => ['name'],
            'enfants' => [
                ['table' => 'partner_contracts', 'colonne' => 'partner_id', 'parent' => 'partners'],
                ['table' => 'partner_contacts', 'colonne' => 'partner_id', 'parent' => 'partners'],
                ['table' => 'partner_documents', 'colonne' => 'partner_id', 'parent' => 'partners'],
                ['table' => 'partner_reviews', 'colonne' => 'partner_id', 'parent' => 'partners'],
                ['table' => 'crm_contacts', 'colonne' => 'partner_id', 'parent' => 'partners'],
                ['table' => 'opportunities', 'colonne' => 'partner_id', 'parent' => 'partners'],
                ['table' => 'quotes', 'colonne' => 'partner_id', 'parent' => 'partners'],
                ['table' => 'crm_activities', 'colonne' => 'partner_id', 'parent' => 'partners'],
                ['table' => 'sepa_mandates', 'colonne' => 'partner_id', 'parent' => 'partners'],
                ['table' => 'sales_orders', 'colonne' => 'partner_id', 'parent' => 'partners'],
                ['table' => 'sales_order_lines', 'colonne' => 'order_id', 'parent' => 'sales_orders'],
                ['table' => 'delivery_notes', 'colonne' => 'order_id', 'parent' => 'sales_orders'],
                ['table' => 'delivery_note_lines', 'colonne' => 'note_id', 'parent' => 'delivery_notes'],
                ['table' => 'delivery_note_lines', 'colonne' => 'order_line_id', 'parent' => 'sales_order_lines'],
                ['table' => 'sales_invoice_lines', 'colonne' => 'order_line_id', 'parent' => 'sales_order_lines'],
                // Une activité rattachée à une opportunité du tiers, mais pas au
                // tiers lui-même : la cascade l'emporte quand même, par
                // l'opportunité.
                ['table' => 'crm_activities', 'colonne' => 'opportunity_id', 'parent' => 'opportunities'],
            ],
            'liens' => [
                ['table' => 'invoices', 'colonne' => 'partner_id'],
                ['table' => 'items', 'colonne' => 'partner_id'],
                ['table' => 'projects', 'colonne' => 'partner_id'],
                ['table' => 'tickets', 'colonne' => 'partner_id'],
                ['table' => 'subscriptions', 'colonne' => 'partner_id'],
                ['table' => 'incoming_documents', 'colonne' => 'partner_id'],
                ['table' => 'interest_declarations', 'colonne' => 'partner_id'],
                ['table' => 'gift_records', 'colonne' => 'partner_id'],
            ],
        ],
        'facture' => [
            'table' => 'invoices',
            'libelle' => 'champs.obj.facture',
            'etiquette' => ['reference', 'label'],
            'enfants' => [
                ['table' => 'dunning_notices', 'colonne' => 'invoice_id', 'parent' => 'invoices'],
                ['table' => 'sales_invoice_lines', 'colonne' => 'invoice_id', 'parent' => 'invoices'],
                ['table' => 'stripe_sessions', 'colonne' => 'invoice_id', 'parent' => 'invoices'],
            ],
            'liens' => [
                ['table' => 'entries', 'colonne' => 'invoice_id'],
                ['table' => 'quotes', 'colonne' => 'invoice_id'],
                ['table' => 'bank_transactions', 'colonne' => 'invoice_id'],
                ['table' => 'incoming_documents', 'colonne' => 'invoice_id'],
            ],
        ],
        'equipement' => [
            'table' => 'assets',
            'libelle' => 'champs.obj.equipement',
            'etiquette' => ['name'],
            'enfants' => [['table' => 'asset_assignments', 'colonne' => 'asset_id', 'parent' => 'assets']],
            'liens' => [],
        ],
        'evenement' => [
            'table' => 'company_events',
            'libelle' => 'champs.obj.evenement',
            'etiquette' => ['title'],
            'enfants' => [['table' => 'event_registrations', 'colonne' => 'event_id', 'parent' => 'company_events']],
            'liens' => [],
        ],
        /*
         * Le type de demande, et non la demande.
         *
         * Une demande ne se supprime jamais seule : elle s'annule, et elle
         * reste. Mais supprimer le TYPE emportait en silence toutes les demandes
         * déjà closes qui le citaient — la garde ne compte que celles en cours.
         * C'est la perte la plus discrète du produit, et c'est celle que la
         * corbeille répare.
         */
        'type_demande' => [
            'table' => 'request_forms',
            'libelle' => 'corbeille.obj.typeDemande',
            'etiquette' => ['label'],
            'enfants' => [
                ['table' => 'request_steps', 'colonne' => 'form_id', 'parent' => 'request_forms'],
                ['table' => 'workflow_requests', 'colonne' => 'form_id', 'parent' => 'request_forms'],
                ['table' => 'workflow_decisions', 'colonne' => 'request_id', 'parent' => 'workflow_requests'],
            ],
            'liens' => [],
        ],
    ];

    /** Combien de jours la corbeille garde ce qu'on y jette, sans réglage. */
    public const JOURS_PAR_DEFAUT = 30;

    /** @return list<string> */
    private static function colonnes(string $table): array
    {
        return array_map(
            static fn (array $c): string => (string) ($c['name'] ?? $c['Field'] ?? ''),
            Db::pilote() === 'mysql'
                ? Db::all("SHOW COLUMNS FROM `$table`")
                : Db::all("PRAGMA table_info($table)")
        );
    }

    /**
     * Le périmètre, pour les tables qui le portent.
     *
     * La corbeille est un moteur générique : elle touche sept tables, dont
     * certaines sont déjà cloisonnées et d'autres pas encore. Plutôt que de tenir
     * une seconde liste des unes et des autres — qui vieillirait sans qu'on le
     * remarque —, elle demande à la table si elle porte la colonne. On ne jette et
     * on ne remet en place que dans la société où l'on travaille : une facture
     * d'une autre entité du groupe n'est pas « introuvable » par hasard, elle
     * l'est par construction.
     *
     * @return array{clause: string, params: list<mixed>}
     */
    private static function garde(string $table, string $alias = ''): array
    {
        if (!in_array('company_id', self::colonnes($table), true)) {
            return ['clause' => '', 'params' => []];
        }
        $prefixe = $alias === '' ? '' : $alias . '.';
        return ['clause' => " AND {$prefixe}company_id = ?", 'params' => [Societes::courant()]];
    }

    private static function etiquette(array $decl, array $ligne): string
    {
        $morceaux = [];
        foreach ($decl['etiquette'] as $colonne) {
            $valeur = trim((string) ($ligne[$colonne] ?? ''));
            if ($valeur !== '') {
                $morceaux[] = $valeur;
            }
        }
        return mb_substr($morceaux === [] ? '#' . (int) $ligne['id'] : implode(' — ', $morceaux), 0, 200);
    }

    /**
     * Mettre de côté ce que la suppression va emporter.
     *
     * À appeler AVANT la suppression, et avant CustomFields::oublier : c'est le
     * seul instant où tout est encore là. Rend l'identifiant de la ligne de
     * corbeille, ou null si l'objet est inconnu ou l'enregistrement déjà parti —
     * un appelant n'a donc pas à se protéger pour supprimer.
     */
    public static function jeter(string $objet, int $recordId, ?int $parId = null): ?int
    {
        $decl = self::OBJETS[$objet] ?? null;
        if ($decl === null || $recordId <= 0) {
            return null;
        }
        $sienne = self::garde((string) $decl['table']);
        $ligne = Db::get(
            "SELECT * FROM {$decl['table']} WHERE id = ?{$sienne['clause']}",
            [$recordId, ...$sienne['params']],
        );
        if ($ligne === null) {
            return null;
        }

        // Les identifiants retenus table par table : les enfants d'un enfant les
        // suivent.
        $retenus = [$decl['table'] => [$recordId]];
        $enfants = [];
        foreach ($decl['enfants'] as $e) {
            $parents = $retenus[$e['parent']] ?? [];
            if ($parents === []) {
                continue;
            }
            $marques = implode(', ', array_fill(0, count($parents), '?'));
            $leur = self::garde((string) $e['table']);
            $lignes = Db::all(
                "SELECT * FROM {$e['table']} WHERE {$e['colonne']} IN ($marques){$leur['clause']}",
                [...$parents, ...$leur['params']],
            );
            if ($lignes === []) {
                continue;
            }
            $enfants[] = ['table' => $e['table'], 'lignes' => $lignes];
            $retenus[$e['table']] = array_merge(
                $retenus[$e['table']] ?? [],
                array_map(static fn (array $l): int => (int) $l['id'], $lignes),
            );
        }

        $liens = [];
        foreach ($decl['liens'] as $l) {
            $ids = array_map(
                static fn (array $r): int => (int) $r['id'],
                Db::all(
                    "SELECT id FROM {$l['table']} WHERE {$l['colonne']} = ?" . self::garde((string) $l['table'])['clause'],
                    [$recordId, ...self::garde((string) $l['table'])['params']],
                ),
            );
            if ($ids !== []) {
                $liens[] = ['table' => $l['table'], 'colonne' => $l['colonne'], 'ids' => $ids];
            }
        }

        /*
         * Les champs personnalisés de tout ce qui part, et non du seul
         * enregistrement jeté : supprimer un type de demande emporte ses
         * demandes, qui sont elles aussi décorables. La correspondance se lit
         * dans les champs personnalisés eux-mêmes — table par table — plutôt que
         * d'être recopiée ici, où elle aurait vieilli.
         */
        $parTable = [];
        foreach (CustomFields::OBJETS as $cle => $o) {
            $parTable[$o['table']] = $cle;
        }
        $champs = [];
        foreach ($retenus as $table => $ids) {
            $cle = $parTable[$table] ?? null;
            if ($cle === null || $ids === []) {
                continue;
            }
            $marques = implode(', ', array_fill(0, count($ids), '?'));
            $lignes = Db::all(
                "SELECT v.field_id, v.record_id, v.valeur FROM custom_values v
                 JOIN custom_fields f ON f.id = v.field_id
                 WHERE f.objet = ? AND v.record_id IN ($marques)",
                array_merge([$cle], $ids),
            );
            if ($lignes !== []) {
                $champs[] = ['objet' => $cle, 'lignes' => $lignes];
            }
        }

        $charge = json_encode(
            ['ligne' => $ligne, 'enfants' => $enfants, 'liens' => $liens, 'champs' => $champs],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $trashId = Db::insert(
            'INSERT INTO trash (company_id, objet, record_id, libelle, charge, supprime_par)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                Societes::courant(), $objet, $recordId,
                self::etiquette($decl, $ligne), (string) $charge, $parId,
            ],
        );

        /*
         * Les valeurs capturées s'effacent ici, puisqu'elles sont désormais dans
         * la charge. Aucune clé étrangère ne les emporterait — la table visée
         * dépend de la colonne « objet » — et les laisser derrière ferait des
         * orphelines qu'un balayage retirerait avant qu'on pense à remettre
         * l'enregistrement en place.
         */
        foreach ($champs as $groupe) {
            $ids = array_values(array_unique(array_map(
                static fn (array $l): int => (int) $l['record_id'],
                $groupe['lignes'],
            )));
            $marques = implode(', ', array_fill(0, count($ids), '?'));
            Db::run(
                "DELETE FROM custom_values WHERE record_id IN ($marques)
                   AND field_id IN (SELECT id FROM custom_fields WHERE company_id = ? AND objet = ?)",
                array_merge($ids, [Societes::courant(), $groupe['objet']]),
            );
        }

        return $trashId;
    }

    /**
     * La suppression n'a pas eu lieu : défaire la mise de côté.
     *
     * Nécessaire parce que « jeter » passe avant la suppression, et qu'une
     * suppression peut refuser — un type de demande qui porte des demandes en
     * cours, un tiers qu'un bon de commande retient. Sans cela, le refus
     * laisserait une ligne de corbeille pour un enregistrement toujours vivant,
     * et aurait emporté ses champs personnalisés au passage.
     */
    public static function rendre(?int $trashId): bool
    {
        if ($trashId === null) {
            return false;
        }
        $t = self::ligne($trashId);
        if ($t === null) {
            return false;
        }
        $charge = json_decode((string) $t['charge'], true);
        if (!is_array($charge)) {
            return false;
        }

        Db::transaction(static function () use ($charge, $t): void {
            foreach ($charge['champs'] ?? [] as $groupe) {
                foreach ($groupe['lignes'] ?? [] as $c) {
                    Db::run(
                        'INSERT INTO custom_values (field_id, record_id, valeur) VALUES (?, ?, ?)
                         ON CONFLICT(field_id, record_id) DO UPDATE SET valeur = excluded.valeur',
                        [(int) $c['field_id'], (int) $c['record_id'], (string) $c['valeur']],
                    );
                }
            }
            Db::run(
                'DELETE FROM trash WHERE id = ? AND company_id = ?',
                [(int) $t['id'], Societes::courant()]
            );
        });
        return true;
    }

    /**
     * Mettre de côté, puis supprimer — dans cet ordre, et sans pouvoir
     * l'inverser.
     *
     * Les sept écrans de suppression appellent ceci plutôt que « jeter » suivi
     * de leur propre suppression : l'ordre est alors tenu par le module et non
     * par chaque appelant, et un refus ou une erreur de la suppression défait la
     * mise de côté au lieu de laisser une corbeille qui mentirait.
     *
     * « $supprimer » rend ce que rend la suppression de l'appelant ; false, ou
     * un tableau dont « ok » est faux, ou une exception, comptent pour un échec.
     */
    public static function jeterPuis(string $objet, int $recordId, ?int $parId, callable $supprimer): mixed
    {
        $trashId = self::jeter($objet, $recordId, $parId);
        try {
            $verdict = $supprimer();
        } catch (\Throwable $erreur) {
            self::rendre($trashId);
            throw $erreur;
        }
        $echec = $verdict === false || (is_array($verdict) && ($verdict['ok'] ?? true) === false);
        if ($echec) {
            self::rendre($trashId);
        }
        return $verdict;
    }

    /** @return list<array<string, mixed>> */
    public static function lister(?string $objet = null, int $limite = 200): array
    {
        $sql = 'SELECT t.id, t.objet, t.record_id, t.libelle, t.supprime_le,
                       u.first_name, u.last_name
                FROM trash t LEFT JOIN users u ON u.id = t.supprime_par';
        $sql .= ' WHERE t.company_id = ?';
        $params = [Societes::courant()];
        if ($objet !== null) {
            $sql .= ' AND t.objet = ?';
            $params[] = $objet;
        }
        $sql .= ' ORDER BY t.supprime_le DESC, t.id DESC LIMIT ?';
        $params[] = max(1, $limite);

        return array_map(static function (array $l): array {
            $l['auteur'] = ($l['first_name'] ?? '') !== ''
                ? trim($l['first_name'] . ' ' . $l['last_name'])
                : null;
            return $l;
        }, Db::all($sql, $params));
    }

    public static function ligne(int $id): ?array
    {
        return $id > 0
            ? Db::get('SELECT * FROM trash WHERE id = ? AND company_id = ?', [$id, Societes::courant()])
            : null;
    }

    public static function compter(?string $objet = null): int
    {
        return $objet === null
            ? (int) Db::value('SELECT COUNT(*) FROM trash WHERE company_id = ?', [Societes::courant()])
            : (int) Db::value(
                'SELECT COUNT(*) FROM trash WHERE company_id = ? AND objet = ?',
                [Societes::courant(), $objet]
            );
    }

    /** Réinsérer une ligne sauvegardée, en ne gardant que les colonnes qui existent. */
    private static function reposer(string $table, array $ligne, array $connues): void
    {
        $champs = array_values(array_filter(array_keys($ligne), static fn (string $c): bool => in_array($c, $connues, true)));
        if ($champs === []) {
            return;
        }
        $marques = implode(', ', array_fill(0, count($champs), '?'));
        Db::run(
            'INSERT INTO ' . $table . ' (' . implode(', ', $champs) . ") VALUES ($marques)",
            array_map(static fn (string $c): mixed => $ligne[$c], $champs),
        );
    }

    /**
     * Remettre en place.
     *
     * Tout ou rien : une transaction, et la ligne de corbeille ne disparaît que
     * si la remise a réussi. Deux refus possibles, tous deux dits plutôt que
     * devinés : l'identifiant d'origine est repris par un autre enregistrement,
     * ou une table de la sauvegarde n'existe plus — ce qui arrive après une mise
     * à niveau qui retire une fonction.
     *
     * Les colonnes inconnues de la table d'aujourd'hui sont écartées : une
     * sauvegarde faite avant une mise à niveau se remet quand même, en perdant
     * ce que la version actuelle ne sait plus tenir. Mieux vaut cela qu'un
     * refus.
     *
     * @return array{ok:bool, raison?:string, objet?:string, recordId?:int, libelle?:string}
     */
    public static function restaurer(int $id): array
    {
        $t = self::ligne($id);
        if ($t === null) {
            return ['ok' => false, 'raison' => 'corbeille.err.introuvable'];
        }
        $decl = self::OBJETS[$t['objet']] ?? null;
        if ($decl === null) {
            return ['ok' => false, 'raison' => 'corbeille.err.objet'];
        }
        $charge = json_decode((string) $t['charge'], true);
        if (!is_array($charge) || !isset($charge['ligne'])) {
            return ['ok' => false, 'raison' => 'corbeille.err.charge'];
        }

        $recordId = (int) $t['record_id'];
        $sienne = self::garde((string) $decl['table']);
        if (Db::value(
            "SELECT 1 FROM {$decl['table']} WHERE id = ?{$sienne['clause']}",
            [$recordId, ...$sienne['params']],
        ) !== null) {
            return ['ok' => false, 'raison' => 'corbeille.err.reprise'];
        }

        try {
            Db::transaction(static function () use ($decl, $charge, $recordId, $t): void {
                self::reposer($decl['table'], $charge['ligne'], self::colonnes($decl['table']));

                foreach ($charge['enfants'] ?? [] as $groupe) {
                    $connues = self::colonnes((string) $groupe['table']);
                    if ($connues === []) {
                        continue;
                    }
                    foreach ($groupe['lignes'] ?? [] as $l) {
                        self::reposer((string) $groupe['table'], $l, $connues);
                    }
                }

                // Un lien ne se rend que s'il est encore libre : si quelqu'un a
                // rattaché la facture à un autre tiers depuis, c'est son choix
                // qui gagne.
                foreach ($charge['liens'] ?? [] as $lien) {
                    if (!in_array((string) $lien['colonne'], self::colonnes((string) $lien['table']), true)) {
                        continue;
                    }
                    $ids = array_map('intval', (array) $lien['ids']);
                    $marques = implode(', ', array_fill(0, count($ids), '?'));
                    $leur = self::garde((string) $lien['table']);
                    Db::run(
                        "UPDATE {$lien['table']} SET {$lien['colonne']} = ?
                         WHERE id IN ($marques) AND {$lien['colonne']} IS NULL{$leur['clause']}",
                        [$recordId, ...$ids, ...$leur['params']],
                    );
                }

                foreach ($charge['champs'] ?? [] as $groupe) {
                    foreach ($groupe['lignes'] ?? [] as $c) {
                        // Un champ éteint depuis, ou supprimé, ne revient pas :
                        // sa définition n'existe plus, et une valeur sans
                        // définition ne s'affiche nulle part.
                        if (Db::value(
                            'SELECT 1 FROM custom_fields WHERE id = ? AND company_id = ?',
                            [(int) $c['field_id'], Societes::courant()]
                        ) === null) {
                            continue;
                        }
                        Db::run(
                            'INSERT INTO custom_values (field_id, record_id, valeur) VALUES (?, ?, ?)
                             ON CONFLICT(field_id, record_id) DO UPDATE SET valeur = excluded.valeur',
                            [(int) $c['field_id'], (int) $c['record_id'], (string) $c['valeur']],
                        );
                    }
                }

                Db::run(
                    'DELETE FROM trash WHERE id = ? AND company_id = ?',
                    [(int) $t['id'], Societes::courant()]
                );
            });
        } catch (\Throwable $erreur) {
            return ['ok' => false, 'raison' => 'corbeille.err.remise'];
        }

        return [
            'ok' => true,
            'objet' => (string) $t['objet'],
            'recordId' => $recordId,
            'libelle' => (string) $t['libelle'],
        ];
    }

    /** Vider une ligne : la suppression devient définitive. */
    public static function vider(int $id): bool
    {
        return $id > 0
            && Db::run('DELETE FROM trash WHERE id = ? AND company_id = ?', [$id, Societes::courant()]) > 0;
    }

    /**
     * La purge par ancienneté.
     *
     * Une corbeille qu'on ne vide jamais garde indéfiniment des données qu'on a
     * voulu supprimer — ce qui est exactement ce qu'un droit à l'effacement
     * interdit. Le délai est réglable ; zéro veut dire « garder sans limite », et
     * c'est un choix explicite, pas le défaut.
     */
    public static function purger(int $jours = self::JOURS_PAR_DEFAUT): int
    {
        if ($jours <= 0) {
            return 0;
        }
        /*
         * Hors périmètre, et c'est le sens même de la purge : la tâche qui
         * l'appelle tourne sans session, donc sans société. Purger la seule
         * société qui mène garderait indéfiniment, dans les autres, des données
         * qu'on a voulu supprimer — ce qu'un droit à l'effacement interdit.
         */
        return (int) Cloison::sansPerimetre(static fn (): mixed => Db::run(
            "DELETE FROM trash WHERE supprime_le < datetime('now', ?)",
            ['-' . $jours . ' days']
        ));
    }
}
