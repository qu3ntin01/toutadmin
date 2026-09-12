<?php
$csrf = \App\Core\Csrf::field();
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
$who = static fn (array $row): string =>
    trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: '—';
?>
<section class="stats-grid">
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= (int) $stats['meetings'] ?></span>
      <span class="stat-label"><?= e(t('gov.meetings90')) ?></span>
    </span>
  </div>
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= (int) $stats['decisions'] ?></span>
      <span class="stat-label"><?= e(t('gov.decisionsInForce')) ?></span>
    </span>
  </div>
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= (int) $stats['openActions'] ?></span>
      <span class="stat-label">
        <?= e(t('gov.openActions')) ?><?= $stats['overdueActions'] ? ' · ' . e(t('gov.overdueCount', ['count' => $stats['overdueActions']])) : '' ?>
      </span>
    </span>
  </div>
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= (int) $stats['criticalRisks'] ?></span>
      <span class="stat-label"><?= e(t('gov.criticalRisks', ['total' => $stats['risks']])) ?></span>
    </span>
  </div>
</section>

<!-- ---------- Réunions ---------- -->
<section class="tab-panel is-active" id="reunions">
  <div class="card">
    <h2><?= e(t('cse.newMeeting')) ?></h2>
    <form method="POST" action="/direction/reunions" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.title')) ?></span>
        <input type="text" name="title" required maxlength="200" placeholder="<?= e(t('gov.meetingExample')) ?>" />
      </label>
      <label><span><?= e(t('common.type')) ?></span>
        <select name="kind">
          <?php foreach ($meetingKinds as $kind): ?><option value="<?= e($kind) ?>"><?= e($kind) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.date')) ?></span><input type="date" name="held_on" value="<?= e($today) ?>" required /></label>
      <label><span><?= e(t('common.start')) ?></span><input type="time" name="starts_at" /></label>
      <label><span><?= e(t('common.end')) ?></span><input type="time" name="ends_at" /></label>
      <label><span><?= e(t('common.place')) ?></span>
        <input type="text" name="location" maxlength="160" placeholder="<?= e(t('gov.placeExample')) ?>" />
      </label>
      <label><span><?= e(t('gov.chair')) ?></span>
        <select name="chair_id">
          <option value="">—</option>
          <?php foreach ($employees as $person): ?>
            <option value="<?= (int) $person['id'] ?>"><?= e($who($person)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('cse.agenda')) ?></span>
        <textarea name="agenda" rows="4" placeholder="<?= e(t('gov.agendaExample')) ?>"></textarea>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('gov.convene')) ?></button></div>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('gov.meetingsCount', ['count' => count($meetingList)])) ?></h2>
    <?php if ($meetingList === []): ?>
      <div class="empty-state"><?= e(t('gov.noMeeting')) ?></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('common.date')) ?></th>
              <th><?= e(t('common.meeting')) ?></th>
              <th><?= e(t('gov.chairShort')) ?></th>
              <th><?= e(t('common.participants')) ?></th>
              <th><?= e(t('gov.decisions')) ?></th>
              <th><?= e(t('common.actions')) ?></th>
              <th><?= e(t('common.state')) ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($meetingList as $meeting): ?>
              <tr>
                <td class="cell-sub nowrap">
                  <?= e($day($meeting['held_on'])) ?>
                  <?php if ($meeting['starts_at'] !== ''): ?>
                    <br /><span class="cell-sub">
                      <?= e($meeting['starts_at']) ?><?= $meeting['ends_at'] !== '' ? ' – ' . e($meeting['ends_at']) : '' ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td class="cell-strong">
                  <a href="/direction/reunions/<?= (int) $meeting['id'] ?>"><?= e($meeting['title']) ?></a><br />
                  <span class="cell-sub"><?= e($meeting['kind']) ?><?= $meeting['location'] !== '' ? ' · ' . e($meeting['location']) : '' ?></span>
                </td>
                <td class="cell-sub">
                  <?= e($meeting['chair_first'] !== null ? trim($meeting['chair_first'] . ' ' . $meeting['chair_last']) : '—') ?>
                </td>
                <td><?= (int) $meeting['attendee_count'] ?></td>
                <td><?= (int) $meeting['decision_count'] ?></td>
                <td>
                  <?php if ((int) $meeting['open_actions'] > 0): ?>
                    <span class="tag tag-warning"><?= (int) $meeting['open_actions'] ?> ouverte(s)</span>
                  <?php else: ?>
                    <span class="cell-sub">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="tag <?= $meeting['status'] === 'Tenue' ? 'tag-success' : ($meeting['status'] === 'Annulée' ? 'tag-danger' : '') ?>">
                    <?= e(st($meeting['status'])) ?>
                  </span>
                </td>
                <td>
                  <form method="POST" action="/direction/reunions/<?= (int) $meeting['id'] ?>/supprimer" class="inline-form"
                        data-confirm="<?= e(t('gov.deleteMeeting')) ?>">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-link btn-sm"><?= e(t('common.delete')) ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- ---------- Décisions ---------- -->
<section class="tab-panel" id="decisions">
  <div class="card">
    <h2><?= e(t('gov.recordDecision')) ?></h2>
    <form method="POST" action="/direction/decisions" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('gov.decision')) ?></span>
        <input type="text" name="title" required maxlength="200" placeholder="<?= e(t('gov.decisionExample')) ?>" />
      </label>
      <label><span><?= e(t('gov.takenOn')) ?></span><input type="date" name="decided_on" value="<?= e($today) ?>" required /></label>
      <label><span><?= e(t('gov.by')) ?></span>
        <select name="decided_by">
          <option value=""><?= e(t('gov.me')) ?></option>
          <?php foreach ($employees as $person): ?>
            <option value="<?= (int) $person['id'] ?>"><?= e($who($person)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.scope')) ?></span>
        <select name="scope">
          <?php foreach ($decisionScopes as $scope): ?><option value="<?= e($scope) ?>"><?= e($scope) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('gov.inMeeting')) ?></span>
        <select name="meeting_id">
          <option value=""><?= e(t('gov.outsideMeeting')) ?></option>
          <?php foreach ($meetingList as $meeting): ?>
            <option value="<?= (int) $meeting['id'] ?>"><?= e($day($meeting['held_on']) . ' — ' . $meeting['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('common.content')) ?></span>
        <textarea name="body" rows="3" placeholder="<?= e(t('gov.decisionContent')) ?>"></textarea>
      </label>
      <label class="span-2"><span><?= e(t('common.reason')) ?></span>
        <textarea name="rationale" rows="2" placeholder="<?= e(t('gov.decisionReason')) ?>"></textarea>
      </label>
      <label><span><?= e(t('gov.reviewOn')) ?></span><input type="date" name="review_on" /></label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('gov.registerRisk')) ?></button></div>
    </form>
    <p class="hint"><?= e(t('gov.decisionHelp')) ?></p>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('gov.decisionsCount', ['count' => count($decisionList)])) ?></h2>
    <?php if ($decisionList === []): ?>
      <div class="empty-state"><?= e(t('gov.registerEmpty')) ?></div>
    <?php else: ?>
      <ul class="meeting-list">
        <?php foreach ($decisionList as $decision): ?>
          <li class="meeting-item">
            <div class="meeting-head">
              <span class="meeting-title"><?= e($decision['title']) ?></span>
              <span class="tag <?= $decision['status'] === 'En vigueur' ? 'tag-success' : ($decision['status'] === 'Abandonnée' ? 'tag-danger' : 'tag-warning') ?>">
                <?= e(st($decision['status'])) ?>
              </span>
            </div>
            <p class="cell-sub">
              <?= e($day($decision['decided_on'])) ?> · <?= e($decision['scope']) ?> · <?= e($who($decision)) ?>
              <?php if ($decision['meeting_title'] !== null): ?>
                · <a href="/direction/reunions/<?= (int) $decision['meeting_id'] ?>"><?= e($decision['meeting_title']) ?></a>
              <?php endif; ?>
              <?php if ((int) $decision['open_actions'] > 0): ?>
                · <span class="text-warning"><?= (int) $decision['open_actions'] ?> action(s) ouverte(s)</span>
              <?php endif; ?>
              <?php if (!empty($decision['review_on'])): ?>
                · <?= e(t('gov.reviewOn')) ?> <?= e($day($decision['review_on'])) ?>
              <?php endif; ?>
            </p>
            <?php if ($decision['body'] !== ''): ?><p class="cell-sub"><?= e($decision['body']) ?></p><?php endif; ?>
            <?php if ($decision['rationale'] !== ''): ?>
              <details class="minutes"><summary><?= e(t('common.reason')) ?></summary><p><?= e($decision['rationale']) ?></p></details>
            <?php endif; ?>
            <div class="row-actions">
              <form method="POST" action="/direction/decisions/<?= (int) $decision['id'] ?>/statut" class="inline-field">
                <?= $csrf ?>
                <select name="status" class="input-sm">
                  <?php foreach ($decisionStatuses as $status): ?>
                    <option value="<?= e($status) ?>"<?= $status === $decision['status'] ? ' selected' : '' ?>><?= e($status) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline btn-sm"><?= e(t('gov.changeState')) ?></button>
              </form>
              <form method="POST" action="/direction/decisions/<?= (int) $decision['id'] ?>/supprimer" class="inline-form"
                    data-confirm="<?= e(t('gov.deleteDecision')) ?>">
                <?= $csrf ?>
                <button type="submit" class="btn btn-link btn-sm"><?= e(t('common.remove')) ?></button>
              </form>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>

<!-- ---------- Actions ---------- -->
<section class="tab-panel" id="actions">
  <div class="card">
    <h2><?= e(t('gov.entrustAction')) ?></h2>
    <form method="POST" action="/direction/actions" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.action')) ?></span>
        <input type="text" name="label" required maxlength="300" placeholder="<?= e(t('gov.actionExample')) ?>" />
      </label>
      <label><span><?= e(t('gov.entrustedTo')) ?></span>
        <select name="assignee_id">
          <option value="">—</option>
          <?php foreach ($employees as $person): ?>
            <option value="<?= (int) $person['id'] ?>"><?= e($who($person)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('gov.forDate')) ?></span><input type="date" name="due_date" /></label>
      <label class="span-2"><span><?= e(t('gov.sourceDecision')) ?></span>
        <select name="decision_id">
          <option value="">—</option>
          <?php foreach ($decisionList as $decision): ?>
            <option value="<?= (int) $decision['id'] ?>"><?= e($decision['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('gov.entrust')) ?></button></div>
    </form>
    <p class="hint"><?= e(t('gov.actionHelp')) ?></p>
  </div>

  <div class="card mt-l">
    <h2><?= count($actionList) ?> action(s) ouverte(s)</h2>
    <?php if ($actionList === []): ?>
      <div class="empty-state"><?= e(t('gov.nothingOngoing')) ?></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('erp.dueDate')) ?></th>
              <th><?= e(t('common.action')) ?></th>
              <th><?= e(t('gov.holder')) ?></th>
              <th><?= e(t('common.origin')) ?></th>
              <th><?= e(t('common.state')) ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($actionList as $action): ?>
              <tr>
                <td class="cell-sub nowrap">
                  <?php if (!empty($action['due_date']) && $action['due_date'] < $today): ?>
                    <span class="tag tag-danger"><?= e($day($action['due_date'])) ?></span>
                  <?php else: ?>
                    <?= e($day($action['due_date'])) ?>
                  <?php endif; ?>
                </td>
                <td class="cell-strong"><?= e($action['label']) ?></td>
                <td class="cell-sub"><?= e($who($action)) ?></td>
                <td class="cell-sub"><?= e($action['decision_title'] ?? $action['meeting_title'] ?? '—') ?></td>
                <td>
                  <form method="POST" action="/direction/actions/<?= (int) $action['id'] ?>/statut" class="inline-field">
                    <?= $csrf ?>
                    <select name="status" class="input-sm">
                      <?php foreach ($actionStatuses as $status): ?>
                        <option value="<?= e($status) ?>"<?= $status === $action['status'] ? ' selected' : '' ?>><?= e($status) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-outline btn-sm"><?= e(t('common.update')) ?></button>
                  </form>
                </td>
                <td>
                  <form method="POST" action="/direction/actions/<?= (int) $action['id'] ?>/supprimer" class="inline-form"
                        data-confirm="<?= e(t('gov.deleteAction')) ?>">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-link btn-sm"><?= e(t('common.delete')) ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- ---------- Risques ---------- -->
<section class="tab-panel" id="risques">
  <div class="card">
    <h2><?= e(t('gov.riskMatrix')) ?></h2>
    <p class="muted"><?= e(t('gov.matrixLegend')) ?></p>
    <div class="table-wrap">
      <table class="risk-matrix">
        <thead>
          <tr>
            <th class="matrix-head"><?= e(t('gov.matrixAxes')) ?></th>
            <?php foreach ($scale as $level): ?><th class="matrix-head"><?= (int) $level ?></th><?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($matrix as $rowIndex => $row): ?>
            <?php $likelihood = count($scale) - $rowIndex; ?>
            <tr>
              <th class="matrix-head"><?= $likelihood ?></th>
              <?php foreach ($row as $columnIndex => $cell): ?>
                <?php $level = $likelihood * ($columnIndex + 1); ?>
                <td class="matrix-cell risk-cell risk-level-<?= $level >= 15 ? '4' : ($level >= $criticalThreshold ? '3' : ($level >= 6 ? '2' : '1')) ?>">
                  <?= $cell === [] ? '' : count($cell) ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint"><?= e(t('gov.criticalHelp', ['threshold' => $criticalThreshold])) ?></p>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('gov.recordRisk')) ?></h2>
    <form method="POST" action="/direction/risques" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('gov.risk')) ?></span>
        <input type="text" name="title" required maxlength="200"
               placeholder="Dépendance à un client qui pèse 40 % du chiffre d'affaires" />
      </label>
      <label><span><?= e(t('common.category')) ?></span>
        <select name="category">
          <?php foreach ($riskCategories as $category): ?><option value="<?= e($category) ?>"><?= e($category) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.reference')) ?></span><input type="text" name="reference" maxlength="40" placeholder="R-2026-01" /></label>
      <label><span><?= e(t('gov.probability')) ?></span>
        <select name="likelihood">
          <?php foreach ($scale as $level): ?>
            <option value="<?= (int) $level ?>"<?= $level === 3 ? ' selected' : '' ?>><?= (int) $level ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('gov.impact')) ?></span>
        <select name="impact">
          <?php foreach ($scale as $level): ?>
            <option value="<?= (int) $level ?>"<?= $level === 3 ? ' selected' : '' ?>><?= (int) $level ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('gov.holder')) ?></span>
        <select name="owner_id">
          <option value="">—</option>
          <?php foreach ($employees as $person): ?>
            <option value="<?= (int) $person['id'] ?>"><?= e($who($person)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('gov.treatment')) ?></span>
        <select name="treatment">
          <?php foreach ($treatments as $treatment): ?><option value="<?= e($treatment) ?>"><?= e($treatment) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('common.description')) ?></span><textarea name="description" rows="2"></textarea></label>
      <label class="span-2"><span><?= e(t('gov.actionPlan')) ?></span>
        <textarea name="action_plan" rows="2" placeholder="<?= e(t('gov.actionPlanExample')) ?>"></textarea>
      </label>
      <label><span><?= e(t('gov.residualProbability')) ?></span>
        <select name="residual_likelihood">
          <option value="">—</option>
          <?php foreach ($scale as $level): ?><option value="<?= (int) $level ?>"><?= (int) $level ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('gov.residualImpact')) ?></span>
        <select name="residual_impact">
          <option value="">—</option>
          <?php foreach ($scale as $level): ?><option value="<?= (int) $level ?>"><?= (int) $level ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('gov.identifiedOn')) ?></span><input type="date" name="identified_on" value="<?= e($today) ?>" /></label>
      <label><span><?= e(t('gov.nextReview')) ?></span><input type="date" name="next_review" /></label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('gov.registerRisk')) ?></button></div>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('gov.risksInRegister', ['count' => count($riskList)])) ?></h2>
    <?php if ($riskList === []): ?>
      <div class="empty-state"><?= e(t('gov.emptyRiskRegister')) ?></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('gov.reference')) ?></th>
              <th><?= e(t('gov.risk')) ?></th>
              <th><?= e(t('common.category')) ?></th>
              <th><?= e(t('payslip.gross')) ?></th>
              <th><?= e(t('gov.residual')) ?></th>
              <th><?= e(t('gov.treatment')) ?></th>
              <th><?= e(t('gov.holder')) ?></th>
              <th><?= e(t('common.review')) ?></th>
              <th><?= e(t('common.state')) ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($riskList as $risk): ?>
              <tr>
                <td class="cell-sub mono"><?= e($risk['reference'] !== '' ? $risk['reference'] : '—') ?></td>
                <td class="cell-strong">
                  <?= e($risk['title']) ?>
                  <?php if ($risk['action_plan'] !== ''): ?><br /><span class="cell-sub"><?= e($risk['action_plan']) ?></span><?php endif; ?>
                </td>
                <td class="cell-sub"><?= e($risk['category']) ?></td>
                <td><span class="tag"><?= (int) $risk['gross'] ?></span></td>
                <td>
                  <span class="tag <?= $risk['critical'] ? 'tag-danger' : 'tag-success' ?>">
                    <?= $risk['residual'] === null ? '—' : (int) $risk['residual'] ?>
                  </span>
                </td>
                <td class="cell-sub"><?= e($risk['treatment']) ?></td>
                <td class="cell-sub"><?= e($who($risk)) ?></td>
                <td class="cell-sub nowrap"><?= e($day($risk['next_review'])) ?></td>
                <td><span class="tag <?= $risk['status'] === 'Ouvert' ? 'tag-warning' : 'tag-success' ?>"><?= e(st($risk['status'])) ?></span></td>
                <td>
                  <form method="POST" action="/direction/risques/<?= (int) $risk['id'] ?>/supprimer" class="inline-form"
                        data-confirm="<?= e(t('gov.deleteRisk')) ?>">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-link btn-sm"><?= e(t('common.remove')) ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- ---------- Sondages ---------- -->
<section class="tab-panel" id="sondages">
  <div class="card">
    <h2><?= e(t('gov.launchSurvey')) ?></h2>
    <form method="POST" action="/direction/sondages" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.title')) ?></span>
        <input type="text" name="title" required maxlength="200" placeholder="<?= e(t('gov.surveyExample')) ?>" />
      </label>
      <label><span><?= e(t('common.nature')) ?></span>
        <select name="kind">
          <?php foreach ($surveyKinds as $kind): ?><option value="<?= e($kind) ?>"><?= e($kind) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('gov.population')) ?></span>
        <select name="audience">
          <?php foreach ($surveyAudiences as $audience): ?><option value="<?= e($audience) ?>"><?= e($audience) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('gov.departmentOrTeam')) ?></span>
        <select name="audience_id">
          <option value="">— (si population restreinte)</option>
          <optgroup label="Services">
            <?php foreach ($departments as $department): ?>
              <option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <optgroup label="Équipes">
            <?php foreach ($teams as $team): ?>
              <option value="<?= (int) $team['id'] ?>"><?= e($team['name']) ?></option>
            <?php endforeach; ?>
          </optgroup>
        </select>
      </label>
      <label><span><?= e(t('gov.closesOn')) ?></span><input type="date" name="closes_on" /></label>
      <label class="span-2"><span><?= e(t('gov.introduction')) ?></span>
        <textarea name="intro" rows="2" placeholder="<?= e(t('gov.introExample')) ?>"></textarea>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('gov.createDraft')) ?></button></div>
    </form>
    <p class="hint"><?= e(t('gov.anonymityHelp', ['min' => $anonymityThreshold])) ?></p>
  </div>

  <?php if ($barometer !== []): ?>
    <div class="card mt-l">
      <h2><?= e(t('gov.barometer')) ?></h2>
      <p class="muted"><?= e(t('gov.barometerLegend')) ?></p>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('gov.survey')) ?></th>
              <th><?= e(t('gov.closedOn')) ?></th>
              <th><?= e(t('common.answers')) ?></th>
              <th><?= e(t('cse.turnout')) ?></th>
              <th><?= e(t('gov.indexOutOf5')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($barometer as $point): ?>
              <tr>
                <td class="cell-strong">
                  <a href="/direction/sondages/<?= (int) $point['id'] ?>/resultats"><?= e($point['title']) ?></a>
                </td>
                <td class="cell-sub nowrap"><?= e($day($point['closedOn'])) ?></td>
                <td><?= (int) $point['answered'] ?></td>
                <td>
                  <span class="meter"><span class="meter-fill" data-ratio="<?= (int) $point['rate'] ?>"></span></span>
                  <span class="cell-sub"><?= (int) $point['rate'] ?> %</span>
                </td>
                <td>
                  <span class="tag <?= $point['score'] >= 3.5 ? 'tag-success' : ($point['score'] >= 2.5 ? 'tag-warning' : 'tag-danger') ?>">
                    <?= e((string) $point['score']) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="card mt-l">
    <h2><?= count($surveyList) ?> sondage(s)</h2>
    <?php if ($surveyList === []): ?>
      <div class="empty-state"><?= e(t('gov.noSurvey')) ?></div>
    <?php else: ?>
      <ul class="meeting-list">
        <?php foreach ($surveyList as $survey): ?>
          <li class="meeting-item">
            <div class="meeting-head">
              <span class="meeting-title"><?= e($survey['title']) ?></span>
              <span class="tag <?= $survey['status'] === 'Ouvert' ? 'tag-success' : ($survey['status'] === 'Clos' ? '' : 'tag-warning') ?>">
                <?= e(st($survey['status'])) ?>
              </span>
            </div>
            <p class="cell-sub">
              <?= e($survey['kind']) ?> · <?= e($survey['audience']) ?><?= !empty($survey['closes_on']) ? ' · clôture le ' . e($day($survey['closes_on'])) : '' ?>
              · <?= e(t('gov.surveySummary', [
                  'questions' => (int) $survey['question_count'],
                  'answers' => (int) $survey['answer_count'],
              ])) ?>
            </p>

            <?php if ($survey['status'] === 'Brouillon'): ?>
              <form method="POST" action="/direction/sondages/<?= (int) $survey['id'] ?>/questions" class="form-grid">
                <?= $csrf ?>
                <label class="span-2"><span><?= e(t('gov.question')) ?></span>
                  <input type="text" name="label" required maxlength="300" placeholder="<?= e(t('gov.questionExample')) ?>" />
                </label>
                <label><span><?= e(t('common.type')) ?></span>
                  <select name="type">
                    <?php foreach ($questionTypes as $type): ?>
                      <option value="<?= e($type['key']) ?>"><?= e($type['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="check-row">
                  <input type="checkbox" name="required" value="1" checked />
                  <span><?= e(t('gov.requiredAnswer')) ?></span>
                </label>
                <label class="span-2"><span><?= e(t('gov.choices')) ?></span><textarea name="choices" rows="2"></textarea></label>
                <div class="span-2"><button type="submit" class="btn btn-outline btn-block"><?= e(t('gov.addQuestion')) ?></button></div>
              </form>
            <?php endif; ?>

            <div class="row-actions">
              <?php if ($survey['status'] === 'Brouillon'): ?>
                <form method="POST" action="/direction/sondages/<?= (int) $survey['id'] ?>/ouvrir" class="inline-form">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-primary btn-sm"><?= e(t('common.open')) ?></button>
                </form>
              <?php elseif ($survey['status'] === 'Ouvert'): ?>
                <form method="POST" action="/direction/sondages/<?= (int) $survey['id'] ?>/clore" class="inline-form">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-outline btn-sm"><?= e(t('gov.close')) ?></button>
                </form>
              <?php endif; ?>
              <a href="/direction/sondages/<?= (int) $survey['id'] ?>/resultats" class="btn btn-outline btn-sm"><?= e(t('cse.results')) ?></a>
              <form method="POST" action="/direction/sondages/<?= (int) $survey['id'] ?>/supprimer" class="inline-form"
                    data-confirm="<?= e(t('gov.deleteSurvey')) ?>">
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
