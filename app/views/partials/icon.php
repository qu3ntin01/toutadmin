<?php

/**
 * Jeu d'icônes partagé, repris trait pour trait de l'édition Node.
 *
 * Le rail de cette édition n'en portait aucune : il n'affichait que des
 * libellés. C'est un écart avec l'autre édition qui ne se voyait pas tant que
 * le rail restait large — replié, il aurait donné une bande vide.
 */

$taille = (int) ($iconSize ?? 18);
$nom = (string) ($name ?? '');

$formes = [
    /* Le repli : une rubrique dont le dessin n'a pas encore été choisi. Un
       rond neutre vaut mieux qu'une icône trompeuse ou qu'une case vide. */
    'circle' => '<circle cx="12" cy="12" r="4" />',
    'back' => '<path d="M15 5.5 8.5 12l6.5 6.5" />',
    'ballot' => '<path d="M4 20v-6.5l3-5h10l3 5V20Z" /><path d="M4 13.5h4l1 2h6l1-2h4" /><path d="M12 3v5m0 0-2-2m2 2 2-2" />',
    'briefcase' => '<rect x="3.5" y="7.5" width="17" height="12" rx="2" /><path d="M9 7.5V6a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 6v1.5" /><path d="M3.5 12.5h17" />',
    'calendar' => '<rect x="4" y="5" width="16" height="15" rx="2" /><path d="M8 3v4M16 3v4M4 10h16" />',
    'card' => '<rect x="3.5" y="5.5" width="17" height="13" rx="2" /><path d="M3.5 10h17" /><path d="M7 14.5h4" />',
    'check' => '<circle cx="12" cy="12" r="8.5" /><path d="m8.5 12 2.5 2.5 4.5-5" />',
    'clock' => '<circle cx="12" cy="12" r="8.5" /><path d="M12 7.5v5l3.5 2" />',
    /* Les deux suivantes servent à l'assistant d'installation : la base que
       l'on pose, et la mise en route. */
    'database' => '<ellipse cx="12" cy="6" rx="7.5" ry="3" /><path d="M4.5 6v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3V6" /><path d="M4.5 12v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3v-6" />',
    'external' => '<path d="M14 4h6v6" /><path d="M20 4 11 13" /><path d="M18 14v5a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 4 19V8a1.5 1.5 0 0 1 1.5-1.5H10" />',
    'home' => '<path d="M4 10.5 12 4l8 6.5V19a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 19Z" /><path d="M9.5 20.5v-6h5v6" />',
    'inbox' => '<rect x="4" y="4" width="16" height="16" rx="2" /><path d="M8 9h8M8 13h8M8 17h5" />',
    'lifebuoy' => '<circle cx="12" cy="12" r="8.5" /><circle cx="12" cy="12" r="3.5" /><path d="m6 6 3.5 3.5M18 6l-3.5 3.5M6 18l3.5-3.5M18 18l-3.5-3.5" />',
    'link' => '<path d="M10 13.5a3.5 3.5 0 0 0 5 0l3-3a3.5 3.5 0 0 0-5-5l-1.5 1.5" /><path d="M14 10.5a3.5 3.5 0 0 0-5 0l-3 3a3.5 3.5 0 0 0 5 5l1.5-1.5" />',
    'logout' => '<path d="M14 8V6a1.5 1.5 0 0 0-1.5-1.5h-6A1.5 1.5 0 0 0 5 6v12a1.5 1.5 0 0 0 1.5 1.5h6A1.5 1.5 0 0 0 14 18v-2" /><path d="M17 12H9" /><path d="m14.5 9 3 3-3 3" />',
    'megaphone' => '<path d="M4 10v4a1.5 1.5 0 0 0 1.5 1.5H8l7 4.5V5.5L8 10H5.5A1.5 1.5 0 0 0 4 11.5Z" /><path d="M18.5 9.5a3.5 3.5 0 0 1 0 5" />',
    'network' => '<rect x="9" y="3.5" width="6" height="5" rx="1.5" /><rect x="3" y="15.5" width="6" height="5" rx="1.5" /><rect x="15" y="15.5" width="6" height="5" rx="1.5" /><path d="M12 8.5v4M6 15.5v-3h12v3" />',
    'plus' => '<rect x="4" y="4" width="16" height="16" rx="3" /><path d="M8.5 12h7M12 8.5v7" />',
    'rocket' => '<path d="M12 2.5c2.6 2 4 5 4 8.5l-4 4-4-4c0-3.5 1.4-6.5 4-8.5Z" /><circle cx="12" cy="9.5" r="1.8" /><path d="m8 13-2.5 1.5.5 4 3-2" /><path d="m16 13 2.5 1.5-.5 4-3-2" /><path d="M12 19v2.5" />',
    'settings' => '<circle cx="12" cy="12" r="3" /><path d="M12 3.5v2M12 18.5v2M20.5 12h-2M5.5 12h-2M18 6l-1.4 1.4M7.4 16.6 6 18M18 18l-1.4-1.4M7.4 7.4 6 6" />',
    'shield' => '<path d="M12 3.5 5 6.2v5c0 4.6 3 7.4 7 9.3 4-1.9 7-4.7 7-9.3v-5Z" />',
    'ticket' => '<path d="M4 9V7a1.5 1.5 0 0 1 1.5-1.5h13A1.5 1.5 0 0 1 20 7v2a3 3 0 0 0 0 6v2a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 17v-2a3 3 0 0 0 0-6Z" /><path d="M14 6.5v11" />',
    'tool' => '<path d="M14.5 3.5a4.5 4.5 0 0 0 6 6l-11 11-3 1 1-3Z" /><path d="m13 5 6 6" />',
    'user' => '<circle cx="12" cy="8.5" r="3.8" /><path d="M5 20v-1a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v1" />',
    'users' => '<path d="M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20" /><circle cx="10" cy="8" r="3.5" /><path d="M20 20v-1.5a3.5 3.5 0 0 0-2.6-3.4" /><path d="M15 4.6a3.5 3.5 0 0 1 0 6.8" />',
    /* Les vingt-sept suivantes ont été ajoutées avec le rail groupé : quatre
       services partageaient « inbox » et quatre « shield », et un rail replié
       ne montre plus que des icônes — deux rubriques au même dessin ne s'y
       distinguent plus. Un test interdit désormais le doublon. */
    'archive' => '<rect x="3.5" y="4" width="17" height="4.5" rx="1" /><path d="M5 8.5V19a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8.5" /><path d="M10 12.5h4" />',
    'bank' => '<path d="M3.5 9.5 12 4l8.5 5.5" /><path d="M5.5 9.5v8M10 9.5v8M14 9.5v8M18.5 9.5v8" /><path d="M3 20.5h18" />',
    'bell' => '<path d="M7 10a5 5 0 0 1 10 0c0 4 1.5 5.5 1.5 5.5h-13S7 14 7 10Z" /><path d="M10.2 18.5a2 2 0 0 0 3.6 0" />',
    'book' => '<path d="M4.5 5a2 2 0 0 1 2-2H19v15H6.5a2 2 0 0 0-2 2Z" /><path d="M4.5 18a2 2 0 0 0 2 2H19" /><path d="M8.5 7.5h6" />',
    'box' => '<path d="M12 3.5 20 7.5v9L12 20.5 4 16.5v-9Z" /><path d="M4 7.5 12 11.5l8-4" /><path d="M12 11.5v9" />',
    'building' => '<path d="M4.5 20V5.5a1 1 0 0 1 1-1H13a1 1 0 0 1 1 1V20" /><path d="M14 10h4.5a1 1 0 0 1 1 1v9" /><path d="M3 20h18" /><path d="M7.5 8h3M7.5 12h3M7.5 16h3" />',
    'chart' => '<path d="M4 20V4" /><path d="M4 20h16" /><rect x="7.5" y="12" width="3" height="5" rx="0.8" /><rect x="12.5" y="8.5" width="3" height="8.5" rx="0.8" /><rect x="17" y="14.5" width="2.5" height="2.5" rx="0.8" />',
    'code' => '<path d="m8.5 8.5-4 3.5 4 3.5" /><path d="m15.5 8.5 4 3.5-4 3.5" /><path d="m13.5 5-3 14" />',
    'download' => '<path d="M12 3.5v11" /><path d="m7.5 10.5 4.5 4.5 4.5-4.5" /><path d="M4.5 18.5h15" />',
    'euro' => '<circle cx="12" cy="12" r="8.5" /><path d="M15.5 8.6a4 4 0 0 0-5.9 1.4 5 5 0 0 0 0 4 4 4 0 0 0 5.9 1.4" /><path d="M7.8 10.8h5M7.8 13.2h5" />',
    'grid' => '<rect x="4" y="4" width="7" height="7" rx="1.5" /><rect x="13" y="4" width="7" height="7" rx="1.5" /><rect x="4" y="13" width="7" height="7" rx="1.5" /><rect x="13" y="13" width="7" height="7" rx="1.5" />',
    'handshake' => '<path d="M3.5 11.5 7 8h4l2 2-2 2-1.5-1.5" /><path d="M13 10h3l4.5 4-3 3-2.5-2" /><path d="M6 15.5 9 18l2-1.5" /><path d="M11 16.5 13.5 18l1.5-1.5" />',
    'heart' => '<path d="M12 20s-7-4.4-7-9a3.8 3.8 0 0 1 7-2.1A3.8 3.8 0 0 1 19 11c0 4.6-7 9-7 9Z" />',
    'ledger' => '<rect x="4" y="3.5" width="16" height="17" rx="2" /><path d="M8 3.5v17" /><path d="M11.5 8h5M11.5 12h5M11.5 16h3" />',
    'list' => '<path d="M8.5 6.5h11M8.5 12h11M8.5 17.5h11" /><path d="M4.5 6.5h.01M4.5 12h.01M4.5 17.5h.01" />',
    'lock' => '<rect x="4.5" y="10.5" width="15" height="9.5" rx="2" /><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5" /><path d="M12 14.5v2" />',
    'payslip' => '<rect x="4.5" y="3.5" width="15" height="17" rx="2" /><path d="M8 8h8M8 11.5h8" /><path d="M8 15.5h4" /><circle cx="16" cy="16" r="2.5" />',
    'pen' => '<path d="M4 20h4L19.5 8.5a2.1 2.1 0 0 0-3-3L5 17Z" /><path d="m14.5 7 2.5 2.5" />',
    'plug' => '<path d="M9 3.5v5M15 3.5v5" /><path d="M6.5 8.5h11v2.5a5.5 5.5 0 0 1-11 0Z" /><path d="M12 16.5v4" />',
    'receipt' => '<path d="M6 3.5h12v17l-2-1.4-2 1.4-2-1.4-2 1.4-2-1.4-2 1.4Z" /><path d="M9 8h6M9 12h6" />',
    'safe' => '<rect x="3.5" y="4.5" width="17" height="15" rx="2" /><circle cx="11" cy="12" r="3.5" /><path d="M11 8.5v1M11 14.5v1M7.5 12h1M13.5 12h1" /><path d="M17.5 9.5v5" />',
    'scale' => '<path d="M12 4v16M7 20h10" /><path d="M5.5 7.5h13" /><path d="m5.5 7.5-2.5 5.5h5Z" /><path d="m18.5 7.5-2.5 5.5h5Z" /><path d="M3 13a2.5 2.5 0 0 0 5 0M16 13a2.5 2.5 0 0 0 5 0" />',
    'send' => '<path d="M20.5 3.5 10 14" /><path d="M20.5 3.5 14 20.5 10 14 3.5 10Z" />',
    'server' => '<rect x="3.5" y="4" width="17" height="6" rx="1.5" /><rect x="3.5" y="14" width="17" height="6" rx="1.5" /><path d="M7 7h.01M7 17h.01" />',
    'trending' => '<path d="M3.5 16.5 9 11l3.5 3.5L20 7" /><path d="M15 7h5v5" />',
    'truck' => '<path d="M3.5 6.5h9v9h-9z" /><path d="M12.5 10h3.5l3 3v2.5h-6.5" /><circle cx="7" cy="17.5" r="1.8" /><circle cx="17" cy="17.5" r="1.8" />',
    'upload' => '<path d="M12 15V4" /><path d="m7.5 8 4.5-4.5L16.5 8" /><path d="M4.5 18.5h15" />',
    'user-check' => '<path d="M13 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-3A3.5 3.5 0 0 0 3 18.5V20" /><circle cx="8" cy="8" r="3.5" /><path d="m15.5 11.5 2 2 4-4.5" />',
    // Les icônes qu'ajoutent les fiches de la boutique. Le catalogue vit chez
    // l'éditeur : il peut nommer une icône que cette version ne connaît pas
    // encore, et c'est le repli plus bas qui s'en charge.
    'chat' => '<path d="M20 15.5A2.5 2.5 0 0 1 17.5 18H8l-4 3V6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5Z" />',
    'cloud' => '<path d="M7.5 19a4 4 0 0 1-.4-8 5.5 5.5 0 0 1 10.6 1.3A3.6 3.6 0 0 1 17 19Z" />',
    'eye' => '<path d="M2.5 12S6 6 12 6s9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" /><circle cx="12" cy="12" r="3" />',
    'file' => '<path d="M13 3.5H7A1.5 1.5 0 0 0 5.5 5v14A1.5 1.5 0 0 0 7 20.5h10a1.5 1.5 0 0 0 1.5-1.5V9Z" /><path d="M13 3.5V9h5.5" />',
    'key' => '<circle cx="8" cy="12" r="3.5" /><path d="M11.5 12H20" /><path d="M17 12v3" /><path d="M14 12v2.5" />',
    'layers' => '<path d="m12 3.5 8.5 4.5-8.5 4.5L3.5 8Z" /><path d="m3.5 12.5 8.5 4.5 8.5-4.5" /><path d="m3.5 16.5 8.5 4 8.5-4" />',
    'map' => '<path d="M9 4.5 3.5 6.8V20l5.5-2.3 6 2.3 5.5-2.3V4.5L15 6.8Z" /><path d="M9 4.5v13M15 6.8v13" />',
    'star' => '<path d="m12 4 2.6 5.2 5.9.8-4.3 4.1 1.1 5.7L12 17.1 6.7 19.8l1.1-5.7L3.5 10l5.9-.8Z" />',
];

?><svg viewBox="0 0 24 24" width="<?= $taille ?>" height="<?= $taille ?>" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $formes[$nom] ?? $formes['circle'] ?></svg>
