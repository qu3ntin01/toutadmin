<?php
/* Navigation latérale, unique pour tous les espaces.
   Une entrée est soit un lien vers une autre page ({href, label}), soit un
   onglet de la page courante ({tab, label}) — c'est la convention de
   l'édition Node, et le même script les anime.

   « $groupes » range les entrées par métier, chacun repliable : c'est ce que
   l'espace d'administration emploie, où quarante services à la file
   débordaient sous le bord de l'écran. Les espaces qui n'ont qu'une poignée
   d'entrées s'en passent et donnent « $navItems » à plat, comme avant. */
$groupesDuRail = $groupes ?? null;
$items = $navItems ?? [
    ['href' => '/mon-espace', 'label' => t('nav.mySpace')],
    ['href' => '/annuaire', 'label' => t('nav.directory')],
    ['href' => '/mon-profil', 'label' => t('nav.profile')],
];
$index = 0;
?>
<aside class="sidebar">
  <div class="sidebar-brand">
    <span class="brand-mark"><?= e($brandInitials) ?></span>
    <span class="brand-text">
      <span class="brand-name"><?= e($companyName) ?></span>
      <span class="brand-panel"><?= e($panelLabel ?? t('app.portal')) ?></span>
    </span>
    <?php /* Replier le rail rend près de deux cents pixels à la page. Le choix
             appartient à chaque personne et tient dans son navigateur : ce
             n'est pas un réglage de l'instance. */ ?>
    <button type="button" class="rail-toggle" data-rail-toggle
            aria-expanded="true"
            aria-controls="tabs"
            title="<?= e(t('nav.collapse')) ?>"
            data-label-collapse="<?= e(t('nav.collapse')) ?>"
            data-label-expand="<?= e(t('nav.expand')) ?>">
      <span class="sr-only"><?= e(t('nav.collapse')) ?></span>
      <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor"
           stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <rect x="3.5" y="4.5" width="17" height="15" rx="2" /><path d="M10 4.5v15" />
      </svg>
    </button>
  </div>

  <nav class="sidebar-nav" id="tabs" aria-label="<?= e(t('nav.sections')) ?>">
    <?php if ($groupesDuRail !== null): ?>
      <?php foreach ($groupesDuRail as $groupe): ?>
        <div class="side-group" data-groupe="<?= e((string) $groupe['cle']) ?>">
          <?php /* Un bouton, pas un titre : il ouvre et referme le groupe, et le
                   dit au lecteur d'écran par « aria-expanded ». Sans script il ne
                   fait rien — et le rail reste ouvert, entier, tel que le serveur
                   l'a rendu : on perd le pli, jamais la navigation. */ ?>
          <button type="button" class="side-group-tete" data-groupe-bascule="<?= e((string) $groupe['cle']) ?>"
                  aria-expanded="true" aria-controls="groupe-<?= e((string) $groupe['cle']) ?>">
            <span class="side-group-nom"><?= e((string) $groupe['titre']) ?></span>
            <svg class="side-group-chevron" viewBox="0 0 24 24" width="13" height="13" fill="none"
                 stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"
                 aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
          </button>
          <div class="side-group-liste" id="groupe-<?= e((string) $groupe['cle']) ?>">
            <?php foreach ($groupe['entrees'] as $entree): ?>
              <a href="<?= e((string) $entree['href']) ?>"
                 class="side-link<?= !empty($entree['active']) ? ' is-active' : '' ?>"
                 title="<?= e((string) $entree['label']) ?>"
                 <?= !empty($entree['active']) ? 'aria-current="page"' : '' ?>>
                <?= \App\Core\View::partial('icon', ['name' => $entree['icon'], 'iconSize' => 17]) ?>
                <span class="side-link-label"><?= e((string) $entree['label']) ?></span>
                <?php if (!empty($entree['badge'])): ?><span class="nav-badge"><?= e((string) $entree['badge']) ?></span><?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
    <?php foreach ($items as $item): ?>
      <?php if (isset($item['href'])): ?>
        <a href="<?= e($item['href']) ?>" class="side-link<?= ($path ?? '') === $item['href'] ? ' is-active' : '' ?>"
           <?= ($path ?? '') === $item['href'] ? 'aria-current="page"' : '' ?>>
          <?= \App\Core\View::partial('icon', ['name' => \App\Core\NavIcons::pour($item), 'iconSize' => 17]) ?>
          <span class="side-link-label"><?= e($item['label']) ?></span>
          <?php if (!empty($item['badge'])): ?><span class="nav-badge"><?= e((string) $item['badge']) ?></span><?php endif; ?>
        </a>
      <?php else: ?>
        <button type="button" class="side-link tab-btn<?= $index === 0 ? ' is-active' : '' ?>" data-tab="<?= e($item['tab']) ?>">
          <?= \App\Core\View::partial('icon', ['name' => \App\Core\NavIcons::pour($item), 'iconSize' => 17]) ?>
          <span class="side-link-label"><?= e($item['label']) ?></span>
          <?php if (!empty($item['badge'])): ?><span class="nav-badge"><?= e((string) $item['badge']) ?></span><?php endif; ?>
        </button>
        <?php $index++; ?>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php endif; ?>
  </nav>

  <div class="sidebar-foot">
    <?php foreach (($footLinks ?? []) as $link): ?>
      <a href="<?= e($link['href']) ?>" class="side-link side-link-quiet"><?= \App\Core\View::partial('icon', ['name' => \App\Core\NavIcons::pour($link), 'iconSize' => 17]) ?><span class="side-link-label"><?= e($link['label']) ?></span></a>
    <?php endforeach; ?>
    <?= \App\Core\View::partial('theme-toggle') ?>
    <form method="POST" action="/deconnexion">
      <?= \App\Core\Csrf::field() ?>
      <button type="submit" class="side-link side-link-quiet"><?= \App\Core\View::partial('icon', ['name' => 'logout', 'iconSize' => 17]) ?><span class="side-link-label"><?= e(t('common.logout')) ?></span></button>
    </form>
  </div>
</aside>
