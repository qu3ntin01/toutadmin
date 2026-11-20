<?php

use App\Core\Csrf;
use App\Core\View;

$icone = static fn (string $nom): string => View::partial('icon', ['name' => $nom, 'iconSize' => 18]);
?>

<?php if (!$ok): ?>
  <?php
    /*
     * L'éditeur n'a pas répondu. La raison est nommée : « pas réglé » se corrige
     * dans les réglages, « clé refusée » chez l'éditeur, « injoignable » se
     * réessaie. Les trois rendues comme une seule panne laisseraient
     * l'administrateur sans rien à faire.
     */
  ?>
  <div class="flash flash-error"><?= e(t('store.error.' . $reason)) ?></div>
  <p>
    <a class="btn btn-sm" href="/applications?actualiser=1"><?= e(t('store.refresh')) ?></a>
    <a class="btn btn-sm btn-outline" href="/mises-a-jour"><?= e(t('store.goSettings')) ?></a>
  </p>
<?php else: ?>

  <div class="stats-grid">
    <?php foreach ([
        ['total', $compte['total']],
        ['ouvertes', $compte['ouvertes']],
        ['allumees', $compte['allumees']],
        ['eteintes', $compte['eteintes']],
        ['aAcheter', $compte['a_acheter']],
        ['annoncees', $compte['annoncees']],
    ] as [$cle, $valeur]): ?>
      <div class="stat-card">
        <span class="stat-body">
          <span class="stat-value"><?= (int) $valeur ?></span>
          <span class="stat-label"><?= e(t('store.count.' . $cle)) ?></span>
        </span>
      </div>
    <?php endforeach; ?>
  </div>

  <p class="muted store-note">
    <?= e(t('store.honestNote')) ?>
    <a href="/applications?actualiser=1"><?= e(t('store.refresh')) ?></a>
  </p>

  <?php /* Le client vient d'acheter dans son espace client. Sans ce bouton il
           reviendrait ici, ne verrait rien de changé — le catalogue a une
           minute de répit — et ouvrirait une demande d'assistance. */ ?>
  <div class="row-actions store-recuperer">
    <form method="post" action="/applications/recuperer" class="inline-form">
      <?= Csrf::field() ?>
      <button type="submit" class="btn btn-sm btn-primary" data-fenetre="recuperer"><?= e(t('store.fetch')) ?></button>
    </form>
    <span class="muted"><?= e(t('store.fetchNote')) ?></span>
  </div>

  <?php foreach ($rayons as $index => $rayon): ?>
    <section class="tab-panel<?= $index === 0 ? ' is-active' : '' ?>" id="rayon-<?= e($rayon['cle']) ?>">
      <div class="card">
        <div class="card-head">
          <div>
            <h2><?= e($rayon['titre']) ?></h2>
            <p class="card-sub"><?= e(t('store.inShelf', ['n' => count($rayon['fiches'])])) ?></p>
          </div>
        </div>

        <div class="store-grid">
          <?php foreach ($rayon['fiches'] as $app): ?>
            <?php
              /*
               * L'état local décide de ce qu'on propose, et il n'y a que cinq
               * cas. « annoncee » et « a-venir » n'offrent rien : un bouton qui
               * n'allume rien vaut mieux absent.
               */
              $pastille = [
                  'ouverte' => 'tag-accent', 'allumee' => 'tag-success',
                  'eteinte' => '', 'a-acheter' => 'tag-accent',
                  'annoncee' => 'tag-warning', 'a-venir' => 'tag-warning',
              ][$app['etat']] ?? '';
            ?>
            <article class="store-card">
              <div class="store-head">
                <span class="store-icon"><?= $icone((string) $app['icone']) ?></span>
                <div class="store-name">
                  <span class="store-title"><?= e((string) $app['nom']) ?></span>
                  <span class="cell-sub">
                    <?php if (($app['tarif'] ?? '') === 'option'): ?>
                      <?= e(t('store.pricePerMonth', ['prix' => (int) $app['prix']])) ?>
                    <?php else: ?>
                      <?= e(t('store.included')) ?>
                    <?php endif; ?>
                  </span>
                </div>
                <span class="tag <?= $pastille ?>"><?= e(t('store.state.' . $app['etat'])) ?></span>
              </div>

              <p class="store-summary"><?= e((string) $app['resume']) ?></p>
              <?php if (trim((string) ($app['description'] ?? '')) !== ''): ?>
                <details class="disclosure">
                  <summary><?= e(t('store.moreDetail')) ?></summary>
                  <p><?= e((string) $app['description']) ?></p>
                </details>
              <?php endif; ?>

              <?php
                /*
                 * La réserve a une place fixe, jamais un repli : c'est la partie
                 * qu'on est tenté de ne pas lire, et c'est elle qui évite
                 * d'allumer par erreur.
                 */
              ?>
              <div class="banner is-warning store-caveat">
                <?= View::partial('icon', ['name' => 'shield', 'iconSize' => 16]) ?>
                <span><?= e((string) $app['reserve']) ?></span>
              </div>

              <div class="row-actions store-actions">
                <?php if ($app['etat'] === 'eteinte'): ?>
                  <form method="post" action="/applications/<?= e(rawurlencode((string) $app['cle'])) ?>/allumer"
                        class="inline-form">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn-sm btn-primary"
                            data-fenetre="allumer"
                            data-cle="<?= e((string) $app['cle']) ?>"
                            data-nom="<?= e((string) $app['nom']) ?>"
                            data-resume="<?= e((string) ($app['resume'] ?? '')) ?>"
                            data-reserve="<?= e((string) ($app['reserve'] ?? '')) ?>"><?= e(t('store.turnOn')) ?></button>
                  </form>
                <?php elseif ($app['etat'] === 'a-acheter' && $espace_client !== ''): ?>
                  <?php /* L'achat se fait dans l'espace client, qui facture.
                           Cet écran n'a ni panier ni champ de carte, et n'en
                           aura pas : une seule caisse dans la maison. */ ?>
                  <a class="btn btn-sm btn-primary" rel="noopener" target="_blank"
                     href="<?= e($espace_client) ?>/applications?app=<?= e(rawurlencode((string) $app['cle'])) ?>">
                    <?= e(t('store.buy')) ?>
                  </a>
                <?php elseif (in_array($app['etat'], ['ouverte', 'allumee'], true) && (string) $app['href'] !== ''): ?>
                  <a href="<?= e((string) $app['href']) ?>" class="btn btn-outline btn-sm"><?= e(t('common.open')) ?></a>
                <?php else: ?>
                  <span class="muted"><?= e(t('store.nothingToDo')) ?></span>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    </section>
  <?php endforeach; ?>
<?php endif; ?>

<?php /* Le script ne connaît aucun mot : ils viennent d'ici, dans la langue de
         la page. */ ?>
<script type="application/json" id="boutique-libelles"><?= json_encode([
    'titreAllumer' => t('store.window.turnOnTitle'),
    'titreRecuperer' => t('store.window.fetchTitle'),
    'enRecuperation' => t('store.window.fetching'),
    'allumer' => t('store.turnOn'),
    'ouvrir' => t('common.open'),
    'rester' => t('store.window.stay'),
    'annuler' => t('common.cancel'),
    'fermer' => t('common.close'),
    'echec' => t('store.window.failed'),
    'illisible' => t('updates.window.unreadable'),
], JSON_UNESCAPED_UNICODE) ?></script>
