<?php
$csrf = \App\Core\Csrf::field();
$when = static function (?string $value): string {
    return $value === null || $value === '' ? '—' : \App\Core\Dates::moment($value);
};
$levelLabel = static fn (string $key): string => t("log.level.$key");
$channelLabel = static fn (string $key): string => t("log.channel.$key");
$query = static function (array $extra) use ($filters): string {
    $base = array_filter([
        'niveau' => $filters['maxLevel'] === '' ? '' : (string) $filters['maxLevel'],
        'canal' => $filters['channel'], 'evenement' => $filters['event'],
        'recherche' => $filters['search'], 'du' => $filters['from'], 'au' => $filters['to'],
    ], static fn ($v): bool => $v !== '' && $v !== null);
    return http_build_query(array_merge($base, $extra));
};
?>
<section class="stats-grid">
  <?php foreach ([
      [(string) $stats['total'], t('log.total')],
      [(string) $stats['window'], t('log.window', ['days' => $stats['days']])],
      [(string) $stats['serious'], t('log.serious')],
      [$summary['enabled'] . ' / ' . $summary['targets'], t('log.targetsOn')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<!-- ----------------------------------------------------------- Le journal -->
<section class="tab-panel is-active" id="journal">
  <div class="card">
    <h2><?= e(t('log.tabJournal')) ?></h2>

    <form method="GET" action="/journaux" class="form-grid">
      <label><span><?= e(t('log.minLevel')) ?></span>
        <select name="niveau">
          <option value=""><?= e(t('log.allLevels')) ?></option>
          <?php foreach ($levels as $band): ?>
            <option value="<?= (int) $band['value'] ?>"<?= (string) $filters['maxLevel'] === (string) $band['value'] ? ' selected' : '' ?>><?= e($levelLabel($band['key'])) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('log.channel')) ?></span>
        <select name="canal">
          <option value=""><?= e(t('log.allChannels')) ?></option>
          <?php foreach ($channels as $channel): ?>
            <option value="<?= e($channel) ?>"<?= $filters['channel'] === $channel ? ' selected' : '' ?>><?= e($channelLabel($channel)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('log.event')) ?></span>
        <input type="text" name="evenement" list="evenements-connus" value="<?= e($filters['event']) ?>" placeholder="connexion." />
        <datalist id="evenements-connus">
          <?php foreach ($knownEvents as $event): ?><option value="<?= e($event) ?>"></option><?php endforeach; ?>
        </datalist>
      </label>
      <label><span><?= e(t('log.search')) ?></span>
        <input type="search" name="recherche" value="<?= e($filters['search']) ?>" placeholder="<?= e(t('log.searchHint')) ?>" />
      </label>
      <label><span><?= e(t('leave.from')) ?></span><input type="date" name="du" value="<?= e($filters['from']) ?>" /></label>
      <label><span><?= e(t('leave.to')) ?></span><input type="date" name="au" value="<?= e($filters['to']) ?>" /></label>
      <div class="span-2 row-actions">
        <button type="submit" class="btn btn-primary btn-sm"><?= e(t('common.filter')) ?></button>
        <a class="btn btn-sm" href="/journaux"><?= e(t('common.viewAll')) ?></a>
        <a class="btn btn-sm" href="/journaux/journal.csv?<?= e($query([])) ?>"><?= e(t('sec.exportCsv')) ?></a>
      </div>
    </form>
  </div>

  <?php if ($stats['byLevel'] !== []): ?>
    <div class="card">
      <h2><?= e(t('log.spread', ['days' => $stats['days']])) ?></h2>
      <div class="chips">
        <?php foreach ($stats['byLevel'] as $band): ?>
          <a class="chip chip-<?= e($band['key']) ?>" href="/journaux?niveau=<?= (int) $band['level'] ?>">
            <?= e($levelLabel($band['key'])) ?> · <?= (int) $band['n'] ?>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="chips">
        <?php foreach ($stats['byChannel'] as $band): ?>
          <a class="chip" href="/journaux?canal=<?= e($band['channel']) ?>"><?= e($channelLabel($band['channel'])) ?> · <?= (int) $band['n'] ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2><?= e(t('log.entriesPage', ['count' => $journal['total'], 'page' => $journal['page'], 'pages' => $journal['pages']])) ?></h2>

    <?php if ($journal['rows'] === []): ?>
      <div class="empty-state"><?= e(t('log.noEntry')) ?></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('common.date')) ?></th><th><?= e(t('log.level')) ?></th>
              <th><?= e(t('log.channel')) ?></th><th><?= e(t('log.event')) ?></th>
              <th><?= e(t('log.message')) ?></th><th><?= e(t('sec.author')) ?></th><th>IP</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($journal['rows'] as $row): ?>
              <tr<?= $opened !== null && (int) $opened['id'] === (int) $row['id'] ? ' class="is-open"' : '' ?>>
                <td class="cell-sub nowrap"><?= e($when($row['occurred_at'])) ?></td>
                <td><span class="tag tag-<?= e($row['levelKey']) ?>"><?= e($levelLabel($row['levelKey'])) ?></span></td>
                <td class="cell-sub"><?= e($channelLabel($row['channel'])) ?></td>
                <td><code class="audit-action"><?= e($row['event'] !== '' ? $row['event'] : '—') ?></code></td>
                <td><a href="/journaux?<?= e($query(['ligne' => $row['id']])) ?>#ligne"><?= e($row['message'] !== '' ? $row['message'] : '—') ?></a></td>
                <td class="cell-sub"><?= e($row['actor_label']) ?></td>
                <td class="cell-sub"><?= e($row['ip'] !== '' ? $row['ip'] : '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($journal['pages'] > 1): ?>
        <div class="row-actions">
          <?php if ($journal['page'] > 1): ?>
            <a class="btn btn-sm" href="/journaux?<?= e($query(['page' => $journal['page'] - 1])) ?>"><?= e(t('sec.previous')) ?></a>
          <?php endif; ?>
          <?php if ($journal['page'] < $journal['pages']): ?>
            <a class="btn btn-sm" href="/journaux?<?= e($query(['page' => $journal['page'] + 1])) ?>"><?= e(t('sec.next')) ?></a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($opened !== null): ?>
    <div class="card" id="ligne">
      <h2><?= e($opened['event'] !== '' ? $opened['event'] : t('log.entry')) ?>
        <span class="tag tag-<?= e($opened['levelKey']) ?>"><?= e($levelLabel($opened['levelKey'])) ?></span>
      </h2>
      <dl class="detail-list">
        <div><dt><?= e(t('common.date')) ?></dt><dd><?= e($when($opened['occurred_at'])) ?></dd></div>
        <div><dt><?= e(t('log.channel')) ?></dt><dd><?= e($channelLabel($opened['channel'])) ?></dd></div>
        <div><dt><?= e(t('log.message')) ?></dt><dd><?= e($opened['message'] !== '' ? $opened['message'] : '—') ?></dd></div>
        <div><dt><?= e(t('sec.author')) ?></dt><dd><?= e($opened['actor_label']) ?></dd></div>
        <div><dt>IP</dt><dd><?= e($opened['ip'] !== '' ? $opened['ip'] : '—') ?></dd></div>
        <div><dt><?= e(t('log.entryNumber')) ?></dt><dd>#<?= (int) $opened['id'] ?></dd></div>
      </dl>
      <?php if ($opened['context'] !== ''): ?>
        <pre class="code-block"><?= e((string) json_encode($opened['parsedContext'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<!-- ---------------------------------------------------- Les cibles d'export -->
<section class="tab-panel" id="cibles">
  <div class="card">
    <h2><?= e(t('log.exportTitle')) ?></h2>
    <p class="hint"><?= e(t('log.exportLede')) ?></p>
    <p class="hint"><?= e(t('log.wazuhHint')) ?></p>
    <?php if ($summary['pending'] > 0): ?>
      <p class="hint"><?= e(t('log.pendingLines', ['count' => $summary['pending']])) ?></p>
    <?php endif; ?>
  </div>

  <?php foreach ($targets as $target): ?>
    <div class="card">
      <h2><?= e($target['label']) ?>
        <span class="tag <?= (int) $target['enabled'] === 1 ? 'tag-on' : 'tag-off' ?>">
          <?= e((int) $target['enabled'] === 1 ? t('log.on') : t('log.off')) ?>
        </span>
      </h2>
      <p class="card-sub">
        <?= e(t("log.kind.{$target['kind']}")) ?> ·
        <?= e($target['transport'] === 'fichier' ? $target['path'] : $target['host'] . ':' . $target['port']) ?> ·
        <?= e(t("log.transport.{$target['transport']}")) ?> · <?= e(t("log.format.{$target['format']}")) ?>
      </p>

      <dl class="detail-list">
        <div><dt><?= e(t('log.status')) ?></dt><dd><?= e($target['status'] !== '' ? st($target['status']) : '—') ?></dd></div>
        <div><dt><?= e(t('log.sent')) ?></dt><dd><?= (int) $target['sent'] ?></dd></div>
        <div><dt><?= e(t('log.failures')) ?></dt><dd><?= (int) $target['failures'] ?></dd></div>
        <div><dt><?= e(t('log.cursor')) ?></dt><dd>#<?= (int) $target['cursor'] ?></dd></div>
        <div><dt><?= e(t('log.lastAt')) ?></dt><dd><?= e($when($target['last_at'])) ?></dd></div>
        <div><dt><?= e(t('log.minLevel')) ?></dt><dd><?= e($levelLabel($levels[(int) $target['min_level']]['key'])) ?></dd></div>
      </dl>

      <div class="row-actions">
        <form method="POST" action="/journaux/cibles/<?= (int) $target['id'] ?>/essai">
          <?= $csrf ?><button type="submit" class="btn btn-sm"><?= e(t('log.test')) ?></button>
        </form>
        <form method="POST" action="/journaux/cibles/<?= (int) $target['id'] ?>/envoyer">
          <?= $csrf ?><button type="submit" class="btn btn-sm"><?= e(t('log.sendNow')) ?></button>
        </form>
        <form method="POST" action="/journaux/cibles/<?= (int) $target['id'] ?>/etat">
          <?= $csrf ?>
          <input type="hidden" name="enabled" value="<?= (int) $target['enabled'] === 1 ? '0' : '1' ?>" />
          <button type="submit" class="btn btn-sm"><?= e((int) $target['enabled'] === 1 ? t('log.turnOff') : t('log.turnOn')) ?></button>
        </form>
        <form method="POST" action="/journaux/cibles/<?= (int) $target['id'] ?>/rattraper" data-confirm="<?= e(t('log.skipConfirm')) ?>">
          <?= $csrf ?><button type="submit" class="btn btn-sm"><?= e(t('log.skipBacklog')) ?></button>
        </form>
        <form method="POST" action="/journaux/cibles/<?= (int) $target['id'] ?>/supprimer" data-confirm="<?= e(t('log.deleteConfirm')) ?>">
          <?= $csrf ?><button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
        </form>
      </div>

      <details>
        <summary><?= e(t('log.editTarget')) ?></summary>
        <?= \App\Core\View::partial('log-target-form', [
            'target' => $target, 'action' => '/journaux/cibles/' . (int) $target['id'],
            'levels' => $levels, 'channels' => $channels,
            'transports' => $transports, 'formats' => $formats, 'kinds' => $kinds,
        ]) ?>
      </details>
    </div>
  <?php endforeach; ?>

  <?php if ($targets === []): ?>
    <div class="card"><div class="empty-state"><?= e(t('log.noTarget')) ?></div></div>
  <?php endif; ?>

  <div class="card">
    <h2><?= e(t('log.addTarget')) ?></h2>
    <?= \App\Core\View::partial('log-target-form', [
        'target' => null, 'action' => '/journaux/cibles',
        'levels' => $levels, 'channels' => $channels,
        'transports' => $transports, 'formats' => $formats, 'kinds' => $kinds,
    ]) ?>
  </div>
</section>

<!-- --------------------------------------------------------- Conservation -->
<section class="tab-panel" id="conservation">
  <div class="card">
    <h2><?= e(t('log.tabRetention')) ?></h2>
    <p class="hint"><?= e(t('log.retentionBody')) ?></p>
    <dl class="detail-list">
      <div><dt><?= e(t('log.level.debogage')) ?></dt><dd><?= e(t('log.days', ['count' => $retention['debug']])) ?></dd></div>
      <div><dt><?= e(t('log.level.information')) ?></dt><dd><?= e(t('log.days', ['count' => $retention['info']])) ?></dd></div>
      <div><dt><?= e(t('log.level.avertissement')) ?></dt><dd><?= e(t('log.days', ['count' => $retention['warning']])) ?></dd></div>
      <div><dt><?= e(t('log.level.erreur')) ?></dt><dd><?= e(t('log.days', ['count' => $retention['critical']])) ?></dd></div>
      <div><dt><?= e(t('log.oldest')) ?></dt><dd><?= e($when($stats['oldest'])) ?></dd></div>
    </dl>
    <p class="hint"><?= e(t('log.purgeHint')) ?></p>
    <div class="row-actions">
      <form method="POST" action="/journaux/purger">
        <?= $csrf ?><button type="submit" class="btn btn-sm"><?= e(t('log.purge')) ?></button>
      </form>
      <form method="POST" action="/journaux/vider" data-confirm="<?= e(t('log.clearConfirm')) ?>">
        <?= $csrf ?><button type="submit" class="btn btn-sm btn-danger"><?= e(t('log.clear')) ?></button>
      </form>
    </div>
  </div>
</section>
