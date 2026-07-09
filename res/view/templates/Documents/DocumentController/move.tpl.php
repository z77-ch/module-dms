<?php
/**
 * @var \Z77\Module\Dms\Entities\Document $doc
 * @var list<array{id:int, label:string}> $folderOptions
 * @var string $entityCsrf
 * @var string $postUrl  submit target (defaults to the legacy documents endpoint; the Drive passes its own)
 */
$current = $doc->getFolderId();
?>
<form data-fetch-post="<?= e($postUrl ?? $base . '/document/move') ?>">
    <input type="hidden" name="id"          value="<?= (int)$doc->getId() ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header"><h2 class="be-modal__title">Dokument verschieben</h2></div>
    <div class="be-modal__body">
        <div class="be-form__grid" style="grid-template-columns:1fr">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Zielordner für «<?= e($doc->getDisplayName()) ?>»</label>
                <select name="folder_id" required>
                    <?php foreach ($folderOptions as $opt): ?>
                    <option value="<?= (int)$opt['id'] ?>"<?= $current === $opt['id'] ? ' selected' : '' ?>><?= e($opt['label']) ?></option>
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
