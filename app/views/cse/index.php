<?php
$csrf = \App\Core\Csrf::field();
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
$fullName = static fn (array $row): string => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
?>
<?php if ($isElected): ?>
  <div class="banner">
    <span><?= e(t('cse.youSit')) ?> <a href="/cse/gestion"><?= e(t('nav.cseManage')) ?></a></span>
  </div>
<?php endif; ?>

<!-- ---------- Avantages et réductions ---------- -->
<section class="card" id="avantages">
  <div class="card-head">
    <div>
      <h2><?= e(t('cse.benefits')) ?> <span class="muted">(<?= count($benefits) ?>)</span></h2>
      <p class="muted"><?= e(t('cse.benefitsSubtitle')) ?></p>
    </div>
  </div>

  <?php if ($benefits === []): ?>
    <div class="empty-state"><?= e(t('cse.noBenefits')) ?></div>
  <?php else: ?>
    <div class="benefit-grid">
      <?php foreach ($benefits as $benefit): ?>
        <article class="benefit-card">
          <div class="benefit-top">
            <?php if ($benefit['category'] !== ''): ?><span class="tag"><?= e($benefit['category']) ?></span><?php endif; ?>
          </div>
          <h3 class="benefit-title"><?= e($benefit['title']) ?></h3>
          <?php if ($benefit['partner'] !== ''): ?>
            <p class="cell-sub"><?= e(t('cse.partner')) ?> · <?= e($benefit['partner']) ?></p>
          <?php endif; ?>
          <?php if ($benefit['discount'] !== ''): ?>
            <p class="benefit-discount"><?= e($benefit['discount']) ?></p>
          <?php endif; ?>
          <?php if ($benefit['description'] !== ''): ?>
            <p class="benefit-text"><?= e($benefit['description']) ?></p>
          <?php endif; ?>

          <div class="benefit-foot">
            <?php if ($benefit['code'] !== ''): ?>
              <span class="code-chip"><span class="cell-sub"><?= e(t('cse.code')) ?></span><code><?= e($benefit['code']) ?></code></span>
            <?php endif; ?>
            <?php if ($benefit['url'] !== ''): ?>
              <a href="<?= e($benefit['url']) ?>" class="btn btn-outline btn-sm" target="_blank" rel="noopener noreferrer">
                <?= e(t('cse.useBenefit')) ?>
              </a>
            <?php endif; ?>
          </div>
          <?php if (!empty($benefit['valid_until'])): ?>
            <p class="cell-sub"><?= e(t('cse.validUntil', ['date' => $benefit['valid_until']])) ?></p>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<div class="grid-2 mt-l">
  <!-- ---------- Élus ---------- -->
  <section class="card" id="elus">
    <h2><?= e(t('cse.elected')) ?> <span class="muted">(<?= count($mandates) ?>)</span></h2>
    <?php if ($mandates === []): ?>
      <div class="empty-state"><?= e(t('cse.noElected')) ?></div>
    <?php else: ?>
      <ul class="person-list">
        <?php foreach ($mandates as $member): ?>
          <li class="person-row">
            <span class="person-body">
              <span class="person-name"><?= e($fullName($member)) ?></span>
              <span class="cell-sub">
                <?= e($member['mandate_role']) ?><?= !empty($member['department_name']) ? ' · ' . e($member['department_name']) : '' ?>
              </span>
            </span>
            <a href="/messagerie" class="btn btn-outline btn-sm"><?= e(t('nav.messages')) ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <!-- ---------- Réunions ---------- -->
  <section class="card" id="reunions">
    <h2><?= e(t('cse.meetings')) ?></h2>
    <?php if ($meetings === []): ?>
      <div class="empty-state"><?= e(t('cse.noMeetings')) ?></div>
    <?php else: ?>
      <ul class="meeting-list">
        <?php foreach ($meetings as $meeting): ?>
          <li class="meeting-item">
            <div class="meeting-head">
              <span class="meeting-title"><?= e($meeting['title']) ?></span>
              <span class="cell-sub">
                <?= e($meeting['meeting_date']) ?><?= $meeting['meeting_time'] !== '' ? ' · ' . e($meeting['meeting_time']) : '' ?><?= $meeting['location'] !== '' ? ' · ' . e($meeting['location']) : '' ?>
              </span>
            </div>
            <?php if ($meeting['agenda'] !== ''): ?>
              <p class="cell-sub"><strong><?= e(t('cse.agenda')) ?> :</strong> <?= e($meeting['agenda']) ?></p>
            <?php endif; ?>
            <?php if ((int) $meeting['minutes_published'] === 1 && $meeting['minutes'] !== ''): ?>
              <details class="minutes">
                <summary><?= e(t('cse.minutes')) ?></summary>
                <p><?= e($meeting['minutes']) ?></p>
              </details>
            <?php else: ?>
              <p class="cell-sub"><?= e(t('cse.minutesPending')) ?></p>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<!-- ---------- Élections ---------- -->
<section class="card mt-l" id="elections">
  <h2><?= e(t('cse.elections')) ?></h2>

  <?php if ($election === null): ?>
    <div class="empty-state"><?= e(t('cse.noElection')) ?></div>
  <?php else: ?>
    <div class="election-head">
      <div>
        <h3 class="election-title"><?= e($election['title']) ?></h3>
        <p class="cell-sub">
          <?= e(t('cse.seats')) ?> : <?= (int) $election['seats'] ?>
          <?php if (!empty($election['candidacy_deadline'])): ?>
            · <?= e(t('leave.to')) ?> <?= e($election['candidacy_deadline']) ?>
          <?php endif; ?>
        </p>
      </div>
      <span class="status <?= $election['status'] === 'Vote' ? 'status-on' : 'status-warning' ?>">
        <?= e(t('cse.phase')) ?> : <?= e(st($election['status'])) ?>
      </span>
    </div>

    <?php if ($election['description'] !== ''): ?><p class="benefit-text"><?= e($election['description']) ?></p><?php endif; ?>

    <?php if ($election['status'] === 'Candidatures'): ?>
      <?php if ($myCandidacy !== null): ?>
        <div class="banner">
          <span><?= e(t('cse.myCandidacy')) ?> — <?= e(st($myCandidacy['status'])) ?></span>
        </div>
        <?php if ($myCandidacy['statement'] !== ''): ?><p class="benefit-text"><?= e($myCandidacy['statement']) ?></p><?php endif; ?>
        <form method="POST" action="/cse/candidature/retirer" data-confirm="<?= e(t('cse.confirmWithdrawCandidacy')) ?>">
          <?= $csrf ?>
          <button type="submit" class="btn btn-danger btn-sm"><?= e(t('cse.withdraw')) ?></button>
        </form>
      <?php else: ?>
        <form method="POST" action="/cse/candidature" class="stack">
          <?= $csrf ?>
          <label>
            <span><?= e(t('cse.statement')) ?></span>
            <textarea name="statement" rows="4" maxlength="2000" placeholder="<?= e(t('cse.statementHelp')) ?>"></textarea>
          </label>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?= e(t('cse.apply')) ?></button>
          </div>
        </form>
      <?php endif; ?>
    <?php endif; ?>

    <h3 class="section-label"><?= e(t('cse.candidates')) ?> <span class="muted">(<?= count($candidacies) ?>)</span></h3>
    <?php if ($candidacies === []): ?>
      <div class="empty-state"><?= e(t('cse.noCandidates')) ?></div>
    <?php elseif ($election['status'] === 'Vote' && !$hasVoted): ?>
      <form method="POST" action="/cse/vote" class="stack">
        <?= $csrf ?>
        <ul class="candidate-list">
          <?php foreach ($candidacies as $index => $candidate): ?>
            <li class="candidate-row">
              <label class="check-row">
                <input type="radio" name="candidacy_id" value="<?= (int) $candidate['id'] ?>"<?= $index === 0 ? ' required' : '' ?> />
                <span class="candidate-body">
                  <span class="person-name"><?= e($fullName($candidate)) ?></span>
                  <span class="cell-sub">
                    <?= e((string) $candidate['grade']) ?><?= !empty($candidate['department_name']) ? ' · ' . e($candidate['department_name']) : '' ?>
                  </span>
                  <?php if ($candidate['statement'] !== ''): ?>
                    <span class="benefit-text"><?= e($candidate['statement']) ?></span>
                  <?php endif; ?>
                </span>
              </label>
            </li>
          <?php endforeach; ?>
        </ul>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary" data-confirm="<?= e(t('cse.confirmVote')) ?>"><?= e(t('cse.vote')) ?></button>
        </div>
      </form>
    <?php else: ?>
      <?php if ($election['status'] === 'Vote' && $hasVoted): ?>
        <div class="banner"><span><?= e(t('cse.voted')) ?></span></div>
      <?php endif; ?>
      <ul class="person-list">
        <?php foreach ($candidacies as $candidate): ?>
          <li class="person-row">
            <span class="person-body">
              <span class="person-name"><?= e($fullName($candidate)) ?></span>
              <span class="cell-sub">
                <?= e((string) $candidate['grade']) ?><?= !empty($candidate['department_name']) ? ' · ' . e($candidate['department_name']) : '' ?>
              </span>
              <?php if ($candidate['statement'] !== ''): ?>
                <span class="benefit-text"><?= e($candidate['statement']) ?></span>
              <?php endif; ?>
            </span>
            <span class="status <?= $candidate['status'] === 'Validée' ? 'status-on' : ($candidate['status'] === 'Refusée' ? 'status-danger' : 'status-warning') ?>">
              <?= e(st($candidate['status'])) ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>

  <!-- Le dernier scrutin clos reste consultable : c'est là que se lisent les résultats. -->
  <?php if ($lastClosed !== null): ?>
    <h3 class="section-label"><?= e(t('cse.results')) ?> — <?= e($lastClosed['title']) ?></h3>
    <p class="cell-sub">
      <?= e(t('cse.turnout')) ?> : <?= e((string) $closedTurnout['rate']) ?> %
      (<?= (int) $closedTurnout['voters'] ?>/<?= (int) $closedTurnout['electorate'] ?>)
    </p>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.member')) ?></th>
            <th><?= e(t('common.department')) ?></th>
            <th><?= e(t('cse.votes')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($closedResults as $index => $row): ?>
            <tr>
              <td>
                <strong><?= e($fullName($row)) ?></strong>
                <?php if ($index < (int) $lastClosed['seats']): ?>
                  <span class="tag tag-accent"><?= e(t('status.approved')) ?></span>
                <?php endif; ?>
              </td>
              <td><?= e($row['department_name'] ?? '—') ?></td>
              <td><strong><?= (int) $row['votes'] ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
