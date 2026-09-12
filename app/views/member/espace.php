<section class="grid grid-2">
  <article class="card">
    <h2><?= e(t('profile.details')) ?></h2>
    <dl class="detail-list">
      <div><dt><?= e(t('auth.email')) ?></dt><dd><?= e($member['email']) ?></dd></div>
      <?php if (!empty($member['grade'])): ?>
        <div><dt><?= e(t('common.role')) ?></dt><dd><?= e($member['grade']) ?></dd></div>
      <?php endif; ?>
      <?php if ($department !== null): ?>
        <div><dt><?= e(t('common.department')) ?></dt><dd><?= e($department['name']) ?></dd></div>
      <?php endif; ?>
      <?php if ($team !== null): ?>
        <div><dt><?= e(t('common.team')) ?></dt><dd><?= e($team['name']) ?></dd></div>
      <?php endif; ?>
    </dl>
    <p><a class="btn btn-sm" href="/mon-profil"><?= e(t('nav.profile')) ?></a></p>
  </article>

  <article class="card">
    <h2><?= e(t('nav.directory')) ?></h2>
    <?php if ($colleagues === []): ?>
      <div class="empty-state"><?= e(t('directory.empty')) ?></div>
    <?php else: ?>
      <ul class="people">
        <?php foreach ($colleagues as $person): ?>
          <li>
            <strong><?= e(trim($person['first_name'] . ' ' . $person['last_name'])) ?></strong>
            <?php if (!empty($person['grade'])): ?><span class="muted"> · <?= e($person['grade']) ?></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p><a class="btn btn-sm" href="/annuaire"><?= e(t('directory.title')) ?></a></p>
  </article>
</section>
