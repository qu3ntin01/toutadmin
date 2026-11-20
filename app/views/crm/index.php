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
        <h2><?= e(t('crm.forecastTitle')) ?></h2>
        <p class="card-sub"><?= e(t('crm.forecastNote')) ?></p>
        <?php
          $lignes = [];
          if ($pipeline['forecast']['late']['count'] > 0) {
              $lignes[] = [t('crm.forecastLate'), $pipeline['forecast']['late'], 'text-danger'];
          }
          foreach ($pipeline['forecast']['months'] as $m) {
              $lignes[] = [\App\Core\Dates::month($m['month'] . '-01'), $m, ''];
          }
          if ($pipeline['forecast']['later']['count'] > 0) {
              $lignes[] = [t('crm.forecastLater'), $pipeline['forecast']['later'], ''];
          }
          if ($pipeline['forecast']['undated']['count'] > 0) {
              $lignes[] = [t('crm.forecastUndated'), $pipeline['forecast']['undated'], ''];
          }
        ?>
        <table class="table">
          <thead><tr><th><?= e(t('crm.forecastMonth')) ?></th><th><?= e(t('crm.forecastCount')) ?></th><th><?= e(t('erp.amount')) ?></th><th><?= e(t('crm.weighted')) ?></th></tr></thead>
          <tbody>
            <?php foreach ($lignes as [$libelle, $tr, $classe]): ?>
              <tr<?= $classe !== '' ? ' class="' . e($classe) . '"' : '' ?>>
                <td><?= e($libelle) ?></td><td><?= (int) $tr['count'] ?></td><td><?= e($money($tr['amount'])) ?></td><td><strong><?= e($money($tr['weighted'])) ?></strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ($pipeline['lostByReason'] !== []): ?>
          <h3><?= e(t('crm.lostTitle')) ?></h3>
          <ul class="tag-list">
            <?php foreach ($pipeline['lostByReason'] as $r): ?>
              <li><span class="tag"><?= e(t('crm.lost.' . $r['reason'])) ?> · <?= (int) $r['count'] ?></span></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
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
                  <th class="num"><?= e(t('erp.amount')) ?></th>
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
                      <?php if ($opportunity['stage'] === 'Perdue' && ($opportunity['lost_reason'] ?? '') !== ''): ?>
                        <div class="cell-sub"><?= e(t('crm.lost.' . $opportunity['lost_reason'])) ?></div>
                      <?php endif; ?>
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
                        <select name="lost_reason" class="input-sm" aria-label="<?= e(t('crm.lostReason')) ?>">
                          <option value="">— <?= e(t('crm.lostReason')) ?> —</option>
                          <?php foreach ($lostReasons as $r): ?>
                            <option value="<?= e($r) ?>"<?= ($opportunity['lost_reason'] ?? '') === $r ? ' selected' : '' ?>><?= e(t('crm.lost.' . $r)) ?></option>
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
                <th class="num">TTC</th>
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
                        <form method="POST" action="/crm/devis/<?= (int) $quote['id'] ?>/commander" class="inline-form">
                          <?= $csrf ?>
                          <button type="submit" class="btn btn-sm"><?= e(t('ven.toOrder')) ?></button>
                        </form>
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

<?php
$etatCle = ['Brouillon' => 'draft', 'Confirmée' => 'confirmed', 'Partiellement livrée' => 'partial', 'Livrée' => 'delivered', 'Facturée' => 'invoiced', 'Annulée' => 'cancelled'];
$kindCle = static fn (string $k): string => $k === 'Bien' ? 'goods' : 'service';
?>
<section class="tab-panel" id="commandes">
  <div class="grid-2">
    <div class="card">
      <h2><?= e(t('ven.newOrder')) ?></h2>
      <p class="card-sub"><?= e(t('ven.newOrderNote')) ?></p>
      <form method="POST" action="/crm/commandes" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('ven.customer')) ?></span>
          <select name="partner_id" required>
            <option value="">—</option>
            <?php foreach ($clients as $client): ?><option value="<?= (int) $client['id'] ?>"><?= e($client['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('common.date')) ?></span><input type="date" name="order_date" value="<?= e($today) ?>" required /></label>
        <label class="span-2"><span><?= e(t('common.notes')) ?></span><input type="text" name="notes" maxlength="1000" /></label>
        <button type="submit" class="btn btn-primary btn-sm"><?= e(t('ven.createOrder')) ?></button>
      </form>
    </div>
    <div class="card">
      <h2><?= e(t('ven.orders')) ?> <span class="muted">(<?= count($commandes) ?>)</span></h2>
      <?php if ($commandes === []): ?>
        <div class="empty-state"><?= e(t('ven.noOrder')) ?></div>
      <?php else: ?>
        <table class="table">
          <thead><tr><th><?= e(t('common.reference')) ?></th><th><?= e(t('ven.customer')) ?></th><th><?= e(t('ven.totalHt')) ?></th><th><?= e(t('common.status')) ?></th></tr></thead>
          <tbody>
            <?php foreach ($commandes as $c): ?>
              <tr>
                <td><a href="/crm/commandes/<?= (int) $c['id'] ?>"><strong><?= e($c['reference']) ?></strong></a><div class="cell-sub"><?= e($day($c['order_date'])) ?></div></td>
                <td><?= e($c['partner_name']) ?></td>
                <td><?= e($money($c['totalHt'])) ?></td>
                <td><span class="status <?= in_array($c['etat'], ['Facturée', 'Livrée'], true) ? 'status-on' : ($c['etat'] === 'Annulée' ? 'status-off' : 'status-warning') ?>"><?= e(t('ven.state.' . $etatCle[$c['etat']])) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="tab-panel" id="catalogue">
  <div class="grid-2">
    <div class="card">
      <h2><?= e(t('ven.newProduct')) ?></h2>
      <p class="card-sub"><?= e(t('ven.newProductNote')) ?></p>
      <form method="POST" action="/crm/produits" class="form-grid">
        <?= $csrf ?>
        <label><span><?= e(t('common.reference')) ?></span><input type="text" name="reference" required maxlength="40" pattern="[A-Za-z0-9._\-]{1,40}" /></label>
        <label><span><?= e(t('common.type')) ?></span>
          <select name="kind"><?php foreach ($productKinds as $k): ?><option value="<?= e($k) ?>"><?= e(t('ven.kind.' . $kindCle($k))) ?></option><?php endforeach; ?></select>
        </label>
        <label class="span-2"><span><?= e(t('common.name')) ?></span><input type="text" name="name" required maxlength="160" /></label>
        <label><span><?= e(t('ven.priceHt')) ?></span><input type="text" name="price_ht" inputmode="decimal" required /></label>
        <label><span>TVA (%)</span><input type="number" name="vat_rate" min="0" max="100" step="0.1" value="20" required /></label>
        <label><span><?= e(t('ven.unit')) ?></span>
          <select name="unit"><?php foreach ($productUnits as $u): ?><option value="<?= e($u) ?>"><?= e($u) ?></option><?php endforeach; ?></select>
        </label>
        <label><span><?= e(t('ven.stockItem')) ?></span>
          <select name="item_id">
            <option value="">—</option>
            <?php foreach ($articles as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e(($a['reference'] !== '' ? $a['reference'] . ' — ' : '') . $a['label']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('common.description')) ?></span><input type="text" name="description" maxlength="1000" /></label>
        <button type="submit" class="btn btn-primary btn-sm"><?= e(t('ven.addProduct')) ?></button>
      </form>
    </div>
    <div class="card">
      <h2><?= e(t('ven.catalog')) ?> <span class="muted">(<?= count($produits) ?>)</span></h2>
      <?php if ($produits === []): ?>
        <div class="empty-state"><?= e(t('ven.noProduct')) ?></div>
      <?php else: ?>
        <table class="table">
          <thead><tr><th><?= e(t('common.reference')) ?></th><th><?= e(t('common.name')) ?></th><th><?= e(t('ven.priceHt')) ?></th><th></th></tr></thead>
          <tbody>
            <?php foreach ($produits as $p): ?>
              <tr<?= (int) $p['active'] === 1 ? '' : ' class="muted"' ?>>
                <td class="mono"><?= e($p['reference']) ?></td>
                <td><strong><?= e($p['name']) ?></strong>
                  <div class="cell-sub"><?= e(t('ven.kind.' . $kindCle($p['kind']))) ?> · <?= e($p['unit']) ?><?= $p['item_label'] !== null ? ' · ' . e(t('ven.stockItem')) . ' : ' . e($p['item_label']) : '' ?><?= (int) $p['active'] === 1 ? '' : ' · ' . e(t('ven.inactive')) ?></div></td>
                <td><?= e($money((float) $p['price_ht'])) ?><div class="cell-sub">TVA <?= e((string) (float) $p['vat_rate']) ?> %</div></td>
                <td>
                  <form method="POST" action="/crm/produits/<?= (int) $p['id'] ?>/basculer" class="inline-form">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-sm"><?= e((int) $p['active'] === 1 ? t('ven.withdraw') : t('ven.restore')) ?></button>
                  </form>
                  <form method="POST" action="/crm/produits/<?= (int) $p['id'] ?>/supprimer" class="inline-form" data-confirm="<?= e(t('ven.confirmDeleteProduct')) ?>">
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
  </div>
</section>
