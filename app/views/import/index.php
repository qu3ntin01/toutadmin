<?php $csrf = \App\Core\Csrf::field(); ?>
<?php if ($result !== null): ?>
  <div class="card">
    <div class="card-head">
      <h2><?= e(t('imp.importedRows', ['count' => $result['imported']])) ?></h2>
      <span class="card-sub"><?= e($result['entity']['label']) ?></span>
    </div>
    <?php if ($result['credentials'] !== []): ?>
      <p class="hint"><?= e(t('imp.temporaryPasswords')) ?></p>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('common.identifier')) ?></th>
              <th><?= e(t('common.temporaryPassword')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($result['credentials'] as $credential): ?>
              <tr>
                <td class="cell-strong"><?= e($credential['email']) ?></td>
                <td class="mono"><?= e($credential['password']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <div class="row-actions"><a href="/import" class="btn btn-outline btn-sm"><?= e(t('imp.newImport')) ?></a></div>
  </div>
<?php endif; ?>

<?php if ($preview !== null): ?>
  <div class="card mt-l">
    <div class="card-head">
      <h2><?= e(t('imp.previewOf', ['label' => $preview['entity']['label']])) ?></h2>
      <span class="card-sub">
        <?= e(t('imp.rowsAndDelimiter', [
            'count' => count($preview['rows']),
            'delimiter' => $preview['delimiter'] === "\t" ? t('imp.tab') : $preview['delimiter'],
        ])) ?>
      </span>
    </div>

    <?php if ($preview['errors'] !== []): ?>
      <div class="flash flash-error"><?= e(t('imp.errorRows', ['count' => count($preview['errors'])])) ?></div>
    <?php endif; ?>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('imp.line')) ?></th>
            <th><?= e(t('common.content')) ?></th>
            <th><?= e(t('imp.verdict')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($preview['rows'] as $row): ?>
            <tr>
              <td class="cell-sub mono"><?= (int) $row['line'] ?></td>
              <td class="cell-strong"><?= e($row['summary'] !== '' ? $row['summary'] : '—') ?></td>
              <td>
                <?php if ($row['ok']): ?>
                  <span class="tag tag-success"><?= e(t('imp.ready')) ?></span>
                <?php else: ?>
                  <span class="tag tag-danger"><?= e(t('status.refused')) ?></span>
                  <div class="cell-sub"><?= e($row['message']) ?></div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($preview['errors'] === []): ?>
      <form method="POST" action="/import/importer" class="form-grid">
        <?= $csrf ?>
        <input type="hidden" name="entity" value="<?= e($preview['entity']['key']) ?>" />
        <textarea name="content" hidden><?= e($content) ?></textarea>
        <div class="span-2">
          <button type="submit" class="btn btn-primary btn-block">
            <?= e(t('imp.importRows', ['count' => $preview['valid']])) ?>
          </button>
        </div>
      </form>
      <p class="hint"><?= e(t('imp.recheckNote')) ?></p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card mt-l">
  <div class="card-head"><h2><?= e(t('imp.uploadFile')) ?></h2></div>
  <form method="POST" action="/import/apercu" enctype="multipart/form-data" class="form-grid">
    <?= $csrf ?>
    <label class="span-2"><span><?= e(t('imp.dataType')) ?></span>
      <select name="entity" required>
        <?php foreach ($entities as $entity): ?>
          <option value="<?= e($entity['key']) ?>"<?= $entity['key'] === $selected ? ' selected' : '' ?>>
            <?= e($entity['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="span-2"><span><?= e(t('imp.csvFile', ['max' => (int) round($maxBytes / 1048576)])) ?></span>
      <input type="file" name="fichier" accept=".csv,text/csv,text/plain" />
    </label>
    <label class="span-2"><span><?= e(t('imp.orPaste')) ?></span>
      <textarea name="content" rows="6" placeholder="prenom;nom;email;grade;type_contrat"><?= e($content) ?></textarea>
    </label>
    <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('imp.checkFile')) ?></button></div>
  </form>
  <p class="hint"><?= e(t('imp.formatNote')) ?></p>
</div>

<?php foreach ($entities as $entity): ?>
  <div class="card mt-l" id="<?= e($entity['key']) ?>">
    <div class="card-head">
      <h2><?= e($entity['label']) ?></h2>
      <a href="/import/<?= e($entity['key']) ?>/modele.csv" class="btn btn-outline btn-sm">
        <?= e(t('imp.downloadTemplate')) ?>
      </a>
    </div>
    <p class="cell-sub"><?= e($entity['hint']) ?></p>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('imp.column')) ?></th>
            <th><?= e(t('imp.expectedContent')) ?></th>
            <th><?= e(t('common.mandatory')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($entity['columns'] as $column): ?>
            <tr>
              <td class="mono cell-strong"><?= e($column['name']) ?></td>
              <td class="cell-sub"><?= e($column['label']) ?></td>
              <td><?= e($column['required'] ? 'Oui' : '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>
