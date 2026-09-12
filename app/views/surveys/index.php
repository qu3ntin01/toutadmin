<?php $day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso; ?>
<div class="card">
  <h2><?= e(t('srv.toFill', ['count' => count($invitations)])) ?></h2>
  <?php if ($invitations === []): ?>
    <div class="empty-state"><?= e(t('srv.nothingToFill')) ?></div>
  <?php else: ?>
    <ul class="meeting-list">
      <?php foreach ($invitations as $survey): ?>
        <li class="meeting-item">
          <div class="meeting-head">
            <span class="meeting-title"><?= e($survey['title']) ?></span>
            <span class="tag tag-warning"><?= (int) $survey['question_count'] ?> question(s)</span>
          </div>
          <?php if ($survey['intro'] !== ''): ?><p class="cell-sub"><?= e($survey['intro']) ?></p><?php endif; ?>
          <p class="cell-sub">
            <?= e($survey['kind']) ?><?php if (!empty($survey['closes_on'])): ?>
              · <?= e(t('srv.until', ['date' => $day($survey['closes_on'])])) ?>
            <?php endif; ?>
          </p>
          <div class="row-actions">
            <a href="/sondages/<?= (int) $survey['id'] ?>" class="btn btn-primary btn-sm"><?= e(t('common.reply')) ?></a>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <p class="hint"><?= e(t('srv.anonymityNote', ['threshold' => $anonymityThreshold])) ?></p>
</div>

<?php if ($answered !== []): ?>
  <div class="card mt-l">
    <h2><?= e(t('srv.alreadyAnswered')) ?></h2>
    <ul class="person-list">
      <?php foreach ($answered as $survey): ?>
        <li class="person-row">
          <span class="person-body">
            <span class="person-name"><?= e($survey['title']) ?></span>
            <span class="person-role"><?= e($survey['kind']) ?> · <?= e(st($survey['status'])) ?></span>
          </span>
          <span class="tag tag-success"><?= e(t('srv.thanks')) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
