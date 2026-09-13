<?php
$csrf = \App\Core\Csrf::field();
$fullName = static fn (array $p): string => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
$query = static function (array $extra) use ($filters): string {
    $base = array_filter([
        'action' => $filters['action'], 'auteur' => $filters['actorId'],
        'objet' => $filters['entity'], 'du' => $filters['from'], 'au' => $filters['to'],
    ]);
    return http_build_query(array_merge($base, $extra));
};
?>
<section class="stats-grid">
  <?php foreach ([
      [(string) $counters['entries'], t('sec.logEntries')],
      [(string) $counters['totpEnabled'] . ' / ' . $counters['accounts'], t('sec.twoFactorAccounts')],
      [(string) count($sessions), t('sec.tabSessions')],
      [(string) count($risky['locked']), t('sec.lockedAccounts')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<!-- ----------------------------------------------------------- Journal -->
<section class="tab-panel is-active" id="journal">
  <div class="card">
    <h2><?= e(t('sec.tabAudit')) ?></h2>

    <form method="GET" action="/securite" class="form-grid">
      <label><span><?= e(t('common.action')) ?></span>
        <select name="action">
          <option value=""></option>
          <?php foreach ($knownActions as $action): ?>
            <option value="<?= e($action) ?>"<?= $filters['action'] === $action ? ' selected' : '' ?>><?= e($action) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.subject')) ?></span><input type="text" name="objet" value="<?= e((string) $filters['entity']) ?>" /></label>
      <label><span><?= e(t('leave.from')) ?></span><input type="date" name="du" value="<?= e((string) $filters['from']) ?>" /></label>
      <label><span><?= e(t('leave.to')) ?></span><input type="date" name="au" value="<?= e((string) $filters['to']) ?>" /></label>
      <button type="submit" class="btn btn-sm"><?= e(t('common.filter')) ?></button>
      <a class="btn btn-sm" href="/securite/journal.csv?<?= e($query([])) ?>"><?= e(t('sec.exportCsv')) ?></a>
    </form>

    <?php if ($journal['rows'] === []): ?>
      <div class="empty-state"><?= e(t('sec.noEntry')) ?></div>
    <?php else: ?>
      <table class="table mt-l">
        <thead>
          <tr>
            <th><?= e(t('common.date')) ?></th><th><?= e(t('sec.author')) ?></th>
            <th><?= e(t('common.action')) ?></th><th><?= e(t('common.subject')) ?></th>
            <th><?= e(t('common.detail')) ?></th><th>IP</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($journal['rows'] as $row): ?>
            <tr>
              <td class="nowrap"><?= e(\App\Core\Dates::moment((string) $row['occurred_at'])) ?></td>
              <td><?= e((string) $row['actor_label']) ?></td>
              <td><span class="tag"><?= e($row['action']) ?></span></td>
              <td><?= e((string) $row['entity']) ?><?= $row['entity_id'] ? ' #' . (int) $row['entity_id'] : '' ?></td>
              <td class="cell-sub"><?= e(mb_substr((string) $row['detail'], 0, 160)) ?></td>
              <td class="cell-sub"><?= e((string) $row['ip']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <p class="muted">
        <?= e(t('sec.entriesPage', ['page' => $journal['page'], 'pages' => $journal['pages'], 'total' => $journal['total']])) ?>
      </p>
      <div class="row-actions">
        <?php if ($journal['page'] > 1): ?>
          <a class="btn btn-sm" href="/securite?<?= e($query(['page' => $journal['page'] - 1])) ?>#journal"><?= e(t('sec.previous')) ?></a>
        <?php endif; ?>
        <?php if ($journal['page'] < $journal['pages']): ?>
          <a class="btn btn-sm" href="/securite?<?= e($query(['page' => $journal['page'] + 1])) ?>#journal"><?= e(t('sec.next')) ?></a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="/securite/journal/purger" class="mt-l" data-confirm="<?= e(t('sec.confirmPurge')) ?>">
      <?= $csrf ?>
      <button type="submit" class="btn btn-sm btn-danger">
        <?= e(t('sec.purgeBeyond', ['days' => (int) ($policy['audit_retention_days'] ?? 365)])) ?>
      </button>
    </form>
  </div>
</section>

<!-- -------------------------------------------------------- Scellement -->
<section class="tab-panel" id="scellement">
  <div class="card">
    <h2><?= e(t('sec.sealTitle')) ?>
      <span class="status <?= $seal['ok'] ? 'status-on' : 'status-off' ?>">
        <?= e($seal['ok'] ? t('sec.sealOk') : t('sec.sealKo')) ?>
      </span>
    </h2>
    <p class="muted"><?= e(t('sec.sealHow')) ?></p>

    <?php if ($seal['ok']): ?>
      <p class="status status-on"><?= e(t('sec.sealIntact', ['count' => $seal['sealed']])) ?></p>
    <?php else: ?>
      <p class="status status-off"><?= e(t('sec.sealBroken')) ?></p>
      <p>
        <?php $row = $seal['broken']['row']; ?>
        <?= e($seal['broken']['reason'] === 'contenu'
            ? t('sec.sealAlteredAt', [
                'id' => (int) $row['id'], 'action' => $row['action'], 'date' => $row['occurred_at'],
              ])
            : t('sec.sealMissingAt', ['id' => (int) $row['id'], 'date' => $row['occurred_at']])) ?>
      </p>
    <?php endif; ?>

    <dl class="detail-list">
      <div><dt><?= e(t('sec.sealChecked')) ?></dt><dd><?= (int) $seal['checked'] ?></dd></div>
      <div><dt><?= e(t('sec.sealSealed')) ?></dt><dd><?= (int) $seal['sealed'] ?></dd></div>
      <div><dt><?= e(t('sec.sealLegacy')) ?></dt><dd><?= (int) $seal['unsealed'] ?></dd></div>
    </dl>
    <p class="muted"><?= e(t('sec.sealLimit')) ?></p>
  </div>
</section>

<!-- ---------------------------------------------------------- Comptes -->
<section class="tab-panel" id="comptes">
  <div class="card">
    <h2><?= e(t('sec.lockedAccounts')) ?></h2>
    <?php if ($risky['locked'] === []): ?>
      <div class="empty-state"><?= e(t('sec.none')) ?></div>
    <?php else: ?>
      <table class="table">
        <tbody>
          <?php foreach ($risky['locked'] as $person): ?>
            <tr>
              <td><?= e($fullName($person)) ?><br /><span class="cell-sub"><?= e($person['email']) ?></span></td>
              <td><?= e(t('sec.until')) ?> <?= e(substr((string) $person['locked_until'], 0, 19)) ?></td>
              <td>
                <form method="POST" action="/securite/comptes/<?= (int) $person['id'] ?>/deverrouiller">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm"><?= e(t('sec.unlock')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('sec.temporaryPassword')) ?></h2>
    <p class="muted"><?= e(t('sec.temporaryPasswordNote')) ?></p>
    <?php if ($risky['temporary'] === []): ?>
      <div class="empty-state"><?= e(t('sec.none')) ?></div>
    <?php else: ?>
      <ul class="org-list">
        <?php foreach ($risky['temporary'] as $person): ?>
          <li class="org-item"><?= e($fullName($person)) ?> — <span class="cell-sub"><?= e($person['email']) ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('sec.adminsWithout2fa')) ?></h2>
    <?php if ($risky['withoutTotp'] === []): ?>
      <p class="status status-on"><?= e(t('sec.allAdminsProtected')) ?></p>
    <?php else: ?>
      <ul class="org-list">
        <?php foreach ($risky['withoutTotp'] as $person): ?>
          <li class="org-item">
            <?= e($fullName($person)) ?> — <span class="cell-sub"><?= e($person['email']) ?></span>
            <form method="POST" action="/securite/2fa/<?= (int) $person['id'] ?>/reinitialiser"
                  class="inline-form" data-confirm="<?= e(t('sec.confirmReset2fa')) ?>">
              <?= $csrf ?>
              <button type="submit" class="btn btn-sm"><?= e(t('sec.reset2fa')) ?></button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('sec.dormant')) ?></h2>
    <p class="muted"><?= e(t('sec.dormantNote')) ?></p>
    <?php if ($risky['dormant'] === []): ?>
      <div class="empty-state"><?= e(t('sec.none')) ?></div>
    <?php else: ?>
      <ul class="org-list">
        <?php foreach ($risky['dormant'] as $person): ?>
          <li class="org-item">
            <?= e($fullName($person)) ?>
            <span class="cell-sub">
              <?= e(t('sec.lastLoginColon')) ?>
              <?= e($person['last_login_at'] === null ? t('sec.none') : substr((string) $person['last_login_at'], 0, 10)) ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>

<!-- -------------------------------------------------- Administrateurs -->
<section class="tab-panel" id="administrateurs">
  <div class="card">
    <h2><?= e(t('sec.appointAdmin')) ?></h2>
    <p class="muted"><?= e(t('sec.singleAdminNote')) ?></p>
    <form method="POST" action="/securite/administrateurs" class="inline-form">
      <?= $csrf ?>
      <select name="user_id" required>
        <option value=""><?= e(t('common.choose')) ?></option>
        <?php foreach ($promotable as $person): ?>
          <option value="<?= (int) $person['id'] ?>"><?= e($fullName($person)) ?> — <?= e($person['email']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm btn-primary"><?= e(t('sec.appoint')) ?></button>
    </form>

    <table class="table mt-l">
      <thead>
        <tr>
          <th><?= e(t('common.account')) ?></th><th><?= e(t('sec.without2fa')) ?></th>
          <th><?= e(t('sec.lastLogin')) ?></th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($admins as $admin): ?>
          <tr>
            <td><?= e($fullName($admin)) ?><br /><span class="cell-sub"><?= e($admin['email']) ?></span></td>
            <td>
              <span class="status <?= (int) $admin['totp_enabled'] === 1 ? 'status-on' : 'status-off' ?>">
                <?= e((int) $admin['totp_enabled'] === 1 ? t('common.active') : t('sec.without2fa')) ?>
              </span>
            </td>
            <td class="cell-sub"><?= e(substr((string) $admin['last_login_at'], 0, 19)) ?></td>
            <td>
              <form method="POST" action="/securite/administrateurs/<?= (int) $admin['id'] ?>/retirer"
                    data-confirm="<?= e(t('sec.confirmDemote')) ?>">
                <?= $csrf ?>
                <button type="submit" class="btn btn-sm btn-danger"><?= e(t('sec.demote')) ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- ---------------------------------------------------------- Sessions -->
<section class="tab-panel" id="sessions">
  <div class="card">
    <h2><?= e(t('sec.tabSessions')) ?></h2>
    <p class="muted"><?= e(t('sec.sessionNote')) ?></p>
    <?php if ($sessions === []): ?>
      <div class="empty-state"><?= e(t('sec.noSession')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr><th><?= e(t('common.account')) ?></th><th><?= e(t('sec.role')) ?></th><th><?= e(t('common.end')) ?></th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($sessions as $session): ?>
            <tr>
              <td><?= e($fullName($session)) ?><br /><span class="cell-sub"><?= e((string) $session['email']) ?></span></td>
              <td><?= e((string) $session['role']) ?></td>
              <td class="cell-sub"><?= e(gmdate('Y-m-d H:i', (int) $session['expires_at'])) ?></td>
              <td>
                <form method="POST" action="/securite/sessions/<?= (int) $session['user_id'] ?>/fermer"
                      data-confirm="<?= e(t('sec.confirmCloseSessions')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.close')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- --------------------------------------------------------- Politique -->
<section class="tab-panel" id="politique">
  <div class="card">
    <h2><?= e(t('sec.policyTitle')) ?></h2>
    <form method="POST" action="/securite/politique" class="form-grid">
      <?= $csrf ?>
      <label class="span-2">
        <input type="checkbox" name="require_2fa_admin" value="1"
               <?= ($policy['require_2fa_admin'] ?? '') === '1' ? 'checked' : '' ?> />
        <span><?= e(t('sec.require2faAdmins')) ?></span>
      </label>
      <label class="span-2">
        <input type="checkbox" name="require_2fa_all" value="1"
               <?= ($policy['require_2fa_all'] ?? '') === '1' ? 'checked' : '' ?> />
        <span><?= e(t('sec.require2faAll')) ?></span>
      </label>
      <label><span><?= e(t('sec.auditRetention')) ?></span>
        <input type="number" name="audit_retention_days" min="30" max="3650"
               value="<?= (int) ($policy['audit_retention_days'] ?? 365) ?>" required />
      </label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
    </form>
    <p class="muted"><?= e(t('sec.sessionEnvNote')) ?></p>
  </div>
</section>
