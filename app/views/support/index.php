<?php
$csrf = \App\Core\Csrf::field();
$fullName = static fn (array $p, string $prefix = ''): string =>
    trim(($p[$prefix . 'first_name'] ?? '') . ' ' . ($p[$prefix . 'last_name'] ?? ''));
$statusClass = static fn (string $s): string => match ($s) {
    'Résolu', 'Clos' => 'status-on',
    'En attente' => 'status-off',
    default => 'status-warning',
};
?>
<?php if ($isAgent): ?>
  <section class="stats-grid">
    <?php foreach ([
        [$summary['open'], t('prj.openProjects')],
        [$summary['unassigned'], t('sup.noAssignee')],
        [$summary['overdue'], t('sup.overdue')],
        [$summary['closedThisMonth'], t('sup.closedThisMonth')],
    ] as [$value, $label]): ?>
      <div class="stat-card">
        <span class="stat-body">
          <span class="stat-value"><?= (int) $value ?></span>
          <span class="stat-label"><?= e($label) ?></span>
        </span>
      </div>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<section class="tab-panel is-active" id="nouveau">
  <div class="card">
    <h2><?= e(t('sup.tabOpen')) ?></h2>
    <p class="muted"><?= e(t('sup.priorityNote')) ?></p>
    <form method="POST" action="/support/tickets" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.subject')) ?></span><input type="text" name="subject" required maxlength="200" /></label>
      <label><span><?= e(t('common.category')) ?></span>
        <select name="category" required>
          <?php foreach ($categories as $category): ?>
            <option value="<?= e($category) ?>"><?= e($category) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($isAgent): ?>
        <label><span><?= e(t('sup.clientConcerned')) ?></span>
          <select name="partner_id">
            <option value="">—</option>
            <?php foreach ($partners as $partner): ?>
              <option value="<?= (int) $partner['id'] ?>"><?= e((string) $partner['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
      <label><span><?= e(t('common.priority')) ?></span>
        <select name="priority" required>
          <?php foreach ($priorities as $priority): ?>
            <option value="<?= e($priority) ?>"<?= $priority === 'Normale' ? ' selected' : '' ?>>
              <?= e($priority) ?> — <?= (int) $responseHours[$priority] ?> h
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($isAgent): ?>
        <label><span><?= e(t('common.origin')) ?></span>
          <select name="origin">
            <?php foreach ($origins as $origin): ?>
              <option value="<?= e($origin) ?>"><?= e($origin) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>
      <label class="span-2"><span><?= e(t('messages.body')) ?></span><textarea name="body" rows="4" maxlength="5000"></textarea></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.open')) ?></button>
    </form>
  </div>
</section>

<section class="tab-panel" id="tickets">
  <div class="card">
    <div class="org-head">
      <h2><?= e($isAgent ? t('nav.support') : t('nav.support')) ?></h2>
      <form method="GET" action="/support" class="form-grid">
        <label><span><?= e(t('common.status')) ?></span>
          <select name="statut">
            <option value=""><?= e(t('sup.all')) ?></option>
            <?php foreach ($statuses as $status): ?>
              <option value="<?= e($status) ?>"<?= $filters['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('common.category')) ?></span>
          <select name="categorie">
            <option value=""><?= e(t('hr.filterAll')) ?></option>
            <?php foreach ($categories as $category): ?>
              <option value="<?= e($category) ?>"<?= $filters['category'] === $category ? ' selected' : '' ?>><?= e($category) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php if ($isAgent && $groups !== []): ?>
          <label><span><?= e(t('sup.grp.filterGroup')) ?></span>
            <select name="groupe">
              <option value=""><?= e(t('sup.grp.allGroups')) ?></option>
              <?php foreach ($groups as $groupe): ?>
                <option value="<?= (int) $groupe['id'] ?>"<?= (int) $filters['groupId'] === (int) $groupe['id'] ? ' selected' : '' ?>>
                  <?= e((string) $groupe['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
        <label class="check-row span-2">
          <input type="checkbox" name="tous" value="1" <?= $filters['openOnly'] ? '' : 'checked' ?> />
          <span><?= e(t('sup.includeClosed')) ?></span>
        </label>
        <div class="span-2">
          <button type="submit" class="btn btn-outline btn-block"><?= e(t('common.filter')) ?></button>
        </div>
      </form>
    </div>

    <?php /* Composer les groupes est une décision d'instance : le lien ne
             s'affiche que pour qui peut l'ouvrir, plutôt que de mener à un
             refus. */ ?>
    <?php if ($canManageGroups): ?>
      <div class="row-actions">
        <a class="btn btn-sm btn-outline" href="/support/groupes"><?= e(t('sup.grp.manage')) ?></a>
      </div>
    <?php endif; ?>
    <?php if ($myGroups !== []): ?>
      <p class="hint">
        <?= e(t('sup.grp.members')) ?> :
        <?php foreach ($myGroups as $groupe): ?><span class="tag"><?= e((string) $groupe['name']) ?></span><?php endforeach; ?>
      </p>
    <?php endif; ?>

    <?php if ($isAgent): ?>
      <?php /* Deux vues de la même liste, et le même filtrage : le lien garde la
                requête en cours pour que basculer ne perde pas le filtre posé. */ ?>
      <div class="filters">
        <a class="tag <?= $vue === 'liste' ? 'tag-accent' : '' ?>" href="<?= e($lienVue('liste')) ?>"><?= e(t('kanban.viewList')) ?></a>
        <a class="tag <?= $vue === 'kanban' ? 'tag-accent' : '' ?>" href="<?= e($lienVue('kanban')) ?>"><?= e(t('kanban.viewBoard')) ?></a>
      </div>
    <?php endif; ?>
    <?php if ($tickets === []): ?>
      <div class="empty-state"><?= e(t('sup.noTicket')) ?></div>
    <?php elseif ($vue === 'kanban'): ?>
      <?= \App\Core\View::partial('kanban', [
        'tableau' => $tableau,
        'colonnes' => $kanbanColonnes,
      ]) ?>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <?php if ($isAgent): ?>
              <th class="masse-check">
                <input type="checkbox" data-masse-all="masse-ticket" hidden
                       aria-label="<?= e(t('masse.checkAll')) ?>" />
              </th>
            <?php endif; ?>
            <th><?= e(t('common.reference')) ?></th>
            <th><?= e(t('common.subject')) ?></th>
            <th><?= e(t('common.category')) ?></th>
            <th><?= e(t('common.priority')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tickets as $ticket): ?>
            <tr>
              <?php if ($isAgent): ?>
                <td class="masse-check">
                  <input type="checkbox" name="ids" value="<?= (int) $ticket['id'] ?>" form="masse-ticket"
                         aria-label="<?= e((string) $ticket['reference']) ?>" />
                </td>
              <?php endif; ?>
              <td>
                <?= e((string) $ticket['reference']) ?>
                <?php if ($ticket['overdue']): ?><br /><span class="tag tag-off"><?= e(t('sup.overdue')) ?></span><?php endif; ?>
              </td>
              <td>
                <a href="/support/tickets/<?= (int) $ticket['id'] ?>"><?= e($ticket['subject']) ?></a>
                <br /><span class="cell-sub"><?= e($fullName($ticket, 'requester_')) ?></span>
              </td>
              <td><?= e($ticket['category']) ?></td>
              <td><?= e($ticket['priority']) ?></td>
              <td><span class="status <?= e($statusClass($ticket['status'])) ?>"><?= e(st($ticket['status'])) ?></span></td>
              <td><a class="btn btn-sm" href="/support/tickets/<?= (int) $ticket['id'] ?>"><?= e(t('common.open')) ?></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if ($isAgent): ?>
        <?= \App\Core\View::partial('actions-masse', [
          'masseObjet' => 'ticket',
          'masseActions' => [
            ['cle' => 'statut', 'champ' => 'statut', 'options' => array_map(
              static fn (string $v): array => ['valeur' => $v, 'libelle' => st($v)],
              \App\Modules\Support::STATUSES,
            )],
            ['cle' => 'priorite', 'champ' => 'priorite', 'options' => array_map(
              static fn (string $v): array => ['valeur' => $v, 'libelle' => st($v)],
              \App\Modules\Support::PRIORITIES,
            )],
            ['cle' => 'affecter', 'champ' => 'utilisateur', 'options' => array_merge(
              [['valeur' => 0, 'libelle' => t('masse.nobody')]],
              array_map(
                static fn (array $p): array => [
                  'valeur' => (int) $p['id'],
                  'libelle' => trim(($p['last_name'] ?? '') . ' ' . ($p['first_name'] ?? '')),
                ],
                $people,
              ),
            )],
          ],
        ]) ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<?php if ($isAgent): ?>
  <section class="tab-panel" id="a-moi">
    <div class="card">
      <h2><?= e(t('sup.tabAssigned')) ?></h2>
      <?php if ($assigned === []): ?>
        <div class="empty-state"><?= e(t('sup.noTicket')) ?></div>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr><th><?= e(t('common.reference')) ?></th><th><?= e(t('common.subject')) ?></th><th><?= e(t('common.status')) ?></th></tr>
          </thead>
          <tbody>
            <?php foreach ($assigned as $ticket): ?>
              <tr>
                <td><?= e((string) $ticket['reference']) ?></td>
                <td><a href="/support/tickets/<?= (int) $ticket['id'] ?>"><?= e($ticket['subject']) ?></a></td>
                <td><span class="status <?= e($statusClass($ticket['status'])) ?>"><?= e(st($ticket['status'])) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </section>
<?php endif; ?>
