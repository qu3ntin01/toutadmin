<?php $csrf = \App\Core\Csrf::field(); ?>
<section class="tab-panel is-active" id="lecture">
  <div class="card">
    <?php if ((int) $article['published'] === 0): ?>
      <div class="flash flash-error"><?= e(t('art.draftNote')) ?></div>
    <?php endif; ?>
    <p class="article-body"><?= e($article['body'] !== '' ? $article['body'] : 'Cet article est vide.') ?></p>
    <p class="cell-sub"><?= (int) $article['views'] ?> lecture(s)</p>
  </div>
</section>

<?php if ($canWrite): ?>
  <section class="tab-panel" id="edition">
    <div class="card">
      <div class="card-head"><h2><?= e(t('common.edit')) ?></h2></div>
      <form method="POST" action="/base-de-connaissances/<?= (int) $article['id'] ?>/modifier" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('team.newsTitle')) ?></span>
          <input type="text" name="title" required maxlength="200" value="<?= e($article['title']) ?>" />
        </label>
        <label><span><?= e(t('common.category')) ?></span>
          <input type="text" name="category" value="<?= e($article['category']) ?>" maxlength="60" />
        </label>
        <label><span><?= e(t('common.scope')) ?></span>
          <select name="visibility">
            <?php foreach ($visibilities as $visibility): ?>
              <option value="<?= e($visibility) ?>"<?= $article['visibility'] === $visibility ? ' selected' : '' ?>>
                <?= e($visibility) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('art.scope')) ?></span>
          <select name="scope_id">
            <option value="">—</option>
            <optgroup label="<?= e(t('org.departments')) ?>">
              <?php foreach ($departments as $department): ?>
                <option value="<?= (int) $department['id'] ?>"
                  <?= $article['visibility'] === 'Service' && (int) $article['scope_id'] === (int) $department['id'] ? ' selected' : '' ?>>
                  <?= e($department['name']) ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
            <optgroup label="<?= e(t('org.teams')) ?>">
              <?php foreach ($teams as $team): ?>
                <option value="<?= (int) $team['id'] ?>"
                  <?= $article['visibility'] === 'Équipe' && (int) $article['scope_id'] === (int) $team['id'] ? ' selected' : '' ?>>
                  <?= e($team['name']) ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('common.content')) ?></span>
          <textarea name="body" rows="16"><?= e($article['body']) ?></textarea>
        </label>
        <label class="check-row span-2">
          <input type="checkbox" name="published" value="1"<?= (int) $article['published'] === 1 ? ' checked' : '' ?> />
          <span><?= e(t('cse.published')) ?></span>
        </label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
      </form>

      <form method="POST" action="/base-de-connaissances/<?= (int) $article['id'] ?>/supprimer"
            data-confirm="<?= e(t('art.confirmDelete')) ?>">
        <?= $csrf ?>
        <button type="submit" class="btn btn-danger btn-block"><?= e(t('common.delete')) ?></button>
      </form>
    </div>
  </section>
<?php endif; ?>
