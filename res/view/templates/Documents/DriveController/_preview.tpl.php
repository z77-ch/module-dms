<?php
/**
 * DMS Drive — right preview pane (R6c). Self-contained partial shared by the full-page
 * render and the in-place `replace-html` pane update. Contains no folder/file nav links
 * (only the external open/download actions), so it needs no `data-pane` wiring.
 *
 * @var array|null $preview  selected document preview view-model (null = empty state)
 */
?>
<aside class="dms-drive__preview">
  <div class="dms-preview">
    <?php if ($preview === null): ?>
      <div class="dms-preview__empty">
        <svg class="dms-icon"><use href="#i-eye"/></svg>
        <span>Ein Dokument wählen für die Vorschau.</span>
      </div>
    <?php else: ?>
      <div class="dms-preview__media">
        <?php if ($preview['imageUrl'] !== null): ?>
          <img src="<?= e($preview['imageUrl']) ?>" alt="<?= e($preview['displayName']) ?>">
        <?php else: ?>
          <svg class="dms-icon"><use href="#<?= e($preview['icon']) ?>"/></svg>
        <?php endif; ?>
      </div>
      <div class="dms-preview__body">
        <div class="dms-preview__name"><?= e($preview['displayName']) ?></div>
        <div class="dms-preview__meta">
          <span class="dms-preview__meta-key">Typ</span><span class="dms-preview__meta-value"><?= e($preview['kind']) ?> · <?= e($preview['ext']) ?></span>
          <span class="dms-preview__meta-key">Grösse</span><span class="dms-preview__meta-value"><?= e($preview['size']) ?></span>
          <?php if ($preview['dimensions'] !== null): ?>
          <span class="dms-preview__meta-key">Dimension</span><span class="dms-preview__meta-value"><?= e($preview['dimensions']) ?></span>
          <?php endif; ?>
          <span class="dms-preview__meta-key">Modus</span><span class="dms-preview__meta-value"><?= e($preview['deliveryMode']) ?></span>
          <span class="dms-preview__meta-key">Aktiv</span><span class="dms-preview__meta-value"><?= $preview['active'] ? 'ja' : 'nein' ?></span>
        </div>
        <div class="dms-preview__actions">
          <a class="dms-btn dms-btn--ghost" href="<?= e($preview['previewUrl']) ?>" target="_blank" rel="noopener"><svg class="dms-icon"><use href="#i-eye"/></svg> Öffnen</a>
          <a class="dms-btn dms-btn--ghost" href="<?= e($preview['downloadUrl']) ?>"><svg class="dms-icon"><use href="#i-download"/></svg> Download</a>
          <button type="button" class="dms-btn dms-btn--ghost" data-modal="<?= $base ?>/drive/edit?id=<?= (int)$preview['id'] ?>"><svg class="dms-icon"><use href="#i-edit"/></svg> Bearbeiten</button>
          <button type="button" class="dms-btn dms-btn--ghost" data-modal="<?= $base ?>/drive/move?id=<?= (int)$preview['id'] ?>"><svg class="dms-icon"><use href="#i-move"/></svg> Verschieben</button>
          <button type="button" class="dms-btn dms-btn--ghost" data-modal="<?= $base ?>/drive/confirm-delete?id=<?= (int)$preview['id'] ?>"><svg class="dms-icon"><use href="#i-trash"/></svg> Löschen</button>
        </div>
      </div>
    <?php endif; ?>
  </div>
</aside>
