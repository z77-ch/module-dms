<?php
/**
 * DMS Drive — folder move modal (R6c). Rendered by {@see DriveController::folderMoveAction};
 * posts to the Drive's own folder-move endpoint. `$folderOptions` already excludes the folder
 * itself and its whole subtree (a folder cannot be moved into itself or a descendant).
 *
 * @var \Z77\Module\Dms\Entities\Folder $folder
 * @var list<array{id:int, label:string}> $folderOptions
 * @var string $entityCsrf
 */
$current = $folder->getParentId();
?>
<form data-fetch-post="<?= $base ?>/drive/folder-move">
    <input type="hidden" name="id"          value="<?= (int) $folder->getId() ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header"><h2 class="be-modal__title">Ordner verschieben</h2></div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Verschieben'], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <div class="be-form__grid" style="grid-template-columns:1fr">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Zielordner für «<?= e($folder->getName()) ?>»</label>
                <select name="folder_id">
                    <option value="0"<?= $current === null ? ' selected' : '' ?>>Wurzelbereich</option>
                    <?php foreach ($folderOptions as $opt): ?>
                    <option value="<?= (int) $opt['id'] ?>"<?= $current === $opt['id'] ? ' selected' : '' ?>><?= e($opt['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
</form>
