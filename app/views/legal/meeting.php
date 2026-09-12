<?php
$csrf = \App\Core\Csrf::field();
$num = static fn (float $value): string => rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ',');
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
?>
<section class="stats-grid">
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= e($day($meeting['held_on'])) ?></span>
      <span class="stat-label"><?= e(t('common.date')) ?></span>
    </span>
  </div>
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= e($num((float) $quorum['present'])) ?></span>
      <span class="stat-label"><?= e(t('jur.sharesPresent')) ?></span>
    </span>
  </div>
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value <?= $quorum['reached'] ? '' : 'text-danger' ?>">
        <?= e($quorum['reached']
            ? t('jur.quorumReached')
            : t('jur.quorumMissing', ['shares' => $num((float) $quorum['missing'])])) ?>
      </span>
      <span class="stat-label"><?= e(t('jur.quorumRequired')) ?> <?= e($num((float) $quorum['required'])) ?></span>
    </span>
  </div>
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= count($resolutionList) ?></span>
      <span class="stat-label"><?= e(t('jur.resolutions')) ?></span>
    </span>
  </div>
</section>

<section class="tab-panel is-active" id="resolutions">
  <div class="card">
    <div class="card-head">
      <div>
        <h2><?= e(t('jur.resolutions')) ?></h2>
        <p class="muted"><?= e(t('jur.voteHint')) ?></p>
      </div>
      <span class="tag <?= $meeting['status'] === 'Tenue' ? 'tag-success' : ($meeting['status'] === 'Annulée' ? '' : 'tag-warning') ?>">
        <?= e(st($meeting['status'])) ?>
      </span>
    </div>

    <ul class="org-list">
      <?php foreach ($resolutionList as $resolution): ?>
        <?php $expressed = (int) $resolution['votes_for'] + (int) $resolution['votes_against']; ?>
        <li class="org-item">
          <div class="org-head">
            <div>
              <span class="org-name">
                <?= e(t('jur.resolutionNumber', ['number' => (int) $resolution['position']])) ?> — <?= e($resolution['label']) ?>
              </span>
              <span class="cell-sub">
                <?= e(t('jur.majorityRequired', ['percent' => $num((float) $resolution['majority_required'])])) ?>
              </span>
            </div>
            <span class="tag <?= $resolution['outcome'] === 'Adoptée' ? 'tag-success' : ($resolution['outcome'] === 'Rejetée' ? 'tag-danger' : '') ?>">
              <?= e(st($resolution['outcome'])) ?>
            </span>
          </div>
          <p class="cell-sub">
            <?= e(t('jur.votesFor')) ?> <?= e($num((float) $resolution['votes_for'])) ?> ·
            <?= e(t('jur.votesAgainst')) ?> <?= e($num((float) $resolution['votes_against'])) ?> ·
            <?= e(t('jur.votesAbstain')) ?> <?= e($num((float) $resolution['votes_abstain'])) ?>
            <?php if ($expressed > 0): ?>
              · <?= e(t('jur.expressedShare', [
                  'percent' => $num(round((int) $resolution['votes_for'] / $expressed * 1000) / 10),
              ])) ?>
            <?php endif; ?>
          </p>
          <form method="POST" action="/juridique/resolutions/<?= (int) $resolution['id'] ?>/vote" class="form-grid">
            <?= $csrf ?>
            <label><span><?= e(t('jur.votesFor')) ?></span>
              <input type="text" name="votes_for" inputmode="decimal" value="<?= (int) $resolution['votes_for'] ?>" />
            </label>
            <label><span><?= e(t('jur.votesAgainst')) ?></span>
              <input type="text" name="votes_against" inputmode="decimal" value="<?= (int) $resolution['votes_against'] ?>" />
            </label>
            <label><span><?= e(t('jur.votesAbstain')) ?></span>
              <input type="text" name="votes_abstain" inputmode="decimal" value="<?= (int) $resolution['votes_abstain'] ?>" />
            </label>
            <div><button type="submit" class="btn btn-primary btn-block"><?= e(t('jur.recordVote')) ?></button></div>
          </form>
          <div class="actions">
            <form method="POST" action="/juridique/resolutions/<?= (int) $resolution['id'] ?>/supprimer"
                  data-confirm="<?= e(t('jur.confirmDeleteResolution')) ?>">
              <?= $csrf ?>
              <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($resolutionList === []): ?><p class="cell-sub"><?= e(t('jur.noResolution')) ?></p><?php endif; ?>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('jur.addResolution')) ?></h2>
    <form method="POST" action="/juridique/assemblees/<?= (int) $meeting['id'] ?>/resolutions" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('jur.resolutionLabel')) ?></span><input type="text" name="label" required maxlength="300" /></label>
      <label><span><?= e(t('jur.majorityPercent')) ?></span>
        <input type="text" name="majority_required" inputmode="decimal" value="50" required />
      </label>
      <div><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.add')) ?></button></div>
    </form>
    <p class="hint"><?= e(t('jur.majorityHint')) ?></p>
  </div>
</section>

<section class="tab-panel" id="proces-verbal">
  <div class="card">
    <h2><?= e(t('jur.minutes')) ?></h2>
    <p class="muted"><?= e(t('jur.minutesHint')) ?></p>
    <form method="POST" action="/juridique/assemblees/<?= (int) $meeting['id'] ?>/proces-verbal" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('jur.minutes')) ?></span>
        <textarea name="minutes" rows="16" maxlength="20000"><?= e($meeting['minutes']) ?></textarea>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
    </form>
  </div>
</section>

<section class="tab-panel" id="fiche">
  <div class="card">
    <h2><?= e(t('jur.meetingSheet')) ?></h2>
    <form method="POST" action="/juridique/assemblees/<?= (int) $meeting['id'] ?>/modifier" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.type')) ?></span>
        <select name="kind">
          <?php foreach ($kinds as $kind): ?>
            <option value="<?= e($kind) ?>"<?= $meeting['kind'] === $kind ? ' selected' : '' ?>><?= e(st($kind)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.status')) ?></span>
        <select name="status">
          <?php foreach ($statuses as $status): ?>
            <option value="<?= e($status) ?>"<?= $meeting['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.date')) ?></span>
        <input type="date" name="held_on" value="<?= e($meeting['held_on']) ?>" required />
      </label>
      <label><span><?= e(t('evt.location')) ?></span>
        <input type="text" name="location" value="<?= e($meeting['location']) ?>" maxlength="200" />
      </label>
      <label><span><?= e(t('jur.quorumRequired')) ?></span>
        <input type="text" name="quorum_required" inputmode="decimal" value="<?= e((string) $meeting['quorum_required']) ?>" />
      </label>
      <label><span><?= e(t('jur.sharesPresent')) ?></span>
        <input type="text" name="shares_present" inputmode="decimal" value="<?= e((string) $meeting['shares_present']) ?>" />
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
    </form>
    <p class="hint"><?= e(t('jur.presentHint', ['total' => $num((float) $quorum['total'])])) ?></p>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('common.delete')) ?></h2>
    <form method="POST" action="/juridique/assemblees/<?= (int) $meeting['id'] ?>/supprimer"
          data-confirm="<?= e(t('jur.confirmDeleteMeeting')) ?>">
      <?= $csrf ?>
      <button type="submit" class="btn btn-danger"><?= e(t('jur.deleteMeeting')) ?></button>
    </form>
  </div>
</section>
