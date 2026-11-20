<?php
$csrf = \App\Core\Csrf::field();
$jour = static fn (?string $v): string => $v === null || $v === '' ? '—' : \App\Core\Dates::day($v);
$instant = static fn (?string $v): string => $v === null || $v === '' ? '—' : \App\Core\Dates::moment($v);
$l = $etat['licence'] ?? null;
?>
<section class="tab-panel is-active" id="etat">
  <?php if (!$distribuee): ?>
    <?php /* Une instance dont le code ne porte pas la clé publique de l'éditeur
             n'est pas une instance vendue : développement, démonstration, test. */ ?>
    <div class="flash flash-info"><?= e(t('licence.notDistributed')) ?></div>
  <?php endif; ?>

  <div class="stats-grid">
    <?php foreach ([
        [$palier, t('licence.stateLabel')],
        [$l === null ? '—' : $jour($etat['abonnement'] ?? ''), t('licence.until')],
        [
          ((int) $etat['plafond_membres'] > 0)
            ? $effectif . ' / ' . (int) $etat['plafond_membres']
            : (string) $effectif,
          (int) $etat['plafond_membres'] > 0 ? t('licence.members') : t('licence.membersNoCap'),
        ],
        // Ce que l'instance coûte par mois, pour que l'administrateur le voie
        // sans aller le chercher ailleurs. Rien n'est facturé ici.
        [$prixMensuel === null ? '—' : $prixMensuel . ' €', t('licence.monthly')],
    ] as [$valeur, $libelle]): ?>
      <div class="stat-card">
        <span class="stat-body">
          <span class="stat-value"><?= e($valeur) ?></span>
          <span class="stat-label"><?= e($libelle) ?></span>
        </span>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($dernierRefus !== ''): ?>
    <?php /* Le motif vient du service de licences, nommé plutôt que masqué : sans lui,
             l'administrateur ne saurait pas s'il doit payer, appeler, ou faire
             délier une ancienne machine. */ ?>
    <div class="flash flash-error">
      <strong><?= e(t('licence.refusedTitle')) ?></strong>
      <?= e($refusLibelle) ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-head">
      <div>
        <h2><?= e(t('licence.detailsTitle')) ?></h2>
        <p class="card-sub"><?= e(t('licence.detailsSub')) ?></p>
      </div>
    </div>

    <dl class="summary-list">
      <div><dt><?= e(t('licence.client')) ?></dt><dd><?= e($l['client'] ?? '—') ?></dd></div>
      <div><dt><?= e(t('licence.reference')) ?></dt><dd><code><?= e($l['id'] ?? '—') ?></code></dd></div>
      <div><dt><?= e(t('licence.offer')) ?></dt><dd><?= e($l['offre'] ?? '—') ?></dd></div>
      <div>
        <dt><?= e(t('licence.howBilled')) ?></dt>
        <dd><?= e(t('licence.howBilledBody', ['offerts' => (string) $gratuiteSieges, 'prix' => (string) $prixSalarie])) ?></dd>
      </div>
      <div><dt><?= e(t('licence.mode')) ?></dt>
        <dd><?= e($modeLibelle) ?></dd></div>
      <div><dt><?= e(t('licence.leaseUntil')) ?></dt><dd><?= e($jour($etat['expire'] ?? '')) ?></dd></div>
      <div><dt><?= e(t('licence.lastCheck')) ?></dt><dd><?= e($instant($derniereVerif)) ?></dd></div>
      <div>
        <dt><?= e(t('licence.fingerprint')) ?></dt>
        <?php /* Ce que le client communique à l'éditeur pour un jeton hors ligne,
                 ou pour faire délier son ancien serveur. */ ?>
        <dd><code><?= e($empreinte) ?></code></dd>
      </div>
    </dl>
  </div>

  <div class="card">
    <div class="card-head">
      <div>
        <h2><?= e(t('licence.renewTitle')) ?></h2>
        <p class="card-sub"><?= e(t('licence.renewSub')) ?></p>
      </div>
    </div>

    <?php if (!$editeurConfigure): ?>
      <p class="card-sub"><?= e(t('licence.noEditor')) ?></p>
      <a href="/mises-a-jour" class="btn"><?= e(t('updates.title')) ?></a>
    <?php else: ?>
      <form method="post" action="/licence/verifier">
        <?= $csrf ?>
        <button type="submit" class="btn btn-primary"><?= e(t('licence.renewNow')) ?></button>
      </form>
    <?php endif; ?>

    <form method="post" action="/licence/adresse" class="form-grid">
      <?= $csrf ?>
      <label class="span-2">
        <span><?= e(t('licence.publicAddress')) ?></span>
        <input name="adresse" value="<?= e($adresse) ?>" placeholder="https://portail.entreprise.fr">
        <span class="hint"><?= e(t('licence.publicAddressHint')) ?></span>
      </label>
      <div class="span-2">
        <button type="submit" class="btn"><?= e(t('common.save')) ?></button>
      </div>
    </form>
  </div>
</section>

<section class="tab-panel" id="horsligne">
  <div class="card">
    <div class="card-head">
      <div>
        <h2><?= e(t('licence.offlineTitle')) ?></h2>
        <p class="card-sub"><?= e(t('licence.offlineSub')) ?></p>
      </div>
    </div>

    <div class="flash flash-info">
      <?= e(t('licence.offlineGive')) ?>
      <code><?= e($empreinte) ?></code>
    </div>

    <form method="post" action="/licence/poser" class="form-grid">
      <?= $csrf ?>
      <label class="span-2">
        <span><?= e(t('licence.offlineToken')) ?></span>
        <textarea name="jeton" rows="6" placeholder="eyJ2IjoxLCJpZCI6…"></textarea>
        <span class="hint"><?= e(t('licence.offlineTokenHint')) ?></span>
      </label>
      <div class="span-2">
        <button type="submit" class="btn btn-primary"><?= e(t('licence.offlineApply')) ?></button>
      </div>
    </form>
  </div>
</section>

<?php /* ---------- Écrire à l'éditeur ---------- */ ?>
<?php /*
  L'assistance de l'éditeur, depuis l'instance.
  Rien ne part à l'affichage : le relevé est celui du dernier bouton pressé, avec
  sa date. Cet écran est la sortie de secours quand la licence coince, et un
  panneau injoignable ne doit pas le faire attendre quinze secondes.
*/ ?>
<section class="tab-panel" id="assistance">
  <div class="card">
    <div class="card-head"><h2><?= e(t('licence.assist.title')) ?></h2></div>

    <?php if (!$assistConfigure): ?>
      <p class="hint"><?= e(t('licence.assist.notConfigured')) ?></p>
    <?php else: ?>
      <p class="cell-sub"><?= e(t('licence.assist.note')) ?></p>

      <form method="POST" action="/licence/assistance/ouvrir" class="form-grid">
        <?= \App\Core\Csrf::field() ?>
        <label class="span-2"><span><?= e(t('common.subject')) ?></span>
          <input type="text" name="sujet" maxlength="200" required
                 placeholder="<?= e(t('licence.assist.subjectPlaceholder')) ?>" />
        </label>
        <label><span><?= e(t('licence.assist.kind')) ?></span>
          <select name="type">
            <?php foreach ($assistSujets as $sujet): ?>
              <option value="<?= e($sujet) ?>"<?= $sujet === 'technique' ? ' selected' : '' ?>>
                <?= e(t('licence.assist.kind.' . $sujet)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('licence.assist.body')) ?></span>
          <textarea name="corps" rows="5" maxlength="5000" required></textarea>
        </label>
        <div class="span-2">
          <button type="submit" class="btn btn-primary"><?= e(t('licence.assist.send')) ?></button>
        </div>
      </form>

      <p class="hint"><?= e(t('licence.assist.whatLeaves')) ?></p>
    <?php endif; ?>
  </div>

  <?php if ($assistConfigure): ?>
    <div class="card">
      <div class="card-head">
        <h3><?= e(t('licence.assist.mine')) ?></h3>
        <?php if ($assistReleve !== null && ($assistReleve['at'] ?? null) !== null): ?>
          <span class="cell-sub"><?= e(t('licence.assist.fetchedAt', ['quand' => $instant((string) $assistReleve['at'])])) ?></span>
        <?php endif; ?>
      </div>

      <form method="POST" action="/licence/assistance/relever" class="inline-form">
        <?= \App\Core\Csrf::field() ?>
        <button type="submit" class="btn btn-outline btn-sm"><?= e(t('licence.assist.fetch')) ?></button>
      </form>

      <?php if ($assistReleve === null): ?>
        <p class="hint"><?= e(t('licence.assist.neverFetched')) ?></p>
      <?php elseif ($assistReleve['demandes'] === []): ?>
        <p class="hint"><?= e(t('licence.assist.none')) ?></p>
      <?php else: ?>
        <?php foreach ($assistReleve['demandes'] as $demande): ?>
          <?php $etat = (string) ($demande['etat'] ?? 'ouverte'); ?>
          <div class="card">
            <div class="card-head">
              <h4><?= e((string) ($demande['sujet'] ?? '')) ?></h4>
              <span class="tag <?= $etat === 'ouverte' ? 'tag-danger' : ($etat === 'repondue' ? 'tag-success' : '') ?>">
                <?= e(t('licence.assist.state.' . $etat)) ?>
              </span>
            </div>
            <p class="cell-sub">
              <?= e((string) ($demande['reference'] ?? '')) ?> ·
              <?= e(t('licence.assist.kind.' . (string) ($demande['sujet_type'] ?? 'question'))) ?>
            </p>

            <?php foreach (($demande['messages'] ?? []) as $message): ?>
              <div class="assist-card">
                <p class="cell-sub">
                  <strong><?= e((string) $message['auteur'] === 'editeur'
                      ? t('licence.assist.fromEditor') : t('licence.assist.fromUs')) ?></strong>
                  — <?= e($instant((string) ($message['ecrit_le'] ?? ''))) ?>
                </p>
                <p><?= nl2br(e((string) ($message['corps'] ?? ''))) ?></p>
              </div>
            <?php endforeach; ?>

            <?php if ($etat !== 'fermee'): ?>
              <form method="POST" action="/licence/assistance/repondre" class="form-grid">
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="reference" value="<?= e((string) ($demande['reference'] ?? '')) ?>" />
                <label class="span-2"><span><?= e(t('licence.assist.reply')) ?></span>
                  <textarea name="corps" rows="3" maxlength="5000" required></textarea>
                </label>
                <div class="span-2">
                  <button type="submit" class="btn btn-sm"><?= e(t('licence.assist.sendReply')) ?></button>
                </div>
              </form>
            <?php else: ?>
              <p class="hint"><?= e(t('licence.assist.closedNote')) ?></p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
