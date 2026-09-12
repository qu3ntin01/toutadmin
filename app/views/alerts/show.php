<?php
$csrf = \App\Core\Csrf::field();
$moment = static fn (?string $value): string => ($value === null || $value === '')
    ? '—' : str_replace('T', ' ', substr((string) $value, 0, 16));
?>
<div class="card">
  <div class="card-head">
    <div>
      <h2><?= e($report['subject']) ?></h2>
      <p class="card-sub">
        <span class="tag <?= $report['status'] === 'Clôturée' ? '' : 'tag-warning' ?>"><?= e(st($report['status'])) ?></span>
        <span class="tag"><?= e(st($report['category'])) ?></span>
        <?= e((int) $report['anonymous'] === 1
            ? t('alr.anonymous')
            : trim(($report['first_name'] ?? '') . ' ' . ($report['last_name'] ?? ''))) ?>
        · <?= e(t('alr.filedOn', ['date' => $moment($report['submitted_at'])])) ?>
      </p>
    </div>
    <?php if ($report['acknowledged_at'] === null): ?>
      <form method="POST" action="/alertes/signalements/<?= (int) $report['id'] ?>/accuser">
        <?= $csrf ?>
        <button type="submit" class="btn btn-primary btn-sm"><?= e(t('alr.acknowledge')) ?></button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($report['body'] !== ''): ?><p class="ticket-body"><?= e($report['body']) ?></p><?php endif; ?>

  <ul class="org-list">
    <li class="org-item">
      <span><?= e(t('alr.acknowledgement')) ?></span>
      <span class="<?= $report['acknowledged_at'] === null && $age > $ackDays ? 'text-danger' : 'cell-strong' ?>">
        <?= e($report['acknowledged_at'] !== null
            ? $moment($report['acknowledged_at'])
            : t('alr.pendingDays', ['days' => (int) $age])) ?>
      </span>
    </li>
    <li class="org-item">
      <span><?= e(t('alr.outcomeDeadline', ['days' => $outcomeDays])) ?></span>
      <span class="<?= $age > $outcomeDays && $report['status'] !== 'Clôturée' ? 'text-danger' : 'cell-strong' ?>">
        <?= e(t('alr.ageDays', ['days' => (int) $age])) ?>
      </span>
    </li>
    <?php if ($report['closed_at'] !== null): ?>
      <li class="org-item"><span><?= e(t('alr.closedAt')) ?></span>
        <span class="cell-strong"><?= e($moment($report['closed_at'])) ?></span></li>
    <?php endif; ?>
  </ul>
</div>

<div class="card mt-l">
  <div class="card-head"><h2><?= e(t('alr.exchanges')) ?></h2></div>
  <p class="hint"><?= e(t('alr.exchangesHint')) ?></p>
  <ul class="meeting-list">
    <?php foreach ($messages as $message): ?>
      <li class="meeting-item">
        <div class="meeting-head">
          <span class="meeting-title">
            <?= e($message['author_kind'] === 'referent' ? t('alr.fromReferent') : t('alr.fromAuthor')) ?>
          </span>
          <span class="news-meta"><?= e($moment($message['created_at'])) ?></span>
        </div>
        <p class="ticket-body"><?= e($message['body']) ?></p>
      </li>
    <?php endforeach; ?>
    <?php if ($messages === []): ?><li class="cell-sub"><?= e(t('alr.noExchange')) ?></li><?php endif; ?>
  </ul>
  <form method="POST" action="/alertes/signalements/<?= (int) $report['id'] ?>/message" class="stack">
    <?= $csrf ?>
    <label>
      <span><?= e(t('alr.replyToAuthor')) ?></span>
      <textarea name="body" rows="4" maxlength="5000" required></textarea>
    </label>
    <div class="row-actions"><button type="submit" class="btn btn-primary btn-sm"><?= e(t('alr.send')) ?></button></div>
  </form>
</div>

<div class="card mt-l">
  <div class="card-head"><h2><?= e(t('alr.handling')) ?></h2></div>
  <form method="POST" action="/alertes/signalements/<?= (int) $report['id'] ?>/statut" class="form-grid">
    <?= $csrf ?>
    <label><span><?= e(t('common.status')) ?></span>
      <select name="status">
        <?php foreach ($statuses as $status): ?>
          <option value="<?= e($status) ?>"<?= $report['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
  </form>
  <form method="POST" action="/alertes/signalements/<?= (int) $report['id'] ?>/suites" class="stack">
    <?= $csrf ?>
    <label>
      <span><?= e(t('alr.outcome')) ?></span>
      <textarea name="outcome" rows="4" maxlength="4000"><?= e((string) $report['outcome']) ?></textarea>
    </label>
    <div class="row-actions"><button type="submit" class="btn btn-primary btn-sm"><?= e(t('common.save')) ?></button></div>
  </form>
</div>

<div class="card mt-l">
  <div class="card-head"><h2><?= e(t('alr.whoOpened')) ?></h2></div>
  <p class="hint"><?= e(t('alr.accessHint')) ?></p>
  <ul class="org-list">
    <?php foreach ($accessList as $entry): ?>
      <li class="org-item">
        <span class="cell-strong">
          <?= e($entry['first_name'] !== null ? $entry['first_name'] . ' ' . $entry['last_name'] : '—') ?>
        </span>
        <span class="cell-sub"><?= e($moment($entry['occurred_at'])) ?></span>
      </li>
    <?php endforeach; ?>
    <?php if ($accessList === []): ?><li class="cell-sub"><?= e(t('alr.noAccessYet')) ?></li><?php endif; ?>
  </ul>
</div>
