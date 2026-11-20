<?php
/*
 * Le tableau kanban d'une liste.
 *
 * Le pendant de « views/partials/kanban.ejs » de l'édition Node.
 *
 * Attendu de l'appelant : « $tableau » (ce que « Kanban::tableau() » rend) et
 * « $colonnes » (les valeurs déclarées). Les classes sont celles du tableau des
 * tâches d'un projet : une seule mise en forme pour les deux, même si les deux
 * cartes ne portent pas les mêmes actions.
 */
?>
<div class="board">
  <?php foreach ($tableau['colonnes'] as $colonne): ?>
    <div class="board-column">
      <h4 class="board-title">
        <?= e(st($colonne['valeur'])) ?> <span class="tag"><?= count($colonne['cartes']) ?></span>
      </h4>
      <?php foreach ($colonne['cartes'] as $carte): ?>
        <article class="board-card">
          <a class="cell-strong" href="<?= e($carte['lien']) ?>"><?= e($carte['titre']) ?></a>
          <p class="cell-sub">
            <?= e($carte['reference']) ?><?= $carte['lignes'] === [] ? '' : ' · ' . e(implode(' · ', $carte['lignes'])) ?>
          </p>
          <?php if ($carte['etiquette'] !== null): ?>
            <span class="tag tag-danger"><?= e($carte['etiquette']) ?></span>
          <?php endif; ?>
          <?php if ($carte['alerte'] !== null): ?>
            <span class="tag tag-off"><?= e(t($carte['alerte'])) ?></span>
          <?php endif; ?>
          <div class="row-actions">
            <?php /* Un champ de choix et un bouton, et non un glisser-déposer : une
                      vue qui ne marche pas sans script n'est pas une vue, c'est une
                      option. */ ?>
            <form method="POST" action="/kanban/deplacer" class="inline-form">
              <?= \App\Core\Csrf::field() ?>
              <input type="hidden" name="objet" value="<?= e($tableau['objet']) ?>" />
              <input type="hidden" name="id" value="<?= (int) $carte['id'] ?>" />
              <select name="colonne" class="input-sm" aria-label="<?= e(t('kanban.moveTo')) ?>">
                <?php foreach ($colonnes as $valeur): ?>
                  <option value="<?= e($valeur) ?>"<?= $valeur === $colonne['valeur'] ? ' selected' : '' ?>><?= e(st($valeur)) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-sm"><?= e(t('kanban.move')) ?></button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if ($colonne['cartes'] === []): ?>
        <p class="cell-sub"><?= e(t('kanban.emptyColumn')) ?></p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($tableau['horsColonnes'] !== []): ?>
  <?php /* Une ligne dont le statut n'est plus déclaré n'est pas perdue : elle est
            montrée à part plutôt que de disparaître sans un mot. */ ?>
  <div class="board-outside">
    <h4 class="board-title">
      <?= e(t('kanban.outside')) ?> <span class="tag"><?= count($tableau['horsColonnes']) ?></span>
    </h4>
    <?php foreach ($tableau['horsColonnes'] as $carte): ?>
      <article class="board-card">
        <a class="cell-strong" href="<?= e($carte['lien']) ?>"><?= e($carte['titre']) ?></a>
        <p class="cell-sub"><?= e($carte['reference']) ?></p>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
