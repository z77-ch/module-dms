<?php
/**
 * @var \Z77\Module\Dms\Entities\Folder $folder
 * @var \Z77\Module\Dms\Entities\Folder|null $parent
 * @var string $entityCsrf
 */
$isNew     = $folder->getId() === null;
$hasParent = isset($parent) && $parent !== null;
?>
<form data-fetch-post>
    <?php if (!$isNew): ?>
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <?php endif; ?>
    <?php if ($isNew && $hasParent): ?>
    <input type="hidden" name="parent_id" value="<?= e($parent->getId()) ?>">
    <?php endif; ?>
    <div class="be-modal__header">
        <h2 class="be-modal__title">
            <?php if (!$isNew): ?>
                Ordner umbenennen
            <?php elseif ($hasParent): ?>
                Neuer Unterordner in «<?= e($parent->getName()) ?>»
            <?php else: ?>
                Neuer Ordner
            <?php endif; ?>
        </h2>
    </div>
    <div class="be-modal__body">
        <div class="be-form__grid" style="grid-template-columns:1fr">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Ordnername</label>
                <input type="text" name="name" value="<?= e($folder->getName()) ?>" required autocomplete="off"
                       placeholder="z.B. Rechnungen" autofocus>
            </div>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Speichern</button>
    </div>
</form>
