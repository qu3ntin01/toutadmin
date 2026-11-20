<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Les champs personnalisés.
 *
 * Le pendant de « src/custom-fields.js » de l'édition Node, et la raison est
 * la même.
 *
 * ── Pourquoi ils existent ───────────────────────────────────────────────
 *
 * Deux entreprises ne suivent pas les mêmes choses. L'une veut un numéro de
 * badge sur la fiche salarié, l'autre la taille de vêtement de travail, la
 * troisième le nom du tuteur d'alternance. Les coder toutes mènerait à une
 * fiche de deux cents champs dont chacun n'en utilise que douze.
 *
 * ── Deux tables, et pas une colonne par champ ───────────────────────────
 *
 * On aurait pu ajouter une colonne à la table visée à chaque champ créé.
 * C'est plus simple à joindre — et c'est une migration de schéma déclenchée
 * par un clic. Cela marche cent fois, puis casse une base en service, un
 * mardi, sans témoin. La définition et la valeur vivent donc à part.
 *
 * ── Ce que ce module ne fait pas ────────────────────────────────────────
 *
 * Il ne lit jamais les valeurs « de tous les enregistrements ». Un champ posé
 * sur la fiche salarié hérite du cloisonnement de cette fiche : qui ne peut
 * pas lire le salarié ne doit pas lire son numéro de badge. La seule façon
 * d'obtenir une valeur est de nommer l'enregistrement — que l'appelant a donc
 * déjà eu le droit de charger. Il n'y a pas de raccourci, et c'est délibéré.
 */
final class CustomFields
{
    /** Le périmètre courant : les champs personnalisés sont ceux d'une société. */
    private static function ici(): ?int
    {
        return Societes::courant();
    }

    /**
     * Les objets qu'on peut décorer.
     *
     * Une liste fermée, et non une chaîne libre. Une faute de frappe créerait
     * un objet fantôme dont les valeurs ne seraient jamais ni lues, ni
     * effacées au départ de l'enregistrement.
     */
    public const OBJETS = [
        'employe' => ['table' => 'users', 'libelle' => 'champs.obj.employe'],
        'ticket' => ['table' => 'tickets', 'libelle' => 'champs.obj.ticket'],
        'projet' => ['table' => 'projects', 'libelle' => 'champs.obj.projet'],
        'partenaire' => ['table' => 'partners', 'libelle' => 'champs.obj.partenaire'],
        'facture' => ['table' => 'invoices', 'libelle' => 'champs.obj.facture'],
        'equipement' => ['table' => 'assets', 'libelle' => 'champs.obj.equipement'],
        'demande' => ['table' => 'workflow_requests', 'libelle' => 'champs.obj.demande'],
        'evenement' => ['table' => 'company_events', 'libelle' => 'champs.obj.evenement'],
    ];

    /** Les types, et leur libellé. La validation est dans « valider ». */
    public const TYPES = [
        'texte' => 'champs.type.texte',
        'texte_long' => 'champs.type.texteLong',
        'nombre' => 'champs.type.nombre',
        'montant' => 'champs.type.montant',
        'date' => 'champs.type.date',
        'booleen' => 'champs.type.booleen',
        'liste' => 'champs.type.liste',
        'lien' => 'champs.type.lien',
    ];

    /**
     * Ranger une valeur selon son type, ou lever.
     *
     * Le rangement compte autant que le refus : une date tapée « 3/4/26 » et
     * une autre « 2026-04-03 » doivent se comparer, et deux montants
     * « 1 200,50 » et « 1200.5 » doivent s'additionner. Sans normalisation,
     * un champ personnalisé est du texte libre déguisé.
     */
    public static function valider(string $type, mixed $brut, array $champ = []): string
    {
        $texte = is_bool($brut) ? ($brut ? '1' : '0') : trim((string) ($brut ?? ''));

        return match ($type) {
            'texte' => self::borner($texte, 500),
            'texte_long' => self::borner($texte, 10000),
            'nombre' => self::nombre($texte),
            'montant' => self::montant($texte),
            'date' => self::date($texte),
            // Une case non cochée n'est pas envoyée par le navigateur :
            // l'absence vaut « non », et c'est la seule lecture qui ne perde
            // pas de données.
            'booleen' => in_array($texte, ['1', 'on', 'true'], true) ? '1' : '0',
            'liste' => self::dansLaListe($texte, $champ),
            'lien' => self::lien($texte),
            default => throw new \RuntimeException('champs.err.type'),
        };
    }

    private static function borner(string $texte, int $max): string
    {
        if (mb_strlen($texte) > $max) {
            throw new \RuntimeException('champs.err.tropLong');
        }
        return $texte;
    }

    private static function nombre(string $texte): string
    {
        $propre = str_replace(',', '.', $texte);
        if ($propre === '') {
            return '';
        }
        if (preg_match('/^-?\d+(\.\d+)?$/', $propre) !== 1) {
            throw new \RuntimeException('champs.err.nombre');
        }
        return (string) (float) $propre;
    }

    private static function montant(string $texte): string
    {
        // Les espaces de milliers, fines ou insécables, viennent du
        // copier-coller d'un tableur : les refuser ferait passer l'outil pour
        // cassé.
        $propre = str_replace(',', '.', preg_replace('/[\s\x{00a0}\x{202f}]/u', '', $texte) ?? '');
        if ($propre === '') {
            return '';
        }
        if (preg_match('/^-?\d+(\.\d{1,2})?$/', $propre) !== 1) {
            throw new \RuntimeException('champs.err.montant');
        }
        return number_format((float) $propre, 2, '.', '');
    }

    private static function date(string $texte): string
    {
        if ($texte === '') {
            return '';
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texte, $m) !== 1) {
            throw new \RuntimeException('champs.err.date');
        }
        // « 2026-02-31 » passe le motif et n'existe pas.
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new \RuntimeException('champs.err.date');
        }
        return $texte;
    }

    private static function dansLaListe(string $texte, array $champ): string
    {
        if ($texte === '') {
            return '';
        }
        if (!in_array($texte, self::choixDe($champ), true)) {
            throw new \RuntimeException('champs.err.horsListe');
        }
        return $texte;
    }

    private static function lien(string $texte): string
    {
        if ($texte === '') {
            return '';
        }
        // Seuls http et https. « javascript: » dans un champ qu'un écran rend
        // en lien est une porte ouverte, et elle serait posée par l'écran
        // d'administration lui-même.
        if (preg_match('#^https?://\S+$#i', $texte) !== 1 || mb_strlen($texte) > 2000) {
            throw new \RuntimeException('champs.err.lien');
        }
        return $texte;
    }

    /** @return list<string> */
    public static function choixDe(array $champ): array
    {
        $lignes = preg_split('/\r?\n/', (string) ($champ['choix'] ?? '')) ?: [];
        return array_values(array_filter(array_map('trim', $lignes), static fn (string $c): bool => $c !== ''));
    }

    /** La clé : stable, minuscule, sans surprise dans un export ni dans une URL. */
    public static function normaliserCle(string $brut): string
    {
        $sans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $brut);
        $sans = $sans === false ? $brut : $sans;
        $propre = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $sans));
        return mb_substr(trim($propre, '_'), 0, 40);
    }

    /* ------------------------------------------------------- définitions */

    /** @return list<array<string, mixed>> */
    public static function champs(string $objet, bool $tous = false): array
    {
        if (!isset(self::OBJETS[$objet])) {
            return [];
        }
        $sql = $tous
            ? 'SELECT * FROM custom_fields WHERE company_id = ? AND objet = ? ORDER BY rang, id'
            : 'SELECT * FROM custom_fields WHERE company_id = ? AND objet = ? AND actif = 1 ORDER BY rang, id';
        return Db::all($sql, [self::ici(), $objet]);
    }

    public static function champ(int $id): ?array
    {
        return Db::get('SELECT * FROM custom_fields WHERE id = ? AND company_id = ?', [$id, self::ici()]);
    }

    public static function creer(array $valeurs): array
    {
        $objet = (string) ($valeurs['objet'] ?? '');
        $type = (string) ($valeurs['type'] ?? '');
        if (!isset(self::OBJETS[$objet])) {
            throw new \RuntimeException('champs.err.objet');
        }
        if (!isset(self::TYPES[$type])) {
            throw new \RuntimeException('champs.err.type');
        }
        $nom = trim((string) ($valeurs['libelle'] ?? ''));
        if ($nom === '') {
            throw new \RuntimeException('champs.err.libelle');
        }

        $propres = $type === 'liste' ? self::choixDe(['choix' => $valeurs['choix'] ?? '']) : [];
        if ($type === 'liste' && $propres === []) {
            throw new \RuntimeException('champs.err.listeVide');
        }

        /*
         * La clé vient du libellé, et se suffixe si elle est prise.
         *
         * Deux champs nommés « Référence » sur le même objet sont une
         * étourderie, pas une faute : on les accepte, et la clé du second
         * devient « reference_2 ». La clé n'intéresse que les exports.
         */
        $base = self::normaliserCle($nom) ?: 'champ';
        $cle = $base;
        $suffixe = 1;
        while (Db::value(
            'SELECT 1 FROM custom_fields WHERE company_id = ? AND objet = ? AND cle = ?',
            [self::ici(), $objet, $cle]
        ) !== null) {
            $suffixe++;
            $cle = mb_substr($base . '_' . $suffixe, 0, 40);
        }

        $id = Db::insert(
            'INSERT INTO custom_fields (company_id, objet, cle, libelle, type, choix, obligatoire, aide, rang)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [self::ici(), $objet, $cle, $nom, $type, implode("\n", $propres),
             empty($valeurs['obligatoire']) ? 0 : 1,
             trim((string) ($valeurs['aide'] ?? '')), (int) ($valeurs['rang'] ?? 0)],
        );
        return self::champ($id) ?? [];
    }

    /**
     * Modifier : ni le type ni la clé ne bougent.
     *
     * Changer un champ texte en nombre laisserait derrière des valeurs que le
     * nouveau type refuse — invisibles, puisque la validation ne joue qu'à
     * l'écriture, et qui ressortiraient au premier export. Pour changer de
     * type, on crée un champ et on éteint l'ancien.
     */
    public static function modifier(int $id, array $valeurs): ?array
    {
        $existant = self::champ($id);
        if ($existant === null) {
            return null;
        }
        $nom = trim((string) ($valeurs['libelle'] ?? ''));
        if ($nom === '') {
            throw new \RuntimeException('champs.err.libelle');
        }

        $propres = $existant['type'] === 'liste' ? self::choixDe(['choix' => $valeurs['choix'] ?? '']) : [];
        if ($existant['type'] === 'liste' && $propres === []) {
            throw new \RuntimeException('champs.err.listeVide');
        }

        Db::run(
            'UPDATE custom_fields SET libelle = ?, choix = ?, obligatoire = ?, aide = ?, rang = ?
             WHERE id = ? AND company_id = ?',
            [$nom, implode("\n", $propres), empty($valeurs['obligatoire']) ? 0 : 1,
             trim((string) ($valeurs['aide'] ?? '')), (int) ($valeurs['rang'] ?? 0), $id, self::ici()],
        );
        return self::champ($id);
    }

    /** Éteindre : le champ quitte les écrans, ses valeurs restent. */
    public static function basculer(int $id, bool $actif): ?array
    {
        if (self::champ($id) === null) {
            return null;
        }
        Db::run(
            'UPDATE custom_fields SET actif = ? WHERE id = ? AND company_id = ?',
            [$actif ? 1 : 0, $id, self::ici()]
        );
        return self::champ($id);
    }

    /**
     * Supprimer : le champ et toutes ses valeurs.
     *
     * Le nombre de valeurs est rendu pour que l'écran puisse le dire avant de
     * demander confirmation — « vous allez effacer 340 valeurs » n'est pas la
     * même phrase que « supprimer ce champ ? ».
     */
    public static function supprimer(int $id): ?array
    {
        $existant = self::champ($id);
        if ($existant === null) {
            return null;
        }
        $valeurs = self::compterValeurs($id);
        $perimetre = self::ici();
        Db::transaction(static function () use ($id, $perimetre): void {
            Db::run('DELETE FROM custom_values WHERE field_id = ?', [$id]);
            Db::run('DELETE FROM custom_fields WHERE id = ? AND company_id = ?', [$id, $perimetre]);
        });
        return ['champ' => $existant, 'valeurs' => $valeurs];
    }

    public static function compterValeurs(int $id): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM custom_values WHERE field_id = ?', [$id]);
    }

    /* ----------------------------------------------------------- valeurs */

    /**
     * Les valeurs d'un enregistrement, prêtes pour un écran.
     *
     * Rend une ligne par champ actif, valeur comprise — y compris les champs
     * jamais renseignés, qui rendent une valeur vide. Un écran qui ne
     * recevrait que les valeurs posées devrait recroiser lui-même avec les
     * définitions, et la moitié des écrans l'oublierait.
     *
     * @return list<array<string, mixed>>
     */
    public static function valeurs(string $objet, int $recordId): array
    {
        $liste = self::champs($objet);
        if ($liste === []) {
            return [];
        }
        $posees = [];
        foreach (Db::all(
            'SELECT v.field_id, v.valeur FROM custom_values v
             JOIN custom_fields f ON f.id = v.field_id
             WHERE f.objet = ? AND v.record_id = ?',
            [$objet, $recordId],
        ) as $ligne) {
            $posees[(int) $ligne['field_id']] = (string) $ligne['valeur'];
        }

        return array_map(static function (array $c) use ($posees): array {
            $c['valeur'] = $posees[(int) $c['id']] ?? '';
            $c['choixListe'] = self::choixDe($c);
            return $c;
        }, $liste);
    }

    /**
     * Les valeurs de plusieurs enregistrements, en une requête.
     *
     * Une liste qui appellerait « valeurs » ligne par ligne ferait une requête
     * par ligne — invisible sur dix factures, pesant sur deux cents. Rend un
     * tableau indexé par identifiant, chaque entrée ayant la même forme que
     * « valeurs ».
     *
     * Aucun champ posé sur l'objet, ou aucun identifiant : le tableau rendu est
     * vide, et l'écran n'affiche rien. C'est ce qui rend l'appel gratuit tant
     * que l'entreprise n'a rien ajouté.
     *
     * @param list<int> $ids
     * @return array<int, list<array<string, mixed>>>
     */
    public static function valeursEnLot(string $objet, array $ids): array
    {
        $liste = self::champs($objet);
        $propres = array_values(array_unique(array_filter(
            array_map(static fn (mixed $n): int => (int) $n, $ids),
            static fn (int $n): bool => $n > 0,
        )));
        if ($liste === [] || $propres === []) {
            return [];
        }

        $marques = implode(', ', array_fill(0, count($propres), '?'));
        $lignes = Db::all(
            "SELECT v.field_id, v.record_id, v.valeur FROM custom_values v
             WHERE v.record_id IN ($marques)
               AND v.field_id IN (SELECT id FROM custom_fields WHERE company_id = ? AND objet = ?)",
            array_merge($propres, [self::ici(), $objet]),
        );

        $posees = [];
        foreach ($lignes as $ligne) {
            $posees[(int) $ligne['record_id']][(int) $ligne['field_id']] = (string) $ligne['valeur'];
        }

        $sortie = [];
        foreach ($propres as $id) {
            $sortie[$id] = array_map(static function (array $c) use ($posees, $id): array {
                $c['valeur'] = $posees[$id][(int) $c['id']] ?? '';
                $c['choixListe'] = self::choixDe($c);
                return $c;
            }, $liste);
        }
        return $sortie;
    }

    /** La même chose, réduite à une table clé → valeur. Pour un export, un gabarit. */
    public static function valeursParCle(string $objet, int $recordId): array
    {
        $sortie = [];
        foreach (self::valeurs($objet, $recordId) as $ligne) {
            $sortie[(string) $ligne['cle']] = (string) $ligne['valeur'];
        }
        return $sortie;
    }

    /**
     * Contrôler ce qu'un formulaire a envoyé, sans rien écrire.
     *
     * Séparé de l'écriture parce que deux moments en ont besoin : enregistrer,
     * et créer. Un écran de création ne peut pas écrire les valeurs avant que
     * l'enregistrement existe, et il ne peut pas non plus le créer avant de
     * savoir si les valeurs passent — sans quoi un champ refusé laisserait un
     * enregistrement nu derrière lui.
     *
     * @return array{aPoser:list<array{champ:array, valeur:string}>, erreurs:list<array{champ:array, raison:string}>}
     */
    public static function controler(string $objet, array $soumis): array
    {
        $erreurs = [];
        $aPoser = [];

        foreach (self::champs($objet) as $champ) {
            $nom = 'perso_' . $champ['id'];
            // Une case à cocher absente vaut « non » ; un autre champ absent
            // veut dire « ce formulaire ne le portait pas », et sa valeur ne
            // bouge pas.
            if (!array_key_exists($nom, $soumis) && $champ['type'] !== 'booleen') {
                continue;
            }

            try {
                $valeur = self::valider((string) $champ['type'], $soumis[$nom] ?? null, $champ);
            } catch (\RuntimeException $erreur) {
                $erreurs[] = ['champ' => $champ, 'raison' => $erreur->getMessage()];
                continue;
            }
            if (!empty($champ['obligatoire']) && $valeur === '') {
                $erreurs[] = ['champ' => $champ, 'raison' => 'champs.err.obligatoire'];
                continue;
            }
            $aPoser[] = ['champ' => $champ, 'valeur' => $valeur];
        }

        return ['aPoser' => $aPoser, 'erreurs' => $erreurs];
    }

    /**
     * Le refus d'un formulaire de création, avant que l'enregistrement existe.
     *
     * Même message que « refus », mais sans écrire : l'écran contrôle, crée,
     * puis enregistre les valeurs sur l'identifiant tout neuf. Dans cet ordre,
     * un champ refusé ne crée rien du tout.
     */
    public static function verifier(string $objet, array $soumis): ?string
    {
        $erreurs = self::controler($objet, $soumis)['erreurs'];
        if ($erreurs === []) {
            return null;
        }
        return $erreurs[0]['champ']['libelle'] . ' : ' . t($erreurs[0]['raison']);
    }

    /**
     * Enregistrer ce qu'un formulaire a envoyé.
     *
     * Tout ou rien : une valeur refusée annule les autres. Un formulaire à
     * moitié enregistré est pire qu'un formulaire refusé, parce que
     * l'utilisateur croit avoir corrigé ce qu'il voit et ne voit pas ce qui
     * n'est pas passé.
     *
     * @return array{enregistres:int, erreurs:list<array{champ:array, raison:string}>}
     */
    public static function enregistrer(string $objet, int $recordId, array $soumis): array
    {
        $liste = self::champs($objet);
        if ($liste === []) {
            return ['enregistres' => 0, 'erreurs' => []];
        }
        if ($recordId <= 0) {
            throw new \RuntimeException('champs.err.enregistrement');
        }

        ['aPoser' => $aPoser, 'erreurs' => $erreurs] = self::controler($objet, $soumis);
        if ($erreurs !== []) {
            return ['enregistres' => 0, 'erreurs' => $erreurs];
        }

        Db::transaction(static function () use ($aPoser, $recordId): void {
            foreach ($aPoser as $pose) {
                $id = (int) $pose['champ']['id'];
                if ($pose['valeur'] === '') {
                    // Une valeur vidée s'efface plutôt que de garder une ligne
                    // vide : sans cela, « jamais renseigné » et « vidé » se
                    // ressembleraient en base sans se ressembler à l'écran.
                    Db::run('DELETE FROM custom_values WHERE field_id = ? AND record_id = ?', [$id, $recordId]);
                    continue;
                }
                Db::run(
                    "INSERT INTO custom_values (field_id, record_id, valeur, updated_at)
                     VALUES (?, ?, ?, datetime('now'))
                     ON CONFLICT(field_id, record_id) DO UPDATE SET valeur = excluded.valeur, updated_at = excluded.updated_at",
                    [$id, $recordId, $pose['valeur']],
                );
            }
        });

        return ['enregistres' => count($aPoser), 'erreurs' => []];
    }

    /**
     * Enregistrer et rendre le refus tout fait, pour les écrans.
     *
     * Huit fiches appellent « enregistrer » et doivent toutes dire la même
     * chose quand une valeur est refusée : le libellé du champ, puis la raison
     * traduite. Recopier ces trois lignes huit fois, c'est accepter que la
     * huitième dise autre chose — ou oublie de nommer le champ fautif.
     *
     * Rend null quand tout est passé, le message à afficher sinon.
     */
    public static function refus(string $objet, int $recordId, array $soumis): ?string
    {
        $resultat = self::enregistrer($objet, $recordId, $soumis);
        if ($resultat['erreurs'] === []) {
            return null;
        }
        $premier = $resultat['erreurs'][0];
        return $premier['champ']['libelle'] . ' : ' . t($premier['raison']);
    }

    /**
     * Le ménage, quand l'enregistrement décoré s'en va.
     *
     * Aucune clé étrangère ne peut le faire : la table visée dépend de la
     * colonne « objet », et une contrainte ne sait pas dire « selon la valeur
     * d'une autre colonne ». C'est donc au module de suppression de l'objet
     * d'appeler ceci.
     */
    public static function oublier(string $objet, int $recordId): int
    {
        if (!isset(self::OBJETS[$objet])) {
            return 0;
        }
        return Db::run(
            'DELETE FROM custom_values WHERE record_id = ?
               AND field_id IN (SELECT id FROM custom_fields WHERE objet = ?)',
            [$recordId, $objet],
        );
    }

    /**
     * Les valeurs orphelines : celles dont l'enregistrement n'existe plus.
     *
     * Un module qui supprime sans appeler « oublier » laisse des valeurs
     * derrière lui. Elles ne gênent personne — elles ne sont jamais lues, la
     * lecture passant par l'enregistrement — mais elles reviendraient à
     * l'écran si l'identifiant était réattribué.
     */
    public static function orphelines(bool $effacer = false): int
    {
        $total = 0;
        foreach (self::OBJETS as $objet => $infos) {
            $table = $infos['table'];
            $condition = "v.record_id NOT IN (SELECT id FROM $table)
                            AND v.field_id IN (SELECT id FROM custom_fields WHERE company_id = ? AND objet = ?)";
            $n = (int) Db::value(
                "SELECT COUNT(*) FROM custom_values v WHERE $condition",
                [self::ici(), $objet]
            );
            if ($n > 0 && $effacer) {
                /*
                 * MySQL refuse de lire la table qu'il efface dans une
                 * sous-requête — « You can't specify target table for update
                 * in FROM clause ». On relève donc les couples d'abord, et on
                 * efface ensuite, ce que les deux moteurs acceptent.
                 */
                foreach (Db::all(
                    "SELECT v.field_id, v.record_id FROM custom_values v WHERE $condition",
                    [self::ici(), $objet]
                ) as $ligne) {
                    Db::run('DELETE FROM custom_values WHERE field_id = ? AND record_id = ?',
                        [(int) $ligne['field_id'], (int) $ligne['record_id']]);
                }
            }
            $total += $n;
        }
        return $total;
    }
}
