<?php
/**
 * L'entreprise : ce qui s'affichera en tête de chaque écran, et la règle de
 * congés dont héritera chaque salarié créé ensuite.
 */
?>
<div class="form-grid">
  <label class="span-2">
    <span><?= e(t('install.company.name')) ?></span>
    <input type="text" name="company_name" value="<?= e((string) ($brouillon['company_name'] ?? '')) ?>" required maxlength="120" autofocus placeholder="<?= e(t('ins.companyPlaceholder')) ?>" />
    <span class="field-hint"><?= e(t('install.company.help')) ?></span>
  </label>
  <label>
    <span><?= e(t('install.company.leave')) ?></span>
    <input type="number" name="annual_leave_days" value="<?= e((string) ($brouillon['annual_leave_days'] ?? 25)) ?>" min="0" max="365" step="1" />
    <span class="field-hint"><?= e(t('install.company.leaveHelp')) ?></span>
  </label>
</div>
