<?php
/**
 * DMS Drive — upload modal (upload-UX rebuild, 2026-07-02). Rendered into the backend
 * popup by {@see DriveController::addAction}; `documents/upload.js` binds
 * `[data-z77-upload-form]` and runs a PER-FILE, sequential XHR queue against the Drive's
 * upload endpoint (one request per file — progress bar + cancel per file, no redirect).
 *
 * Two size caps drive the client-side gate (P1.3):
 *  - `data-max-bytes`       = transport cap (`serverMaxBytes`), applies to EVERY file;
 *  - `data-max-image-bytes` = memory cap (`effectiveMaxUploadBytes`), applies to `image/*`
 *    only (GD must decode the pixels; a video is moved and bounded by the transport cap).
 * The "max. N MB" hint shows the transport cap. The server's own size + `fitsMemory` guards
 * remain the authoritative second line (a client can bypass JS).
 *
 * @var int|null $folderId       target folder pre-selected (the folder open in the Drive)
 * @var list<array{id:int, label:string}> $folderOptions
 * @var int      $maxBytes       transport cap (bytes) — all files, drives the hint
 * @var int      $maxImageBytes  memory cap (bytes) — images only
 */
$maxMb = (int) floor($maxBytes / (1024 * 1024));
?>
<form data-z77-upload-form
      action="<?= $base ?>/drive/upload"
      data-max-bytes="<?= (int) $maxBytes ?>"
      data-max-image-bytes="<?= (int) $maxImageBytes ?>">
    <div class="be-modal__header">
        <h2 class="be-modal__title">Dateien hochladen</h2>
    </div>
    <div class="be-modal__body">
        <div class="be-form__grid" style="grid-template-columns:1fr">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Datei(en) — max. <?= $maxMb ?> MB pro Datei</label>
                <input type="file" name="files[]" multiple required>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Zielordner</label>
                <?php if ($folderOptions === []): ?>
                <p style="font-size:.85rem;color:var(--be-muted,#94a3b8);margin:0">Es gibt noch keinen Ordner — bitte zuerst einen Ordner anlegen. Dokumente werden immer in einem Ordner abgelegt.</p>
                <?php else: ?>
                <select name="folder_id" required>
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
        <!-- class="dms" anchors the --dms-* tokens here: this list renders inside the backend
             popup (a page-level <dialog>), OUTSIDE the .dms fragment, so the tokens would not
             otherwise inherit and the rows (incl. the progress fill) would render unstyled. -->
        <ul class="dms dms-upload-list" data-z77-upload-list hidden></ul>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--primary" data-z77-upload-submit<?= $folderOptions === [] ? ' disabled' : '' ?>>Hochladen</button>
    </div>
</form>
