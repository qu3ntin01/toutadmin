<?php $csrf = \App\Core\Csrf::field(); ?>
<div class="card">
  <div class="org-head">
    <h2><?= e(t('nav.notifications')) ?> <span class="muted">(<?= (int) $unread ?>)</span></h2>
    <?php if ($unread > 0): ?>
      <form method="POST" action="/notifications/tout-lire">
        <?= $csrf ?>
        <button type="submit" class="btn btn-sm"><?= e(t('ntf.markAllRead')) ?></button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($notifications === []): ?>
    <div class="empty-state"><?= e(t('ntf.nothing')) ?></div>
  <?php else: ?>
    <ul class="org-list">
      <?php foreach ($notifications as $item): ?>
        <li class="org-item<?= $item['read_at'] === null ? '' : ' is-muted' ?>">
          <div class="org-head">
            <div>
              <span class="org-name"><?= e($item['title']) ?></span>
              <span class="cell-sub"><?= e($item['created_at']) ?></span>
            </div>
            <div class="row-actions">
              <?php if (!empty($item['link'])): ?>
                <a class="btn btn-sm" href="<?= e($item['link']) ?>"><?= e(t('common.open')) ?></a>
              <?php endif; ?>
              <?php if ($item['read_at'] === null): ?>
                <form method="POST" action="/notifications/<?= (int) $item['id'] ?>/lue">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm"><?= e(t('ntf.markRead')) ?></button>
                </form>
              <?php endif; ?>
              <form method="POST" action="/notifications/<?= (int) $item['id'] ?>/supprimer">
                <?= $csrf ?>
                <button type="submit" class="btn btn-sm"><?= e(t('common.delete')) ?></button>
              </form>
            </div>
          </div>
          <?php if (!empty($item['body'])): ?><p><?= e($item['body']) ?></p><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
