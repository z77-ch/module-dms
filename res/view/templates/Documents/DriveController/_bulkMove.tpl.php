<?php
/**
 * DMS Drive — bulk move modal (v1 bulk actions). The selection travels as ONE hidden
 * CSV field; the POST goes back to `drive/bulk-move` ({@see DriveControllerTrait::bulkMoveAction}).
 *
 * @var int                              $count
 * @var string                           $countLabel    "N Dokumente" / "1 Dokument"
 * @var list<string>                     $names         display names of the selection
 * @var string                           $idsCsv        "33,35,…"
 * @var int|null                         $currentFolder
 * @var list<array{id:int, label:string}> $folderOptions
 * @var string                           $postUrl
 */
?>
<form data-fetch-post="<?= e($postUrl) ?>">
    <input type="hidden" name="ids" value="<?= e($idsCsv) ?>">
    <div class="be-modal__header"><h2 class="be-modal__title"><?= e($countLabel) ?> verschieben</h2></div>
    <div class="be-modal__body">
        <ul style="max-height:10rem;overflow-y:auto;margin:0 0 .8rem;padding:0 0 0 1.1rem;font-size:.85rem">
            <?php foreach ($names as $name): ?>
            <li style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($name) ?></li>
            <?php endforeach; ?>
        </ul>
        <div class="be-form__grid" style="grid-template-columns:1fr">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Zielordner</label>
                <select name="folder_id" required>
                    <?php foreach ($folderOptions as $opt): ?>
                    <option value="<?= (int) $opt['id'] ?>"<?= $currentFolder === $opt['id'] ? ' selected' : '' ?>><?= e($opt['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary">Verschieben</button>
    </div>
</form>
