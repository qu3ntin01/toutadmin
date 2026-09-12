<?php
/* Navigation latérale, unique pour tous les espaces.
   Une entrée est soit un lien vers une autre page ({href, label}), soit un
   onglet de la page courante ({tab, label}) — c'est la convention de
   l'édition Node, et le même script les anime. */
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
  </div>

  <nav class="sidebar-nav" id="tabs" aria-label="<?= e(t('nav.sections')) ?>">
    <?php foreach ($items as $item): ?>
      <?php if (isset($item['href'])): ?>
        <a href="<?= e($item['href']) ?>" class="side-link<?= ($path ?? '') === $item['href'] ? ' is-active' : '' ?>"
           <?= ($path ?? '') === $item['href'] ? 'aria-current="page"' : '' ?>>
          <span class="side-link-label"><?= e($item['label']) ?></span>
          <?php if (!empty($item['badge'])): ?><span class="nav-badge"><?= e((string) $item['badge']) ?></span><?php endif; ?>
        </a>
      <?php else: ?>
        <button type="button" class="side-link tab-btn<?= $index === 0 ? ' is-active' : '' ?>" data-tab="<?= e($item['tab']) ?>">
          <span class="side-link-label"><?= e($item['label']) ?></span>
          <?php if (!empty($item['badge'])): ?><span class="nav-badge"><?= e((string) $item['badge']) ?></span><?php endif; ?>
        </button>
        <?php $index++; ?>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-foot">
    <?php foreach (($footLinks ?? []) as $link): ?>
      <a href="<?= e($link['href']) ?>" class="side-link side-link-quiet"><span class="side-link-label"><?= e($link['label']) ?></span></a>
    <?php endforeach; ?>
    <?= \App\Core\View::partial('theme-toggle') ?>
    <form method="POST" action="/deconnexion">
      <?= \App\Core\Csrf::field() ?>
      <button type="submit" class="side-link side-link-quiet"><?= e(t('common.logout')) ?></button>
    </form>
  </div>
</aside>
