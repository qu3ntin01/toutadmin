<?php
/* Console d'administration : un onglet par domaine de décision. */
$csrf = \App\Core\Csrf::field();
$fullName = static fn (array $p): string => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
?>
<section class="stats-grid">
  <?php foreach ([
      [$stats['employeeCount'], t('admin.staffMembers')],
      [$stats['toolCount'], t('admin.catalogueTools')],
      [$stats['assignedCount'], t('admin.activeAssignments')],
      [$stats['availableCount'], t('admin.unassignedTools')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= (int) $value ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<!-- ---------------------------------------------------------- Personnel -->
<section class="tab-panel is-active" id="personnel">
  <div class="card">
    <h2><?= e(t('admin.addMember')) ?></h2>
    <p class="muted"><?= e(t('admin.newMemberHelp', ['days' => $annualLeaveDays])) ?></p>
    <form method="POST" action="/admin/employes" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('install.admin.firstName')) ?></span><input type="text" name="first_name" required maxlength="100" /></label>
      <label><span><?= e(t('install.admin.lastName')) ?></span><input type="text" name="last_name" required maxlength="100" /></label>
      <label><span><?= e(t('auth.email')) ?></span><input type="email" name="email" required maxlength="254" /></label>
      <label><span><?= e(t('common.grade')) ?></span>
        <select name="grade" required>
          <option value=""></option>
          <?php foreach ($grades as $grade): ?><option value="<?= e($grade) ?>"><?= e($grade) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.contract')) ?></span>
        <select name="contract_type" required>
          <option value=""></option>
          <?php foreach ($contractTypes as $type): ?><option value="<?= e($type) ?>"><?= e($type) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('admin.contractEnd')) ?></span><input type="date" name="contract_end_date" /></label>
      <label><span><?= e(t('common.department')) ?></span>
        <select name="department_id">
          <option value=""></option>
          <?php foreach ($departments as $department): ?>
            <option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.team')) ?></span>
        <select name="team_id">
          <option value=""></option>
          <?php foreach ($teams as $team): ?>
            <option value="<?= (int) $team['id'] ?>"><?= e($team['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('emp.dailyRate')) ?></span><input type="text" name="daily_rate" inputmode="decimal" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('admin.addMember')) ?></button>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('admin.staffMembers')) ?></h2>
    <?php if ($employees === []): ?>
      <div class="empty-state"><?= e(t('admin.noMemberYet')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.name')) ?></th>
            <th><?= e(t('common.grade')) ?></th>
            <th><?= e(t('common.contract')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($employees as $employee): ?>
            <tr>
              <td>
                <strong><?= e($fullName($employee)) ?></strong><br />
                <span class="cell-sub"><?= e($employee['email']) ?></span>
              </td>
              <td><?= e((string) $employee['grade']) ?></td>
              <td>
                <?= e((string) $employee['contract_type']) ?>
                <?php if (!empty($employee['contract_end_date'])): ?>
                  <br /><span class="cell-sub"><?= e($employee['contract_end_date']) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <span class="tag <?= (int) $employee['active'] === 1 ? 'tag-ok' : 'tag-off' ?>">
                  <?= e((int) $employee['active'] === 1 ? t('common.active') : t('common.inactive')) ?>
                </span>
              </td>
              <td class="row-actions">
                <a class="btn btn-sm" href="/admin/employes/<?= (int) $employee['id'] ?>/modifier"><?= e(t('common.edit')) ?></a>
                <form method="POST" action="/admin/employes/<?= (int) $employee['id'] ?>/statut">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm">
                    <?= e((int) $employee['active'] === 1 ? t('common.deactivate') : t('common.activate')) ?>
                  </button>
                </form>
                <form method="POST" action="/admin/employes/<?= (int) $employee['id'] ?>/reinitialiser">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm"><?= e(t('admin.resetPassword')) ?></button>
                </form>
                <form method="POST" action="/admin/employes/<?= (int) $employee['id'] ?>/supprimer"
                      data-confirm="<?= e(t('admin.deleteMember')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- ------------------------------------------------------------- Outils -->
<section class="tab-panel" id="outils">
  <div class="card">
    <h2><?= e(t('admin.addTool')) ?></h2>
    <form method="POST" action="/admin/outils" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.name')) ?></span><input type="text" name="name" required maxlength="150" /></label>
      <label><span><?= e(t('common.category')) ?></span><input type="text" name="category" maxlength="100" /></label>
      <label><span><?= e(t('common.reference')) ?></span><input type="text" name="reference" maxlength="100" /></label>
      <label><span><?= e(t('admin.loginUrl')) ?></span><input type="url" name="login_url" maxlength="500" placeholder="https://" /></label>
      <label class="span-2"><span><?= e(t('common.description')) ?></span><textarea name="description" rows="3" maxlength="1000"></textarea></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.add')) ?></button>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('nav.tools')) ?></h2>
    <?php if ($tools === []): ?>
      <div class="empty-state"><?= e(t('admin.noToolYet')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr><th><?= e(t('common.name')) ?></th><th><?= e(t('common.category')) ?></th><th><?= e(t('nav.assignments')) ?></th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($tools as $tool): ?>
            <tr>
              <td>
                <strong><?= e($tool['name']) ?></strong>
                <?php if (!empty($tool['reference'])): ?><br /><span class="cell-sub"><?= e($tool['reference']) ?></span><?php endif; ?>
              </td>
              <td><?= e((string) $tool['category']) ?></td>
              <td><?= count($employeesByTool[(int) $tool['id']] ?? []) ?></td>
              <td class="row-actions">
                <a class="btn btn-sm" href="/admin/outils/<?= (int) $tool['id'] ?>/modifier"><?= e(t('common.edit')) ?></a>
                <form method="POST" action="/admin/outils/<?= (int) $tool['id'] ?>/supprimer"
                      data-confirm="<?= e(t('admin.deleteMember')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- ------------------------------------------------------- Affectations -->
<section class="tab-panel" id="affectations">
  <div class="card">
    <h2><?= e(t('admin.assignTool')) ?></h2>
    <p class="muted"><?= e(t('admin.identifierHelp')) ?></p>
    <form method="POST" action="/admin/affectations" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.member')) ?></span>
        <select name="employee_id" required>
          <option value=""></option>
          <?php foreach ($employees as $employee): ?>
            <option value="<?= (int) $employee['id'] ?>"><?= e($fullName($employee)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('admin.tool')) ?></span>
        <select name="tool_id" required>
          <option value=""></option>
          <?php foreach ($tools as $tool): ?>
            <option value="<?= (int) $tool['id'] ?>"><?= e($tool['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('admin.loginEmail')) ?></span><input type="text" name="username" maxlength="150" /></label>
      <label><span><?= e(t('common.note')) ?></span><input type="text" name="note" maxlength="300" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.assign')) ?></button>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('nav.assignments')) ?></h2>
    <?php $rows = []; foreach ($toolsByEmployee as $employeeId => $list) { foreach ($list as $row) { $rows[] = $row; } } ?>
    <?php if ($rows === []): ?>
      <div class="empty-state"><?= e(t('admin.noAssignment')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr><th><?= e(t('common.member')) ?></th><th><?= e(t('admin.tool')) ?></th><th><?= e(t('admin.loginEmail')) ?></th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <?php
              $employee = $employeesById[(int) $row['employee_id']] ?? null;
              $tool = $toolsById[(int) $row['tool_id']] ?? null;
            ?>
            <tr>
              <td><?= e($employee === null ? '—' : $fullName($employee)) ?></td>
              <td><?= e($tool === null ? '—' : $tool['name']) ?></td>
              <td><?= e((string) $row['username']) ?></td>
              <td class="row-actions">
                <form method="POST" action="/admin/affectations/<?= (int) $row['id'] ?>/supprimer">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm"><?= e(t('common.remove')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- -------------------------------------------------------- Organisation -->
<section class="tab-panel" id="organisation">
  <div class="grid grid-2">
    <div class="card">
      <h2><?= e(t('org.newDepartment')) ?></h2>
      <form method="POST" action="/admin/services" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('common.name')) ?></span><input type="text" name="name" required maxlength="120" /></label>
        <label class="span-2"><span><?= e(t('common.description')) ?></span><input type="text" name="description" maxlength="500" /></label>
        <button type="submit" class="btn btn-primary"><?= e(t('common.add')) ?></button>
      </form>
    </div>
    <div class="card">
      <h2><?= e(t('org.newTeam')) ?></h2>
      <form method="POST" action="/admin/equipes" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('common.name')) ?></span><input type="text" name="name" required maxlength="120" /></label>
        <label class="span-2"><span><?= e(t('common.department')) ?></span>
          <select name="department_id">
            <option value=""></option>
            <?php foreach ($departments as $department): ?>
              <option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit" class="btn btn-primary"><?= e(t('common.add')) ?></button>
      </form>
    </div>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('org.departments')) ?></h2>
    <?php if ($departments === []): ?>
      <div class="empty-state"><?= e(t('org.noDepartment')) ?></div>
    <?php endif; ?>
    <?php foreach ($departments as $department): ?>
      <div class="sub-card">
        <form method="POST" action="/admin/services/<?= (int) $department['id'] ?>/modifier" class="inline-form">
          <?= $csrf ?>
          <input type="text" name="name" value="<?= e($department['name']) ?>" maxlength="120" required />
          <input type="text" name="description" value="<?= e((string) $department['description']) ?>" maxlength="500" />
          <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
        </form>
        <form method="POST" action="/admin/services/<?= (int) $department['id'] ?>/supprimer"
              data-confirm="<?= e(t('admin.deleteMember')) ?>">
          <?= $csrf ?>
          <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
        </form>

        <p class="muted">
          <?= e(t('org.management')) ?> :
          <?php $managers = $departmentManagers[(int) $department['id']] ?? []; ?>
          <?= $managers === [] ? e(t('org.noManager')) : '' ?>
        </p>
        <?php foreach ($managers as $manager): ?>
          <form method="POST" action="/admin/encadrement/retirer" class="inline-form">
            <?= $csrf ?>
            <input type="hidden" name="scope" value="department" />
            <input type="hidden" name="scope_id" value="<?= (int) $department['id'] ?>" />
            <input type="hidden" name="user_id" value="<?= (int) $manager['id'] ?>" />
            <span><?= e($fullName($manager)) ?></span>
            <button type="submit" class="btn btn-sm"><?= e(t('common.remove')) ?></button>
          </form>
        <?php endforeach; ?>

        <form method="POST" action="/admin/encadrement" class="inline-form">
          <?= $csrf ?>
          <input type="hidden" name="scope" value="department" />
          <input type="hidden" name="scope_id" value="<?= (int) $department['id'] ?>" />
          <select name="user_id" required>
            <option value=""></option>
            <?php foreach ($employees as $employee): ?>
              <option value="<?= (int) $employee['id'] ?>"><?= e($fullName($employee)) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm"><?= e(t('org.addManager')) ?></button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('org.teams')) ?></h2>
    <?php if ($teams === []): ?>
      <div class="empty-state"><?= e(t('org.noTeam')) ?></div>
    <?php endif; ?>
    <?php foreach ($teams as $team): ?>
      <div class="sub-card">
        <form method="POST" action="/admin/equipes/<?= (int) $team['id'] ?>/modifier" class="inline-form">
          <?= $csrf ?>
          <input type="text" name="name" value="<?= e($team['name']) ?>" maxlength="120" required />
          <select name="department_id">
            <option value=""></option>
            <?php foreach ($departments as $department): ?>
              <option value="<?= (int) $department['id'] ?>"
                <?= (int) ($team['department_id'] ?? 0) === (int) $department['id'] ? ' selected' : '' ?>>
                <?= e($department['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
        </form>
        <form method="POST" action="/admin/equipes/<?= (int) $team['id'] ?>/supprimer"
              data-confirm="<?= e(t('admin.deleteMember')) ?>">
          <?= $csrf ?>
          <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
        </form>

        <?php foreach (($teamManagers[(int) $team['id']] ?? []) as $manager): ?>
          <form method="POST" action="/admin/encadrement/retirer" class="inline-form">
            <?= $csrf ?>
            <input type="hidden" name="scope" value="team" />
            <input type="hidden" name="scope_id" value="<?= (int) $team['id'] ?>" />
            <input type="hidden" name="user_id" value="<?= (int) $manager['id'] ?>" />
            <span><?= e($fullName($manager)) ?></span>
            <button type="submit" class="btn btn-sm"><?= e(t('common.remove')) ?></button>
          </form>
        <?php endforeach; ?>

        <form method="POST" action="/admin/encadrement" class="inline-form">
          <?= $csrf ?>
          <input type="hidden" name="scope" value="team" />
          <input type="hidden" name="scope_id" value="<?= (int) $team['id'] ?>" />
          <select name="user_id" required>
            <option value=""></option>
            <?php foreach ($employees as $employee): ?>
              <option value="<?= (int) $employee['id'] ?>"><?= e($fullName($employee)) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm"><?= e(t('org.addManager')) ?></button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('org.assignment')) ?></h2>
    <p class="muted"><?= e(t('admin.orgHelp')) ?></p>
    <table class="table">
      <thead>
        <tr><th><?= e(t('common.member')) ?></th><th><?= e(t('org.assignment')) ?></th><th><?= e(t('nav.directory')) ?></th></tr>
      </thead>
      <tbody>
        <?php foreach ($employees as $employee): ?>
          <tr>
            <td><?= e($fullName($employee)) ?></td>
            <td>
              <form method="POST" action="/admin/employes/<?= (int) $employee['id'] ?>/rattachement" class="inline-form">
                <?= $csrf ?>
                <select name="department_id">
                  <option value=""></option>
                  <?php foreach ($departments as $department): ?>
                    <option value="<?= (int) $department['id'] ?>"
                      <?= (int) ($employee['department_id'] ?? 0) === (int) $department['id'] ? ' selected' : '' ?>>
                      <?= e($department['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <select name="team_id">
                  <option value=""></option>
                  <?php foreach ($teams as $team): ?>
                    <option value="<?= (int) $team['id'] ?>"
                      <?= (int) ($employee['team_id'] ?? 0) === (int) $team['id'] ? ' selected' : '' ?>>
                      <?= e($team['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
              </form>
            </td>
            <td>
              <form method="POST" action="/admin/employes/<?= (int) $employee['id'] ?>/annuaire">
                <?= $csrf ?>
                <button type="submit" class="btn btn-sm">
                  <?= e((int) $employee['directory_hidden'] === 1 ? t('admin.visible') : t('admin.hidden')) ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- --------------------------------------------------------- Actualités -->
<section class="tab-panel" id="actualites">
  <div class="card">
    <h2><?= e(t('team.publishNews')) ?></h2>
    <form method="POST" action="/admin/actualites" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('team.newsTitle')) ?></span><input type="text" name="title" required maxlength="150" /></label>
      <label class="span-2"><span><?= e(t('team.newsBody')) ?></span><textarea name="body" rows="4" maxlength="2000"></textarea></label>
      <label><span><?= e(t('common.recipients')) ?></span>
        <select name="target">
          <option value="company"><?= e(t('home.companyNews')) ?></option>
          <?php foreach ($departments as $department): ?>
            <option value="department:<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option>
          <?php endforeach; ?>
          <?php foreach ($teams as $team): ?>
            <option value="team:<?= (int) $team['id'] ?>"><?= e($team['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn btn-primary"><?= e(t('team.publishNews')) ?></button>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('home.news')) ?></h2>
    <?php if ($news === []): ?>
      <div class="empty-state"><?= e(t('home.noNews')) ?></div>
    <?php endif; ?>
    <?php foreach ($news as $item): ?>
      <article class="sub-card">
        <h3><?= e($item['title']) ?></h3>
        <p class="cell-sub">
          <?= e($item['created_at']) ?> ·
          <?= e($item['scope'] === 'company' ? t('home.companyNews') : (string) $item['scope_name']) ?>
        </p>
        <p><?= nl2br(e((string) $item['body'])) ?></p>
        <form method="POST" action="/admin/actualites/<?= (int) $item['id'] ?>/supprimer">
          <?= $csrf ?>
          <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
        </form>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<!-- -------------------------------------------------------------- Droits -->
<section class="tab-panel" id="droits">
  <?php foreach (\App\Modules\Users::ROLE_FLAGS as $flag => $label): ?>
    <div class="card mt-l">
      <h2><?= e(ucfirst($label)) ?></h2>
      <?php $members = $flagMembers[$flag] ?? []; ?>
      <?php if ($members === []): ?>
        <div class="empty-state"><?= e(t('common.none')) ?></div>
      <?php else: ?>
        <ul class="people">
          <?php foreach ($members as $member): ?>
            <li>
              <?= e($fullName($member)) ?>
              <form method="POST" action="/admin/droits/<?= e($flag) ?>/<?= (int) $member['id'] ?>/retirer" class="inline-form">
                <?= $csrf ?>
                <button type="submit" class="btn btn-sm"><?= e(t('common.remove')) ?></button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <form method="POST" action="/admin/droits/<?= e($flag) ?>" class="inline-form">
        <?= $csrf ?>
        <select name="employee_id" required>
          <option value=""></option>
          <?php foreach (($flagEligible[$flag] ?? []) as $employee): ?>
            <option value="<?= (int) $employee['id'] ?>"><?= e($fullName($employee)) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-primary"><?= e(t('common.add')) ?></button>
      </form>
    </div>
  <?php endforeach; ?>
</section>

<!-- ------------------------------------------------------------- Modules -->
<section class="tab-panel" id="modules">
  <div class="card">
    <div class="card-head">
      <div>
        <h2><?= e(t('nav.modules')) ?>
          <span class="muted">(<?= count(array_filter($modules, static fn (array $m): bool => $m['enabled'])) ?>/<?= count($modules) ?>)</span>
        </h2>
        <p class="card-sub"><?= e(t('admin.modulesHelp')) ?></p>
      </div>
    </div>

    <ul class="org-list">
      <?php foreach ($modules as $module): ?>
        <li class="org-item">
          <div class="org-head">
            <div>
              <span class="org-name"><?= e($module['label']) ?></span>
              <span class="cell-sub"><?= e($module['href']) ?></span>
            </div>
            <span class="status <?= $module['enabled'] ? 'status-on' : 'status-off' ?>">
              <?= e($module['enabled'] ? t('common.active') : t('common.inactive')) ?>
            </span>
          </div>
          <p class="benefit-text"><?= e($module['description']) ?></p>
          <!-- Ce que le module ne garantit pas, dit à l'activation plutôt que
               caché dans une documentation. -->
          <div class="banner is-warning"><span><?= e($module['caveat']) ?></span></div>
          <div class="row-actions">
            <form method="POST" action="/admin/modules/<?= e($module['key']) ?>" class="inline-form"
                  <?= $module['enabled'] ? 'data-confirm="' . e(t('admin.disableModule')) . '"' : '' ?>>
              <?= $csrf ?>
              <?php if (!$module['enabled']): ?><input type="hidden" name="enabled" value="on" /><?php endif; ?>
              <button type="submit" class="btn btn-sm<?= $module['enabled'] ? '' : ' btn-primary' ?>">
                <?= e($module['enabled'] ? t('admin.disableModule') : t('common.unlock')) ?>
              </button>
            </form>
            <?php if ($module['enabled']): ?>
              <a href="<?= e($module['href']) ?>" class="btn btn-outline btn-sm"><?= e(t('common.open')) ?></a>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<!-- ----------------------------------------------------------- Apparence -->
<section class="tab-panel" id="apparence">
  <div class="card">
    <h2><?= e(t('admin.title')) ?></h2>
    <form method="POST" action="/admin/entreprise" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('install.company.name')) ?></span>
        <input type="text" name="company_name" value="<?= e($companyName) ?>" maxlength="120" required />
      </label>
      <label><span><?= e(t('install.company.leave')) ?></span>
        <input type="number" name="annual_leave_days" value="<?= (int) $annualLeaveDays ?>" min="0" max="60" />
      </label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('admin.instancePalette')) ?></h2>
    <p class="muted"><?= e(t('admin.paletteHelp')) ?></p>
    <div class="grid grid-3">
      <?php foreach ($palettes as $palette): ?>
        <form method="POST" action="/admin/apparence" class="palette<?= $palette['active'] ? ' is-active' : '' ?>">
          <?= $csrf ?>
          <input type="hidden" name="palette" value="<?= e($palette['key']) ?>" />
          <h3><?= e($palette['label']) ?></h3>
          <p class="muted"><?= e($palette['description']) ?></p>
          <button type="submit" class="btn btn-sm<?= $palette['active'] ? '' : ' btn-primary' ?>" <?= $palette['active'] ? 'disabled' : '' ?>>
            <?= e($palette['active'] ? t('common.active') : t('common.choose')) ?>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
</section>
