<?php
$csrf = \App\Core\Csrf::field();
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
$money = static fn (mixed $value): string => $value === null
    ? '—' : number_format((float) $value, 2, ',', ' ') . ' €';
$stateTag = static fn (string $state): string => match ($state) {
    'perime' => 'tag-danger', 'bientot' => 'tag-warning', 'valable' => 'tag-success', default => '',
};
$stateLabel = static fn (string $state): string => match ($state) {
    'perime' => t('ptn.expired'), 'bientot' => t('ptn.expiringSoon'),
    'valable' => t('ptn.valid'), default => t('ptn.noExpiry'),
};
?>
<section class="stats-grid">
  <?php foreach ([
      [(string) $compliance['expired'], t('ptn.expiredDocs')],
      [(string) $compliance['soon'], t('ptn.expiringDocs', ['days' => $warningDays])],
      [$lastScore === null ? '—' : (string) $lastScore, t('ptn.lastScore')],
      [(string) count($contracts), t('erp.contracts')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<!-- ---------- Fiche ---------- -->
<section class="tab-panel is-active" id="fiche">
  <div class="card">
    <div class="card-head">
      <div>
        <h2><?= e($partner['name']) ?></h2>
        <p class="card-sub">
          <span class="tag"><?= e(st($partner['kind'])) ?></span>
          <span class="tag <?= (int) $partner['active'] === 1 ? 'tag-success' : '' ?>">
            <?= e((int) $partner['active'] === 1 ? t('common.active') : t('common.inactive')) ?>
          </span>
          <?= e((string) ($partner['registration'] ?? '')) ?>
        </p>
      </div>
      <a class="btn btn-outline btn-sm" href="/gestion#tiers"><?= e(t('ptn.editInGestion')) ?></a>
    </div>
    <ul class="org-list">
      <li class="org-item"><span><?= e(t('ptn.mainContact')) ?></span>
        <span class="cell-strong"><?= e((string) ($partner['contact_name'] ?: '—')) ?></span></li>
      <li class="org-item"><span><?= e(t('common.email')) ?></span>
        <span class="cell-strong"><?= e((string) ($partner['email'] ?: '—')) ?></span></li>
      <li class="org-item"><span><?= e(t('common.phone')) ?></span>
        <span class="cell-strong"><?= e((string) ($partner['phone'] ?: '—')) ?></span></li>
      <li class="org-item"><span><?= e(t('ptn.address')) ?></span>
        <span class="cell-strong"><?= e((string) ($partner['address'] ?: '—')) ?></span></li>
      <?php if ($nextReview !== null): ?>
        <li class="org-item"><span><?= e(t('ptn.nextReview')) ?></span>
          <span class="cell-strong"><?= e($day($nextReview)) ?></span></li>
      <?php endif; ?>
    </ul>
    <?php if (($partner['notes'] ?? '') !== ''): ?>
      <p class="article-body"><?= e($partner['notes']) ?></p>
    <?php endif; ?>
  </div>

  <div class="card mt-l">
    <div class="card-head"><h2><?= e(t('erp.contracts')) ?> <span class="muted">(<?= count($contracts) ?>)</span></h2></div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.title')) ?></th>
            <th><?= e(t('ptn.period')) ?></th>
            <th><?= e(t('erp.amount')) ?></th>
            <th><?= e(t('common.status')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($contracts as $contract): ?>
            <tr>
              <td class="cell-strong"><?= e($contract['title']) ?>
                <?php if (($contract['reference'] ?? '') !== ''): ?>
                  <br /><span class="cell-sub"><?= e($contract['reference']) ?></span>
                <?php endif; ?>
              </td>
              <td class="cell-sub nowrap"><?= e($day($contract['start_date'])) ?> → <?= e($day($contract['end_date'])) ?></td>
              <td class="cell-sub nowrap"><?= e($money($contract['amount'])) ?></td>
              <td><span class="tag"><?= e(st($contract['status'])) ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($contracts === []): ?>
            <tr><td colspan="4" class="cell-sub"><?= e(t('ptn.noContract')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card mt-l">
    <div class="card-head"><h2><?= e(t('erp.invoices')) ?> <span class="muted">(<?= count($invoices) ?>)</span></h2></div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.reference')) ?></th>
            <th><?= e(t('common.date')) ?></th>
            <th><?= e(t('erp.amount')) ?></th>
            <th><?= e(t('common.status')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($invoices as $invoice): ?>
            <tr>
              <td class="cell-strong"><?= e($invoice['reference'] !== '' ? $invoice['reference'] : $invoice['label']) ?>
                <br /><span class="cell-sub"><?= e(st($invoice['direction'])) ?></span>
              </td>
              <td class="cell-sub nowrap"><?= e($day($invoice['issue_date'])) ?></td>
              <td class="cell-sub nowrap"><?= e($money($invoice['amount_ht'])) ?></td>
              <td><span class="tag"><?= e(st($invoice['status'])) ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($invoices === []): ?>
            <tr><td colspan="4" class="cell-sub"><?= e(t('ptn.noInvoice')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- ---------- Interlocuteurs ---------- -->
<section class="tab-panel" id="contacts">
  <div class="card">
    <div class="card-head"><h2><?= e(t('ptn.addContact')) ?></h2></div>
    <form method="POST" action="/partenaires/<?= (int) $partner['id'] ?>/contacts" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.name')) ?></span><input type="text" name="name" required maxlength="120" /></label>
      <label><span><?= e(t('ptn.role')) ?></span><input type="text" name="role" maxlength="80" /></label>
      <label><span><?= e(t('common.email')) ?></span><input type="email" name="email" maxlength="254" /></label>
      <label><span><?= e(t('common.phone')) ?></span><input type="text" name="phone" maxlength="40" /></label>
      <label class="span-2 check-row">
        <input type="checkbox" name="is_primary" value="1" /><span><?= e(t('ptn.setPrimary')) ?></span>
      </label>
      <label class="span-2"><span><?= e(t('common.notes')) ?></span>
        <textarea name="notes" rows="2" maxlength="500"></textarea>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
    </form>
  </div>

  <div class="card mt-l">
    <div class="card-head"><h2><?= e(t('ptn.contactCount', ['count' => count($contacts)])) ?></h2></div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.name')) ?></th>
            <th><?= e(t('ptn.role')) ?></th>
            <th><?= e(t('ptn.reach')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($contacts as $contact): ?>
            <tr>
              <td class="cell-strong"><?= e($contact['name']) ?>
                <?php if ((int) $contact['is_primary'] === 1): ?>
                  <span class="tag tag-success"><?= e(t('ptn.primary')) ?></span>
                <?php endif; ?>
                <?php if ($contact['notes'] !== ''): ?><br /><span class="cell-sub"><?= e($contact['notes']) ?></span><?php endif; ?>
              </td>
              <td class="cell-sub"><?= e($contact['role'] !== '' ? $contact['role'] : '—') ?></td>
              <td class="cell-sub">
                <?= e(implode(' · ', array_filter([$contact['email'], $contact['phone']])) ?: '—') ?>
              </td>
              <td class="actions">
                <?php if ((int) $contact['is_primary'] === 0): ?>
                  <form method="POST" action="/partenaires/contacts/<?= (int) $contact['id'] ?>/principal" class="inline-form">
                    <?= $csrf ?>
                    <input type="hidden" name="partner_id" value="<?= (int) $partner['id'] ?>" />
                    <button type="submit" class="btn btn-outline btn-sm"><?= e(t('ptn.makePrimary')) ?></button>
                  </form>
                <?php endif; ?>
                <form method="POST" action="/partenaires/contacts/<?= (int) $contact['id'] ?>/supprimer" class="inline-form"
                      data-confirm="<?= e(t('ptn.confirmDeleteContact')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($contacts === []): ?>
            <tr><td colspan="4" class="cell-sub"><?= e(t('ptn.noContact')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- ---------- Conformité ---------- -->
<section class="tab-panel" id="conformite">
  <div class="card">
    <div class="card-head"><h2><?= e(t('ptn.addDocument')) ?></h2></div>
    <p class="hint"><?= e(t('ptn.complianceHint', ['days' => $warningDays])) ?></p>
    <form method="POST" action="/partenaires/<?= (int) $partner['id'] ?>/pieces" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('ptn.documentKind')) ?></span>
        <select name="kind">
          <?php foreach ($documentKinds as $kind): ?>
            <option value="<?= e($kind) ?>"><?= e(st($kind)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.reference')) ?></span><input type="text" name="reference" maxlength="80" /></label>
      <label><span><?= e(t('ptn.issuedOn')) ?></span><input type="date" name="issued_on" /></label>
      <label><span><?= e(t('ptn.expiresOn')) ?></span><input type="date" name="expires_on" /></label>
      <label class="span-2"><span><?= e(t('common.notes')) ?></span>
        <textarea name="notes" rows="2" maxlength="500"></textarea>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
    </form>
  </div>

  <div class="card mt-l">
    <div class="card-head"><h2><?= e(t('ptn.documentCount', ['count' => count($documents)])) ?></h2></div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('ptn.documentKind')) ?></th>
            <th><?= e(t('common.reference')) ?></th>
            <th><?= e(t('ptn.issuedOn')) ?></th>
            <th><?= e(t('ptn.expiresOn')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($documents as $document): ?>
            <tr>
              <td class="cell-strong"><?= e(st($document['kind'])) ?>
                <?php if ($document['notes'] !== ''): ?><br /><span class="cell-sub"><?= e($document['notes']) ?></span><?php endif; ?>
              </td>
              <td class="cell-sub"><?= e($document['reference'] !== '' ? $document['reference'] : '—') ?></td>
              <td class="cell-sub nowrap"><?= e($day($document['issued_on'])) ?></td>
              <td class="cell-sub nowrap"><?= e($day($document['expires_on'])) ?></td>
              <td><span class="tag <?= e($stateTag($document['state'])) ?>"><?= e($stateLabel($document['state'])) ?></span></td>
              <td class="actions">
                <form method="POST" action="/partenaires/pieces/<?= (int) $document['id'] ?>/supprimer" class="inline-form"
                      data-confirm="<?= e(t('ptn.confirmDeleteDocument')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($documents === []): ?>
            <tr><td colspan="6" class="cell-sub"><?= e(t('ptn.noDocument')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- ---------- Évaluations ---------- -->
<section class="tab-panel" id="evaluations">
  <div class="card">
    <div class="card-head"><h2><?= e(t('ptn.addReview')) ?></h2></div>
    <p class="hint"><?= e(t('ptn.reviewHint')) ?></p>
    <form method="POST" action="/partenaires/<?= (int) $partner['id'] ?>/evaluations" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('ptn.reviewedOn')) ?></span>
        <input type="date" name="reviewed_on" value="<?= e($today) ?>" required />
      </label>
      <label><span><?= e(t('ptn.nextReview')) ?></span><input type="date" name="next_review" /></label>
      <label><span><?= e(t('ptn.quality')) ?></span><input type="number" name="quality" min="1" max="5" /></label>
      <label><span><?= e(t('ptn.leadTime')) ?></span><input type="number" name="lead_time" min="1" max="5" /></label>
      <label><span><?= e(t('ptn.price')) ?></span><input type="number" name="price" min="1" max="5" /></label>
      <label class="span-2"><span><?= e(t('ptn.comment')) ?></span>
        <textarea name="comment" rows="3" maxlength="2000"></textarea>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
    </form>
  </div>

  <?php foreach ($reviews as $review): ?>
    <div class="card mt-l">
      <div class="card-head">
        <div>
          <h2>
            <?= e($day($review['reviewed_on'])) ?> —
            <?= e($review['score'] === null ? '—' : t('ptn.scoreOf', ['score' => $review['score']])) ?>
          </h2>
          <p class="card-sub">
            <?= e(t('ptn.quality')) ?> <?= e((string) ($review['quality'] ?? '—')) ?> ·
            <?= e(t('ptn.leadTime')) ?> <?= e((string) ($review['lead_time'] ?? '—')) ?> ·
            <?= e(t('ptn.price')) ?> <?= e((string) ($review['price'] ?? '—')) ?>
            <?php if ($review['first_name'] !== null): ?>
              · <?= e($review['first_name'] . ' ' . $review['last_name']) ?>
            <?php endif; ?>
            <?php if ($review['next_review'] !== null): ?>
              · <?= e(t('ptn.nextReviewOn', ['date' => $day($review['next_review'])])) ?>
            <?php endif; ?>
          </p>
        </div>
        <form method="POST" action="/partenaires/evaluations/<?= (int) $review['id'] ?>/supprimer" class="inline-form"
              data-confirm="<?= e(t('ptn.confirmDeleteReview')) ?>">
          <?= $csrf ?>
          <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
        </form>
      </div>
      <?php if ($review['comment'] !== ''): ?><p class="article-body"><?= e($review['comment']) ?></p><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if ($reviews === []): ?>
    <div class="card mt-l"><div class="empty-state"><?= e(t('ptn.noReview')) ?></div></div>
  <?php endif; ?>
</section>
