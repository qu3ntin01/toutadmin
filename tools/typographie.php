<?php

/**
 * L'espace insécable française, posée et vérifiée.
 *
 * Le pendant de « scripts/typographie.js » de l'édition Node, et la raison
 * d'être est la même : le français met une espace devant «  ; : ? ! » et à
 * l'intérieur des guillemets, et une espace ordinaire à cet endroit est un
 * endroit où la ligne peut se couper. Elle se coupe. Sur la carte des accès,
 * « qui lit ces demandes ? ». rendait un « ». » solitaire en début de ligne
 * suivante — une faute de composition, sur la capture même que le site montre
 * pour vendre l'écran.
 *
 * Aucun des seize fichiers de langue n'avait jusqu'ici la moindre espace
 * insécable. Ce script en pose, et « tests/test_typographie.php » veille.
 *
 * ── Quelle espace, et pourquoi celle-là ──────────────────────────────────
 *
 * U+00A0, l'espace insécable ordinaire, et non U+202F, l'espace fine que
 * l'Imprimerie nationale prescrit devant «  ; ? ! ». La fine est plus juste à
 * l'œil ; elle manque aussi à beaucoup de polices, où elle se rend en
 * rectangle vide ou disparaît. Ces chaînes ne vont pas qu'à l'écran : elles
 * entrent dans les PDF que le produit génère, dans des courriels, dans des
 * exports. Une espace présente partout et un peu large vaut mieux qu'une
 * espace juste qui manque une fois sur dix. U+00A0 est d'ailleurs la bonne
 * espace devant les deux-points, qui est le cas le plus fréquent ici.
 *
 * ── Ce qu'il ne touche pas ───────────────────────────────────────────────
 *
 * Les commentaires, le code, et tout ce qui n'est pas une chaîne. Un
 * « $a ? $b : $c » n'est pas de la typographie française. Le fichier est donc
 * lu comme du PHP, pas comme du texte : seules les chaînes sont transformées.
 *
 * Les quinze autres langues ne sont pas touchées non plus : l'allemand,
 * l'anglais, l'arabe et le japonais collent leur ponctuation, et leur poser
 * une espace serait y introduire la faute qu'on corrige ici.
 *
 *     php tools/typographie.php              # dit ce qui manque
 *     php tools/typographie.php --corriger   # le pose
 */

declare(strict_types=1);

/** L'espace insécable. Voir plus haut pourquoi celle-ci et pas la fine. */
const TYPO_INSECABLE = "\u{00a0}";

/**
 * Les signes qui veulent une espace insécable avant eux.
 *
 * Le tiret cadratin n'y est pas : il s'emploie sans espace insécable, et une
 * ligne qui casse devant un tiret d'incise ne choque personne.
 */
const TYPO_AVANT = [';', '?', '!', '»', '%', '€'];

/** Et celui qui la veut après lui. */
const TYPO_APRES = '«';

/**
 * Le fichier de langue française, et lui seul. Relatif à la racine du dépôt.
 */
const TYPO_FICHIER = 'app/locales/fr.php';

/**
 * Poser les espaces insécables dans un texte déjà isolé de son code.
 *
 * Idempotent : une espace déjà insécable est laissée telle quelle, et deux
 * passages donnent le même résultat qu'un seul.
 */
function typo_poser(string $texte): string
{
    // Un garde-fou, pour le jour où l'on pointera l'outil ailleurs.
    //
    // Les deux règles ci-dessous écartent « = ? » et « (:client) ». Elles
    // n'écartent pas « LIMIT ? » ni « LIKE ? », dont le point d'interrogation
    // est précédé d'un mot, exactement comme une question française — aucune
    // règle de ponctuation ne saurait les distinguer. La seule défense est de
    // ne jamais présenter à cet outil un texte qui contient une requête.
    //
    // « <select name=… » n'en est pas une : le motif exige un FROM après le
    // SELECT plutôt que le seul mot.
    if (preg_match('/\\b(?:INSERT\\s+INTO|DELETE\\s+FROM|UPDATE\\s+[A-Za-z_]+\\s+SET|SELECT\\b[\\s\\S]{0,300}?\\bFROM)\\b/i', $texte) === 1) {
        throw new RuntimeException(
            'Texte contenant une requête SQL — l’outil refuse d’y toucher : '
            . mb_substr(trim($texte), 0, 80),
        );
    }

    // Deux précautions, et elles ne sont pas décoratives.
    //
    // 1. Ce qui précède l'espace doit être un mot, un chiffre, ou ce qui ferme
    //    un mot — une parenthèse, un guillemet, un point. En français c'est
    //    toujours le cas. En SQL, « VALUES (?, ?) » et « WHERE id = ? » ont un
    //    point d'interrogation précédé d'une virgule, d'une parenthèse ou d'un
    //    signe égal : ce sont des paramètres, pas des questions, et une espace
    //    insécable y donne une requête que la base refuse.
    //
    // 2. Le deux-points ne doit pas être suivi d'une lettre. « = :client » est
    //    un paramètre nommé ; en français, le deux-points est suivi d'une
    //    espace, ou termine le texte.
    //
    // Sans ces deux règles, l'outil casse silencieusement toute requête du
    // dépôt où il passe — rien dans la syntaxe de PHP ne s'en plaint, et cela
    // ne se voit qu'à l'exécution.
    $avantOk = '[\p{L}\p{N})}»’\'".…!?%€]';

    foreach (TYPO_AVANT as $signe) {
        $echappe = preg_quote($signe, '/');
        $texte = (string) preg_replace(
            '/(?<=' . $avantOk . ') ' . $echappe . '/u',
            TYPO_INSECABLE . $signe,
            $texte,
        );
    }

    $texte = (string) preg_replace(
        '/(?<=' . $avantOk . ') :(?![A-Za-z0-9_])/u',
        TYPO_INSECABLE . ':',
        $texte,
    );

    return str_replace(TYPO_APRES . ' ', TYPO_APRES . TYPO_INSECABLE, $texte);
}

/**
 * Relire un fichier PHP et n'appliquer « typo_poser » qu'aux chaînes.
 *
 * L'analyseur de PHP est dans PHP : token_get_all rend les chaînes déjà
 * séparées du code et des commentaires, ce qui vaut mieux que de les
 * reconnaître à la main. Les chaînes à guillemets doubles (T_ENCAPSED…) ne
 * sont pas touchées : le fichier de langue n'en contient pas, et une
 * interpolation de variable ne se recompose pas aussi simplement.
 */
function typo_transformer(string $source): string
{
    $sortie = '';
    foreach (token_get_all($source) as $jeton) {
        if (is_string($jeton)) {
            $sortie .= $jeton;
            continue;
        }

        [$type, $texte] = $jeton;
        if ($type === T_CONSTANT_ENCAPSED_STRING) {
            $guillemet = $texte[0];
            $contenu = substr($texte, 1, -1);
            $sortie .= $guillemet . typo_poser($contenu) . $guillemet;
            continue;
        }

        $sortie .= $texte;
    }

    return $sortie;
}

/**
 * Ce qui reste à corriger : une ligne par endroit, avec son numéro.
 *
 * Le témoin est la liste, pas un booléen : un test qui dit « la typographie
 * n'est pas bonne » sans dire où ne sert à rien.
 *
 * @return list<array{ligne:int, texte:string}>
 */
function typo_verifier(?string $racine = null): array
{
    $racine ??= dirname(__DIR__);
    $chemin = $racine . '/' . TYPO_FICHIER;
    $source = (string) file_get_contents($chemin);
    $corrige = typo_transformer($source);
    if ($corrige === $source) {
        return [];
    }

    $avant = explode("\n", $source);
    $apres = explode("\n", $corrige);
    $manques = [];
    foreach ($avant as $i => $ligne) {
        if (($apres[$i] ?? null) !== $ligne) {
            $manques[] = ['ligne' => $i + 1, 'texte' => trim($ligne)];
        }
    }

    return $manques;
}

function typo_corriger(?string $racine = null): void
{
    $racine ??= dirname(__DIR__);
    $chemin = $racine . '/' . TYPO_FICHIER;
    $source = (string) file_get_contents($chemin);
    $corrige = typo_transformer($source);
    if ($corrige !== $source) {
        file_put_contents($chemin, $corrige);
    }
}

// Appelé en ligne de commande, et non inclus par un test.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    if (in_array('--corriger', $argv, true)) {
        typo_corriger();
        $reste = typo_verifier();
        echo $reste === []
            ? TYPO_FICHIER . " : espaces insécables posées.\n"
            : TYPO_FICHIER . ' : ' . count($reste) . " ligne(s) résistent encore.\n";
        exit(0);
    }

    $manques = typo_verifier();
    if ($manques === []) {
        echo TYPO_FICHIER . " : typographie française conforme.\n";
        exit(0);
    }

    fwrite(STDERR, TYPO_FICHIER . ' : ' . count($manques) . " ligne(s) sans espace insécable.\n");
    foreach (array_slice($manques, 0, 20) as $m) {
        fwrite(STDERR, '  ' . $m['ligne'] . '  ' . mb_substr($m['texte'], 0, 110) . "\n");
    }
    if (count($manques) > 20) {
        fwrite(STDERR, '  … et ' . (count($manques) - 20) . " autre(s).\n");
    }
    fwrite(STDERR, "Posez-les : php tools/typographie.php --corriger\n");
    exit(1);
}
