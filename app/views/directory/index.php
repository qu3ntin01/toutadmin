<form method="GET" action="/annuaire" class="toolbar">
  <label class="sr-only" for="q"><?= e(t('directory.search')) ?></label>
  <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="<?= e(t('directory.search')) ?>" />
  <button type="submit" class="btn btn-sm"><?= e(t('directory.search')) ?></button>
</form>

<p class="muted"><?= e(t('directory.count', ['count' => count($people)])) ?></p>

<?php if ($people === []): ?>
  <div class="empty-state"><?= e(t('directory.empty')) ?></div>
<?php else: ?>
  <table class="table">
    <thead>
      <tr>
        <th><?= e(t('common.name')) ?></th>
        <th><?= e(t('common.role')) ?></th>
        <th><?= e(t('common.department')) ?></th>
        <th><?= e(t('auth.email')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($people as $person): ?>
        <tr>
          <td>
            <span class="avatar avatar-sm">
              <?php if (!empty($person['avatar_file'])): ?>
                <img src="/media/avatars/<?= e($person['avatar_file']) ?>"
                     alt="<?= e(trim($person['first_name'] . ' ' . $person['last_name'])) ?>" />
              <?php else: ?>
                <?= e(mb_substr($person['first_name'], 0, 1) . mb_substr($person['last_name'], 0, 1)) ?>
              <?php endif; ?>
            </span>
            <?= e(trim($person['first_name'] . ' ' . $person['last_name'])) ?>
          </td>
          <td><?= e((string) $person['grade']) ?></td>
          <td><?= e((string) $person['department']) ?></td>
          <td><a href="mailto:<?= e($person['email']) ?>"><?= e($person['email']) ?></a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
