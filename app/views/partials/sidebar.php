<?php
/* Navigation latérale. Les entrées viennent du contrôleur : une page ne décrit
   que ce qu'elle sait, et la barre ne propose jamais un espace fermé à la
   personne connectée. */
$items = $navItems ?? [
    ['href' => '/mon-espace', 'label' => t('nav.mySpace')],
    ['href' => '/annuaire', 'label' => t('nav.directory')],
    ['href' => '/mon-profil', 'label' => t('nav.profile')],
];
?>
<aside class="sidebar">
  <div class="sidebar-brand">
    <span class="brand-mark"><?= e($brandInitials) ?></span>
    <span class="brand-text">
      <span class="brand-name"><?= e($companyName) ?></span>
      <span class="brand-panel"><?= e($panelLabel ?? t('app.portal')) ?></span>
    </span>
  </div>

  <nav class="sidebar-nav" aria-label="<?= e(t('nav.sections')) ?>">
    <?php foreach ($items as $item): ?>
      <a href="<?= e($item['href']) ?>" class="side-link<?= ($path ?? '') === $item['href'] ? ' is-active' : '' ?>"
         <?= ($path ?? '') === $item['href'] ? 'aria-current="page"' : '' ?>>
        <span class="side-link-label"><?= e($item['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-foot">
    <?= \App\Core\View::partial('theme-toggle') ?>
    <form method="POST" action="/deconnexion">
      <?= \App\Core\Csrf::field() ?>
      <button type="submit" class="side-link side-link-quiet"><?= e(t('common.logout')) ?></button>
    </form>
  </div>
</aside>
