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
