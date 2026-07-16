<?php
/**
 * DMS Drive — middle document-list pane (R6c). Self-contained partial shared by the
 * full-page render and the in-place `replace-html` pane update. File rows carry the
 * full-reload `href` plus the `data-pane` fetch endpoint (see {@see _tree.tpl.php}).
 *
 * @var array    $files            file row view-models
 * @var int|null $selectedFolderId
 * @var int|null $selectedDoc       current selected document (context for the ⋮ action hub)
 */
$listBase = $base . '/drive/list';
$paneBase = $base . '/drive/pane';
$fileUrl  = function (string $base, ?int $folderId, int $docId): string {
    return $base . ($folderId ? '?folder=' . $folderId . '&' : '?') . 'doc=' . $docId;
};
// Current drive selection appended to the ⋮ hub URL (so its active toggle refreshes this view).
$ctx = '&folder=' . ($selectedFolderId ?? '') . '&doc=' . ($selectedDoc ?? '');
?>
<div class="dms-drive__list">
  <?php if (empty($files)): ?>
    <div class="dms-filelist__empty"><?= $selectedFolderId === null
        ? 'Wähle links einen Ordner — oder lege oben einen an. Dokumente liegen immer in einem Ordner.'
        : 'Keine Dokumente in diesem Ordner. Über «Hochladen» eine Datei ablegen.' ?></div>
  <?php else: ?>
  <?php /* Bulk selection (v1: documents only, delete + move). Modal URLs are server-built;
           drive.js only appends the checked ids (`&ids=1,2,…`). The bar itself is revealed
           by CSS `:has()` as soon as one row checkbox is checked — no JS for the reveal. */ ?>
  <div class="dms-filelist"
       data-bulk-delete-url="<?= e($base . '/drive/bulk-confirm-delete?folder=' . ($selectedFolderId ?? '')) ?>"
       data-bulk-move-url="<?= e($base . '/drive/bulk-move?folder=' . ($selectedFolderId ?? '')) ?>">
    <div class="dms-filelist-bulkbar">
      <span class="dms-filelist-bulkbar__count" data-bulk-count></span>
      <button type="button" class="dms-btn dms-btn--ghost" data-bulk-all>Alle</button>
      <button type="button" class="dms-btn dms-btn--ghost" data-bulk-none>Keine</button>
      <span class="dms-filelist-bulkbar__spacer"></span>
      <button type="button" class="dms-btn dms-btn--muted" data-bulk-action="move">Verschieben</button>
      <button type="button" class="dms-btn dms-btn--muted" data-bulk-action="delete">Löschen</button>
    </div>
    <?php foreach ($files as $f): ?>
    <div class="dms-file<?= $f['isActive'] ? ' dms-file--active' : '' ?><?= $f['active'] ? '' : ' dms-file--inactive' ?>">
      <input type="checkbox" class="dms-file__select" data-bulk-check
             value="<?= (int) $f['id'] ?>" aria-label="«<?= e($f['displayName']) ?>» auswählen">
      <span class="dms-file__thumb dms-file__thumb--<?= e($f['thumbClass']) ?>">
        <?php if ($f['thumbUrl'] !== null): ?>
          <img src="<?= e($f['thumbUrl']) ?>" alt="" width="40" height="40">
        <?php else: ?>
          <svg class="dms-icon"><use href="#<?= e($f['icon']) ?>"/></svg>
        <?php endif; ?>
      </span>
      <button type="button" class="dms-rowmenu" data-modal="<?= $base ?>/drive/actions?type=document&id=<?= (int) $f['id'] . $ctx ?>" title="Aktionen">⋮</button>
      <a class="dms-file__main"
         href="<?= e($fileUrl($listBase, $selectedFolderId, $f['id'])) ?>"
         data-pane="<?= e($fileUrl($paneBase, $selectedFolderId, $f['id'])) ?>">
        <div class="dms-file__name"><?= e($f['displayName']) ?></div>
        <div class="dms-file__meta">
          <span><?= e($f['ext']) ?></span><span class="dms-file__meta-sep">·</span><span><?= e($f['size']) ?></span>
          <?php if ($f['dimensions'] !== null): ?><span class="dms-file__meta-sep">·</span><span><?= e($f['dimensions']) ?></span><?php endif; ?>
        </div>
      </a>
      <span class="dms-file__badge dms-file__badge--<?= e($f['deliveryMode']) ?>"><?= e($f['deliveryMode']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
