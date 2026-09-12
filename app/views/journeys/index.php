<?php
$csrf = \App\Core\Csrf::field();
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
$fullName = static fn (array $row): string => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
$ongoing = count(array_filter($checklistList, static fn (array $row): bool => $row['completed_at'] === null));
?>
<section class="stats-grid">
  <?php foreach ([
      [(string) $ongoing, t('par.ongoing')],
      [(string) count($late), t('par.latePoints')],
      [(string) count($expiring), t('par.toRenew')],
      [(string) count($missing), t('par.missingMandatory')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<!-- ---------- Parcours ---------- -->
<section class="tab-panel is-active" id="parcours">
  <div class="grid-2">
    <div class="card">
      <div class="card-head"><h2><?= e(t('par.start')) ?></h2></div>
      <form method="POST" action="/parcours/listes" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('common.template')) ?></span>
          <select name="template_id" required>
            <option value="">—</option>
            <?php foreach ($templateList as $template): ?>
              <option value="<?= (int) $template['id'] ?>">
                <?= e($template['kind'] . ' — ' . $template['name'] . ' (' . count($template['items']) . ' point(s))') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('sst.personConcerned')) ?></span>
          <select name="user_id" required>
            <option value="">—</option>
            <?php foreach ($employees as $person): ?>
              <option value="<?= (int) $person['id'] ?>"><?= e($fullName($person) . ' — ' . $person['email']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('par.pivotDateLong')) ?></span>
          <input type="date" name="reference_date" value="<?= e($today) ?>" required />
        </label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('par.launch')) ?></button></div>
      </form>
      <p class="hint"><?= e(t('par.offsetNote')) ?></p>
    </div>

    <div class="card">
      <div class="card-head"><h2><?= e(t('par.latePoints')) ?></h2></div>
      <ul class="org-list">
        <?php foreach (array_slice($late, 0, 12) as $item): ?>
          <li class="org-item">
            <span>
              <span class="cell-strong"><?= e($item['label']) ?></span>
              <br /><span class="cell-sub">
                <?= e($fullName($item)) ?> · <?= e($item['owner_role']) ?> · <?= e(t('par.dueOn')) ?> <?= e($day($item['due_date'])) ?>
              </span>
            </span>
            <form method="POST" action="/parcours/listes/points/<?= (int) $item['id'] ?>/basculer" class="inline-form">
              <?= $csrf ?>
              <button type="submit" class="btn btn-outline btn-sm"><?= e(t('par.done')) ?></button>
            </form>
          </li>
        <?php endforeach; ?>
        <?php if ($late === []): ?><li class="cell-sub"><?= e(t('par.nothingLate')) ?></li><?php endif; ?>
      </ul>
    </div>
  </div>

  <div class="card mt-l">
    <div class="card-head"><h2><?= e(t('par.journeyCount', ['count' => count($checklistList)])) ?></h2></div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.person')) ?></th>
            <th><?= e(t('common.type')) ?></th>
            <th><?= e(t('par.pivotDate')) ?></th>
            <th><?= e(t('par.progress')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($checklistList as $checklist): ?>
            <tr>
              <td>
                <a class="cell-strong" href="/parcours/listes/<?= (int) $checklist['id'] ?>"><?= e($fullName($checklist)) ?></a>
                <br /><span class="cell-sub"><?= e($checklist['template_name'] ?? '—') ?></span>
              </td>
              <td>
                <span class="tag <?= $checklist['kind'] === 'Départ' ? 'tag-danger' : 'tag-accent' ?>">
                  <?= e(st($checklist['kind'])) ?>
                </span>
              </td>
              <td class="cell-sub nowrap"><?= e($day($checklist['reference_date'])) ?></td>
              <td>
                <span class="meter">
                  <span class="meter-fill" data-ratio="<?= (int) $checklist['total'] > 0
                      ? (int) round((int) $checklist['done'] * 100 / (int) $checklist['total']) : 0 ?>"></span>
                </span>
                <span class="cell-sub">
                  <?= (int) $checklist['done'] ?>/<?= (int) $checklist['total'] ?><?= $checklist['completed_at'] !== null ? ' — ' . e(t('par.finished')) : '' ?>
                </span>
              </td>
              <td>
                <form method="POST" action="/parcours/listes/<?= (int) $checklist['id'] ?>/supprimer" class="inline-form"
                      data-confirm="<?= e(t('par.confirmDeleteJourney')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.delete')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($checklistList === []): ?>
            <tr><td colspan="5" class="cell-sub"><?= e(t('par.noJourney')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- ---------- Modèles ---------- -->
<section class="tab-panel" id="modeles">
  <div class="card">
    <div class="card-head"><h2><?= e(t('par.createTemplate')) ?></h2></div>
    <form method="POST" action="/parcours/modeles" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.name')) ?></span>
        <input type="text" name="name" required maxlength="120" placeholder="<?= e(t('par.templatePlaceholder')) ?>" />
      </label>
      <label><span><?= e(t('common.type')) ?></span>
        <select name="kind">
          <?php foreach ($kinds as $kind): ?><option value="<?= e($kind) ?>"><?= e(st($kind)) ?></option><?php endforeach; ?>
        </select>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.create')) ?></button></div>
    </form>
  </div>

  <?php foreach ($templateList as $template): ?>
    <div class="card mt-l">
      <div class="card-head">
        <h2><?= e($template['name']) ?> <span class="tag"><?= e(st($template['kind'])) ?></span></h2>
        <form method="POST" action="/parcours/modeles/<?= (int) $template['id'] ?>/supprimer" class="inline-form"
              data-confirm="<?= e(t('par.confirmDeleteTemplate')) ?>">
          <?= $csrf ?>
          <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.delete')) ?></button>
        </form>
      </div>
      <ul class="org-list">
        <?php foreach ($template['items'] as $item): ?>
          <li class="org-item">
            <span>
              <span class="cell-strong"><?= e($item['label']) ?></span>
              <br /><span class="cell-sub">
                <?= e($item['owner_role']) ?> · J<?= (int) $item['offset_days'] >= 0 ? '+' . (int) $item['offset_days'] : (int) $item['offset_days'] ?>
              </span>
            </span>
            <form method="POST" action="/parcours/modeles/points/<?= (int) $item['id'] ?>/supprimer" class="inline-form">
              <?= $csrf ?>
              <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.remove')) ?></button>
            </form>
          </li>
        <?php endforeach; ?>
        <?php if ($template['items'] === []): ?><li class="cell-sub"><?= e(t('par.noItem')) ?></li><?php endif; ?>
      </ul>

      <form method="POST" action="/parcours/modeles/<?= (int) $template['id'] ?>/points" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('par.itemToDo')) ?></span>
          <input type="text" name="label" required maxlength="200" placeholder="<?= e(t('par.itemPlaceholder')) ?>" />
        </label>
        <label><span><?= e(t('common.owner')) ?></span>
          <select name="owner_role">
            <?php foreach ($ownerRoles as $role): ?><option value="<?= e($role) ?>"><?= e($role) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('par.offsetLabel')) ?></span>
          <input type="number" name="offset_days" value="0" min="-365" max="365" required />
        </label>
        <div class="span-2"><button type="submit" class="btn btn-outline btn-block"><?= e(t('par.addItem')) ?></button></div>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if ($templateList === []): ?>
    <div class="card mt-l"><p class="cell-sub"><?= e(t('par.noTemplate')) ?></p></div>
  <?php endif; ?>
</section>

<!-- ---------- Compétences ---------- -->
<section class="tab-panel" id="competences">
  <div class="grid-2">
    <div class="card">
      <div class="card-head"><h2><?= e(t('par.declareSkill')) ?></h2></div>
      <form method="POST" action="/parcours/competences" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('common.title')) ?></span>
          <input type="text" name="name" required maxlength="120" placeholder="<?= e(t('par.skillPlaceholder')) ?>" />
        </label>
        <label><span><?= e(t('common.category')) ?></span>
          <input type="text" name="category" value="Générale" maxlength="60" />
        </label>
        <label><span><?= e(t('sst.validityMonths')) ?></span>
          <input type="number" name="validity_months" min="1" max="600" placeholder="36" />
        </label>
        <label class="check-row span-2">
          <input type="checkbox" name="mandatory" value="1" /><span><?= e(t('par.mandatoryForAll')) ?></span>
        </label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.add')) ?></button></div>
      </form>
      <p class="hint"><?= e(t('par.noValidityNote')) ?></p>
    </div>

    <div class="card">
      <div class="card-head"><h2><?= e(t('par.assign')) ?></h2></div>
      <form method="POST" action="/parcours/competences/attribuer" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('common.person')) ?></span>
          <select name="user_id" required>
            <option value="">—</option>
            <?php foreach ($employees as $person): ?>
              <option value="<?= (int) $person['id'] ?>"><?= e($fullName($person)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('par.skill')) ?></span>
          <select name="skill_id" required>
            <option value="">—</option>
            <?php foreach ($skillList as $skill): ?>
              <option value="<?= (int) $skill['id'] ?>"><?= e($skill['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('par.level')) ?></span>
          <select name="level">
            <?php foreach ($levels as $level): ?>
              <option value="<?= (int) $level ?>"<?= $level === 2 ? ' selected' : '' ?>><?= (int) $level ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('par.obtainedOn')) ?></span>
          <input type="date" name="obtained_on" value="<?= e($today) ?>" />
        </label>
        <label class="span-2"><span><?= e(t('par.certificateRef')) ?></span>
          <input type="text" name="reference" maxlength="120" />
        </label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('par.assign')) ?></button></div>
      </form>
    </div>
  </div>

  <div class="card mt-l">
    <div class="card-head"><h2><?= e(t('par.matrix')) ?></h2></div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.person')) ?></th>
            <?php foreach ($skillList as $skill): ?><th class="matrix-head"><?= e($skill['name']) ?></th><?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($matrix as $person): ?>
            <tr>
              <td class="cell-strong nowrap"><?= e($fullName($person)) ?></td>
              <?php foreach ($skillList as $skill): ?>
                <?php $held = $person['held'][(int) $skill['id']] ?? null; ?>
                <td class="matrix-cell">
                  <?php if ($held === null): ?>
                    <span class="cell-sub">—</span>
                  <?php else: ?>
                    <span class="tag <?= $held['expires_on'] !== null && $held['expires_on'] < $today ? 'tag-danger' : 'tag-success' ?>">
                      N<?= (int) $held['level'] ?><?= $held['expires_on'] !== null ? ' · ' . e($day($held['expires_on'])) : '' ?>
                    </span>
                    <form method="POST" action="/parcours/competences/<?= (int) $skill['id'] ?>/retirer/<?= (int) $person['id'] ?>"
                          class="inline-form">
                      <?= $csrf ?>
                      <button type="submit" class="btn-link"><?= e(t('par.remove')) ?></button>
                    </form>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          <?php if ($matrix === [] || $skillList === []): ?>
            <tr><td colspan="<?= count($skillList) + 1 ?>" class="cell-sub"><?= e(t('par.declareSkillsFirst')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card mt-l">
    <div class="card-head"><h2><?= e(t('par.declaredSkills')) ?></h2></div>
    <ul class="org-list">
      <?php foreach ($skillList as $skill): ?>
        <li class="org-item">
          <span>
            <span class="cell-strong"><?= e($skill['name']) ?></span>
            <?php if ((int) $skill['mandatory'] === 1): ?>
              <span class="tag tag-danger"><?= e(t('par.mandatoryTag')) ?></span>
            <?php endif; ?>
            <br /><span class="cell-sub">
              <?= e($skill['category']) ?> ·
              <?= e($skill['validity_months'] !== null
                  ? t('par.validMonths', ['count' => (int) $skill['validity_months']])
                  : t('par.noExpiry')) ?>
              · <?= e(t('par.holderCount', ['count' => (int) $skill['holders']])) ?>
            </span>
          </span>
          <form method="POST" action="/parcours/competences/<?= (int) $skill['id'] ?>/supprimer" class="inline-form"
                data-confirm="<?= e(t('par.confirmDeleteSkill')) ?>">
            <?= $csrf ?>
            <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.delete')) ?></button>
          </form>
        </li>
      <?php endforeach; ?>
      <?php if ($skillList === []): ?><li class="cell-sub"><?= e(t('par.noSkill')) ?></li><?php endif; ?>
    </ul>
  </div>
</section>

<!-- ---------- Échéances ---------- -->
<section class="tab-panel" id="echeances">
  <div class="grid-2">
    <div class="card">
      <div class="card-head"><h2><?= e(t('par.expiringTitle')) ?></h2></div>
      <ul class="org-list">
        <?php foreach ($expiring as $row): ?>
          <li class="org-item">
            <span>
              <span class="cell-strong"><?= e($fullName($row)) ?></span>
              <br /><span class="cell-sub">
                <?= e($row['name']) ?> — <?= e($row['expires_on'] < $today ? t('par.expiredOn') : t('par.expiresOn')) ?>
                <?= e($day($row['expires_on'])) ?>
              </span>
            </span>
            <span class="tag <?= $row['expires_on'] < $today ? 'tag-danger' : '' ?>">
              <?= e($row['expires_on'] < $today ? t('par.expiredTag') : t('par.toRenewTag')) ?>
            </span>
          </li>
        <?php endforeach; ?>
        <?php if ($expiring === []): ?><li class="cell-sub"><?= e(t('par.nothingToRenew')) ?></li><?php endif; ?>
      </ul>
    </div>

    <div class="card">
      <div class="card-head"><h2><?= e(t('par.missingMandatory')) ?></h2></div>
      <p class="hint"><?= e(t('par.missingNote')) ?></p>
      <ul class="org-list">
        <?php foreach ($missing as $row): ?>
          <li class="org-item">
            <span class="cell-strong"><?= e($fullName($row)) ?></span>
            <span class="cell-sub">
              <?= e($row['name']) ?><?= $row['expires_on'] !== null ? ' ' . e(t('par.expiredParen', ['date' => $day($row['expires_on'])])) : '' ?>
            </span>
          </li>
        <?php endforeach; ?>
        <?php if ($missing === []): ?><li class="cell-sub"><?= e(t('par.everyoneUpToDate')) ?></li><?php endif; ?>
      </ul>
    </div>
  </div>
</section>
