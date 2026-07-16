<?php
/**
 * DMS Drive — bulk delete confirmation modal (v1 bulk actions). Soft delete: the
 * selection goes to the trash. Posts to `drive/bulk-remove`
 * ({@see DriveControllerTrait::bulkRemoveAction}); `folder` keeps the pane refresh
 * in the current folder.
 *
 * @var int          $count
 * @var string       $countLabel  "N Dokumente" / "1 Dokument"
 * @var list<string> $names       display names of the selection
 * @var string       $idsCsv      "33,35,…"
 * @var int|null     $folderId
 * @var string       $removeUrl
 */
?>
<form data-fetch-post="<?= e($removeUrl) ?>">
    <input type="hidden" name="ids"    value="<?= e($idsCsv) ?>">
    <input type="hidden" name="folder" value="<?= (int) $folderId ?>">
    <div class="be-modal__header"><h2 class="be-modal__title"><?= e($countLabel) ?> löschen</h2></div>
    <div class="be-modal__body">
        <p><?= e($countLabel) ?> in den Papierkorb legen?</p>
        <ul style="max-height:10rem;overflow-y:auto;margin:0 0 .8rem;padding:0 0 0 1.1rem;font-size:.85rem">
            <?php foreach ($names as $name): ?>
            <li style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($name) ?></li>
            <?php endforeach; ?>
        </ul>
        <p style="font-size:.8rem;color:var(--be-muted,#94a3b8)">Die Dokumente wandern in den Papierkorb (wiederherstellbar); die Dateien bleiben erhalten. Endgültiges Löschen erfolgt dort.</p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger">Löschen</button>
    </div>
</form>
