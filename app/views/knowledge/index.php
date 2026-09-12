<?php $csrf = \App\Core\Csrf::field(); ?>
<section class="tab-panel is-active" id="articles">
  <div class="card">
    <div class="card-head"><h2><?= e(t('kb.search')) ?></h2></div>
    <form method="GET" action="/base-de-connaissances" class="form-grid">
      <label><span><?= e(t('kb.keywords')) ?></span>
        <input type="search" name="q" value="<?= e($query) ?>" placeholder="<?= e(t('kb.keywordsPlaceholder')) ?>" />
      </label>
      <label><span><?= e(t('common.category')) ?></span>
        <select name="categorie">
          <option value=""><?= e(t('hr.filterAll')) ?></option>
          <?php foreach ($categoryList as $name): ?>
            <option value="<?= e($name) ?>"<?= $category === $name ? ' selected' : '' ?>><?= e($name) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="span-2 row-actions">
        <button type="submit" class="btn btn-primary btn-sm"><?= e(t('kb.search')) ?></button>
        <a class="btn btn-outline btn-sm" href="/base-de-connaissances"><?= e(t('common.viewAll')) ?></a>
      </div>
    </form>
  </div>

  <div class="card mt-l">
    <div class="card-head"><h2><?= count($articleList) ?> article(s)</h2></div>
    <ul class="org-list">
      <?php foreach ($articleList as $article): ?>
        <li class="org-item">
          <span>
            <a class="cell-strong" href="/base-de-connaissances/<?= (int) $article['id'] ?>"><?= e($article['title']) ?></a>
            <span class="tag"><?= e($article['category']) ?></span>
            <?php if ($article['visibility'] !== 'Entreprise'): ?>
              <span class="tag tag-accent"><?= e($article['visibility']) ?></span>
            <?php endif; ?>
            <br />
            <span class="cell-sub">
              <?= $article['first_name'] !== null ? e($article['first_name'] . ' ' . $article['last_name'] . ' · ') : '' ?>
              <?= (int) $article['views'] ?> lecture(s)
            </span>
          </span>
          <a class="btn btn-outline btn-sm" href="/base-de-connaissances/<?= (int) $article['id'] ?>"><?= e(t('kb.read')) ?></a>
        </li>
      <?php endforeach; ?>
      <?php if ($articleList === []): ?>
        <li class="cell-sub"><?= e(t('kb.noMatch')) ?></li>
      <?php endif; ?>
    </ul>
  </div>
</section>

<?php if ($canWrite): ?>
  <section class="tab-panel" id="rediger">
    <div class="card">
      <div class="card-head"><h2><?= e(t('kb.writeArticle')) ?></h2></div>
      <form method="POST" action="/base-de-connaissances" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('team.newsTitle')) ?></span>
          <input type="text" name="title" required maxlength="200" />
        </label>
        <label><span><?= e(t('common.category')) ?></span>
          <input type="text" name="category" list="kb-categories" value="Général" maxlength="60" />
          <datalist id="kb-categories">
            <?php foreach ($categoryList as $name): ?><option value="<?= e($name) ?>"></option><?php endforeach; ?>
          </datalist>
        </label>
        <label><span><?= e(t('common.scope')) ?></span>
          <select name="visibility">
            <?php foreach ($visibilities as $visibility): ?>
              <option value="<?= e($visibility) ?>"><?= e($visibility) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('kb.scopeIfLimited')) ?></span>
          <select name="scope_id">
            <option value="">—</option>
            <optgroup label="<?= e(t('org.departments')) ?>">
              <?php foreach ($departments as $department): ?>
                <option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <optgroup label="<?= e(t('org.teams')) ?>">
              <?php foreach ($teams as $team): ?>
                <option value="<?= (int) $team['id'] ?>"><?= e($team['name']) ?></option>
              <?php endforeach; ?>
            </optgroup>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('common.content')) ?></span>
          <textarea name="body" rows="12" placeholder="<?= e(t('kb.bodyPlaceholder')) ?>"></textarea>
        </label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.publish')) ?></button></div>
      </form>
      <p class="hint"><?= e(t('kb.adminScopeNote')) ?></p>
    </div>
  </section>
<?php endif; ?>
