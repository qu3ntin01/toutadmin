<?php
$csrf = \App\Core\Csrf::field();
$moment = static fn (?string $value): string => ($value === null || $value === '')
    ? '—' : str_replace('T', ' ', substr((string) $value, 0, 16));
?>
<?php if ($created !== null): ?>
  <div class="card">
    <div class="card-head">
      <h2><?= e($created['kind'] === 'token' ? 'Votre jeton' : 'Secret de signature') ?> — <?= e($created['label']) ?></h2>
    </div>
    <p class="mono cred-box"><?= e($created['value']) ?></p>
    <p class="hint">
      <?= e($created['kind'] === 'token'
          ? "Il n'est conservé que sous forme d'empreinte : cette page est le seul endroit où il s'affiche en clair. Valable jusqu'au " . $created['expiresAt'] . '.'
          : "Il sert au destinataire à vérifier la signature de chaque envoi. Il est chiffré en base et ne sera plus affiché.") ?>
    </p>
  </div>
<?php endif; ?>

<!-- ---------- Jetons ---------- -->
<section class="tab-panel is-active" id="jetons">
  <div class="card">
    <div class="card-head"><h2><?= e(t('api.newToken')) ?></h2></div>
    <form method="POST" action="/integrations/jetons" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.title')) ?></span>
        <input type="text" name="label" required maxlength="120" placeholder="<?= e(t('api.tokenPlaceholder')) ?>" />
      </label>
      <label><span><?= e(t('api.lifetimeDays')) ?></span>
        <input type="number" name="days" min="1" max="3650" value="<?= (int) $defaultDays ?>" required />
      </label>
      <div class="span-2">
        <p class="section-label"><?= e(t('api.scopes')) ?></p>
        <?php foreach ($scopes as $scope): ?>
          <label class="check-row">
            <input type="checkbox" name="scopes[]" value="<?= e($scope['key']) ?>" />
            <span><strong><?= e($scope['label']) ?></strong> — <?= e($scope['hint']) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('api.createToken')) ?></button></div>
    </form>
    <p class="hint"><?= e(t('api.readOnlyNotice')) ?></p>
  </div>

  <div class="card mt-l">
    <div class="card-head"><h2><?= count($tokenList) ?> jeton(s)</h2></div>
    <?php if ($tokenList === []): ?>
      <p class="empty-state"><?= e(t('api.noToken')) ?></p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('api.token')) ?></th>
              <th><?= e(t('api.scopes')) ?></th>
              <th><?= e(t('api.expires')) ?></th>
              <th><?= e(t('api.lastCall')) ?></th>
              <th><?= e(t('api.calls')) ?></th>
              <th><?= e(t('common.state')) ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tokenList as $token): ?>
              <tr>
                <td>
                  <div class="cell-strong"><?= e($token['label']) ?></div>
                  <div class="cell-sub mono"><?= e($token['prefix']) ?>…</div>
                </td>
                <td class="cell-sub"><?= e(implode(', ', $token['scopeList'])) ?></td>
                <td class="cell-sub nowrap"><?= e($token['expires_at'] ?? '—') ?></td>
                <td class="cell-sub nowrap">
                  <?= e($moment($token['last_used_at'])) ?>
                  <?php if ($token['last_ip'] !== ''): ?><br /><span class="cell-sub"><?= e($token['last_ip']) ?></span><?php endif; ?>
                </td>
                <td><?= (int) $token['calls'] ?></td>
                <td>
                  <span class="tag <?= $token['revoked'] ? 'tag-danger' : ($token['expired'] ? 'tag-warning' : 'tag-success') ?>">
                    <?= e($token['revoked'] ? 'Révoqué' : ($token['expired'] ? 'Expiré' : 'Actif')) ?>
                  </span>
                </td>
                <td>
                  <div class="row-actions">
                    <?php if (!$token['revoked']): ?>
                      <form method="POST" action="/integrations/jetons/<?= (int) $token['id'] ?>/revoquer" class="inline-form"
                            data-confirm="<?= e(t('api.confirmRevoke')) ?>">
                        <?= $csrf ?>
                        <button type="submit" class="btn btn-outline btn-sm"><?= e(t('api.revoke')) ?></button>
                      </form>
                    <?php else: ?>
                      <form method="POST" action="/integrations/jetons/<?= (int) $token['id'] ?>/supprimer" class="inline-form"
                            data-confirm="<?= e(t('api.confirmDeleteToken')) ?>">
                        <?= $csrf ?>
                        <button type="submit" class="btn btn-link btn-sm"><?= e(t('common.delete')) ?></button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- ---------- Webhooks ---------- -->
<section class="tab-panel" id="webhooks">
  <div class="card">
    <div class="card-head"><h2><?= e(t('api.declareWebhook')) ?></h2></div>
    <form method="POST" action="/integrations/webhooks" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.title')) ?></span>
        <input type="text" name="label" required maxlength="120" placeholder="<?= e(t('api.webhookPlaceholder')) ?>" />
      </label>
      <label><span><?= e(t('api.urlHttps')) ?></span>
        <input type="url" name="url" required maxlength="500" placeholder="https://exemple.fr/hooks/toutadmin" />
      </label>
      <div class="span-2">
        <p class="section-label"><?= e(t('api.eventsWatched')) ?></p>
        <?php foreach ($events as $event): ?>
          <label class="check-row">
            <input type="checkbox" name="events[]" value="<?= e($event['key']) ?>" />
            <span><?= e($event['label']) ?> <span class="mono cell-sub"><?= e($event['key']) ?></span></span>
          </label>
        <?php endforeach; ?>
      </div>
      <label class="span-2 check-row">
        <input type="checkbox" name="allow_private" value="1" /><span><?= e(t('api.allowPrivate')) ?></span>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.declare')) ?></button></div>
    </form>
    <p class="hint"><?= t('api.signatureNote', ['header' => '<span class="mono">x-toutadmin-signature</span>']) ?></p>
    <p class="hint"><?= e(t('api.retryNote', ['attempts' => $maxAttempts, 'limit' => $failureLimit])) ?></p>
  </div>

  <div class="card mt-l">
    <div class="card-head"><h2><?= count($webhookList) ?> webhook(s)</h2></div>
    <?php if ($webhookList === []): ?>
      <p class="empty-state"><?= e(t('api.noWebhook')) ?></p>
    <?php else: ?>
      <ul class="meeting-list">
        <?php foreach ($webhookList as $hook): ?>
          <li class="meeting-item">
            <div class="meeting-head">
              <span class="meeting-title"><?= e($hook['label']) ?></span>
              <span class="tag <?= (int) $hook['active'] === 1 ? 'tag-success' : 'tag-danger' ?>">
                <?= e((int) $hook['active'] === 1 ? t('common.active') : t('api.suspended')) ?>
              </span>
            </div>
            <p class="cell-sub mono"><?= e($hook['url']) ?></p>
            <p class="cell-sub">
              <?= e(implode(', ', $hook['eventList'])) ?>
              <?php if ($hook['last_attempt_at'] !== null): ?>
                · <?= e(t('api.lastSend')) ?> <?= e($moment($hook['last_attempt_at'])) ?> : <?= e($hook['last_status']) ?>
              <?php endif; ?>
              <?php if ((int) $hook['failures'] > 0): ?>
                · <span class="text-danger"><?= e(t('api.consecutiveFailures', ['count' => (int) $hook['failures']])) ?></span>
              <?php endif; ?>
              <?php if ((int) $hook['allow_private'] === 1): ?> · <?= e(t('api.privateAllowed')) ?><?php endif; ?>
            </p>
            <div class="row-actions">
              <form method="POST" action="/integrations/webhooks/<?= (int) $hook['id'] ?>/tester" class="inline-form">
                <?= $csrf ?>
                <button type="submit" class="btn btn-outline btn-sm"><?= e(t('api.testSend')) ?></button>
              </form>
              <form method="POST" action="/integrations/webhooks/<?= (int) $hook['id'] ?>/etat" class="inline-form">
                <?= $csrf ?>
                <input type="hidden" name="active" value="<?= (int) $hook['active'] === 1 ? '0' : '1' ?>" />
                <button type="submit" class="btn btn-outline btn-sm">
                  <?= e((int) $hook['active'] === 1 ? t('api.suspend') : t('api.reactivate')) ?>
                </button>
              </form>
              <form method="POST" action="/integrations/webhooks/<?= (int) $hook['id'] ?>/supprimer" class="inline-form"
                    data-confirm="<?= e(t('api.confirmDeleteWebhook')) ?>">
                <?= $csrf ?>
                <button type="submit" class="btn btn-link btn-sm"><?= e(t('common.delete')) ?></button>
              </form>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>

<!-- ---------- Livraisons ---------- -->
<section class="tab-panel" id="livraisons">
  <div class="card">
    <div class="card-head">
      <h2><?= e(t('api.deliveryLog')) ?></h2>
      <span class="card-sub">
        <?= e(t('api.deliveryStats', ['waiting' => $stats['waiting'], 'abandoned' => $stats['abandoned']])) ?>
      </span>
    </div>
    <?php if ($deliveries === []): ?>
      <p class="empty-state"><?= e(t('api.noDelivery')) ?></p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('api.event')) ?></th>
              <th><?= e(t('api.webhook')) ?></th>
              <th><?= e(t('common.state')) ?></th>
              <th><?= e(t('api.attempts')) ?></th>
              <th><?= e(t('api.createdAt')) ?></th>
              <th><?= e(t('api.deliveredAt')) ?></th>
              <th><?= e(t('api.lastError')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($deliveries as $delivery): ?>
              <tr>
                <td class="cell-strong mono"><?= e($delivery['event']) ?></td>
                <td class="cell-sub"><?= e($delivery['label']) ?></td>
                <td>
                  <span class="tag <?= $delivery['status'] === 'Livré'
                      ? 'tag-success' : ($delivery['status'] === 'En attente' ? 'tag-warning' : 'tag-danger') ?>">
                    <?= e(st($delivery['status'])) ?>
                  </span>
                </td>
                <td><?= (int) $delivery['attempts'] ?></td>
                <td class="cell-sub nowrap"><?= e($moment($delivery['created_at'])) ?></td>
                <td class="cell-sub nowrap"><?= e($moment($delivery['delivered_at'])) ?></td>
                <td class="cell-sub"><?= e($delivery['last_error'] !== '' ? $delivery['last_error'] : '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="hint"><?= e(t('api.purgeNotice')) ?></p>
    <?php endif; ?>
  </div>
</section>

<!-- ---------- Documentation ---------- -->
<section class="tab-panel" id="documentation">
  <div class="card">
    <div class="card-head"><h2><?= e(t('api.queryApi')) ?></h2></div>
    <p class="cell-sub"><?= e(t('api.authHeaderNote')) ?></p>
    <p class="mono cred-box">curl -H "Authorization: Bearer sm_…" https://votre-instance/api/v1/collaborateurs</p>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('api.route')) ?></th>
            <th><?= e(t('api.scopeRequired')) ?></th>
            <th><?= e(t('common.content')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ([
              ['/api/v1', '—', t('api.rootDesc')],
              ['/api/v1/collaborateurs', 'annuaire', t('api.staffDesc')],
              ['/api/v1/services', 'annuaire', t('api.servicesDesc')],
              ['/api/v1/equipes', 'annuaire', t('api.teamsDesc')],
              ['/api/v1/absences', 'rh', t('api.absencesDesc')],
              ['/api/v1/tiers', 'gestion', t('erp.partnersSub')],
              ['/api/v1/factures', 'gestion', t('api.invoicesDesc')],
              ['/api/v1/abonnements', 'gestion', t('api.subscriptionsDesc')],
              ['/api/v1/projets', 'projets', t('api.projectsDesc')],
              ['/api/v1/indicateurs', 'pilotage', t('api.indicatorsDesc')],
          ] as [$route, $scope, $description]): ?>
            <tr>
              <td class="mono"><?= e($route) ?></td>
              <td class="cell-sub"><?= e($scope) ?></td>
              <td class="cell-sub"><?= e($description) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint">
      <?= t('api.paginationNote', [
          'limit' => '<span class="mono">?limite=</span>',
          'since' => '<span class="mono">?depuis=</span>',
      ]) ?>
    </p>
  </div>

  <div class="card mt-l">
    <div class="card-head"><h2><?= e(t('api.verifySignature')) ?></h2></div>
    <p class="mono cred-box"><?= e(t('api.signatureFormula')) ?></p>
    <p class="hint"><?= e(t('api.constantTimeNote')) ?></p>
  </div>
</section>
