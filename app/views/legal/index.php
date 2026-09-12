<?php
$csrf = \App\Core\Csrf::field();
$money = static fn (?float $value): string => $value === null ? '—' : number_format($value, 2, ',', ' ') . ' €';
$num = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ',');
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
$fullName = static fn (array $row): string => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
?>
<?php if ($isAdmin): ?>
  <section class="stats-grid">
    <?php foreach ([
        [(string) $summary['shareholders'], t('jur.shareholders')],
        [$num((float) $summary['shares']), t('jur.shares')],
        [(string) $summary['mandates'], t('jur.liveMandates')],
        [(string) $summary['giftsToReview'], t('jur.giftsToReview')],
    ] as [$value, $label]): ?>
      <div class="stat-card">
        <span class="stat-body">
          <span class="stat-value"><?= e($value) ?></span>
          <span class="stat-label"><?= e($label) ?></span>
        </span>
      </div>
    <?php endforeach; ?>
  </section>

  <!-- ---------- Capital ---------- -->
  <section class="tab-panel is-active" id="capital">
    <div class="card">
      <h2><?= e(t('jur.capitalTitle', ['total' => $num((float) $capital['total'])])) ?></h2>
      <p class="muted"><?= e(t('jur.capitalHint')) ?></p>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('jur.shareholder')) ?></th>
              <th><?= e(t('common.type')) ?></th>
              <th><?= e(t('jur.shares')) ?></th>
              <th><?= e(t('jur.stake')) ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($capital['holders'] as $holder): ?>
              <tr>
                <td class="cell-strong">
                  <?= e($holder['name']) ?>
                  <?php if ($holder['registration'] !== ''): ?><br /><span class="cell-sub"><?= e($holder['registration']) ?></span><?php endif; ?>
                </td>
                <td class="cell-sub"><?= e(st($holder['kind'])) ?></td>
                <td class="cell-sub nowrap"><?= e($num((float) $holder['shares'])) ?></td>
                <td class="<?= $holder['share'] > 50 ? 'cell-strong' : 'cell-sub' ?> nowrap"><?= e($num((float) $holder['share'])) ?> %</td>
                <td class="actions">
                  <form method="POST" action="/juridique/associes/<?= (int) $holder['id'] ?>/supprimer"
                        data-confirm="<?= e(t('jur.confirmDeleteShareholder')) ?>">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($capital['holders'] === []): ?>
              <tr><td colspan="5" class="cell-sub"><?= e(t('jur.noShareholder')) ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($capital['majority'] !== []): ?>
        <p class="hint"><?= e(t('jur.majorityHolder', ['name' => implode(', ', $capital['majority'])])) ?></p>
      <?php endif; ?>
    </div>

    <div class="grid-2 mt-l">
      <div class="card">
        <h2><?= e(t('jur.addShareholder')) ?></h2>
        <form method="POST" action="/juridique/associes" class="form-grid">
          <?= $csrf ?>
          <label class="span-2"><span><?= e(t('common.name')) ?></span><input type="text" name="name" required maxlength="150" /></label>
          <label><span><?= e(t('common.type')) ?></span>
            <select name="kind">
              <?php foreach ($shareholderKinds as $kind): ?>
                <option value="<?= e($kind) ?>"><?= e(st($kind)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label><span><?= e(t('jur.registration')) ?></span><input type="text" name="registration" maxlength="40" /></label>
          <label><span><?= e(t('common.email')) ?></span><input type="email" name="email" maxlength="254" /></label>
          <label><span><?= e(t('jur.linkedAccount')) ?></span>
            <select name="user_id">
              <option value="">—</option>
              <?php foreach ($employees as $person): ?>
                <option value="<?= (int) $person['id'] ?>"><?= e($fullName($person)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="span-2"><span><?= e(t('ptn.address')) ?></span><input type="text" name="address" maxlength="300" /></label>
          <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
        </form>
      </div>

      <div class="card">
        <h2><?= e(t('jur.addMovement')) ?></h2>
        <form method="POST" action="/juridique/mouvements" class="form-grid">
          <?= $csrf ?>
          <label class="span-2"><span><?= e(t('jur.shareholder')) ?></span>
            <select name="shareholder_id" required>
              <option value="" disabled selected><?= e(t('common.choose')) ?></option>
              <?php foreach ($shareholderList as $holder): ?>
                <option value="<?= (int) $holder['id'] ?>"><?= e($holder['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label><span><?= e(t('common.type')) ?></span>
            <select name="kind">
              <?php foreach ($movementKinds as $kind): ?>
                <option value="<?= e($kind) ?>"><?= e(st($kind)) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label><span><?= e(t('common.date')) ?></span><input type="date" name="moved_on" value="<?= e($today) ?>" required /></label>
          <label><span><?= e(t('jur.shares')) ?></span><input type="text" name="shares" inputmode="decimal" required /></label>
          <label><span><?= e(t('jur.unitPrice')) ?></span><input type="text" name="unit_price" inputmode="decimal" /></label>
          <label class="span-2"><span><?= e(t('jur.counterparty')) ?></span>
            <select name="counterparty_id">
              <option value="">—</option>
              <?php foreach ($shareholderList as $holder): ?>
                <option value="<?= (int) $holder['id'] ?>"><?= e($holder['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('jur.record')) ?></button></div>
        </form>
        <p class="hint"><?= e(t('jur.transferHint')) ?></p>
      </div>
    </div>

    <div class="card mt-l">
      <h2><?= e(t('jur.movementRegister', ['count' => count($movementList)])) ?></h2>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('common.date')) ?></th>
              <th><?= e(t('jur.shareholder')) ?></th>
              <th><?= e(t('common.type')) ?></th>
              <th><?= e(t('jur.shares')) ?></th>
              <th><?= e(t('jur.counterparty')) ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($movementList as $movement): ?>
              <tr>
                <td class="cell-sub nowrap"><?= e($day($movement['moved_on'])) ?></td>
                <td class="cell-strong"><?= e($movement['shareholder_name']) ?></td>
                <td class="cell-sub"><?= e(st($movement['kind'])) ?></td>
                <td class="<?= (float) $movement['shares'] < 0 ? 'text-danger' : 'cell-sub' ?> nowrap"><?= e($num((float) $movement['shares'])) ?></td>
                <td class="cell-sub"><?= e($movement['counterparty_name'] ?? '—') ?></td>
                <td class="actions">
                  <form method="POST" action="/juridique/mouvements/<?= (int) $movement['id'] ?>/supprimer"
                        data-confirm="<?= e(t('jur.confirmDeleteMovement')) ?>">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($movementList === []): ?>
              <tr><td colspan="6" class="cell-sub"><?= e(t('jur.noMovement')) ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- ---------- Mandats ---------- -->
  <section class="tab-panel" id="mandats">
    <div class="card">
      <h2><?= e(t('jur.mandateCount', ['count' => count($mandateList)])) ?></h2>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('jur.holder')) ?></th>
              <th><?= e(t('jur.role')) ?></th>
              <th><?= e(t('jur.period')) ?></th>
              <th><?= e(t('jur.appointedBy')) ?></th>
              <th><?= e(t('common.status')) ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($mandateList as $mandate): ?>
              <tr>
                <td class="cell-strong"><?= e($mandate['holder_name']) ?></td>
                <td class="cell-sub"><?= e(st($mandate['role'])) ?></td>
                <td class="cell-sub nowrap"><?= e($day($mandate['started_on'])) ?> → <?= e($day($mandate['ends_on'])) ?></td>
                <td class="cell-sub"><?= e($mandate['appointed_by'] !== '' ? $mandate['appointed_by'] : '—') ?></td>
                <td><span class="tag <?= $mandate['status'] === 'En cours' ? 'tag-success' : '' ?>"><?= e(st($mandate['status'])) ?></span></td>
                <td class="actions">
                  <form method="POST" action="/juridique/mandats/<?= (int) $mandate['id'] ?>/statut" class="inline-form">
                    <?= $csrf ?>
                    <select name="status" class="inline-select">
                      <?php foreach ($mandateStatuses as $status): ?>
                        <option value="<?= e($status) ?>"<?= $mandate['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
                  </form>
                  <form method="POST" action="/juridique/mandats/<?= (int) $mandate['id'] ?>/supprimer"
                        data-confirm="<?= e(t('jur.confirmDeleteMandate')) ?>">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($mandateList === []): ?>
              <tr><td colspan="6" class="cell-sub"><?= e(t('jur.noMandate')) ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mt-l">
      <h2><?= e(t('jur.addMandate')) ?></h2>
      <form method="POST" action="/juridique/mandats" class="form-grid">
        <?= $csrf ?>
        <label><span><?= e(t('jur.holder')) ?></span><input type="text" name="holder_name" required maxlength="150" /></label>
        <label><span><?= e(t('jur.role')) ?></span>
          <select name="role">
            <?php foreach ($mandateRoles as $role): ?>
              <option value="<?= e($role) ?>"><?= e(st($role)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('jur.startedOn')) ?></span><input type="date" name="started_on" value="<?= e($today) ?>" required /></label>
        <label><span><?= e(t('jur.endsOn')) ?></span><input type="date" name="ends_on" /></label>
        <label><span><?= e(t('jur.linkedAccount')) ?></span>
          <select name="user_id">
            <option value="">—</option>
            <?php foreach ($employees as $person): ?>
              <option value="<?= (int) $person['id'] ?>"><?= e($fullName($person)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('jur.appointedBy')) ?></span><input type="text" name="appointed_by" maxlength="150" /></label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
      </form>
    </div>
  </section>

  <!-- ---------- Assemblées ---------- -->
  <section class="tab-panel" id="assemblees">
    <div class="card">
      <h2><?= e(t('jur.meetingCount', ['count' => count($meetingList)])) ?></h2>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('common.reference')) ?></th>
              <th><?= e(t('common.type')) ?></th>
              <th><?= e(t('common.date')) ?></th>
              <th><?= e(t('jur.resolutions')) ?></th>
              <th><?= e(t('common.status')) ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($meetingList as $meeting): ?>
              <tr>
                <td class="cell-strong mono">
                  <a href="/juridique/assemblees/<?= (int) $meeting['id'] ?>"><?= e($meeting['reference']) ?></a>
                </td>
                <td class="cell-sub"><?= e(st($meeting['kind'])) ?></td>
                <td class="cell-sub nowrap"><?= e($day($meeting['held_on'])) ?></td>
                <td class="cell-sub"><?= (int) $meeting['resolution_count'] ?></td>
                <td><span class="tag <?= $meeting['status'] === 'Tenue' ? 'tag-success' : '' ?>"><?= e(st($meeting['status'])) ?></span></td>
                <td>
                  <a class="btn btn-outline btn-sm" href="/juridique/assemblees/<?= (int) $meeting['id'] ?>"><?= e(t('common.open')) ?></a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($meetingList === []): ?>
              <tr><td colspan="6" class="cell-sub"><?= e(t('jur.noMeeting')) ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mt-l">
      <h2><?= e(t('jur.callMeeting')) ?></h2>
      <form method="POST" action="/juridique/assemblees" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('common.type')) ?></span>
          <select name="kind">
            <?php foreach ($meetingKinds as $kind): ?>
              <option value="<?= e($kind) ?>"><?= e(st($kind)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('common.date')) ?></span><input type="date" name="held_on" value="<?= e($today) ?>" required /></label>
        <label><span><?= e(t('evt.location')) ?></span><input type="text" name="location" maxlength="200" /></label>
        <label class="span-2"><span><?= e(t('jur.quorumRequired')) ?></span>
          <input type="text" name="quorum_required" inputmode="decimal" value="0" />
        </label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('jur.call')) ?></button></div>
      </form>
      <p class="hint"><?= e(t('jur.quorumHint')) ?></p>
    </div>
  </section>

  <!-- ---------- Délégations ---------- -->
  <section class="tab-panel" id="delegations">
    <div class="card">
      <h2><?= e(t('jur.delegationCount', ['count' => count($delegationList)])) ?></h2>
      <p class="muted"><?= e(t('jur.delegationHint')) ?></p>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('jur.delegate')) ?></th>
              <th><?= e(t('jur.scope')) ?></th>
              <th><?= e(t('jur.limit')) ?></th>
              <th><?= e(t('jur.period')) ?></th>
              <th><?= e(t('common.status')) ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($delegationList as $delegation): ?>
              <tr>
                <td class="cell-strong">
                  <?= e($fullName($delegation)) ?>
                  <?php if (!empty($delegation['granted_first_name'])): ?>
                    <br /><span class="cell-sub">
                      <?= e(t('jur.grantedBy', [
                          'name' => trim($delegation['granted_first_name'] . ' ' . $delegation['granted_last_name']),
                      ])) ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td><?= e($delegation['scope']) ?></td>
                <td class="cell-sub nowrap">
                  <?= e($delegation['amount_limit'] === null ? t('jur.noLimit') : $money((float) $delegation['amount_limit'])) ?>
                </td>
                <td class="cell-sub nowrap"><?= e($day($delegation['starts_on'])) ?> → <?= e($day($delegation['ends_on'])) ?></td>
                <td><span class="tag <?= $delegation['status'] === 'En vigueur' ? 'tag-success' : '' ?>"><?= e(st($delegation['status'])) ?></span></td>
                <td class="actions">
                  <form method="POST" action="/juridique/delegations/<?= (int) $delegation['id'] ?>/statut" class="inline-form">
                    <?= $csrf ?>
                    <select name="status" class="inline-select">
                      <?php foreach ($delegationStatuses as $status): ?>
                        <option value="<?= e($status) ?>"<?= $delegation['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($delegationList === []): ?>
              <tr><td colspan="6" class="cell-sub"><?= e(t('jur.noDelegation')) ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mt-l">
      <h2><?= e(t('jur.addDelegation')) ?></h2>
      <form method="POST" action="/juridique/delegations" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('jur.delegate')) ?></span>
          <select name="holder_id" required>
            <option value="" disabled selected><?= e(t('common.choose')) ?></option>
            <?php foreach ($employees as $person): ?>
              <option value="<?= (int) $person['id'] ?>"><?= e($fullName($person)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('jur.scope')) ?></span><input type="text" name="scope" required maxlength="300" /></label>
        <label><span><?= e(t('jur.limit')) ?></span><input type="text" name="amount_limit" inputmode="decimal" /></label>
        <label><span><?= e(t('jur.startedOn')) ?></span><input type="date" name="starts_on" value="<?= e($today) ?>" required /></label>
        <label><span><?= e(t('jur.endsOn')) ?></span><input type="date" name="ends_on" /></label>
        <label class="span-2"><span><?= e(t('common.notes')) ?></span><textarea name="notes" rows="2" maxlength="1000"></textarea></label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
      </form>
    </div>
  </section>
<?php endif; ?>

<!-- ---------- Conflits d'intérêts ---------- -->
<section class="tab-panel<?= $isAdmin ? '' : ' is-active' ?>" id="interets">
  <div class="card">
    <h2><?= e($isAdmin ? t('jur.interestRegister', ['count' => count($declarationList)]) : t('jur.myInterests')) ?></h2>
    <p class="hint"><?= e(t('jur.interestHint')) ?></p>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <?php if ($isAdmin): ?><th><?= e(t('common.member')) ?></th><?php endif; ?>
            <th><?= e(t('common.type')) ?></th>
            <th><?= e(t('jur.entity')) ?></th>
            <th><?= e(t('jur.declaredOn')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <?php if ($isAdmin): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($declarationList as $declaration): ?>
            <tr>
              <?php if ($isAdmin): ?><td class="cell-strong"><?= e($fullName($declaration)) ?></td><?php endif; ?>
              <td class="cell-sub"><?= e(st($declaration['kind'])) ?></td>
              <td>
                <?= e($declaration['entity']) ?>
                <?php if ($declaration['description'] !== ''): ?>
                  <br /><span class="cell-sub"><?= e($declaration['description']) ?></span>
                <?php endif; ?>
                <?php if ($declaration['measure'] !== ''): ?>
                  <br /><span class="cell-sub"><?= e(t('jur.measure')) ?> : <?= e($declaration['measure']) ?></span>
                <?php endif; ?>
              </td>
              <td class="cell-sub nowrap"><?= e($day($declaration['declared_on'])) ?></td>
              <td><span class="tag <?= $declaration['status'] === 'Déclaré' ? 'tag-warning' : '' ?>"><?= e(st($declaration['status'])) ?></span></td>
              <?php if ($isAdmin): ?>
                <td>
                  <form method="POST" action="/juridique/interets/<?= (int) $declaration['id'] ?>/examen" class="form-grid">
                    <?= $csrf ?>
                    <label><span><?= e(t('common.status')) ?></span>
                      <select name="status">
                        <?php foreach ($interestStatuses as $status): ?>
                          <option value="<?= e($status) ?>"<?= $declaration['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label><span><?= e(t('jur.measure')) ?></span>
                      <input type="text" name="measure" value="<?= e($declaration['measure']) ?>" maxlength="1000" />
                    </label>
                    <div><button type="submit" class="btn btn-primary btn-block btn-sm"><?= e(t('common.save')) ?></button></div>
                  </form>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <?php if ($declarationList === []): ?>
            <tr><td colspan="<?= $isAdmin ? 6 : 4 ?>" class="cell-sub"><?= e(t('jur.noDeclaration')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('jur.declareInterest')) ?></h2>
    <form method="POST" action="/juridique/interets" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.type')) ?></span>
        <select name="kind">
          <?php foreach ($interestKinds as $kind): ?>
            <option value="<?= e($kind) ?>"><?= e(st($kind)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('jur.entity')) ?></span><input type="text" name="entity" required maxlength="200" /></label>
      <label><span><?= e(t('jur.relatedPartner')) ?></span>
        <select name="partner_id">
          <option value="">—</option>
          <?php foreach ($partners as $partner): ?>
            <option value="<?= (int) $partner['id'] ?>"><?= e($partner['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('jur.declaredOn')) ?></span><input type="date" name="declared_on" value="<?= e($today) ?>" required /></label>
      <label class="span-2"><span><?= e(t('common.description')) ?></span><textarea name="description" rows="3" maxlength="2000"></textarea></label>
      <label class="span-2"><span><?= e(t('jur.endsOn')) ?></span><input type="date" name="ends_on" /></label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('jur.declare')) ?></button></div>
    </form>
  </div>
</section>

<!-- ---------- Cadeaux ---------- -->
<section class="tab-panel" id="cadeaux">
  <div class="card">
    <h2><?= e($isAdmin ? t('jur.giftRegister', ['count' => count($giftList)]) : t('jur.myGifts')) ?></h2>
    <p class="hint"><?= e(t('jur.giftHint', ['threshold' => $money((float) $giftThreshold)])) ?></p>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <?php if ($isAdmin): ?><th><?= e(t('common.member')) ?></th><?php endif; ?>
            <th><?= e(t('jur.direction')) ?></th>
            <th><?= e(t('common.type')) ?></th>
            <th><?= e(t('jur.thirdParty')) ?></th>
            <th><?= e(t('common.date')) ?></th>
            <th><?= e(t('jur.value')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <?php if ($isAdmin): ?><th></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($giftList as $gift): ?>
            <?php $over = (float) $gift['value'] > $giftThreshold; ?>
            <tr>
              <?php if ($isAdmin): ?><td class="cell-strong"><?= e($fullName($gift)) ?></td><?php endif; ?>
              <td class="cell-sub"><?= e(st($gift['direction'])) ?></td>
              <td class="cell-sub"><?= e(st($gift['kind'])) ?></td>
              <td>
                <?= e($gift['partner_name'] ?? ($gift['third_party'] !== '' ? $gift['third_party'] : '—')) ?>
                <?php if ($gift['description'] !== ''): ?><br /><span class="cell-sub"><?= e($gift['description']) ?></span><?php endif; ?>
              </td>
              <td class="cell-sub nowrap"><?= e($day($gift['occurred_on'])) ?></td>
              <td class="<?= $over ? 'text-danger' : 'cell-sub' ?> nowrap"><?= e($money((float) $gift['value'])) ?></td>
              <td><span class="tag <?= $gift['status'] === 'Déclaré' && $over ? 'tag-warning' : '' ?>"><?= e(st($gift['status'])) ?></span></td>
              <?php if ($isAdmin): ?>
                <td>
                  <form method="POST" action="/juridique/cadeaux/<?= (int) $gift['id'] ?>/examen" class="inline-form">
                    <?= $csrf ?>
                    <select name="status" class="inline-select">
                      <?php foreach ($giftStatuses as $status): ?>
                        <option value="<?= e($status) ?>"<?= $gift['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
                  </form>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <?php if ($giftList === []): ?>
            <tr><td colspan="<?= $isAdmin ? 8 : 6 ?>" class="cell-sub"><?= e(t('jur.noGift')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('jur.declareGift')) ?></h2>
    <form method="POST" action="/juridique/cadeaux" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('jur.direction')) ?></span>
        <select name="direction">
          <?php foreach ($giftDirections as $direction): ?>
            <option value="<?= e($direction) ?>"><?= e(st($direction)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.type')) ?></span>
        <select name="kind">
          <?php foreach ($giftKinds as $kind): ?>
            <option value="<?= e($kind) ?>"><?= e(st($kind)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('jur.relatedPartner')) ?></span>
        <select name="partner_id">
          <option value="">—</option>
          <?php foreach ($partners as $partner): ?>
            <option value="<?= (int) $partner['id'] ?>"><?= e($partner['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('jur.thirdParty')) ?></span><input type="text" name="third_party" maxlength="200" /></label>
      <label><span><?= e(t('common.date')) ?></span><input type="date" name="occurred_on" value="<?= e($today) ?>" required /></label>
      <label><span><?= e(t('jur.value')) ?></span><input type="text" name="value" inputmode="decimal" value="0" /></label>
      <label class="span-2"><span><?= e(t('common.description')) ?></span><textarea name="description" rows="2" maxlength="1000"></textarea></label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('jur.declare')) ?></button></div>
    </form>
  </div>
</section>
