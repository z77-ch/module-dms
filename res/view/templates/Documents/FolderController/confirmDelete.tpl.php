<?php
/**
 * A field-less confirm (ADR-049 revision 2026-10-10): its bar stays at the bottom (`end`).
 *
 * @var \Z77\Module\Dms\Entities\Folder|null $folder
 * @var string $entityCsrf
 * @var string|null $blockReason
 * @var string $removeUrl  submit target (defaults to the legacy folder endpoint; the Drive passes its own)
 */
if ($folder === null): ?>
<div class="be-modal__body"><p>Ordner nicht gefunden.</p></div>
<?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen', 'end' => true], 'Z77\\Shared') ?>
<?php return; endif; ?>

<?php if ($blockReason !== null): ?>
<div class="be-modal__header"><h2 class="be-modal__title">Ordner löschen</h2></div>
<div class="be-modal__body">
    <div class="be-modal__alert be-modal__alert--error"><?= e($blockReason) ?></div>
</div>
<?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen', 'end' => true], 'Z77\\Shared') ?>
<?php return; endif; ?>

<form data-fetch-post="<?= e($removeUrl ?? $base . '/folder/remove') ?>">
    <input type="hidden" name="id"          value="<?= (int)$folder->getId() ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header"><h2 class="be-modal__title">Ordner löschen</h2></div>
    <div class="be-modal__body">
        <p>«<?= e($folder->getName()) ?>» wirklich löschen?</p>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Löschen', 'kind' => 'danger', 'end' => true], 'Z77\\Shared') ?>
</form>
