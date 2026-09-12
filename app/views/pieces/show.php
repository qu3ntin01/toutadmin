<?php
$csrf = \App\Core\Csrf::field();
$value = static fn (mixed $raw): string => ($raw === null || $raw === '') ? '—' : (string) $raw;
$moment = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : str_replace('T', ' ', substr((string) $iso, 0, 16));
/** D'où vient un champ : des règles, ou du modèle qui a comblé un manque. */
$from = static fn (string $name): string => ($document['sources'][$name] ?? '') === 'ia' ? 'modèle' : 'règles';
$fields = $document['fields'];
?>
<section class="tab-panel is-active" id="lecture">
  <div class="card">
    <div class="card-head">
      <h2><?= e(t('pce.autoRead')) ?></h2>
      <span class="tag <?= (int) $document['confidence'] >= 70 ? 'tag-success' : ((int) $document['confidence'] >= 40 ? 'tag-warning' : 'tag-danger') ?>">
        <?= e(t('pcs.reading')) ?> <?= (int) $document['confidence'] ?> %
      </span>
    </div>

    <?php if (!$integrity['ok']): ?>
      <div class="flash flash-error"><?= e(t('pce.integrityFailed', ['reason' => $integrity['reason']])) ?></div>
    <?php endif; ?>
    <?php if ((int) $document['text_length'] === 0): ?>
      <div class="flash flash-error"><?= e(t('pce.noTextRead')) ?></div>
    <?php endif; ?>
    <?php foreach ($document['notes'] as $note): ?>
      <p class="hint"><?= e($note) ?></p>
    <?php endforeach; ?>
    <?php if (!empty($document['analysis']['aiError'])): ?>
      <p class="hint"><?= e(t('pce.aiUnavailable', ['reason' => $document['analysis']['aiError']])) ?></p>
    <?php endif; ?>

    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('pce.field')) ?></th>
            <th><?= e(t('pce.readValue')) ?></th>
            <th><?= e(t('common.origin')) ?></th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td class="cell-sub"><?= e(t('common.issuer')) ?></td>
            <td class="cell-strong"><?= e($value($document['partner_name'] ?? ($fields['supplierName'] ?? null))) ?></td>
            <td class="cell-sub">
              <?= e($from('supplierName')) ?><?= !empty($fields['partnerReason']) ? e(' · tiers reconnu par ' . $fields['partnerReason']) : '' ?>
            </td>
          </tr>
          <tr>
            <td class="cell-sub"><?= e(t('common.number')) ?></td>
            <td><?= e($value($fields['reference'] ?? null)) ?></td>
            <td class="cell-sub"><?= e($from('reference')) ?></td>
          </tr>
          <tr>
            <td class="cell-sub"><?= e(t('common.date')) ?></td>
            <td><?= e($value($fields['issueDate'] ?? null)) ?></td>
            <td class="cell-sub"><?= e($from('issueDate')) ?></td>
          </tr>
          <tr>
            <td class="cell-sub"><?= e(t('erp.dueDate')) ?></td>
            <td><?= e($value($fields['dueDate'] ?? null)) ?></td>
            <td class="cell-sub"><?= e($from('dueDate')) ?></td>
          </tr>
          <tr>
            <td class="cell-sub"><?= e(t('pce.totalExclVat')) ?></td>
            <td class="num"><?= e($value($fields['amountHt'] ?? null)) ?></td>
            <td class="cell-sub"><?= e($from('amountHt')) ?></td>
          </tr>
          <tr>
            <td class="cell-sub"><?= e(t('nav.vat')) ?></td>
            <td class="num">
              <?= e($value($fields['amountVat'] ?? null)) ?>
              <?= !empty($fields['vatRate']) ? e('(' . $fields['vatRate'] . ' %)') : '' ?>
            </td>
            <td class="cell-sub"><?= e($from('amountVat')) ?></td>
          </tr>
          <tr>
            <td class="cell-sub"><?= e(t('pce.totalInclVat')) ?></td>
            <td class="num cell-strong">
              <?= e($value($fields['amountTtc'] ?? null)) ?> <?= e((string) ($fields['currency'] ?? '')) ?>
            </td>
            <td class="cell-sub"><?= e($from('amountTtc')) ?></td>
          </tr>
          <tr>
            <td class="cell-sub">SIRET / TVA</td>
            <td class="mono">
              <?= e($value($fields['siret'] ?? ($fields['siren'] ?? null))) ?> <?= e($value($fields['vatNumber'] ?? null)) ?>
            </td>
            <td class="cell-sub"><?= e($from('siret')) ?></td>
          </tr>
          <tr>
            <td class="cell-sub">IBAN</td>
            <td class="mono"><?= e($value($fields['iban'] ?? null)) ?></td>
            <td class="cell-sub"><?= e($from('iban')) ?></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="row-actions">
      <a href="/pieces/<?= (int) $document['id'] ?>/fichier" class="btn btn-outline btn-sm"><?= e(t('pce.openFile')) ?></a>
      <form method="POST" action="/pieces/<?= (int) $document['id'] ?>/analyser" class="inline-form">
        <?= $csrf ?>
        <button type="submit" class="btn btn-outline btn-sm">
          <?= e(t('pce.reRead')) ?><?= $aiReady ? e(' (' . t('pce.withModel') . ')') : '' ?>
        </button>
      </form>
    </div>
    <p class="cell-sub mono">SHA-256 : <?= e($document['sha256']) ?></p>
  </div>

  <?php if ($document['mail_subject'] !== '' || $document['mail_from'] !== ''): ?>
    <div class="card mt-l">
      <div class="card-head"><h2><?= e(t('pce.sourceEmail')) ?></h2></div>
      <table class="table">
        <tbody>
          <tr><td class="cell-sub"><?= e(t('pce.sender')) ?></td><td class="cell-strong"><?= e($value($document['mail_from'])) ?></td></tr>
          <tr><td class="cell-sub"><?= e(t('common.subject')) ?></td><td><?= e($value($document['mail_subject'])) ?></td></tr>
          <tr><td class="cell-sub"><?= e(t('common.date')) ?></td><td><?= e($moment($document['mail_date'])) ?></td></tr>
          <tr>
            <td class="cell-sub"><?= e(t('pce.attachment')) ?></td>
            <td><?= e($document['original_name']) ?> · <?= (int) round((int) $document['byte_size'] / 1024) ?> Ko</td>
          </tr>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="tab-panel" id="facturer">
  <?php if ($document['status'] === 'À traiter'): ?>
    <div class="card">
      <div class="card-head"><h2><?= e(t('pce.createInvoice')) ?></h2></div>
      <form method="POST" action="/pieces/<?= (int) $document['id'] ?>/facturer" class="form-grid">
        <?= $csrf ?>
        <label><span><?= e(t('common.direction')) ?></span>
          <select name="direction">
            <?php $suggested = $document['analysis']['direction'] ?? 'Fournisseur'; ?>
            <?php foreach ($invoiceDirections as $direction): ?>
              <option value="<?= e($direction) ?>"<?= $direction === $suggested ? ' selected' : '' ?>><?= e(st($direction)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('erp.partners')) ?></span>
          <select name="partner_id">
            <option value="">—</option>
            <?php foreach ($partners as $partner): ?>
              <option value="<?= (int) $partner['id'] ?>"<?= (int) $partner['id'] === (int) $document['partner_id'] ? ' selected' : '' ?>>
                <?= e($partner['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('common.title')) ?></span>
          <?php
          $proposed = $fields['label'] ?? implode(' — ', array_filter([
              $document['partner_name'] ?? ($fields['supplierName'] ?? null),
              $fields['reference'] ?? null,
          ]));
          ?>
          <input type="text" name="label" required maxlength="160" value="<?= e((string) $proposed) ?>" />
        </label>
        <label><span><?= e(t('common.reference')) ?></span>
          <input type="text" name="reference" maxlength="60" value="<?= e((string) ($fields['reference'] ?? '')) ?>" />
        </label>
        <label><span><?= e(t('ges.chargedDepartment')) ?></span>
          <select name="department_id">
            <option value="">—</option>
            <?php foreach ($departments as $department): ?>
              <option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('ges.issue')) ?></span>
          <input type="date" name="issue_date" required value="<?= e((string) ($fields['issueDate'] ?? $today)) ?>" />
        </label>
        <label><span><?= e(t('erp.dueDate')) ?></span>
          <input type="date" name="due_date" value="<?= e((string) ($fields['dueDate'] ?? '')) ?>" />
        </label>
        <label><span><?= e(t('ges.amountExclVat')) ?></span>
          <input type="text" name="amount_ht" inputmode="decimal" required
                 value="<?= e(($fields['amountHt'] ?? null) === null ? '' : (string) $fields['amountHt']) ?>" />
        </label>
        <label><span>TVA (%)</span>
          <input type="number" name="vat_rate" min="0" max="100" step="0.1" required
                 value="<?= e((string) (($fields['vatRate'] ?? null) === null ? 20 : $fields['vatRate'])) ?>" />
        </label>
        <label><span><?= e(t('common.currency')) ?></span>
          <select name="currency">
            <?php $chosen = $fields['currency'] ?? $baseCurrency; ?>
            <?php foreach ($currencies as $currency): ?>
              <option value="<?= e($currency['code']) ?>"<?= $currency['code'] === $chosen ? ' selected' : '' ?>><?= e($currency['code']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('pce.createAndFile')) ?></button></div>
      </form>
      <p class="hint"><?= e(t('pce.valuesNote')) ?></p>
    </div>

    <div class="card mt-l">
      <div class="card-head"><h2><?= e(t('pce.setAside')) ?></h2></div>
      <form method="POST" action="/pieces/<?= (int) $document['id'] ?>/ecarter" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('common.reason')) ?></span>
          <input type="text" name="note" maxlength="500" placeholder="<?= e(t('pce.setAsidePlaceholder')) ?>" />
        </label>
        <div class="span-2"><button type="submit" class="btn btn-outline btn-block"><?= e(t('pce.setAsidePiece')) ?></button></div>
      </form>
    </div>
  <?php else: ?>
    <div class="card">
      <div class="card-head">
        <h2><?= e(t('pce.handled')) ?></h2>
        <span class="tag <?= $document['status'] === 'Facturée' ? 'tag-success' : 'tag-danger' ?>"><?= e(st($document['status'])) ?></span>
      </div>
      <p class="cell-sub">
        <?= $document['handled_at'] !== null ? e(t('pce.handledOn', ['date' => $moment($document['handled_at'])])) : '' ?>
        <?php if (!empty($document['invoice_reference'])): ?>
          · <?= t('pce.invoiceRef', ['reference' => '<span class="mono">' . e($document['invoice_reference']) . '</span>']) ?>
        <?php endif; ?>
      </p>
      <?php if ($document['note'] !== ''): ?>
        <p class="cell-sub"><?= e(t('pce.reason', ['reason' => $document['note']])) ?></p>
      <?php endif; ?>

      <?php if ($document['status'] !== 'Facturée'): ?>
        <div class="row-actions">
          <form method="POST" action="/pieces/<?= (int) $document['id'] ?>/supprimer" class="inline-form"
                data-confirm="<?= e(t('pce.confirmDelete')) ?>">
            <?= $csrf ?>
            <button type="submit" class="btn btn-link btn-sm"><?= e(t('common.delete')) ?></button>
          </form>
        </div>
      <?php else: ?>
        <p class="hint"><?= e(t('pce.notDeletable')) ?></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
