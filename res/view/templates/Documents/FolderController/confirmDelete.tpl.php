<?php
/**
 * @var \Z77\Module\Dms\Entities\Folder|null $folder
 * @var string $entityCsrf
 * @var string|null $blockReason
 * @var string $removeUrl  submit target (defaults to the legacy folder endpoint; the Drive passes its own)
 */
if ($folder === null): ?>
<div class="be-modal__body"><p>Ordner nicht gefunden.</p></div>
<div class="be-modal__footer">
    <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
</div>
<?php return; endif; ?>

<?php if ($blockReason !== null): ?>
<div class="be-modal__header"><h2 class="be-modal__title">Ordner löschen</h2></div>
<div class="be-modal__body">
    <div class="be-modal__alert be-modal__alert--error"><?= e($blockReason) ?></div>
</div>
<div class="be-modal__footer">
    <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
</div>
<?php return; endif; ?>

<form data-fetch-post="<?= e($removeUrl ?? $base . '/folder/remove') ?>">
    <input type="hidden" name="id"          value="<?= (int)$folder->getId() ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header"><h2 class="be-modal__title">Ordner löschen</h2></div>
    <div class="be-modal__body">
        <p>«<?= e($folder->getName()) ?>» wirklich löschen?</p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger">Löschen</button>
    </div>
</form>
