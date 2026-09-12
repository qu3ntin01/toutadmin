<?php
$csrf = \App\Core\Csrf::field();
$money = static fn (mixed $value): string => number_format((float) ($value ?? 0), 2, ',', ' ') . ' €';
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
$pending = count(array_filter($activities, static fn (array $row): bool => $row['done_at'] === null));
?>
<section class="stats-grid">
  <?php foreach ([
      [$money($pipeline['total']), t('crm.openPipeline')],
      [$money($pipeline['weighted']), t('crm.weighted')],
      [$money($pipeline['won']), t('crm.won')],
      [$pipeline['winRate'] . ' %', t('crm.winRate')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<?php if ($clients === []): ?>
  <div class="banner is-warning">
    <span><?= t('crm.noClientBanner', ['link' => '<a href="/gestion#tiers">' . e(t('crm.management')) . '</a>']) ?></span>
  </div>
<?php endif; ?>

<!-- ---------- Pipeline ---------- -->
<section class="tab-panel is-active" id="pipeline">
  <div class="grid-2">
    <div class="card">
      <div class="card-head"><h2><?= e(t('crm.newOpportunity')) ?></h2></div>
      <form method="POST" action="/crm/opportunites" class="stack">
        <?= $csrf ?>
        <label><span><?= e(t('common.client')) ?></span>
          <select name="partner_id" required>
            <option value="">—</option>
            <?php foreach ($clients as $client): ?>
              <option value="<?= (int) $client['id'] ?>"><?= e($client['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('common.title')) ?></span><input type="text" name="title" maxlength="160" required /></label>
        <div class="form-grid">
          <label><span><?= e(t('erp.amount')) ?></span>
            <input type="text" name="amount" inputmode="decimal" value="0" required />
          </label>
          <label><span><?= e(t('crm.probability')) ?></span>
            <input type="number" name="probability" min="0" max="100" value="50" required />
          </label>
          <label><span><?= e(t('crm.expectedClose')) ?></span><input type="date" name="expected_close" /></label>
          <label><span><?= e(t('common.owner')) ?></span>
            <select name="owner_id">
              <option value="">—</option>
              <?php foreach ($employees as $person): ?>
                <option value="<?= (int) $person['id'] ?>"><?= e($person['first_name'] . ' ' . $person['last_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <button type="submit" class="btn btn-primary btn-block"><?= e(t('common.create')) ?></button>
      </form>
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-head"><h2><?= e(t('crm.byStage')) ?></h2></div>
        <ul class="org-list">
          <?php foreach ($pipeline['byStage'] as $stage): ?>
            <li class="org-item">
              <div class="org-head">
                <div>
                  <span class="org-name"><?= e(st($stage['stage'])) ?></span>
                  <span class="cell-sub"><?= (int) $stage['count'] ?> affaire(s)</span>
                </div>
                <span class="cell-strong"><?= e($money($stage['amount'])) ?></span>
              </div>
              <div class="meter">
                <span class="meter-fill" data-ratio="<?= $pipeline['total'] > 0
                    ? (int) round($stage['amount'] / $pipeline['total'] * 100) : 0 ?>"></span>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="card">
        <div class="card-head"><h2><?= e(t('crm.opportunities', ['count' => count($opportunities)])) ?></h2></div>
        <?php if ($opportunities === []): ?>
          <div class="empty-state"><?= e(t('crm.noOpportunity')) ?></div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th><?= e(t('crm.opportunity')) ?></th>
                  <th><?= e(t('erp.amount')) ?></th>
                  <th><?= e(t('common.step')) ?></th>
                  <th class="actions"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($opportunities as $opportunity): ?>
                  <tr>
                    <td>
                      <div class="cell-strong"><?= e($opportunity['title']) ?></div>
                      <div class="cell-sub">
                        <?= e($opportunity['partner_name']) ?><?= $opportunity['expected_close'] !== null
                            ? ' · ' . e($day($opportunity['expected_close'])) : '' ?>
                        <?= $opportunity['owner_first_name'] !== null
                            ? ' · ' . e($opportunity['owner_first_name'] . ' ' . $opportunity['owner_last_name']) : '' ?>
                      </div>
                    </td>
                    <td class="num cell-strong">
                      <?= e($money($opportunity['amount'])) ?>
                      <div class="cell-sub"><?= (int) $opportunity['probability'] ?> %</div>
                    </td>
                    <td>
                      <span class="status <?= $opportunity['stage'] === 'Gagnée'
                          ? 'status-on' : ($opportunity['stage'] === 'Perdue' ? 'status-danger' : 'status-warning') ?>">
                        <?= e(st($opportunity['stage'])) ?>
                      </span>
                    </td>
                    <td class="actions">
                      <form method="POST" action="/crm/opportunites/<?= (int) $opportunity['id'] ?>/etape" class="inline-form">
                        <?= $csrf ?>
                        <select name="stage" class="input-sm">
                          <?php foreach ($stages as $stage): ?>
                            <option value="<?= e($stage) ?>"<?= $opportunity['stage'] === $stage ? ' selected' : '' ?>>
                              <?= e(st($stage)) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
                      </form>
                      <form method="POST" action="/crm/opportunites/<?= (int) $opportunity['id'] ?>/supprimer"
                            class="inline-form" data-confirm="<?= e(t('crm.confirmDeleteOpportunity')) ?>">
                        <?= $csrf ?>
                        <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.delete')) ?></button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- ---------- Devis ---------- -->
<section class="tab-panel" id="devis">
  <div class="grid-2">
    <div class="card">
      <div class="card-head"><h2><?= e(t('crm.newQuote')) ?></h2></div>
      <form method="POST" action="/crm/devis" class="stack">
        <?= $csrf ?>
        <label><span><?= e(t('common.client')) ?></span>
          <select name="partner_id" required>
            <option value="">—</option>
            <?php foreach ($clients as $client): ?>
              <option value="<?= (int) $client['id'] ?>"><?= e($client['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('crm.opportunity')) ?> <span class="cell-sub">(<?= e(t('common.optional')) ?>)</span></span>
          <select name="opportunity_id">
            <option value="">—</option>
            <?php foreach ($opportunities as $opportunity): ?>
              <option value="<?= (int) $opportunity['id'] ?>"><?= e($opportunity['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('common.title')) ?></span><input type="text" name="label" maxlength="160" required /></label>
        <div class="form-grid">
          <label><span><?= e(t('common.reference')) ?></span><input type="text" name="reference" maxlength="60" /></label>
          <label><span><?= e(t('ges.issue')) ?></span>
            <input type="date" name="issue_date" value="<?= e($today) ?>" required />
          </label>
          <label><span><?= e(t('crm.validUntil')) ?></span><input type="date" name="valid_until" /></label>
          <label><span><?= e(t('ges.amountExclVat')) ?></span>
            <input type="text" name="amount_ht" inputmode="decimal" value="0" required />
          </label>
          <label><span>TVA (%)</span>
            <input type="number" name="vat_rate" min="0" max="100" step="0.1" value="20" required />
          </label>
        </div>
        <button type="submit" class="btn btn-primary btn-block"><?= e(t('common.create')) ?></button>
      </form>
    </div>

    <div class="card">
      <div class="card-head"><h2><?= e(t('crm.quotes', ['count' => count($quotes)])) ?></h2></div>
      <?php if ($quotes === []): ?>
        <div class="empty-state"><?= e(t('crm.noQuote')) ?></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th><?= e(t('crm.tabQuotes')) ?></th>
                <th>TTC</th>
                <th><?= e(t('common.status')) ?></th>
                <th class="actions"><?= e(t('common.actions')) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($quotes as $quote): ?>
                <tr>
                  <td>
                    <div class="cell-strong">
                      <?= e($quote['reference'] !== '' ? $quote['reference'] : '(sans réf.)') ?> — <?= e($quote['label']) ?>
                    </div>
                    <div class="cell-sub">
                      <?= e($quote['partner_name']) ?> · <?= e($day($quote['issue_date'])) ?>
                      <?= $quote['valid_until'] !== null ? ' → ' . e($day($quote['valid_until'])) : '' ?>
                    </div>
                  </td>
                  <td class="num cell-strong"><?= e($money($quote['amount_ttc'])) ?></td>
                  <td>
                    <span class="status <?= $quote['status'] === 'Accepté'
                        ? 'status-on'
                        : ($quote['status'] === 'Refusé' || $quote['expired'] ? 'status-danger' : 'status-warning') ?>">
                      <?= e($quote['expired'] ? t('crm.quoteExpired') : st($quote['status'])) ?>
                    </span>
                    <?php if ($quote['invoice_id'] !== null): ?>
                      <div class="cell-sub"><?= e(t('crm.invoiced')) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="actions">
                    <?php if ($quote['invoice_id'] === null): ?>
                      <form method="POST" action="/crm/devis/<?= (int) $quote['id'] ?>/statut" class="inline-form">
                        <?= $csrf ?>
                        <select name="status" class="input-sm">
                          <?php foreach ($quoteStatuses as $status): ?>
                            <option value="<?= e($status) ?>"<?= $quote['status'] === $status ? ' selected' : '' ?>>
                              <?= e(st($status)) ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
                      </form>
                      <?php if ($quote['status'] === 'Accepté'): ?>
                        <form method="POST" action="/crm/devis/<?= (int) $quote['id'] ?>/facturer" class="inline-form">
                          <?= $csrf ?>
                          <button type="submit" class="btn btn-primary btn-sm"><?= e(t('crm.invoice')) ?></button>
                        </form>
                      <?php endif; ?>
                    <?php endif; ?>
                    <form method="POST" action="/crm/devis/<?= (int) $quote['id'] ?>/supprimer" class="inline-form"
                          data-confirm="<?= e(t('crm.confirmDeleteQuote')) ?>">
                      <?= $csrf ?>
                      <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.delete')) ?></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="hint"><?= e(t('crm.quoteOnceNote')) ?></p>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ---------- Relances ---------- -->
<section class="tab-panel" id="relances">
  <div class="grid-2">
    <div class="card">
      <div class="card-head"><h2><?= e(t('crm.planFollowUp')) ?></h2></div>
      <form method="POST" action="/crm/relances" class="stack">
        <?= $csrf ?>
        <label><span><?= e(t('common.client')) ?></span>
          <select name="partner_id">
            <option value="">—</option>
            <?php foreach ($clients as $client): ?>
              <option value="<?= (int) $client['id'] ?>"><?= e($client['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('crm.opportunity')) ?></span>
          <select name="opportunity_id">
            <option value="">—</option>
            <?php foreach ($opportunities as $opportunity): ?>
              <option value="<?= (int) $opportunity['id'] ?>"><?= e($opportunity['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="form-grid">
          <label><span><?= e(t('common.type')) ?></span>
            <select name="kind">
              <?php foreach ($activityKinds as $kind): ?>
                <option value="<?= e($kind) ?>"><?= e(st($kind)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label><span><?= e(t('erp.dueDate')) ?></span>
            <input type="date" name="due_on" value="<?= e($today) ?>" required />
          </label>
        </div>
        <label><span><?= e(t('common.note')) ?></span>
          <textarea name="note" rows="3" maxlength="1000"></textarea>
        </label>
        <button type="submit" class="btn btn-primary btn-block"><?= e(t('common.create')) ?></button>
        <p class="hint"><?= e(t('crm.followUpTarget')) ?></p>
      </form>
    </div>

    <div class="card">
      <div class="card-head"><h2><?= e(t('crm.followUps', ['count' => $pending])) ?></h2></div>
      <?php if ($activities === []): ?>
        <div class="empty-state"><?= e(t('crm.noFollowUp')) ?></div>
      <?php else: ?>
        <ul class="person-list">
          <?php foreach ($activities as $activity): ?>
            <li class="person-row">
              <span class="person-body">
                <span class="person-name">
                  <?= e(st($activity['kind'])) ?><?= $activity['partner_name'] !== null ? ' · ' . e($activity['partner_name']) : '' ?><?= $activity['opportunity_title'] !== null ? ' · ' . e($activity['opportunity_title']) : '' ?>
                </span>
                <span class="cell-sub <?= $activity['overdue'] ? 'text-danger' : '' ?>">
                  <?= e($day($activity['due_on'])) ?><?= $activity['done_at'] !== null
                      ? ' · faite' : ($activity['overdue'] ? ' · en retard' : '') ?>
                </span>
                <?php if ($activity['note'] !== ''): ?>
                  <span class="benefit-text"><?= e($activity['note']) ?></span>
                <?php endif; ?>
              </span>
              <span class="row-actions">
                <?php if ($activity['done_at'] === null): ?>
                  <form method="POST" action="/crm/relances/<?= (int) $activity['id'] ?>/faite" class="inline-form">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-outline btn-sm"><?= e(t('crm.markDone')) ?></button>
                  </form>
                <?php endif; ?>
                <form method="POST" action="/crm/relances/<?= (int) $activity['id'] ?>/supprimer" class="inline-form"
                      data-confirm="<?= e(t('crm.confirmDeleteFollowUp')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.delete')) ?></button>
                </form>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ---------- Contacts ---------- -->
<section class="tab-panel" id="contacts">
  <div class="grid-2">
    <div class="card">
      <div class="card-head"><h2><?= e(t('crm.newContact')) ?></h2></div>
      <form method="POST" action="/crm/contacts" class="stack">
        <?= $csrf ?>
        <label><span><?= e(t('common.client')) ?></span>
          <select name="partner_id" required>
            <option value="">—</option>
            <?php foreach ($clients as $client): ?>
              <option value="<?= (int) $client['id'] ?>"><?= e($client['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div class="form-grid">
          <label><span><?= e(t('common.firstName')) ?></span>
            <input type="text" name="first_name" maxlength="100" required />
          </label>
          <label><span><?= e(t('common.name')) ?></span>
            <input type="text" name="last_name" maxlength="100" required />
          </label>
          <label><span><?= e(t('common.role')) ?></span><input type="text" name="role" maxlength="100" /></label>
          <label><span><?= e(t('common.email')) ?></span><input type="email" name="email" maxlength="254" /></label>
        </div>
        <button type="submit" class="btn btn-primary btn-block"><?= e(t('common.create')) ?></button>
      </form>
    </div>

    <div class="card">
      <div class="card-head"><h2><?= e(t('crm.contacts', ['count' => count($contacts)])) ?></h2></div>
      <?php if ($contacts === []): ?>
        <div class="empty-state"><?= e(t('crm.noContact')) ?></div>
      <?php else: ?>
        <ul class="person-list">
          <?php foreach ($contacts as $contact): ?>
            <li class="person-row">
              <span class="person-body">
                <span class="person-name"><?= e($contact['first_name'] . ' ' . $contact['last_name']) ?></span>
                <span class="cell-sub">
                  <?= e(implode(' · ', array_filter([$contact['partner_name'], $contact['role'], $contact['email']]))) ?>
                </span>
              </span>
              <form method="POST" action="/crm/contacts/<?= (int) $contact['id'] ?>/supprimer" class="inline-form"
                    data-confirm="<?= e(t('crm.confirmDeleteContact')) ?>">
                <?= $csrf ?>
                <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.delete')) ?></button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</section>
