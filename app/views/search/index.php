<div class="card">
  <form method="GET" action="/recherche" class="form-grid">
    <label class="span-2">
      <span><?= e(t('rch.what')) ?></span>
      <input type="search" name="q" value="<?= e($query) ?>" placeholder="<?= e(t('rch.placeholder')) ?>"
             autofocus minlength="2" required />
    </label>
    <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('kb.search')) ?></button></div>
  </form>
  <p class="hint"><?= e(t('rch.scopeNote')) ?></p>
</div>

<?php if ($query !== ''): ?>
  <?php foreach ($groups as $group): ?>
    <div class="card">
      <div class="card-head"><h3><?= e($group['source']) ?></h3></div>
      <ul class="org-list">
        <?php foreach ($group['rows'] as $row): ?>
          <li class="org-item">
            <span>
              <span class="cell-strong"><?= e((string) $row['label']) ?></span>
              <?php $detail = trim((string) ($row['detail'] ?? '')); ?>
              <br /><span class="cell-sub"><?= e($detail !== '' ? $detail : '—') ?></span>
            </span>
            <a class="btn btn-outline btn-sm" href="<?= e((string) $row['link']) ?>"><?= e(t('common.open')) ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endforeach; ?>
  <?php if ($groups === []): ?>
    <div class="card"><p class="cell-sub"><?= e(t('rch.noMatch', ['query' => $query])) ?></p></div>
  <?php endif; ?>
<?php endif; ?>
