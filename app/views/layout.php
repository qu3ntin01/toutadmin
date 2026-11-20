<?php

use App\Core\Sections;

/*
 * La mise en page commune : le rail, le bandeau, les onglets de l'écran.
 *
 * ── Quel rail ────────────────────────────────────────────────────────────
 *
 * Deux sortes d'écrans cohabitent. Ceux de l'espace d'administration — que le
 * catalogue connaît — portent le rail des services : le même partout, rangé en
 * sept métiers repliables. Les autres espaces (le mien, les RH, le CSE) gardent
 * leur rail à plat, qui n'a qu'une poignée d'entrées et leur est propre.
 *
 * La décision se prend ici, une fois, plutôt que dans quarante-cinq
 * contrôleurs : c'est le chemin courant qui la dicte, et rien d'autre.
 *
 * ── Où vont les onglets ──────────────────────────────────────────────────
 *
 * Les onglets d'un écran tenaient dans le rail, mêlés aux liens vers les autres
 * services : on ne voyait pas où l'on changeait de page et où l'on changeait
 * d'onglet. Sur un écran d'administration, ils passent donc dans une barre sous
 * le titre, et le rail ne porte plus que les services. Ils gardent leur classe
 * « tab-btn » et leur « data-tab » : le script qui les anime n'a pas changé.
 */
$monGroupe = Sections::groupeDuChemin($path ?? null);
$mesDroits = $droits ?? ['role' => null];

/* Chaque groupe s'ouvre sur sa vue d'ensemble : les tuiles y disent ce que
   chaque service sert à faire, ce qu'une liste de noms ne dit pas. Elle porte
   la grille plutôt que l'icône du groupe, laquelle est souvent déjà celle d'un
   des services en dessous — deux lignes voisines au même dessin ne se
   distinguent plus. */
$railGroupe = null;
if ($monGroupe !== null) {
    $railGroupe = [];
    foreach (Sections::GROUPES as $groupe) {
        $visibles = Sections::servicesDe($groupe['cle'], $mesDroits);
        if ($visibles === []) {
            continue;
        }
        $entrees = [[
            'href' => '/section/' . $groupe['cle'],
            'label' => t('nav.overview'),
            'icon' => 'grid',
            'active' => ($path ?? '') === '/section/' . $groupe['cle'],
        ]];
        foreach ($visibles as $service) {
            $chemin = explode('#', $service['href'])[0];
            $entrees[] = [
                'href' => $service['href'],
                'label' => t($service['titre']),
                'icon' => $service['icone'],
                'active' => ($path ?? '') === $chemin,
            ];
        }
        $railGroupe[] = ['cle' => $groupe['cle'], 'titre' => t($groupe['titre']), 'entrees' => $entrees];
    }
    /* Une section dont ce compte ne verrait rien n'existe pas pour lui : on
       retombe alors sur le rail à plat que le contrôleur avait prévu. */
    if ($railGroupe === []) {
        $railGroupe = null;
        $monGroupe = null;
    }
}

/* Sur un écran d'administration, les entrées passées par le contrôleur ne sont
   plus le rail : ce sont les onglets de la page. */
$ongletsDeLEcran = $railGroupe !== null ? ($navItems ?? []) : [];

/* Les liens de pied menaient aux services voisins, faute de rail qui les
   montre tous. Le rail les montre — les garder les afficherait deux fois, une
   fois rangés dans leur métier et une fois en vrac sous la barre. Ne restent
   que ceux qui mènent ailleurs qu'aux services : vers un autre espace. */
$piedDuRail = $footLinks ?? [];
if ($railGroupe !== null) {
    $piedDuRail = array_values(array_filter(
        $piedDuRail,
        static fn (array $lien): bool => Sections::groupeDuChemin($lien['href'] ?? '') === null
    ));
}

/* Le rail replié a besoin de deux choses avant le premier rendu : la liste des
   groupes, et celui d'où l'on est. Elles se posent sur <html>, où la feuille de
   style et le script d'en-tête les lisent avant que le rail n'existe. */
$attributsDuRail = ' data-groupes="' . e(implode(' ', Sections::clesDesGroupes())) . '"';
if ($monGroupe !== null) {
    $attributsDuRail .= ' data-groupe-actif="' . e($monGroupe) . '"';
}
?>
<!doctype html>
<html lang="<?= e($locale) ?>" dir="<?= e($localeDir) ?>" data-palette="<?= e($palette) ?>"<?= $attributsDuRail ?>>
<head>
<?= \App\Core\View::partial('head', ['title' => $title ?? t('app.name'), 'nonce' => $nonce]) ?>
</head>
<body class="app-body">
<div class="app-shell">
<?= \App\Core\View::partial('sidebar', array_merge(get_defined_vars(), ['groupes' => $railGroupe, 'footLinks' => $piedDuRail])) ?>
  <?php /* Un écran qui porte un script a besoin d'un point d'accroche et de
           quelques données : il les pose ici plutôt que de les deviner du DOM.
           Les autres écrans n'en mettent pas, et rien ne change pour eux. */ ?>
  <div class="app-main"<?= isset($mainId) ? ' id="' . e($mainId) . '"' : '' ?><?php foreach (($mainData ?? []) as $nom => $valeur): ?> data-<?= e($nom) ?>="<?= e((string) $valeur) ?>"<?php endforeach; ?>>
    <div class="app-top">
    <header class="app-header">
      <div>
        <h1><?= e($headerTitle ?? ($title ?? '')) ?></h1>
        <?php if (!empty($headerSubtitle)): ?><p class="muted"><?= e($headerSubtitle) ?></p><?php endif; ?>
      </div>
      <div class="app-header-right">
        <?php if (!empty($sessionUser)): ?>
          <span class="user-chip"><?= e($sessionUser['firstName'] . ' ' . $sessionUser['lastName']) ?></span>
        <?php endif; ?>
      </div>
    </header>

    <?php if ($ongletsDeLEcran !== []): ?>
      <nav class="page-tabs" aria-label="<?= e(t('nav.sections')) ?>">
        <?php $rang = 0; ?>
        <?php foreach ($ongletsDeLEcran as $item): ?>
          <?php
            /* Le premier est allumé d'office quand aucun ne se déclare actif :
               le gabarit pose « is-active » sur son premier panneau, et le rail
               le disait déjà ainsi. */
            $allume = array_key_exists('active', $item)
                ? (bool) $item['active']
                : (isset($item['href']) ? ($path ?? '') === $item['href'] : $rang === 0);
          ?>
          <?php if (isset($item['href'])): ?>
            <a href="<?= e($item['href']) ?>" class="tab-btn<?= $allume ? ' is-active' : '' ?>"
               <?= $allume ? 'aria-current="page"' : '' ?>>
              <?= \App\Core\View::partial('icon', ['name' => \App\Core\NavIcons::pour($item), 'iconSize' => 16]) ?>
              <span><?= e($item['label']) ?></span>
              <?php if (!empty($item['badge'])): ?><span class="nav-badge"><?= e((string) $item['badge']) ?></span><?php endif; ?>
            </a>
          <?php else: ?>
            <button type="button" class="tab-btn<?= $allume ? ' is-active' : '' ?>" data-tab="<?= e($item['tab']) ?>">
              <?= \App\Core\View::partial('icon', ['name' => \App\Core\NavIcons::pour($item), 'iconSize' => 16]) ?>
              <span><?= e($item['label']) ?></span>
              <?php if (!empty($item['badge'])): ?><span class="nav-badge"><?= e((string) $item['badge']) ?></span><?php endif; ?>
            </button>
            <?php $rang++; ?>
          <?php endif; ?>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
    </div>

    <main class="page">
<?php /* Le bandeau de licence suit l'en-tête sur tous les écrans : posé ici une
         fois, il ne peut pas manquer sur celui qu'on aurait oublié. */ ?>
<?php /* Le périmètre, avant les bandeaux : savoir dans quelle société on se
         trouve passe avant tout le reste, et une facture saisie dans la mauvaise
         entité d'un groupe ne se répare pas en la déplaçant. */ ?>
<?= \App\Core\View::partial('societe-selecteur') ?>
<?= \App\Core\View::partial('licence-bandeau', ['utilisateur' => $sessionUser ?? null]) ?>
<?php /* L'emprunt d'identité : au même endroit, et pour la même raison — un
         bandeau qu'un seul gabarit oublierait serait un bandeau qui ne protège
         rien. */ ?>
<?= \App\Core\View::partial('emprunt-bandeau') ?>
<?= \App\Core\View::partial('flash', ['flash' => $flash ?? null]) ?>
<?= $content ?>
    </main>
  </div>
</div>
<script src="<?= e(asset('/js/theme.js')) ?>" nonce="<?= e($nonce) ?>"></script>
<?php foreach (($scripts ?? []) as $script): ?>
<script src="<?= e($script) ?>" nonce="<?= e($nonce) ?>"></script>
<?php endforeach; ?>
</body>
</html>
