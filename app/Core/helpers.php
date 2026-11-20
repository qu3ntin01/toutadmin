<?php

declare(strict_types=1);

/**
 * Échappement HTML. Nommé court parce qu'il apparaît dans chaque gabarit :
 * un nom long y serait recopié des centaines de fois, et une sortie non
 * échappée est la faille la plus facile à laisser passer.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Traduit une clé dans la langue de la requête. */
function t(string $key, array $params = []): string
{
    return \App\Core\I18n::translate($key, $params);
}

/** Libellé traduit d'un statut stocké en français. */
function st(?string $value): string
{
    return \App\Core\I18n::status($value);
}

/**
 * L'adresse d'un fichier statique, suivie de son empreinte.
 *
 * Une mise à jour remplace la feuille de style ; le navigateur, lui, garde
 * l'ancienne — nginx pose volontiers « expires 30d » sur /css et /js, et le
 * HTML tout neuf se retrouve peint avec la CSS d'avant. L'écran est alors
 * cassé sans que rien ne soit en panne, et personne ne pense à vider un cache.
 *
 * Le nom du fichier ne change pas ; son adresse, si. La date de dernière
 * écriture suffit : elle change à chaque pose de fichier et à aucun autre
 * moment, de sorte que le cache long redevient ce qu'il doit être — une bonne
 * chose, jusqu'à la mise à jour suivante.
 */
function asset(string $path): string
{
    static $empreintes = [];

    if (!array_key_exists($path, $empreintes)) {
        $fichier = APP_ROOT . '/public' . $path;
        $empreintes[$path] = is_file($fichier) ? (string) filemtime($fichier) : '';
    }

    return $empreintes[$path] === '' ? $path : $path . '?v=' . $empreintes[$path];
}
