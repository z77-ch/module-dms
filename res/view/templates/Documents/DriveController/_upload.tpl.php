<?php
/**
 * DMS Drive — upload modal. Since 2026-10-09 it renders THE upload component
 * (`Z77\Shared` `partials/upload`, UPLOAD-001) instead of the Drive's own 396-line
 * uploader: one request per file, three in flight, a row per file with its own bar and its
 * own outcome, drag & drop, and the component's `ask` mode for «überschreiben?» — which is
 * exactly what this screen always did, now shared with every other upload.
 *
 * What stays the Drive's own:
 *   - the target FOLDER and «Original ausliefern» ride as `$fields` INSIDE the component's
 *     form, so they travel with every file of the run;
 *   - the video POSTER frame comes from `documents/upload-poster.js`
 *     (`data-upload-extra="dms-poster"`) — a canvas grab the kernel has no business knowing;
 *   - the two caps: the transport cap for every file, a smaller one for `image/*` because
 *     GD decodes the pixels ({@see \Z77\Shared\Upload\UploadPolicy::$maxBytesPer}).
 *
 * @var \Z77\Shared\Upload\UploadPolicy $uploadPolicy
 * @var int|null $folderId       target folder pre-selected (the folder open in the Drive)
 * @var list<array{id:int, label:string}> $folderOptions
 * @var string|null $targetDelivery effective delivery of the pre-selected folder
 */
ob_start();
?>
<div class="be-form__grid" style="grid-template-columns:1fr">
    <div class="be-form__field" data-z77-field-wrapper>
        <label for="dms-upload-folder">Zielordner</label>
        <?php if ($folderOptions === []): ?>
        <p style="font-size:.85rem;color:var(--be-muted,#94a3b8);margin:0">Es gibt noch keinen Ordner — bitte zuerst einen Ordner anlegen. Dokumente werden immer in einem Ordner abgelegt.</p>
        <?php else: ?>
        <select id="dms-upload-folder" name="folder_id" required>
            <?php foreach ($folderOptions as $opt): ?>
            <option value="<?= (int) $opt['id'] ?>"<?= $folderId === $opt['id'] ? ' selected' : '' ?>><?= e($opt['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
    </div>
    <div class="be-form__field" data-z77-field-wrapper>
        <label style="display:flex;align-items:center;gap:.5rem;font-weight:normal">
            <input type="checkbox" name="show_original" value="1">
            Originalbild unverändert ausliefern (nur Thumbnail generieren)
        </label>
    </div>
</div>
<?php
$fields = (string) ob_get_clean();
?>
<div class="be-modal__header">
    <h2 class="be-modal__title">Dateien hochladen</h2>
</div>
<div class="be-modal__body">
    <?php if ($targetDelivery === 'public'): ?>
    <?php // The consequence, stated before the file is chosen: inside a public partition
          // every upload is world-readable via /media (ADR-017 inheritance). ?>
    <p style="font-size:.78rem;color:#f59e0b;margin:0 0 .75rem">
        <strong>Zielordner liefert öffentlich aus.</strong> Was hier landet, ist ohne
        Anmeldung über <code>/media</code> erreichbar — auch für Suchmaschinen.
    </p>
    <?php endif; ?>

    <?= $this->partial('partials/upload', [
        'policy' => $uploadPolicy,
        'fields' => $fields,
        'extra'  => 'dms-poster',
    ], 'Z77\Shared') ?>
</div>
<div class="be-modal__footer">
    <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
</div>
