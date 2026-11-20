<?php
$csrf = \App\Core\Csrf::field();
$fullName = static fn (array $p): string => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
?>

<?php /* ---------- Qui a accès à quoi ---------- */ ?>
<?php /*
  L'écran commence par la carte, et non par les groupes : la question d'une
  administration n'est pas « quels groupes existent » mais « qui lit les
  demandes informatiques ». Les groupes sont le moyen ; ceci est la réponse.
*/ ?>
<section class="tab-panel is-active" id="acces">
  <div class="card">
    <div class="card-head"><h2><?= e(t('sup.grp.mapTitle')) ?></h2></div>
    <p class="hint"><?= e(t('sup.grp.mapNote')) ?></p>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th><?= e(t('common.category')) ?></th>
            <th><?= e(t('sup.grp.byRole')) ?></th>
            <th><?= e(t('sup.grp.byGroup')) ?></th>
            <th><?= e(t('sup.grp.named')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($access as $ligne): ?>
            <tr>
              <td>
                <strong><?= e((string) $ligne['category']) ?></strong>
                <?php if ($ligne['restricted']): ?>
                  <span class="tag tag-danger"><?= e(t('sup.grp.sensitive')) ?></span>
                <?php endif; ?>
              </td>
              <td class="cell-sub">
                <?php if ($ligne['roles'] === []): ?>—<?php else: ?>
                  <?= e(implode(', ', array_map(static fn (string $r): string => t('sup.grp.role.' . $r), $ligne['roles']))) ?>
                <?php endif; ?>
              </td>
              <td class="cell-sub">
                <?php if ($ligne['groups'] === []): ?>
                  <?= e(t('sup.grp.noGroup')) ?>
                <?php else: ?>
                  <?php foreach ($ligne['groups'] as $groupe): ?>
                    <span class="tag"><?= e((string) $groupe['name']) ?></span>
                  <?php endforeach; ?>
                <?php endif; ?>
              </td>
              <td class="cell-sub"><?= (int) $ligne['named'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p class="hint"><?= e(t('sup.grp.mapAdd')) ?></p>
  </div>
</section>

<?php /* ---------- Les groupes ---------- */ ?>
<section class="tab-panel" id="groupes">
  <?php if ($groups === []): ?>
    <div class="card"><p class="hint"><?= e(t('sup.grp.none')) ?></p></div>
  <?php endif; ?>

  <?php foreach ($groups as $groupe): ?>
    <div class="card">
      <div class="card-head">
        <h3><?= e((string) $groupe['name']) ?></h3>
        <span class="tag"><?= e(t('sup.grp.memberCount', ['n' => count($groupe['members'])])) ?></span>
      </div>

      <?php if ((string) $groupe['description'] !== ''): ?>
        <p class="cell-sub"><?= e((string) $groupe['description']) ?></p>
      <?php endif; ?>

      <form method="POST" action="/support/groupes/<?= (int) $groupe['id'] ?>/modifier" class="form-grid">
        <?= $csrf ?>
        <label><span><?= e(t('common.name')) ?></span>
          <input type="text" name="name" maxlength="120" required value="<?= e((string) $groupe['name']) ?>" />
        </label>
        <label><span><?= e(t('common.description')) ?></span>
          <input type="text" name="description" maxlength="500" value="<?= e((string) $groupe['description']) ?>" />
        </label>
        <div class="span-2 form-actions">
          <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
        </div>
      </form>

      <h4><?= e(t('sup.grp.handles')) ?></h4>
      <form method="POST" action="/support/groupes/<?= (int) $groupe['id'] ?>/categories">
        <?= $csrf ?>
        <?php foreach ($categories as $categorie): ?>
          <label class="check-row">
            <input type="checkbox" name="categories[]" value="<?= e($categorie) ?>"
                   <?= in_array($categorie, $groupe['categories'], true) ? 'checked' : '' ?> />
            <span>
              <?= e($categorie) ?>
              <?php if ($categorie === $restricted): ?>
                — <strong><?= e(t('sup.grp.sensitiveWarn')) ?></strong>
              <?php endif; ?>
            </span>
          </label>
        <?php endforeach; ?>
        <div class="row-actions">
          <button type="submit" class="btn btn-sm btn-primary"><?= e(t('sup.grp.saveHandles')) ?></button>
        </div>
      </form>

      <h4><?= e(t('sup.grp.members')) ?></h4>
      <?php if ($groupe['members'] === []): ?>
        <p class="hint"><?= e(t('sup.grp.noMember')) ?></p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <tbody>
              <?php foreach ($groupe['members'] as $membre): ?>
                <tr>
                  <td><?= e($fullName($membre)) ?></td>
                  <td class="cell-sub"><?= e((string) $membre['email']) ?></td>
                  <td class="row-actions">
                    <form method="POST" action="/support/groupes/<?= (int) $groupe['id'] ?>/membres/retirer" class="inline-form">
                      <?= $csrf ?>
                      <input type="hidden" name="user_id" value="<?= (int) $membre['id'] ?>" />
                      <button type="submit" class="btn btn-sm btn-outline"><?= e(t('common.remove')) ?></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <form method="POST" action="/support/groupes/<?= (int) $groupe['id'] ?>/membres" class="inline-form">
        <?= $csrf ?>
        <select name="user_id" required>
          <option value=""><?= e(t('sup.grp.pickPerson')) ?></option>
          <?php foreach ($people as $personne): ?>
            <option value="<?= (int) $personne['id'] ?>"><?= e($fullName($personne)) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm"><?= e(t('sup.grp.addPerson')) ?></button>
      </form>

      <details class="danger-zone">
        <summary><?= e(t('sup.grp.deleteTitle')) ?></summary>
        <p class="hint"><?= e(t('sup.grp.deleteNote')) ?></p>
        <form method="POST" action="/support/groupes/<?= (int) $groupe['id'] ?>/supprimer">
          <?= $csrf ?>
          <button type="submit" class="btn btn-sm btn-danger" data-confirm="<?= e(t('sup.grp.deleteConfirm')) ?>">
            <?= e(t('common.delete')) ?>
          </button>
        </form>
      </details>
    </div>
  <?php endforeach; ?>
</section>

<?php /* ---------- Un nouveau groupe ---------- */ ?>
<section class="tab-panel" id="nouveau">
  <div class="card">
    <div class="card-head"><h2><?= e(t('sup.grp.newTitle')) ?></h2></div>
    <p class="hint"><?= e(t('sup.grp.newNote')) ?></p>

    <form method="POST" action="/support/groupes" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.name')) ?></span>
        <input type="text" name="name" maxlength="120" required placeholder="<?= e(t('sup.grp.namePlaceholder')) ?>" />
      </label>
      <label><span><?= e(t('common.description')) ?></span>
        <input type="text" name="description" maxlength="500" />
      </label>
      <div class="span-2">
        <p class="section-label"><?= e(t('sup.grp.handles')) ?></p>
        <?php foreach ($categories as $categorie): ?>
          <label class="check-row">
            <input type="checkbox" name="categories[]" value="<?= e($categorie) ?>" />
            <span>
              <?= e($categorie) ?>
              <?php if ($categorie === $restricted): ?>
                — <strong><?= e(t('sup.grp.sensitiveWarn')) ?></strong>
              <?php endif; ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('sup.grp.create')) ?></button></div>
    </form>
  </div>
</section>
