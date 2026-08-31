<?php
/**
 * DMS Drive — toolbar breadcrumb pane (R6c). Self-contained partial shared by the
 * full-page render and the in-place `replace-html` pane update. Crumb links carry the
 * full-reload `href` plus the `data-pane` fetch endpoint (see {@see _tree.tpl.php}).
 *
 * @var bool     $rootActive       whether the mount root (breadcrumb home) is selected
 * @var string   $rootLabel        breadcrumb-home label ('drive' in the full view, else the mount-root name)
 * @var int|null $rootFolderId     the mount-root folder id (null = full view / true tree top)
 * @var array    $crumbs           [[id,name], …] from the mount root to the selected folder
 * @var int|null $selectedFolderId  the folder currently open (drives the upload target)
 */
$rootLabel    = $rootLabel    ?? 'drive';
$rootFolderId = $rootFolderId ?? null;
$listBase = $base . '/drive/list';
$paneBase = $base . '/drive/pane';
$listUrl  = fn(?int $id) => $listBase . ($id ? '?folder=' . $id : '');
$paneUrl  = fn(?int $id) => $paneBase . ($id ? '?folder=' . $id : '');
// Server-built action URLs for the folder currently open. The toolbar buttons (in the static
// shell) read these off the breadcrumb pane, so after an in-place pane refresh they always
// target the current folder — no URL assembly in JS. Folder-add targets the current folder as
// parent (root when none selected); rename/move/delete only exist for a real selected folder.
$addUrl       = $base . '/drive/add'         . ($selectedFolderId ? '?folder=' . $selectedFolderId : '');
$folderAddUrl = $base . '/drive/folder-add'  . ($selectedFolderId ? '?parent=' . $selectedFolderId : '');
// Trash carries the CURRENT selection so a restore/purge can refresh the panes in
// place without resetting the open folder (same mechanism as the ⋮ action hub).
$trashQuery = http_build_query(array_filter([
    'folder' => $selectedFolderId,
    'doc'    => $selectedDoc ?? null,
]));
$trashUrl = $base . '/drive/trash' . ($trashQuery !== '' ? '?' . $trashQuery : '');
// The folder tools (edit/move/delete) moved OUT of this pane into the shell's hc2
// toolbar (ADR-033: the crumb line carries position and state, the toolbar operates).
// Their server-built URLs travel as data attributes here — empty when no folder is
// selected — so the static toolbar buttons stay current across pane swaps, exactly
// like data-add-url does for the upload button.
$folderEditUrl   = $selectedFolderId !== null ? $base . '/drive/folder-edit?id=' . (int)$selectedFolderId : '';
$folderMoveUrl   = $selectedFolderId !== null ? $base . '/drive/folder-move?id=' . (int)$selectedFolderId : '';
$folderDeleteUrl = $selectedFolderId !== null ? $base . '/drive/folder-confirm-delete?id=' . (int)$selectedFolderId : '';
?>
<div class="dms-drive__breadcrumb" data-add-url="<?= e($addUrl) ?>" data-folder-add-url="<?= e($folderAddUrl) ?>" data-trash-url="<?= e($trashUrl) ?>"
     data-folder-edit-url="<?= e($folderEditUrl) ?>" data-folder-move-url="<?= e($folderMoveUrl) ?>" data-folder-delete-url="<?= e($folderDeleteUrl) ?>">
  <a class="dms-drive__crumb<?= $rootActive ? ' dms-drive__crumb--active' : '' ?>" href="<?= e($listUrl($rootFolderId)) ?>" data-pane="<?= e($paneUrl($rootFolderId)) ?>"><?= e($rootLabel) ?></a>
  <?php foreach ($crumbs as $c): ?>
    <span class="dms-drive__crumb-sep">›</span>
    <a class="dms-drive__crumb dms-drive__crumb--active" href="<?= e($listUrl($c['id'])) ?>" data-pane="<?= e($paneUrl($c['id'])) ?>"><?= e($c['name']) ?></a>
  <?php endforeach; ?>
</div>
