<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Traduction du dialecte.
 *
 * Le schéma et les requêtes de ce produit sont écrits en SQLite — c'est la
 * base par défaut, celle qui ne demande rien à l'hébergement. Servir aussi
 * MySQL n'exige pas de réécrire mille requêtes : elles ne s'écartent de la
 * norme qu'en une poignée d'endroits, tous recensés ci-dessous.
 *
 * La traduction se fait ici, à un seul endroit, plutôt qu'aux mille points
 * d'appel. Ce qui suit dit donc aussi, noir sur blanc, ce que le produit
 * emploie de non portable — et quiconque en ajoutera devra passer par ici.
 */
final class Sql
{
    /**
     * Les colonnes de texte servant de clé ou d'index ne peuvent rester TEXT :
     * MySQL refuse d'indexer un TEXT sans longueur de préfixe. 191 caractères
     * tient sous la limite de 767 octets des anciennes versions en utf8mb4.
     */
    private const LONGUEUR_INDEX = 191;

    /** Une requête ordinaire, dite en MySQL. */
    public static function requete(string $sql): string
    {
        $sql = self::dates($sql);

        $sql = self::sansNocase($sql);

        // « max(a, b) » est en SQLite le plus grand de deux valeurs ; en MySQL,
        // MAX est un agrégat et n'en prend qu'une. Le nom diffère, pas l'idée.
        $sql = preg_replace('/\bMAX\s*\(([^(),]+),\s*([^(),]+)\)/i', 'GREATEST($1, $2)', $sql) ?? $sql;
        $sql = preg_replace('/\bMIN\s*\(([^(),]+),\s*([^(),]+)\)/i', 'LEAST($1, $2)', $sql) ?? $sql;

        $sql = self::upsert($sql);

        // « INSERT OR IGNORE » n'existe pas : MySQL écrit « INSERT IGNORE ».
        $sql = preg_replace('/\bINSERT\s+OR\s+IGNORE\b/i', 'INSERT IGNORE', $sql) ?? $sql;
        $sql = preg_replace('/\bINSERT\s+OR\s+REPLACE\b/i', 'REPLACE', $sql) ?? $sql;

        return self::echapperMotsReserves($sql);
    }

    /**
     * « COLLATE NOCASE » demande à SQLite d'ignorer la casse. La collation des
     * tables — utf8mb4_unicode_ci — le fait déjà, et MySQL ne connaît pas ce
     * nom. La clause apparaît dans le schéma comme dans les requêtes : d'où
     * une seule fonction, appelée des deux côtés.
     */
    private static function sansNocase(string $sql): string
    {
        return preg_replace('/\s+COLLATE\s+NOCASE\b/i', '', $sql) ?? $sql;
    }

    /**
     * L'insertion qui se rattrape.
     *
     * SQLite écrit « ON CONFLICT(colonnes) DO UPDATE SET x = excluded.x » ;
     * MySQL, « ON DUPLICATE KEY UPDATE x = VALUES(x) ». La liste de colonnes
     * disparaît au passage : MySQL retient n'importe quelle clé unique, ce qui
     * revient au même ici, chaque table n'en ayant qu'une qui puisse entrer en
     * conflit.
     *
     * « DO NOTHING » devient « INSERT IGNORE », qui se pose sur le verbe et
     * non à la fin — d'où la reprise du début de la requête.
     */
    private static function upsert(string $sql): string
    {
        $sql = preg_replace_callback(
            '/\bON\s+CONFLICT\s*\([^)]*\)(?:\s+WHERE\s+.*?)?\s+DO\s+UPDATE\s+SET\s+/is',
            static fn (): string => 'ON DUPLICATE KEY UPDATE ',
            $sql
        ) ?? $sql;

        // « excluded.x » désigne la ligne qu'on tentait d'insérer ; MySQL la
        // nomme VALUES(x).
        $sql = preg_replace('/\bexcluded\.(\w+)/i', 'VALUES($1)', $sql) ?? $sql;

        $sql = preg_replace_callback(
            '/\bINSERT\b(.*?)\bON\s+CONFLICT\s*\([^)]*\)(?:\s+WHERE\s+.*?)?\s+DO\s+NOTHING/is',
            static fn (array $m): string => 'INSERT IGNORE' . rtrim($m[1]),
            $sql
        ) ?? $sql;

        return $sql;
    }

    /**
     * Les dates.
     *
     * L'heure « maintenant » de SQLite est en temps universel ; NOW() est à
     * l'heure de la session. Prendre NOW() ferait dériver les dates de
     * plusieurs heures selon le fuseau du serveur, silencieusement — d'où
     * UTC_TIMESTAMP() et UTC_DATE().
     *
     * Les modificateurs littéraux se traduisent en intervalles. Ceux passés en
     * paramètre ne le peuvent pas : une unité de temps n'est pas une valeur
     * qu'on lie, et c'est pourquoi le code appelant calcule désormais sa date
     * lui-même. S'il en reste un, mieux vaut le dire que le laisser passer.
     */
    private static function dates(string $sql): string
    {
        if (preg_match("/\b(?:date|datetime)\('now',\s*\?/i", $sql)) {
            throw new \RuntimeException(
                'Modificateur de date passé en paramètre : intraduisible en MySQL. '
                . 'Calculez la date en PHP et comparez-la à une valeur.'
            );
        }

        // Un décalage calculé à partir d'une colonne : « date(fin, '-' || préavis
        // || ' days') ». Le modificateur n'est ni littéral ni lié, il est
        // construit par la requête — d'où une règle à lui. Un seul endroit
        // l'emploie : l'échéance de dénonciation d'un contrat, qui tombe avant
        // sa fin du nombre de jours de préavis.
        $sql = preg_replace_callback(
            "/\bdate\(\s*(\w+)\s*,\s*'([+-])'\s*\|\|\s*(.+?)\s*\|\|\s*' days'\s*\)/i",
            static function (array $m): string {
                // Le signe est dans la requête, pas dans ce fichier : il faut
                // le lire à l'exécution, et non comparer un texte de motif.
                $sens = $m[2] === '-' ? 'DATE_SUB' : 'DATE_ADD';
                return "$sens({$m[1]}, INTERVAL {$m[3]} DAY)";
            },
            $sql
        ) ?? $sql;

        $sql = preg_replace_callback(
            "/\b(date|datetime)\('now'(?:,\s*'([^']*)')?\)/i",
            static function (array $m): string {
                $base = strtolower($m[1]) === 'date' ? 'UTC_DATE()' : 'UTC_TIMESTAMP()';
                $modificateur = trim($m[2] ?? '');
                if ($modificateur === '') {
                    return $base;
                }
                if (strcasecmp($modificateur, 'start of month') === 0) {
                    return "DATE_FORMAT($base, '%Y-%m-01')";
                }
                if (preg_match('/^([+-])\s*(\d+)\s+(day|days|month|months|year|years|hour|hours|minute|minutes)$/i', $modificateur, $d)) {
                    $sens = $d[1] === '-' ? 'DATE_SUB' : 'DATE_ADD';
                    $unite = strtoupper(rtrim(strtolower($d[3]), 's'));
                    return "$sens($base, INTERVAL {$d[2]} $unite)";
                }
                throw new \RuntimeException('Modificateur de date inconnu : ' . $modificateur);
            },
            $sql
        ) ?? $sql;

        return $sql;
    }

    /**
     * Les noms de colonne que MySQL se réserve.
     *
     * La liste n'est pas devinée : les cinq cent trente-sept noms de colonne
     * du schéma ont été soumis au serveur un par un, et ces deux-là sont les
     * seuls qu'il refuse. Un nom ajouté plus tard qui collerait à un mot
     * réservé se signalerait par une erreur de syntaxe à l'installation — il
     * suffira alors de l'inscrire ici.
     */
    private const MOTS_RESERVES = ['key', 'cursor'];

    /**
     * On les entoure d'accents graves — sans toucher aux « PRIMARY KEY »,
     * « FOREIGN KEY », « UNIQUE KEY » ni « ON DUPLICATE KEY », qui sont de la
     * syntaxe et non des noms de colonne.
     */
    private static function echapperMotsReserves(string $sql): string
    {
        foreach (self::MOTS_RESERVES as $mot) {
            $sql = preg_replace(
                '/(?<!`)(?<!PRIMARY )(?<!FOREIGN )(?<!UNIQUE )(?<!DUPLICATE )(?<!\.)\b' . $mot . '\b(?!`)(?!\s*\()/i',
                '`' . $mot . '`',
                $sql
            ) ?? $sql;
        }
        return $sql;
    }

    /**
     * Le schéma, dit en MySQL.
     *
     * Deux difficultés : l'auto-incrément ne s'écrit pas pareil, et une
     * colonne de texte indexée doit annoncer sa longueur. On relève donc
     * d'abord quelles colonnes sont indexées — clé primaire, contrainte
     * d'unicité, index déclaré — avant de choisir leur type.
     */
    public static function schema(string $sql): string
    {
        $indexees = self::colonnesIndexees($sql);

        $sortie = [];
        foreach (self::morceaux($sql) as $instruction) {
            $traduite = self::instructionSchema($instruction, $indexees);
            if ($traduite !== null) {
                $sortie[] = $traduite;
            }
        }
        return implode(";\n", $sortie) . ";\n";
    }

    /**
     * Les index partiels.
     *
     * SQLite sait n'indexer qu'une partie des lignes ; MySQL non. Les trois
     * cas du schéma ne se valent pas, et aucune règle générale ne les couvre :
     *
     *  • « WHERE colonne IS NOT NULL » sur une colonne de l'index n'a pas
     *    d'équivalent à écrire : MySQL laisse déjà les NULL se répéter dans un
     *    index unique. Retirer la clause rend exactement le même sens.
     *
     *  • « WHERE dedupe_key != '' » et « WHERE revoked_on IS NULL » portent
     *    sur autre chose. Les retirer interdirait deux notifications sans clé
     *    de dédoublonnage, ou de rendre une licence à quelqu'un à qui on l'a
     *    retirée. On passe donc par une colonne calculée qui vaut NULL hors du
     *    domaine voulu — et les NULL, eux, ne se heurtent pas.
     */
    private const INDEX_PARTIELS = [
        'idx_notifications_dedupe' => [
            'colonne' => 'dedupe_unique',
            'calcul' => "NULLIF(dedupe_key, '')",
            'type' => 'VARCHAR(191)',
            'table' => 'notifications',
            'index' => 'user_id, dedupe_unique',
        ],
        'idx_software_accesses_open' => [
            'colonne' => 'user_ouvert',
            'calcul' => 'IF(revoked_on IS NULL, user_id, NULL)',
            'type' => 'BIGINT',
            'table' => 'software_accesses',
            'index' => 'licence_id, user_ouvert',
        ],
    ];

    private static function index(string $instruction): string
    {
        // MySQL ne connaît pas « CREATE INDEX IF NOT EXISTS » ; MariaDB si.
        // On retire la clause, l'appelant rattrapant le doublon.
        $instruction = preg_replace('/\bIF\s+NOT\s+EXISTS\s+/i', '', $instruction) ?? $instruction;

        if (!preg_match('/\bWHERE\b/i', $instruction)) {
            return $instruction;
        }

        preg_match('/INDEX\s+(\w+)\s+ON/i', $instruction, $m);
        $nom = $m[1] ?? '';
        $cas = self::INDEX_PARTIELS[$nom] ?? null;

        if ($cas === null) {
            // Le filtre ne porte que sur des colonnes de l'index : la clause
            // tombe d'elle-même. Si elle portait sur autre chose, il faudrait
            // l'inscrire ci-dessus plutôt que de perdre la contrainte.
            return (string) preg_replace('/\s+WHERE\b.*$/is', '', $instruction);
        }

        return sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s AS (%s) VIRTUAL;' . "\n"
            . 'CREATE UNIQUE INDEX %s ON %s(%s)',
            $cas['table'], $cas['colonne'], $cas['type'], $cas['calcul'],
            $nom, $cas['table'], $cas['index']
        );
    }

    /** Les colonnes que le schéma indexe, par table. */
    private static function colonnesIndexees(string $sql): array
    {
        $par_table = [];

        // Les index déclarés à part.
        if (preg_match_all('/CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?[^\s(]+\s+ON\s+(\w+)\s*\(([^)]*)\)/i', $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $index) {
                foreach (explode(',', $index[2]) as $colonne) {
                    // « ON t(a, b DESC) » : le sens du tri n'est pas un nom de
                    // colonne, et le garder ferait manquer la colonne — qui
                    // resterait alors en TEXT, que MySQL refuse d'indexer.
                    $colonne = preg_replace('/\s+(ASC|DESC)\s*$/i', '', trim($colonne)) ?? $colonne;
                    $par_table[strtolower($index[1])][] = strtolower(trim($colonne, " \t\n`"));
                }
            }
        }

        // Les contraintes posées dans la table même.
        if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)\s*\((.*?)\n\)/is', $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $table) {
                $nom = strtolower($table[1]);
                foreach (explode("\n", $table[2]) as $ligne) {
                    $ligne = trim($ligne);
                    if (preg_match('/^(\w+)\s+TEXT\b.*\b(PRIMARY\s+KEY|UNIQUE)\b/i', $ligne, $c)) {
                        $par_table[$nom][] = strtolower($c[1]);
                    }
                    if (preg_match('/^(?:UNIQUE|PRIMARY\s+KEY)\s*\(([^)]*)\)/i', $ligne, $c)) {
                        foreach (explode(',', $c[1]) as $colonne) {
                            $par_table[$nom][] = strtolower(trim($colonne, " \t`"));
                        }
                    }
                }
            }
        }

        return array_map('array_unique', $par_table);
    }

    private static function instructionSchema(string $instruction, array $indexees): ?string
    {
        $instruction = trim($instruction);
        if ($instruction === '') {
            return null;
        }

        // Le schéma est commenté : chaque table est précédée de ce qu'elle
        // sert. Les commentaires font partie de l'instruction et masqueraient
        // le mot-clé si l'on regardait seulement son premier mot.
        $corps = self::sansCommentaireDeTete($instruction);
        if ($corps === '' || preg_match('/^PRAGMA\b/i', $corps)) {
            return null;   // les réglages de SQLite n'ont pas d'équivalent
        }

        if (preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX/i', $corps)) {
            return self::index($corps);
        }

        if (!preg_match('/^CREATE\s+TABLE/i', $corps)) {
            return self::requete($instruction);
        }

        preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)/i', $corps, $m);
        $table = strtolower($m[1] ?? '');
        $colonnes = $indexees[$table] ?? [];

        $lignes = [];
        foreach (explode("\n", $instruction) as $ligne) {
            $lignes[] = self::ligneDeTable($ligne, $colonnes);
        }
        $sortie = implode("\n", $lignes);

        // Le schéma est rejoué à chaque démarrage : une table déjà là n'est pas
        // une erreur. C'est ici que la clause se pose, et non chez l'appelant,
        // qui aurait à chercher le mot-clé sous le commentaire de la table.
        $sortie = preg_replace(
            '/\bCREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i',
            'CREATE TABLE IF NOT EXISTS ',
            $sortie,
            1
        ) ?? $sortie;

        // InnoDB et utf8mb4 : les clés étrangères et les seize langues.
        return rtrim($sortie) . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    /** L'instruction sans le bloc de commentaires qui l'annonce. */
    private static function sansCommentaireDeTete(string $instruction): string
    {
        return trim(preg_replace('/\A(?:\s*--[^\n]*\n)+/', '', $instruction) ?? $instruction);
    }

    private static function ligneDeTable(string $ligne, array $indexees): string
    {
        // Un commentaire n'est pas du code : le traduire changerait le texte
        // qui explique la table, parfois jusqu'à le rendre faux.
        if (preg_match('/^\s*--/', $ligne)) {
            return $ligne;
        }

        // L'auto-incrément : en MySQL il fait partie de la définition de clé.
        //
        // Et INTEGER devient BIGINT, non INT : l'INTEGER de SQLite tient sur
        // 64 bits, celui de MySQL sur 32. Rien dans ce produit-ci ne déborde
        // aujourd'hui, mais l'édition Node y range des instants en
        // millisecondes, et les deux éditions doivent poser le même schéma
        // pour qu'une base écrite par l'une soit lisible par l'autre.
        $ligne = preg_replace(
            '/\bINTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT\b/i',
            'BIGINT AUTO_INCREMENT PRIMARY KEY',
            $ligne
        ) ?? $ligne;

        $ligne = preg_replace('/\bINTEGER\b/i', 'BIGINT', $ligne) ?? $ligne;

        // Une valeur par défaut calculée : même traitement que dans les
        // requêtes, temps universel compris.
        $ligne = self::sansNocase(self::dates($ligne));


        // Une colonne de texte indexée doit annoncer sa longueur.
        if (preg_match('/^\s*(\w+)\s+TEXT\b/i', $ligne, $c)
            && in_array(strtolower($c[1]), $indexees, true)) {
            $ligne = preg_replace('/\bTEXT\b/i', 'VARCHAR(' . self::LONGUEUR_INDEX . ')', $ligne, 1) ?? $ligne;
        }

        return self::echapperMotsReserves($ligne);
    }

    /**
     * Découpe un script en instructions.
     *
     * Db sait déjà le faire, commentaires compris — et c'est une subtilité :
     * le schéma est commenté en français, et les apostrophes de « l'édition »
     * passeraient pour des guillemets ouvrants si on ne sautait pas les
     * commentaires. En écrire un second découpeur, c'était se donner deux
     * occasions de se tromper et n'en corriger qu'une.
     */
    public static function morceaux(string $sql): array
    {
        return Db::statements($sql);
    }
}
