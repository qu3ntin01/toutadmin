<?php
$csrf = \App\Core\Csrf::field();
$fullName = static fn (array $p): string => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
?>
<?php if ($eraseReport !== null): ?>
  <div class="card">
    <h2><?= e(t('gdpr.erasureOf', ['email' => $eraseReport['email']])) ?></h2>
    <h3><?= e(t('gdpr.erased')) ?></h3>
    <?php if ($eraseReport['erased'] === []): ?>
      <p class="muted"><?= e(t('gdpr.nothingToErase')) ?></p>
    <?php else: ?>
      <ul class="org-list">
        <?php foreach ($eraseReport['erased'] as $line): ?>
          <li class="org-item"><?= e($line['label']) ?> — <?= (int) $line['count'] ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <h3><?= e(t('gdpr.keptByObligation')) ?></h3>
    <?php if ($eraseReport['kept'] === []): ?>
      <p class="muted"><?= e(t('gdpr.nothingKept')) ?></p>
    <?php else: ?>
      <ul class="org-list">
        <?php foreach ($eraseReport['kept'] as $line): ?>
          <li class="org-item"><?= e($line['label']) ?> — <?= (int) $line['count'] ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- ---------------------------------------------------------- Registre -->
<section class="tab-panel is-active" id="registre">
  <div class="card">
    <h2><?= e(t('gdpr.tabRegister')) ?></h2>
    <?php if ($recordList === []): ?>
      <div class="empty-state"><?= e(t('gdpr.prefillNote', ['software' => t('gdpr.thisSoftware')])) ?></div>
      <form method="POST" action="/rgpd/traitements/amorcer">
        <?= $csrf ?>
        <button type="submit" class="btn btn-primary"><?= e(t('gdpr.prefill')) ?></button>
      </form>
    <?php else: ?>
      <?php foreach ($recordList as $record): ?>
        <div class="org-item">
          <div class="org-head">
            <div>
              <span class="org-name"><?= e($record['name']) ?></span>
              <span class="cell-sub"><?= e(t('gdpr.legalBasis')) ?> : <?= e($record['legal_basis']) ?></span>
            </div>
            <form method="POST" action="/rgpd/traitements/<?= (int) $record['id'] ?>/supprimer"
                  data-confirm="<?= e(t('gdpr.confirmRemoveProcessing')) ?>">
              <?= $csrf ?>
              <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
            </form>
          </div>
          <dl class="detail-list">
            <div><dt><?= e(t('gdpr.purpose')) ?></dt><dd><?= e((string) $record['purpose']) ?></dd></div>
            <div><dt><?= e(t('gdpr.dataCategories')) ?></dt><dd><?= e((string) $record['data_categories']) ?></dd></div>
            <div><dt><?= e(t('common.recipients')) ?></dt><dd><?= e((string) $record['recipients']) ?></dd></div>
            <div><dt><?= e(t('gdpr.retentionPeriod')) ?></dt><dd><?= e((string) $record['retention']) ?></dd></div>
            <div><dt><?= e(t('gdpr.securityMeasures')) ?></dt><dd><?= e((string) $record['measures']) ?></dd></div>
          </dl>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('gdpr.addProcessing')) ?></h2>
    <form method="POST" action="/rgpd/traitements" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.title')) ?></span><input type="text" name="name" required maxlength="160" /></label>
      <label><span><?= e(t('gdpr.legalBasis')) ?></span>
        <select name="legal_basis" required>
          <?php foreach ($legalBases as $basis): ?>
            <option value="<?= e($basis) ?>"><?= e($basis) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('gdpr.retention')) ?></span><input type="text" name="retention" maxlength="300" /></label>
      <label class="span-2"><span><?= e(t('gdpr.purpose')) ?></span><textarea name="purpose" rows="2" maxlength="1000"></textarea></label>
      <label class="span-2"><span><?= e(t('gdpr.dataCategories')) ?></span><textarea name="data_categories" rows="2" maxlength="1000"></textarea></label>
      <label class="span-2"><span><?= e(t('common.recipients')) ?></span><textarea name="recipients" rows="2" maxlength="1000"></textarea></label>
      <label class="span-2"><span><?= e(t('gdpr.securityMeasures')) ?></span><textarea name="measures" rows="2" maxlength="1000"></textarea></label>
      <button type="submit" class="btn btn-primary"><?= e(t('acc.register')) ?></button>
    </form>
  </div>
</section>

<!-- ------------------------------------------------- Droit d'accès -->
<section class="tab-panel" id="acces">
  <div class="card">
    <h2><?= e(t('gdpr.choosePerson')) ?></h2>
    <form method="GET" action="/rgpd" class="inline-form">
      <select name="personne" required>
        <option value=""><?= e(t('common.choose')) ?></option>
        <?php foreach ($people as $person): ?>
          <option value="<?= (int) $person['id'] ?>"<?= $target !== null && (int) $target['id'] === (int) $person['id'] ? ' selected' : '' ?>>
            <?= e($fullName($person)) ?> — <?= e($person['email']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm"><?= e(t('gdpr.seeWhatHeld')) ?></button>
    </form>
  </div>

  <?php if ($target !== null && $held !== null): ?>
    <div class="card mt-l">
      <h2><?= e($fullName($target)) ?></h2>
      <a class="btn btn-sm btn-primary" href="/rgpd/personnes/<?= (int) $target['id'] ?>/export.json">
        <?= e(t('gdpr.exportJson')) ?>
      </a>
      <table class="table mt-l">
        <thead>
          <tr>
            <th><?= e(t('common.source')) ?></th>
            <th><?= e(t('gdpr.records')) ?></th>
            <th><?= e(t('gdpr.onErasureRequest')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($held as $source): ?>
            <tr>
              <td><?= e($source['label']) ?></td>
              <td><?= count($source['rows']) ?></td>
              <td>
                <span class="tag <?= $source['erasable'] ? '' : 'tag-off' ?>">
                  <?= e($source['erasable'] ? t('gdpr.erased') : t('gdpr.keptByObligation')) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card mt-l">
      <h2><?= e(t('gdpr.eraseTitle')) ?></h2>
      <p class="muted"><?= e(t('gdpr.eraseNote')) ?></p>
      <form method="POST" action="/rgpd/personnes/<?= (int) $target['id'] ?>/effacer" class="form-grid"
            data-confirm="<?= e(t('gdpr.confirmErase')) ?>">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('gdpr.typeToConfirm', ['email' => $target['email']])) ?></span>
          <input type="text" name="confirmation" required maxlength="254" />
        </label>
        <button type="submit" class="btn btn-danger"><?= e(t('gdpr.erase')) ?></button>
      </form>
    </div>
  <?php endif; ?>
</section>
