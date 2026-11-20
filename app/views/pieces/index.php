<?php
$csrf = \App\Core\Csrf::field();
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : substr((string) $iso, 0, 10);
$moment = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : str_replace('T', ' ', substr((string) $iso, 0, 16));
$money = static fn (mixed $value, ?string $code): string => $value === null
    ? '—'
    : number_format((float) $value, 2, ',', ' ') . ' ' . (string) $code;

/** La liste des pièces, la même pour celles qui attendent et celles qui sont traitées. */
$pieceList = static function (array $rows, string $empty) use ($day, $money): void {
    if ($rows === []) {
        echo '<p class="empty-state">' . e($empty) . '</p>';
        return;
    } ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('pcs.received')) ?></th>
            <th><?= e(t('pcs.piece')) ?></th>
            <th><?= e(t('common.issuer')) ?></th>
            <th class="num"><?= e(t('erp.amount')) ?></th>
            <th><?= e(t('pcs.reading')) ?></th>
            <th><?= e(t('common.state')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td class="cell-sub nowrap">
                <?= e($day($row['received_at'])) ?><br />
                <span class="cell-sub"><?= e($row['source']) ?></span>
              </td>
              <td>
                <div class="cell-strong">
                  <a href="/pieces/<?= (int) $row['id'] ?>">
                    <?= e($row['fields']['reference'] ?? '' ?: ($row['original_name'] ?: t('pcs.pieceNumber', ['id' => (int) $row['id']]))) ?>
                  </a>
                </div>
                <div class="cell-sub"><?= e($row['original_name']) ?></div>
                <?php if ($row['mail_from'] !== ''): ?>
                  <div class="cell-sub"><?= e(t('pcs.from')) ?> <?= e($row['mail_from']) ?></div>
                <?php endif; ?>
              </td>
              <td class="cell-sub">
                <?= e($row['partner_name'] ?? ($row['fields']['supplierName'] ?? null) ?? '—') ?>
                <?php if (!empty($row['partner_name'])): ?>
                  <br /><span class="tag tag-success"><?= e(t('pcs.knownPartner')) ?></span>
                <?php endif; ?>
              </td>
              <td class="num"><?= e($money($row['fields']['amountTtc'] ?? null, $row['fields']['currency'] ?? '')) ?></td>
              <td>
                <span class="meter">
                  <span class="meter-fill <?= (int) $row['confidence'] < 40 ? 'is-over' : ((int) $row['confidence'] < 70 ? 'is-warn' : '') ?>"
                        data-ratio="<?= (int) $row['confidence'] ?>"></span>
                </span>
                <span class="cell-sub"><?= (int) $row['confidence'] ?> %<?= (int) $row['text_length'] === 0 ? ' · ' . e(t('pcs.noReadableText')) : '' ?></span>
              </td>
              <td>
                <span class="tag <?= $row['status'] === 'Facturée' ? 'tag-success' : ($row['status'] === 'Écartée' ? 'tag-danger' : 'tag-warning') ?>">
                  <?= e(st($row['status'])) ?>
                </span>
                <?php if (!empty($row['invoice_reference'])): ?>
                  <br /><span class="cell-sub mono"><?= e($row['invoice_reference']) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
};
?>

<section class="stats-grid">
  <?php foreach ([
      [(string) $stats['waiting'], t('pcs.toHandle')],
      [(string) $stats['lowConfidence'], t('pcs.unsureReads')],
      [(string) $stats['unreadable'], t('pcs.noReadableText')],
      [(string) $stats['invoiced'], t('pcs.becameInvoices')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<!-- ---------- À traiter ---------- -->
<section class="tab-panel is-active" id="a-traiter">
  <div class="card">
    <div class="card-head"><h2><?= e(t('pcs.uploadInvoice')) ?></h2></div>
    <form method="POST" action="/pieces/deposer" enctype="multipart/form-data" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('pcs.fileLabel', ['max' => (int) round($maxBytes / 1048576)])) ?></span>
        <input type="file" name="document" accept=".pdf,.docx,.txt,application/pdf,text/plain" required />
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('pcs.uploadAndAnalyse')) ?></button></div>
    </form>
    <p class="hint"><?= e(t('pcs.uploadNote')) ?></p>
  </div>

  <div class="card">
    <div class="card-head"><h2><?= e(t('pcs.toHandleCount', ['count' => count($waiting)])) ?></h2></div>
    <?php $pieceList($waiting, t('pcs.nothingWaiting')); ?>
  </div>
</section>

<!-- ---------- Traitées ---------- -->
<section class="tab-panel" id="traitees">
  <div class="card">
    <div class="card-head"><h2><?= e(t('pcs.handledTitle')) ?></h2></div>
    <?php $pieceList($handled, t('pcs.noHandled')); ?>
  </div>
</section>

<!-- ---------- Capture ---------- -->
<section class="tab-panel" id="capture">
  <div class="card">
    <div class="card-head">
      <h2><?= e(t('pcs.fetchMailbox')) ?></h2>
      <span class="tag <?= $mail['enabled'] ? 'tag-success' : '' ?>">
        <?= e($mail['enabled'] ? t('pcs.mailActive') : t('pcs.mailInactive')) ?>
      </span>
    </div>

    <?php if ($mailStatus !== null): ?>
      <div class="flash flash-<?= !empty($mailStatus['ok']) ? 'success' : 'error' ?>">
        <?= e(t('pcs.lastFetch')) ?> <?= e($moment($mailStatus['at'] ?? null)) ?> : <?= e($mailStatus['message'] ?? '') ?>
      </div>
    <?php endif; ?>

    <?php if ($admin): ?>
      <form method="POST" action="/pieces/capture/reglages" class="form-grid">
        <?= $csrf ?>
        <label><span><?= e(t('pcs.imapServer')) ?></span>
          <input type="text" name="host" maxlength="200" value="<?= e($mail['host']) ?>" placeholder="imap.exemple.fr" required />
        </label>
        <label><span><?= e(t('pcs.port')) ?></span>
          <input type="number" name="port" min="1" max="65535" value="<?= (int) $mail['port'] ?>" required />
        </label>
        <label><span><?= e(t('common.identifier')) ?></span>
          <input type="text" name="user" maxlength="200" value="<?= e($mail['user']) ?>" placeholder="factures@exemple.fr" required />
        </label>
        <label><span><?= e(t('auth.password')) ?></span>
          <input type="password" name="password" placeholder="<?= e($mail['password']) ?>" autocomplete="new-password" />
        </label>
        <label><span><?= e(t('pcs.folderFetched')) ?></span>
          <input type="text" name="folder" maxlength="120" value="<?= e($mail['folder']) ?>" />
        </label>
        <label><span><?= e(t('pcs.afterHandling')) ?></span>
          <select name="action">
            <?php foreach ($mailActions as $action): ?>
              <option value="<?= e($action['key']) ?>"<?= $action['key'] === $mail['action'] ? ' selected' : '' ?>><?= e($action['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('pcs.filingFolder')) ?></span>
          <input type="text" name="move_folder" maxlength="120" value="<?= e($mail['moveFolder']) ?>" />
        </label>
        <label><span><?= e(t('pcs.fetchWindowDays')) ?></span>
          <input type="number" name="since_days" min="1" max="365" value="<?= (int) $mail['sinceDays'] ?>" />
        </label>
        <label><span><?= e(t('pcs.messagesPerFetch')) ?></span>
          <input type="number" name="batch" min="1" max="100" value="<?= (int) $mail['batch'] ?>" />
        </label>
        <label class="check-row">
          <input type="checkbox" name="secure" value="1"<?= $mail['secure'] ? ' checked' : '' ?> /><span><?= e(t('pcs.imaps')) ?></span>
        </label>
        <label class="check-row">
          <input type="checkbox" name="allow_self_signed" value="1"<?= $mail['allowSelfSigned'] ? ' checked' : '' ?> /><span><?= e(t('pcs.selfSigned')) ?></span>
        </label>
        <label class="check-row">
          <input type="checkbox" name="enabled" value="1"<?= $mail['enabled'] ? ' checked' : '' ?> /><span><?= e(t('pcs.autoFetch')) ?></span>
        </label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
      </form>
    <?php else: ?>
      <p class="hint"><?= e(t('pcs.mailAdminNote')) ?></p>
    <?php endif; ?>

    <?php if ($mailReady): ?>
      <div class="row-actions">
        <form method="POST" action="/pieces/capture/tester" class="inline-form">
          <?= $csrf ?>
          <button type="submit" class="btn btn-outline btn-sm"><?= e(t('pcs.testConnection')) ?></button>
        </form>
        <form method="POST" action="/pieces/capture/relever" class="inline-form">
          <?= $csrf ?>
          <button type="submit" class="btn btn-outline btn-sm"><?= e(t('pcs.fetchNow')) ?></button>
        </form>
      </div>
    <?php endif; ?>

    <p class="hint"><?= t('pcs.mailScopeNote', ['unread' => '<strong>' . e(t('messages.unread')) . '</strong>']) ?></p>
    <p class="hint"><?= e(t('pcs.passwordNote')) ?></p>
  </div>
</section>

<!-- ---------- Analyse assistée ---------- -->
<?php /* Le réglage vit désormais dans « Interfaces » : il sert quatre espaces, et
         décider de ce qui sort de l'entreprise n'est pas un geste comptable. Cet
         onglet garde l'état sous les yeux de qui traite les pièces, et rien de
         plus — la porte se règle là où sont les autres portes. */ ?>
<section class="tab-panel" id="analyse">
  <div class="card">
    <div class="card-head">
      <h2><?= e(t('pcs.aiTitle')) ?></h2>
      <span class="tag <?= $aiConfig['enabled'] ? 'tag-success' : '' ?>">
        <?= e($aiConfig['enabled'] ? t('ia.set.on') : t('ia.set.off')) ?>
      </span>
    </div>

    <p class="cell-sub"><?= e(t('pcs.aiNote')) ?></p>

    <?php if ($aiStatus !== null): ?>
      <div class="flash flash-<?= !empty($aiStatus['ok']) ? 'success' : 'error' ?>">
        <?= e(t('bak.lastAttempt')) ?> <?= e($moment($aiStatus['at'] ?? null)) ?> : <?= e($aiStatus['message'] ?? '') ?>
      </div>
    <?php endif; ?>

    <p class="hint"><?= e(t('pcs.modelDecidesNothing')) ?></p>

    <?php if ($admin): ?>
      <div class="row-actions">
        <a class="btn btn-outline btn-sm" href="/integrations#assistance"><?= e(t('ia.set.goSettings')) ?></a>
      </div>
      <p class="hint"><?= e(t('ia.set.movedHere')) ?></p>
    <?php else: ?>
      <p class="hint"><?= e(t('pcs.settingAdminOnly')) ?></p>
    <?php endif; ?>
  </div>
</section>
