<?php
$csrf = \App\Core\Csrf::field();
$mo = static fn (int $bytes): string => number_format($bytes / 1048576, 1, ',', ' ') . ' Mo';
?>
<section class="stats-grid">
  <?php foreach ([
      [(string) $summary['count'], t('bak.kept')],
      [$mo($summary['bytes']), t('bak.totalSize')],
      [$summary['latest'] === null ? '—' : substr((string) $summary['latest']['createdAt'], 0, 16), t('bak.createdOn')],
      [$summary['enabled'] ? t('bak.running') : t('bak.stopped'),
       $summary['enabled']
           ? t('bak.everyKept', ['minutes' => $summary['intervalMinutes'], 'count' => $summary['keep']])
           : t('bak.automatic')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<!-- ---------------------------------------------------------- Archives -->
<section class="tab-panel is-active" id="archives">
  <div class="card">
    <h2><?= e(t('bak.backupNow')) ?></h2>
    <p class="muted"><?= e(t('bak.archiveHelp')) ?></p>
    <form method="POST" action="/sauvegardes" class="inline-form">
      <?= $csrf ?>
      <input type="text" name="label" maxlength="120" placeholder="<?= e(t('bak.reasonExample')) ?>" />
      <button type="submit" class="btn btn-primary btn-sm"><?= e(t('bak.createBackup')) ?></button>
    </form>
    <p class="muted"><?= e(t('bak.sensitiveNotice')) ?></p>
    <p class="cell-sub"><?= e(t('bak.serverFolder')) ?> <code><?= e($directory) ?></code></p>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('bak.archives')) ?></h2>
    <?php if ($archives === []): ?>
      <div class="empty-state"><?= e(t('bak.noArchive')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr><th><?= e(t('bak.archive')) ?></th><th><?= e(t('common.size')) ?></th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($archives as $archive): ?>
            <tr>
              <td>
                <?= e($archive['fileName']) ?>
                <br /><span class="cell-sub"><?= e(substr((string) $archive['createdAt'], 0, 19)) ?></span>
              </td>
              <td><?= e($mo((int) $archive['bytes'])) ?></td>
              <td class="row-actions">
                <a class="btn btn-sm" href="/sauvegardes/<?= e(rawurlencode($archive['fileName'])) ?>/telecharger">
                  <?= e(t('common.download')) ?>
                </a>
                <a class="btn btn-sm" href="/sauvegardes/<?= e(rawurlencode($archive['fileName'])) ?>/verifier">
                  <?= e(t('common.verify')) ?>
                </a>
                <form method="POST" action="/sauvegardes/<?= e(rawurlencode($archive['fileName'])) ?>/supprimer"
                      data-confirm="<?= e(t('bak.deleteBackup')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- ----------------------------------------------------- Restauration -->
<section class="tab-panel" id="restauration">
  <?php if ($restoreReport !== null): ?>
    <div class="card">
      <h2><?= e(t('bak.lastRestore')) ?></h2>
      <dl class="detail-list">
        <div><dt><?= e(t('bak.backupDated')) ?></dt><dd><?= e(substr((string) $restoreReport['createdAt'], 0, 19)) ?></dd></div>
        <div><dt><?= e(t('bak.tablesRestored')) ?></dt><dd><?= (int) $restoreReport['tables'] ?></dd></div>
        <div><dt><?= e(t('bak.rowsRewritten')) ?></dt><dd><?= (int) $restoreReport['rows'] ?></dd></div>
        <div><dt><?= e(t('bak.filesRestored')) ?></dt><dd><?= (int) $restoreReport['files'] ?></dd></div>
        <div><dt><?= e(t('bak.previousSavedAs')) ?></dt><dd><?= e((string) $restoreReport['safety']) ?></dd></div>
        <?php if ($restoreReport['skipped'] !== []): ?>
          <div><dt><?= e(t('bak.tablesEmptied')) ?></dt><dd><?= e(implode(', ', $restoreReport['skipped'])) ?></dd></div>
        <?php endif; ?>
      </dl>
    </div>
  <?php endif; ?>

  <div class="card mt-l">
    <h2><?= e(t('bak.whatRestoreDoes')) ?></h2>
    <p class="muted"><?= e(t('bak.restoreHelp')) ?></p>
    <p class="muted">
      <?= e(t('bak.twoExceptions', [
          'sessions' => t('bak.sessionsWord'),
          'state' => t('bak.stateSavedFirst'),
      ])) ?>
    </p>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('bak.restoreFromServer')) ?></h2>
    <?php if ($archives === []): ?>
      <div class="empty-state"><?= e(t('bak.noArchiveAvailable')) ?></div>
    <?php else: ?>
      <?php foreach ($archives as $archive): ?>
        <form method="POST" action="/sauvegardes/<?= e(rawurlencode($archive['fileName'])) ?>/restaurer"
              class="form-grid" data-confirm="<?= e(t('bak.confirmRestore')) ?>">
          <?= $csrf ?>
          <label class="span-2">
            <span><?= e($archive['fileName']) ?></span>
            <input type="text" name="confirmation" required placeholder="<?= e(t('bak.exactName')) ?>" />
          </label>
          <button type="submit" class="btn btn-danger"><?= e(t('bak.restore')) ?></button>
        </form>
      <?php endforeach; ?>
      <p class="muted"><?= e(t('bak.exactNameHelp')) ?></p>
    <?php endif; ?>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('bak.restoreFromUpload')) ?></h2>
    <form method="POST" action="/sauvegardes/televerser" class="form-grid" enctype="multipart/form-data">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('bak.archiveFile')) ?></span>
        <input type="file" name="archive" accept=".gz,.tar.gz" required />
      </label>
      <label class="span-2"><span><?= e(t('bak.typeRestore')) ?></span>
        <input type="text" name="confirmation" required />
      </label>
      <button type="submit" class="btn btn-danger"><?= e(t('bak.uploadAndRestore')) ?></button>
    </form>
    <p class="muted"><?= e(t('bak.uploadLimit', ['max' => (int) round($maxUploadBytes / 1048576)])) ?></p>
  </div>
</section>

<!-- --------------------------------------------------- Externalisation -->
<section class="tab-panel" id="externalisation">
  <div class="card">
    <h2><?= e(t('bak.whyOffsite')) ?></h2>
    <p class="muted"><?= e(t('bak.whyOffsiteHelp')) ?></p>
    <p class="muted"><?= e(t('bak.destinationNotice')) ?></p>
  </div>

  <?php foreach ($destinations as $destination): ?>
    <div class="card mt-l">
      <h2>
        <?= e($destination['label']) ?>
        <span class="status <?= $destination['enabled'] ? 'status-on' : 'status-off' ?>">
          <?= e($destination['enabled'] ? t('common.active') : t('common.inactive')) ?>
        </span>
      </h2>
      <p class="muted"><?= e($destination['hint']) ?></p>

      <?php if ($destination['status'] !== null): ?>
        <p class="status <?= $destination['status']['ok'] ? 'status-on' : 'status-off' ?>">
          <?= e(t('bak.lastAttempt')) ?> <?= e(substr((string) $destination['status']['at'], 0, 19)) ?> :
          <?= e($destination['status']['ok'] ? t('bak.attemptOk') : t('bak.attemptFailed')) ?>
          <?= $destination['status']['message'] === '' ? '' : ' — ' . e((string) $destination['status']['message']) ?>
        </p>
      <?php endif; ?>

      <form method="POST" action="/sauvegardes/destinations/<?= e($destination['key']) ?>" class="form-grid">
        <?= $csrf ?>
        <?php foreach ($destination['fields'] as $field): ?>
          <?php $value = (string) ($destination['values'][$field['name']] ?? ''); ?>
          <?php $type = $field['type'] ?? 'text'; ?>
          <label class="<?= in_array($type, ['textarea', 'checkbox'], true) ? 'span-2' : '' ?>">
            <span><?= e($field['label']) ?></span>
            <?php if ($type === 'select'): ?>
              <select name="<?= e($field['name']) ?>">
                <?php foreach ($field['options'] as $option): ?>
                  <option value="<?= e($option['value']) ?>"<?= $value === $option['value'] ? ' selected' : '' ?>>
                    <?= e($option['label']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($type === 'textarea'): ?>
              <textarea name="<?= e($field['name']) ?>" rows="3" placeholder="<?= e($value) ?>"></textarea>
            <?php elseif ($type === 'checkbox'): ?>
              <input type="checkbox" name="<?= e($field['name']) ?>" value="1" <?= $value !== '' && $value !== '0' ? 'checked' : '' ?> />
            <?php elseif (!empty($field['secret'])): ?>
              <input type="password" name="<?= e($field['name']) ?>" placeholder="<?= e($value) ?>" autocomplete="new-password" />
            <?php else: ?>
              <input type="<?= e($type) ?>" name="<?= e($field['name']) ?>" value="<?= e($value) ?>"
                     placeholder="<?= e((string) ($field['placeholder'] ?? '')) ?>" />
            <?php endif; ?>
          </label>
        <?php endforeach; ?>

        <label class="span-2">
          <input type="checkbox" name="enabled" value="1" <?= $destination['enabled'] ? 'checked' : '' ?> />
          <span><?= e(t('bak.sendHere')) ?></span>
        </label>
        <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
      </form>
      <p class="muted"><?= e(t('bak.secretsHelp')) ?></p>

      <form method="POST" action="/sauvegardes/destinations/<?= e($destination['key']) ?>/tester">
        <?= $csrf ?>
        <button type="submit" class="btn btn-sm" <?= $destination['configured'] ? '' : 'disabled' ?>>
          <?= e(t('bak.testConnection')) ?>
        </button>
      </form>
    </div>
  <?php endforeach; ?>

  <?php if ($archives !== []): ?>
    <div class="card mt-l">
      <h2><?= e(t('bak.sendOffsite')) ?></h2>
      <table class="table">
        <tbody>
          <?php foreach ($archives as $archive): ?>
            <tr>
              <td><?= e($archive['fileName']) ?></td>
              <td>
                <form method="POST" action="/sauvegardes/<?= e(rawurlencode($archive['fileName'])) ?>/externaliser">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm"><?= e(t('bak.sendOffsite')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<!-- ----------------------------------------------------------- Export -->
<section class="tab-panel" id="export">
  <div class="card">
    <h2><?= e(t('bak.takeEverything')) ?></h2>
    <p class="muted"><?= e(t('bak.reversibility')) ?></p>
    <form method="POST" action="/sauvegardes/export">
      <?= $csrf ?>
      <button type="submit" class="btn btn-primary"><?= e(t('bak.buildExport')) ?></button>
    </form>
    <p class="muted"><?= e(t('bak.exportNotice')) ?></p>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('bak.exportContains')) ?></h2>
    <dl class="detail-list">
      <div><dt><?= e(t('bak.table')) ?></dt><dd><?= (int) $exportPreview['tableCount'] ?> · <?= (int) $exportPreview['rows'] ?></dd></div>
      <div><dt><?= e(t('bak.attachments')) ?></dt>
        <dd>
          <?= e(t('bak.profilePhotos')) ?> <?= (int) $exportPreview['files']['uploads'] ?>
          · <?= e(t('bak.cvsReceived')) ?> <?= (int) $exportPreview['files']['cv'] ?>
          · <?= e(t('nav.vault')) ?> <?= (int) $exportPreview['files']['coffre'] ?>
          · <?= e(t('nav.signing')) ?> <?= (int) $exportPreview['files']['parapheur'] ?>
          · <?= e(t('nav.intake')) ?> <?= (int) $exportPreview['files']['pieces'] ?>
        </dd>
      </div>
    </dl>

    <h3><?= e(t('bak.largestTables')) ?></h3>
    <table class="table">
      <thead><tr><th><?= e(t('bak.table')) ?></th><th><?= e(t('common.lines')) ?></th></tr></thead>
      <tbody>
        <?php foreach (array_slice($exportPreview['tables'], 0, 12) as $row): ?>
          <tr><td><?= e($row['table']) ?></td><td><?= (int) $row['lignes'] ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <h3><?= e(t('bak.exportExcludes')) ?></h3>
    <ul class="org-list">
      <li class="org-item"><?= e(t('bak.excludeHashes')) ?></li>
      <li class="org-item"><?= e(t('bak.excludeTokens')) ?></li>
      <li class="org-item"><?= e(t('bak.excludeSessions')) ?></li>
      <li class="org-item"><?= e(t('bak.excludeSecrets')) ?></li>
    </ul>
    <p class="muted"><?= e(t('bak.excludeHelp')) ?></p>
  </div>
</section>

<!-- --------------------------------------------------------- Réglages -->
<section class="tab-panel" id="reglages">
  <div class="card">
    <h2><?= e(t('bak.automatic')) ?></h2>
    <form method="POST" action="/sauvegardes/reglages" class="form-grid">
      <?= $csrf ?>
      <label class="span-2">
        <input type="checkbox" name="enabled" value="1" <?= $summary['enabled'] ? 'checked' : '' ?> />
        <span><?= e(t('bak.backUpAutomatically')) ?></span>
      </label>
      <label><span><?= e(t('bak.intervalMinutes')) ?></span>
        <input type="number" name="interval_minutes" min="15" max="1440" value="<?= (int) $summary['intervalMinutes'] ?>" />
      </label>
      <label><span><?= e(t('bak.kept')) ?></span>
        <input type="number" name="keep" min="2" max="500" value="<?= (int) $summary['keep'] ?>" />
      </label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
    </form>
    <p class="muted"><?= e(t('bak.sweepHelp')) ?></p>
  </div>
</section>
