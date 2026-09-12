<div class="card">
  <h2><?= e($tool['name']) ?></h2>
  <form method="POST" action="/admin/outils/<?= (int) $tool['id'] ?>/modifier" class="form-grid">
    <?= \App\Core\Csrf::field() ?>
    <label><span><?= e(t('admin.toolName')) ?></span>
      <input type="text" name="name" value="<?= e($tool['name']) ?>" required maxlength="150" />
    </label>
    <label><span><?= e(t('common.category')) ?></span>
      <input type="text" name="category" value="<?= e((string) $tool['category']) ?>" maxlength="100" />
    </label>
    <label><span><?= e(t('common.reference')) ?></span>
      <input type="text" name="reference" value="<?= e((string) $tool['reference']) ?>" maxlength="100" />
    </label>
    <label><span><?= e(t('admin.loginUrl')) ?></span>
      <input type="url" name="login_url" value="<?= e((string) $tool['login_url']) ?>" maxlength="500" />
    </label>
    <label class="span-2"><span><?= e(t('common.description')) ?></span>
      <textarea name="description" rows="4" maxlength="1000"><?= e((string) $tool['description']) ?></textarea>
    </label>
    <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
  </form>
</div>

<p class="mt-l"><a class="btn btn-sm" href="/admin#outils"><?= e(t('err.backHome')) ?></a></p>
