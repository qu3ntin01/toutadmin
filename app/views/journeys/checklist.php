<?php
$csrf = \App\Core\Csrf::field();
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
$done = count(array_filter($items, static fn (array $item): bool => $item['done_at'] !== null));
?>
<section class="tab-panel is-active" id="points">
  <div class="card">
    <div class="card-head">
      <h2><?= $done ?>/<?= count($items) ?> point(s) faits</h2>
      <?php if ($checklist['completed_at'] !== null): ?>
        <span class="tag tag-success"><?= e(t('par.finished')) ?></span>
      <?php endif; ?>
    </div>
    <ul class="org-list">
      <?php foreach ($items as $item): ?>
        <li class="org-item <?= $item['done_at'] !== null ? 'is-done' : '' ?>">
          <span>
            <span class="cell-strong"><?= e($item['label']) ?></span>
            <br />
            <span class="cell-sub">
              <?= e($item['owner_role']) ?> · <?= e(t('plst.dueLower')) ?>
              <span class="<?= $item['done_at'] === null && $item['due_date'] !== null && $item['due_date'] < $today ? 'text-danger' : '' ?>">
                <?= e($day($item['due_date'])) ?>
              </span>
              <?php if ($item['done_at'] !== null): ?>
                · <?= e(t('plst.doneBy', ['name' => trim(($item['first_name'] ?? '') . ' ' . ($item['last_name'] ?? ''))])) ?>
              <?php endif; ?>
            </span>
          </span>
          <form method="POST" action="/parcours/listes/points/<?= (int) $item['id'] ?>/basculer" class="inline-form">
            <?= $csrf ?>
            <button type="submit" class="btn <?= $item['done_at'] !== null ? 'btn-outline' : 'btn-primary' ?> btn-sm">
              <?= e($item['done_at'] !== null ? 'Rouvrir' : 'Marquer fait') ?>
            </button>
          </form>
        </li>
      <?php endforeach; ?>
      <?php if ($items === []): ?><li class="cell-sub"><?= e(t('plst.noItem')) ?></li><?php endif; ?>
    </ul>
  </div>
</section>
