<section class="stats-grid">
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= (int) $result['answered'] ?></span>
      <span class="stat-label"><?= e(t('srz.answeredOf', ['invited' => $result['invited']])) ?></span>
    </span>
  </div>
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= (int) $result['rate'] ?> %</span>
      <span class="stat-label"><?= e(t('cse.turnout')) ?></span>
    </span>
  </div>
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= count($result['questions']) ?></span>
      <span class="stat-label"><?= e(t('srz.questions')) ?></span>
    </span>
  </div>
</section>

<?php if ($result['withheld']): ?>
  <div class="card">
    <h2><?= e(t('srz.notShown')) ?></h2>
    <div class="empty-state">
      <?= e(t('srz.thresholdNote', ['count' => $result['answered'], 'threshold' => $result['threshold']])) ?>
    </div>
  </div>
<?php else: ?>
  <?php foreach ($result['questions'] as $question): ?>
    <div class="card mt-l">
      <div class="card-head">
        <h2><?= e($question['label']) ?></h2>
        <span class="muted"><?= e(t('srz.answerCount', ['count' => (int) $question['count']])) ?></span>
      </div>

      <?php if ($question['type'] === 'echelle'): ?>
        <p class="stat-value">
          <?= $question['average'] === null ? '—' : e((string) $question['average']) ?>
          <span class="cell-sub"><?= e(t('srz.outOfFive')) ?></span>
        </p>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th><?= e(t('common.note')) ?></th>
                <th><?= e(t('common.answers')) ?></th>
                <th><?= e(t('srz.share')) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($question['distribution'] as $line): ?>
                <tr>
                  <td class="cell-strong"><?= e((string) $line['value']) ?></td>
                  <td><?= (int) $line['count'] ?></td>
                  <td>
                    <span class="meter">
                      <span class="meter-fill"
                            data-ratio="<?= $question['count'] ? (int) round($line['count'] * 100 / $question['count']) : 0 ?>"></span>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php elseif ($question['type'] === 'texte'): ?>
        <?php if ($question['verbatims'] === []): ?>
          <div class="empty-state"><?= e(t('srz.noFreeAnswer')) ?></div>
        <?php else: ?>
          <ul class="meeting-list">
            <?php foreach ($question['verbatims'] as $verbatim): ?>
              <li class="meeting-item"><p class="cell-sub"><?= e($verbatim) ?></p></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th><?= e(t('srz.answer')) ?></th>
                <th><?= e(t('common.count')) ?></th>
                <th><?= e(t('srz.share')) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($question['distribution'] as $line): ?>
                <tr>
                  <td class="cell-strong"><?= e((string) $line['value']) ?></td>
                  <td><?= (int) $line['count'] ?></td>
                  <td>
                    <span class="meter">
                      <span class="meter-fill"
                            data-ratio="<?= $question['count'] ? (int) round($line['count'] * 100 / $question['count']) : 0 ?>"></span>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
