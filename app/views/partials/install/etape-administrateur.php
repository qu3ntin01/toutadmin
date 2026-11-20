<?php
/**
 * Le compte d'administration.
 *
 * Le seul de l'instance à sa naissance, et celui qui créera tous les autres.
 * Il n'y a pas de mot de passe par défaut : il n'y en a jamais eu, et il n'y
 * en aura pas ici non plus.
 */
?>
<div class="form-grid">
  <label>
    <span><?= e(t('install.admin.firstName')) ?></span>
    <input type="text" name="first_name" value="<?= e((string) ($brouillon['first_name'] ?? '')) ?>" maxlength="80" autofocus autocomplete="given-name" />
  </label>
  <label>
    <span><?= e(t('install.admin.lastName')) ?></span>
    <input type="text" name="last_name" value="<?= e((string) ($brouillon['last_name'] ?? '')) ?>" maxlength="80" autocomplete="family-name" />
  </label>
  <label class="span-2">
    <span><?= e(t('auth.email')) ?></span>
    <input type="email" name="email" value="<?= e((string) ($brouillon['email'] ?? '')) ?>" required autocomplete="username" placeholder="<?= e(t('common.emailPlaceholder')) ?>" />
  </label>
  <label>
    <span><?= e(t('auth.password')) ?></span>
    <input type="password" name="password" required minlength="12" autocomplete="new-password" placeholder="••••••••••••" />
  </label>
  <label>
    <span><?= e(t('profile.confirmPassword')) ?></span>
    <input type="password" name="confirm" required minlength="12" autocomplete="new-password" placeholder="••••••••••••" />
  </label>
  <p class="field-hint span-2"><?= e(t('profile.passwordHelp')) ?></p>
</div>
