<?php
$csrf = \App\Core\Csrf::field();
$money = static fn (mixed $value): string => number_format((float) ($value ?? 0), 2, ',', ' ') . ' €';
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
?>
<div class="banner is-warning">
  <span><?= e(t('einv.payloadNote')) ?></span>
</div>

<?php if ($issuerGaps !== []): ?>
  <div class="banner is-danger">
    <span><?= e(t('einv.issuerGaps', ['gaps' => implode(', ', $issuerGaps)])) ?></span>
  </div>
<?php endif; ?>

<!-- ---------- Factures ---------- -->
<section class="tab-panel is-active" id="factures">
  <div class="card">
    <div class="card-head">
      <div>
        <h2><?= e(t('einv.customerInvoices')) ?>
          <span class="muted">(<?= (int) $readyCount ?>/<?= count($invoices) ?> conformes)</span>
        </h2>
        <p class="card-sub"><?= e(t('einv.gapsListed')) ?></p>
      </div>
    </div>

    <?php if ($invoices === []): ?>
      <div class="empty-state"><?= e(t('einv.noInvoice')) ?></div>
    <?php else: ?>
      <ul class="meeting-list">
        <?php foreach ($invoices as $invoice): ?>
          <li class="meeting-item">
            <div class="meeting-head">
              <span class="meeting-title">
                <?= e($invoice['reference'] !== '' ? $invoice['reference'] : '(sans numéro)') ?> — <?= e($invoice['label']) ?>
              </span>
              <span class="status <?= $invoice['conformity']['ok'] ? 'status-on' : 'status-danger' ?>">
                <?= e($invoice['conformity']['ok']
                    ? 'Conforme'
                    : count($invoice['conformity']['gaps']) . ' manque(s)') ?>
              </span>
            </div>
            <p class="cell-sub">
              <?= e($invoice['partner_name'] ?? 'Client non renseigné') ?> · <?= e($day($invoice['issue_date'])) ?>
              · <?= e($money($invoice['amount_ttc'])) ?> TTC
            </p>

            <?php if (!$invoice['conformity']['ok']): ?>
              <ul class="check-list">
                <?php foreach ($invoice['conformity']['gaps'] as $gap): ?>
                  <li class="check-item">
                    <span class="check-mark is-fail">✕</span>
                    <span class="check-body"><span class="check-label"><?= e($gap) ?></span></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <div class="row-actions">
                <a href="/facturation-electronique/factures/<?= (int) $invoice['id'] ?>.xml" class="btn btn-primary btn-sm">
                  <?= e(t('einv.downloadXml')) ?>
                </a>
              </div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>

<!-- ---------- Émetteur ---------- -->
<section class="tab-panel" id="emetteur">
  <div class="grid-2">
    <div class="card">
      <div class="card-head">
        <div>
          <h2><?= e(t('einv.issuerIdentity')) ?></h2>
          <p class="card-sub"><?= e(t('einv.mentionsNote')) ?></p>
        </div>
      </div>
      <form method="POST" action="/facturation-electronique/emetteur" class="stack">
        <?= $csrf ?>
        <?php foreach ($issuerFields as $field): ?>
          <label>
            <span>
              <?= e($field['label']) ?>
              <?php if ($field['hint'] !== ''): ?><span class="cell-sub">(<?= e($field['hint']) ?>)</span><?php endif; ?>
            </span>
            <input type="text" name="<?= e($field['key']) ?>" value="<?= e($issuer[$field['key']]) ?>" maxlength="200"
                   class="<?= $field['required'] && $issuer[$field['key']] === '' ? 'is-missing' : '' ?>" />
          </label>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button>
      </form>
    </div>

    <div class="card">
      <div class="card-head"><h2><?= e(t('einv.exportContent')) ?></h2></div>
      <ul class="check-list">
        <?php foreach ([
            ['Profil EN 16931', 'Le XML déclare la spécification urn:cen.eu:en16931:2017.'],
            ['Format CII', 'CrossIndustryInvoice UN/CEFACT, celui attendu par Factur-X.'],
            ['Identification des parties', "SIREN et TVA de l'émetteur, identifiant et adresse du client."],
            ['Montants', 'HT, TVA par taux, TTC et net à payer.'],
            ['Échéance', 'Date de paiement attendue.'],
        ] as [$label, $detail]): ?>
          <li class="check-item">
            <span class="check-mark is-ok">✓</span>
            <span class="check-body">
              <span class="check-label"><?= e($label) ?></span>
              <span class="check-detail"><?= e($detail) ?></span>
            </span>
          </li>
        <?php endforeach; ?>
        <li class="check-item">
          <span class="check-mark is-warn">!</span>
          <span class="check-body">
            <span class="check-label"><?= e(t('einv.pdfa3')) ?></span>
            <span class="check-detail"><?= e(t('einv.pdfa3Note')) ?></span>
          </span>
        </li>
      </ul>
    </div>
  </div>
</section>
