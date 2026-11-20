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
      <button type="submit" class="btn btn-primary"><?= e(t('admin.createAccount')) ?></button>
    </form>
  </div>

  <div class="card">
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
                  <br /><span class="cell-sub"><?= e(\App\Core\Dates::short((string) $employee['contract_end_date'])) ?></span>
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
                <?php /* Voir l'écran de la personne, pour reproduire ce qu'elle
                         décrit. Le bouton ne s'affiche que sur un compte actif :
                         emprunter un compte fermé ne montrerait rien. */ ?>
                <?php if ((int) $employee['active'] === 1): ?>
                  <form method="POST" action="/admin/employes/<?= (int) $employee['id'] ?>/emprunter"
                        data-confirm="<?= e(t('emprunt.confirm', ['nom' => trim($employee['first_name'] . ' ' . $employee['last_name'])])) ?>">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-sm"><?= e(t('emprunt.action')) ?></button>
                  </form>
                <?php endif; ?>
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

  <div class="card">
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

  <div class="card">
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

  <div class="card">
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

  <div class="card">
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

  <div class="card">
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

  <div class="card">
    <h2><?= e(t('home.news')) ?></h2>
    <?php if ($news === []): ?>
      <div class="empty-state"><?= e(t('home.noNews')) ?></div>
    <?php endif; ?>
    <?php foreach ($news as $item): ?>
      <article class="sub-card">
        <h3><?= e($item['title']) ?></h3>
        <p class="cell-sub">
          <?= e(\App\Core\Dates::moment((string) $item['created_at'])) ?> ·
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
  <?php
    /* Un droit transverse s'accorde en sachant ce qu'il ouvre : chaque bloc
       porte donc sa portée et sa nuance, comme dans l'édition Node. */
    $rights = [
      'is_hr' => [
        'title' => t('nav.hrSpace'), 'scope' => t('admin.hrScope'),
        'href' => '/rh', 'open' => t('nav.openHrSpace'),
        'appoint' => t('admin.appointHr'), 'grant' => t('admin.grantHr'),
        'all' => t('admin.allHaveHr'), 'help' => t('admin.hrHelp'),
        'members' => t('admin.hrMembers'), 'none' => t('admin.noHrYet'),
        'confirm' => t('admin.revokeHr'),
      ],
      'is_finance' => [
        'title' => t('admin.financeTitle'), 'scope' => t('admin.financeScope'),
        'href' => '/gestion', 'open' => t('admin.openFinance'),
        'appoint' => t('admin.appointManager'), 'grant' => t('admin.grantFinance'),
        'all' => t('admin.allHaveFinance'), 'help' => t('admin.financeHelp'),
        'members' => t('common.managers'), 'none' => t('admin.noManagerYet'),
        'confirm' => t('admin.revokeFinance'),
      ],
      'is_it' => [
        'title' => t('admin.itTitle'), 'scope' => t('admin.itScope'),
        'href' => '/informatique', 'open' => t('admin.openIt'),
        'appoint' => t('admin.appointIt'), 'grant' => t('admin.grantIt'),
        'all' => t('admin.allHaveIt'), 'help' => t('admin.itHelp'),
        'members' => t('admin.itMembers'), 'none' => t('admin.noItYet'),
        'confirm' => t('admin.revokeIt'),
      ],
      'is_referent' => [
        'title' => t('admin.referentTitle'), 'scope' => t('admin.referentScope'),
        'href' => '/alertes', 'open' => t('admin.openWhistleblow'),
        'appoint' => t('admin.appointReferent'), 'grant' => t('admin.grantReferent'),
        'all' => t('admin.allAreReferent'), 'help' => t('admin.referentHelp'),
        'members' => t('admin.referentMembers'), 'none' => t('admin.noReferentYet'),
        'confirm' => t('admin.revokeReferent'),
      ],
    ];
  ?>

  <?php foreach ($rights as $flag => $right): ?>
    <div class="card">
      <div class="card-head">
        <div>
          <h2><?= e($right['title']) ?></h2>
          <p class="card-sub"><?= e($right['scope']) ?></p>
        </div>
        <a href="<?= e($right['href']) ?>" class="btn btn-sm"><?= e($right['open']) ?></a>
      </div>
    </div>

    <div class="grid grid-2">
      <div class="card">
        <div class="card-head"><h3><?= e($right['appoint']) ?></h3></div>
        <?php $eligible = $flagEligible[$flag] ?? []; ?>
        <?php if ($eligible === []): ?>
          <div class="empty-state"><?= e($right['all']) ?></div>
        <?php else: ?>
          <form method="POST" action="/admin/droits/<?= e($flag) ?>" class="form-grid">
            <?= $csrf ?>
            <label class="span-2">
              <span><?= e(t('common.member')) ?></span>
              <select name="employee_id" required>
                <option value=""></option>
                <?php foreach ($eligible as $employee): ?>
                  <option value="<?= (int) $employee['id'] ?>">
                    <?= e($fullName($employee)) ?><?= $employee['grade'] ? ' — ' . e((string) $employee['grade']) : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <div class="span-2">
              <button type="submit" class="btn btn-primary btn-block"><?= e($right['grant']) ?></button>
            </div>
          </form>
        <?php endif; ?>
        <p class="hint"><?= e($right['help']) ?></p>
      </div>

      <div class="card">
        <?php $members = $flagMembers[$flag] ?? []; ?>
        <div class="card-head">
          <h3><?= e($right['members']) ?> <span class="muted">(<?= count($members) ?>)</span></h3>
        </div>
        <?php if ($members === []): ?>
          <div class="empty-state"><?= e($right['none']) ?></div>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr><th><?= e(t('common.member')) ?></th><th><?= e(t('common.grade')) ?></th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach ($members as $member): ?>
                <tr>
                  <td>
                    <div class="cell-strong"><?= e($fullName($member)) ?></div>
                    <div class="cell-sub"><?= e((string) $member['email']) ?></div>
                  </td>
                  <td><span class="tag"><?= e((string) $member['grade']) ?></span></td>
                  <td class="row-actions">
                    <form method="POST" action="/admin/droits/<?= e($flag) ?>/<?= (int) $member['id'] ?>/retirer"
                          data-confirm="<?= e($right['confirm']) ?>">
                      <?= $csrf ?>
                      <button type="submit" class="btn btn-sm btn-danger"><?= e(t('admin.revokeAccess')) ?></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
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
              <span class="org-name"><?= e(t('mod.' . $module['key'] . '.label')) ?></span>
              <span class="cell-sub"><?= e($module['href']) ?></span>
            </div>
            <span class="status <?= $module['enabled'] ? 'status-on' : 'status-off' ?>">
              <?= e($module['enabled'] ? t('common.active') : t('common.inactive')) ?>
            </span>
          </div>
          <p class="benefit-text"><?= e(t('mod.' . $module['key'] . '.what')) ?></p>
          <!-- Ce que le module ne garantit pas, dit à l'activation plutôt que
               caché dans une documentation. -->
          <div class="banner is-warning"><span><?= e(t('mod.' . $module['key'] . '.caveat')) ?></span></div>
          <div class="row-actions">
            <form method="POST" action="/admin/modules/<?= e($module['key']) ?>" class="inline-form"
                  <?= $module['enabled'] ? 'data-confirm="' . e(t('admin.disableModule')) . '"' : '' ?>>
              <?= $csrf ?>
              <?php if (!$module['enabled']): ?><input type="hidden" name="enabled" value="on" /><?php endif; ?>
              <button type="submit" class="btn btn-sm<?= $module['enabled'] ? '' : ' btn-primary' ?>"
                      data-fenetre="module"
                      data-cle="<?= e((string) $module['key']) ?>"
                      data-nom="<?= e(t('mod.' . $module['key'] . '.label')) ?>"
                      data-quoi="<?= e(t('mod.' . $module['key'] . '.what')) ?>"
                      data-reserve="<?= e(t('mod.' . $module['key'] . '.caveat')) ?>"
                      data-actif="<?= $module['enabled'] ? '1' : '0' ?>">
                <?php /* Le produit nomme l'état d'un module en allumé ou éteint : la
                         boutique affiche « éteinte » et « allumée », et le message de
                         réussite dit « allumée ». Le bouton dit donc « Allumer », et
                         non « Débloquer » — rien n'était retenu.

                         Et « Désactiver », pas la phrase de confirmation : celle-ci
                         tenait tout entière dans le bouton, question comprise, et
                         s'affichait une seconde fois à la confirmation. */ ?>
                <?= e($module['enabled'] ? t('common.disable') : t('store.turnOn')) ?>
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

  <div class="card">
    <h2><?= e(t('admin.instancePalette')) ?></h2>
    <p class="muted"><?= e(t('admin.paletteHelp')) ?></p>
    <?php
      /*
       * L'aperçu plutôt que la description seule : une palette se choisit à
       * l'œil. Le dégradé, le fond et les contours viennent de la feuille de
       * style, qui définit chaque palette une seconde fois sous
       * « [data-palette-preview] » — c'est la même déclaration que celle qui
       * habille l'instance entière, appliquée à une vignette.
       */
    ?>
    <div class="palette-grid">
      <?php foreach ($palettes as $palette): ?>
        <form method="POST" action="/admin/apparence" class="palette-card<?= $palette['active'] ? ' is-active' : '' ?>">
          <?= $csrf ?>
          <input type="hidden" name="palette" value="<?= e($palette['key']) ?>" />

          <span class="palette-preview" data-palette-preview="<?= e($palette['key']) ?>" aria-hidden="true">
            <span class="palette-nav">
              <span class="palette-mark"></span>
              <span class="palette-line"></span>
              <span class="palette-line is-short"></span>
            </span>
            <span class="palette-body">
              <span class="palette-card-sample">
                <span class="palette-line is-dark"></span>
                <span class="palette-line is-dark is-short"></span>
                <span class="palette-button"></span>
              </span>
            </span>
          </span>

          <span class="palette-name">
            <?= e($palette['label']) ?>
            <?php if ($palette['active']): ?><span class="tag tag-accent"><?= e(t('adm.inService')) ?></span><?php endif; ?>
          </span>
          <span class="palette-text"><?= e($palette['description']) ?></span>

          <button type="submit" class="btn btn-sm <?= $palette['active'] ? 'btn-outline' : 'btn-primary' ?>" <?= $palette['active'] ? 'disabled' : '' ?>>
            <?= e($palette['active'] ? t('adm.currentPalette') : t('common.apply')) ?>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="tab-panel" id="champs">
  <div class="card">
    <div class="card-head">
      <div>
        <h2><?= e(t('champs.title')) ?></h2>
        <p class="card-sub"><?= e(t('champs.lede')) ?></p>
      </div>
      <?php if ($champsOrphelines > 0): ?>
        <form method="POST" action="/admin/champs/balayer">
          <?= $csrf ?>
          <button type="submit" class="btn btn-sm"><?= e(t('champs.sweep', ['n' => $champsOrphelines])) ?></button>
        </form>
      <?php endif; ?>
    </div>

    <?php foreach ($champsObjets as $objet => $infos): ?>
      <?php $liste = $champsParObjet[$objet] ?? []; ?>
      <h3 class="section-title">
        <?= e(t($infos['libelle'])) ?>
        <?php if ($liste !== []): ?><span class="tag"><?= count($liste) ?></span><?php endif; ?>
      </h3>

      <?php if ($liste === []): ?>
        <p class="muted"><?= e(t('champs.none')) ?></p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th><?= e(t('champs.label')) ?></th>
                <th><?= e(t('champs.type')) ?></th>
                <th><?= e(t('champs.key')) ?></th>
                <th><?= e(t('champs.values')) ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($liste as $champ): ?>
                <?php $n = $champsValeurs[(int) $champ['id']] ?? 0; ?>
                <tr<?= empty($champ['actif']) ? ' class="is-off"' : '' ?>>
                  <td>
                    <strong><?= e($champ['libelle']) ?></strong>
                    <?php if (!empty($champ['obligatoire'])): ?> <span class="tag"><?= e(t('champs.required')) ?></span><?php endif; ?>
                    <?php if (empty($champ['actif'])): ?> <span class="status status-off"><?= e(t('champs.off')) ?></span><?php endif; ?>
                    <?php if ($champ['aide'] !== ''): ?><span class="cell-sub"><?= e($champ['aide']) ?></span><?php endif; ?>
                  </td>
                  <td><?= e(t(\App\Modules\CustomFields::TYPES[$champ['type']] ?? $champ['type'])) ?></td>
                  <td><code><?= e($champ['cle']) ?></code></td>
                  <td><?= (int) $n ?></td>
                  <td class="row-actions">
                    <form method="POST" action="/admin/champs/<?= (int) $champ['id'] ?>/basculer">
                      <?= $csrf ?>
                      <button type="submit" class="btn btn-sm"><?= e(empty($champ['actif']) ? t('champs.turnOn') : t('champs.turnOff')) ?></button>
                    </form>
                    <?php /* La suppression dit ce qu'elle emporte : « supprimer ce
                             champ ? » et « effacer 340 valeurs ? » ne sont pas la
                             même question. */ ?>
                    <form method="POST" action="/admin/champs/<?= (int) $champ['id'] ?>/supprimer"
                          data-confirm="<?= e($n > 0 ? t('champs.confirmWithValues', ['n' => $n]) : t('champs.confirm')) ?>">
                      <?= $csrf ?>
                      <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
                    </form>
                  </td>
                </tr>
                <tr class="row-detail">
                  <td colspan="5">
                    <details class="disclosure">
                      <summary><?= e(t('champs.edit')) ?></summary>
                      <form method="POST" action="/admin/champs/<?= (int) $champ['id'] ?>/modifier" class="form-grid">
                        <?= $csrf ?>
                        <label><span><?= e(t('champs.label')) ?></span>
                          <input name="libelle" value="<?= e($champ['libelle']) ?>" required />
                        </label>
                        <label><span><?= e(t('champs.rank')) ?></span>
                          <input name="rang" type="number" value="<?= (int) $champ['rang'] ?>" />
                        </label>
                        <?php if ($champ['type'] === 'liste'): ?>
                          <label class="span-2"><span><?= e(t('champs.choices')) ?></span>
                            <textarea name="choix" rows="4"><?= e($champ['choix']) ?></textarea>
                            <span class="hint"><?= e(t('champs.choicesHelp')) ?></span>
                          </label>
                        <?php endif; ?>
                        <label class="span-2"><span><?= e(t('champs.help')) ?></span>
                          <input name="aide" value="<?= e($champ['aide']) ?>" />
                        </label>
                        <label class="check span-2">
                          <input type="checkbox" name="obligatoire" value="1"<?= empty($champ['obligatoire']) ? '' : ' checked' ?> />
                          <span><?= e(t('champs.requiredLabel')) ?></span>
                        </label>
                        <button type="submit" class="btn btn-sm btn-primary"><?= e(t('common.save')) ?></button>
                      </form>
                    </details>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <h2><?= e(t('champs.add')) ?></h2>
    <p class="card-sub"><?= e(t('champs.addHelp')) ?></p>
    <form method="POST" action="/admin/champs" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('champs.object')) ?></span>
        <select name="objet" required>
          <?php foreach ($champsObjets as $objet => $infos): ?>
            <option value="<?= e($objet) ?>"><?= e(t($infos['libelle'])) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('champs.type')) ?></span>
        <select name="type" required>
          <?php foreach ($champsTypes as $cle => $libelle): ?>
            <option value="<?= e($cle) ?>"><?= e(t($libelle)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('champs.label')) ?></span>
        <input name="libelle" required maxlength="120" />
        <span class="hint"><?= e(t('champs.labelHelp')) ?></span>
      </label>
      <label class="span-2"><span><?= e(t('champs.choices')) ?></span>
        <textarea name="choix" rows="3"></textarea>
        <span class="hint"><?= e(t('champs.choicesHelp')) ?></span>
      </label>
      <label class="span-2"><span><?= e(t('champs.help')) ?></span>
        <input name="aide" maxlength="300" />
      </label>
      <label class="check span-2">
        <input type="checkbox" name="obligatoire" value="1" />
        <span><?= e(t('champs.requiredLabel')) ?></span>
      </label>
      <button type="submit" class="btn btn-primary"><?= e(t('champs.add')) ?></button>
    </form>
  </div>
</section>

<!-- SOCIÉTÉS -->
<section class="tab-panel" id="societes">
  <div class="card">
    <h2><?= e(t('societes.title')) ?></h2>
    <p class="muted"><?= e(t('societes.lede')) ?></p>
    <table class="table">
      <thead>
        <tr>
          <th><?= e(t('societes.name')) ?></th>
          <th><?= e(t('societes.form')) ?></th>
          <th><?= e(t('societes.registration')) ?></th>
          <th><?= e(t('societes.members')) ?></th>
          <th><?= e(t('common.status')) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($societesToutes as $societe): ?>
          <tr>
            <td>
              <strong><?= e($societe['name']) ?></strong>
              <?php if ((int) $societe['id'] === (int) $societesCourante): ?>
                <span class="tag tag-accent"><?= e(t('societes.current')) ?></span>
              <?php endif; ?>
              <?php if (($societe['address'] ?? '') !== ''): ?>
                <br /><span class="cell-sub"><?= e($societe['address']) ?></span>
              <?php endif; ?>
            </td>
            <td><?= e((string) ($societe['legal_form'] ?: '—')) ?></td>
            <td><?= e((string) ($societe['registration'] ?: '—')) ?></td>
            <td><?= e(t('societes.memberCount', ['n' => count($societesMembres[(int) $societe['id']] ?? [])])) ?></td>
            <td>
              <span class="status <?= (int) $societe['active'] === 1 ? 'status-on' : 'status-off' ?>">
                <?= e((int) $societe['active'] === 1 ? t('common.active') : t('common.inactive')) ?>
              </span>
            </td>
            <td>
              <form method="POST" action="/societes/<?= (int) $societe['id'] ?>/etat" class="inline-form">
                <?= $csrf ?>
                <button type="submit" class="btn btn-sm">
                  <?= e((int) $societe['active'] === 1 ? t('societes.close') : t('societes.reopen')) ?>
                </button>
              </form>
            </td>
          </tr>
          <tr class="row-detail">
            <td colspan="6">
              <details class="disclosure">
                <summary><?= e(t('common.edit')) ?> — <?= e(t('societes.members')) ?></summary>
                <form method="POST" action="/societes/<?= (int) $societe['id'] ?>/modifier" class="form-grid">
                  <?= $csrf ?>
                  <label><span><?= e(t('societes.name')) ?></span><input name="nom" value="<?= e($societe['name']) ?>" required maxlength="160" /></label>
                  <label><span><?= e(t('societes.form')) ?></span><input name="forme" value="<?= e((string) $societe['legal_form']) ?>" maxlength="60" /></label>
                  <label><span><?= e(t('societes.registration')) ?></span><input name="immatriculation" value="<?= e((string) $societe['registration']) ?>" maxlength="40" /></label>
                  <label><span><?= e(t('societes.address')) ?></span><input name="adresse" value="<?= e((string) $societe['address']) ?>" maxlength="300" /></label>
                  <div class="span-2"><button type="submit" class="btn btn-sm btn-primary"><?= e(t('common.save')) ?></button></div>
                </form>

                <h4 class="section-label"><?= e(t('societes.members')) ?></h4>
                <ul class="org-list">
                  <?php foreach (($societesMembres[(int) $societe['id']] ?? []) as $membre): ?>
                    <li class="org-item">
                      <span>
                        <?= e(trim($membre['first_name'] . ' ' . $membre['last_name'])) ?>
                        <?php if ($membre['role'] === 'admin'): ?><span class="tag"><?= e(t('common.adminRole')) ?></span><?php endif; ?>
                      </span>
                      <form method="POST" action="/societes/<?= (int) $societe['id'] ?>/membres/retirer" class="inline-form">
                        <?= $csrf ?>
                        <input type="hidden" name="user_id" value="<?= (int) $membre['id'] ?>" />
                        <button type="submit" class="btn btn-sm"><?= e(t('societes.detach')) ?></button>
                      </form>
                    </li>
                  <?php endforeach; ?>
                </ul>
                <form method="POST" action="/societes/<?= (int) $societe['id'] ?>/membres" class="form-grid">
                  <?= $csrf ?>
                  <label class="span-2"><span><?= e(t('societes.attach')) ?></span>
                    <select name="user_id" required>
                      <option value=""><?= e(t('champs.pick')) ?></option>
                      <?php foreach ($societesRattachables as $personne): ?>
                        <option value="<?= (int) $personne['id'] ?>"><?= e(trim($personne['first_name'] . ' ' . $personne['last_name'])) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <div class="span-2"><button type="submit" class="btn btn-sm"><?= e(t('societes.attach')) ?></button></div>
                </form>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h2><?= e(t('societes.add')) ?></h2>
    <form method="POST" action="/societes" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('societes.name')) ?></span><input name="nom" required maxlength="160" /></label>
      <label><span><?= e(t('societes.form')) ?></span><input name="forme" maxlength="60" placeholder="SAS, SARL…" /></label>
      <label><span><?= e(t('societes.registration')) ?></span><input name="immatriculation" maxlength="40" /></label>
      <label><span><?= e(t('societes.address')) ?></span><input name="adresse" maxlength="300" /></label>
      <div class="span-2"><button type="submit" class="btn btn-primary"><?= e(t('societes.add')) ?></button></div>
    </form>
  </div>
</section>

<!-- CORBEILLE -->
<?php /* Les dates sont stockées en UTC sans suffixe : il faut le dire au
         navigateur, sinon il les lit dans son propre fuseau. */ ?>
<section class="tab-panel" id="corbeille">
  <div class="card">
    <h2><?= e(t('corbeille.title')) ?></h2>
    <p class="muted"><?= e(t('corbeille.lede')) ?></p>
    <?php /* Le libellé, l'auteur et la date — jamais la charge. Ce qui est dans
             la corbeille vient de fiches dont l'accès était réglé ; l'afficher
             ici le rendrait lisible à qui tient la console. */ ?>
    <?php if ($corbeilleLignes === []): ?>
      <div class="empty-state"><?= e(t('corbeille.none')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('corbeille.object')) ?></th>
            <th><?= e(t('corbeille.label')) ?></th>
            <th><?= e(t('corbeille.deletedBy')) ?></th>
            <th><?= e(t('corbeille.deletedOn')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($corbeilleLignes as $jetee): ?>
            <tr>
              <td><?= e(t($corbeilleObjets[$jetee['objet']]['libelle'] ?? $jetee['objet'])) ?></td>
              <td><strong><?= e($jetee['libelle']) ?></strong></td>
              <td><?= e((string) ($jetee['auteur'] ?? '—')) ?></td>
              <td><?= e(\App\Core\Dates::moment((string) $jetee['supprime_le'])) ?></td>
              <td>
                <form method="POST" action="/admin/corbeille/<?= (int) $jetee['id'] ?>/restaurer" class="inline-form">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm"><?= e(t('corbeille.restore')) ?></button>
                </form>
                <form method="POST" action="/admin/corbeille/<?= (int) $jetee['id'] ?>/supprimer" class="inline-form"
                      data-confirm="<?= e(t('corbeille.confirmDrop')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm btn-danger"><?= e(t('corbeille.drop')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>


<?php /* Le script ne connaît aucun mot : ils viennent d'ici, dans la langue de
         la page. */ ?>
<script type="application/json" id="modules-libelles"><?= json_encode([
    'titreActiver' => t('admin.window.enableTitle'),
    'titreRetirer' => t('admin.window.disableTitle'),
    'donneesGardees' => t('admin.window.dataKept'),
    'activer' => t('store.turnOn'),
    'retirer' => t('common.disable'),
    'ouvrir' => t('common.open'),
    'rester' => t('store.window.stay'),
    'annuler' => t('common.cancel'),
    'fermer' => t('common.close'),
    'echec' => t('store.window.failed'),
    'illisible' => t('updates.window.unreadable'),
], JSON_UNESCAPED_UNICODE) ?></script>
