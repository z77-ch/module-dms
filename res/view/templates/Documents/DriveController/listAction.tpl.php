<?php
/**
 * DMS Drive — embedded `.dms` 3-pane fragment (R6b shell, R6c pane split). The four
 * panes (tree, breadcrumb, list, preview) are extracted into self-contained partials so
 * the same markup serves the full page AND the in-place `replace-html` pane updates
 * ({@see DriveController::paneAction}). Server-rendered links carry `href` (full-reload
 * fallback) + `data-pane` (the fetch endpoint `drive.js` calls). Styled by the module-dms
 * `dms.css` bundle (loaded via DriveController::addCss).
 *
 * @var array    $roots            nested folder nodes (id,name,count,active,onPath,inactive,children)
 * @var int      $rootCount        live docs at the area root
 * @var bool     $rootActive       whether the area root is selected
 * @var array    $crumbs           [[id,name], …] from root to selected folder
 * @var int|null $selectedFolderId
 * @var array    $files            file row view-models
 * @var array|null $preview        selected document preview view-model
 */
$ns = 'Documents/DriveController/';
?>
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="i-chevron" viewBox="0 0 24 24"><polyline points="9 6 15 12 9 18"/></symbol>
  <symbol id="i-folder" viewBox="0 0 24 24"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></symbol>
  <symbol id="i-folder-plus" viewBox="0 0 24 24"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></symbol>
  <symbol id="i-image" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-5-5L5 21"/></symbol>
  <symbol id="i-doc" viewBox="0 0 24 24"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><polyline points="14 3 14 8 19 8"/></symbol>
  <symbol id="i-text" viewBox="0 0 24 24"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><polyline points="14 3 14 8 19 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="13" y2="17"/></symbol>
  <symbol id="i-archive" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8"/><line x1="10" y1="12" x2="14" y2="12"/></symbol>
  <symbol id="i-audio" viewBox="0 0 24 24"><path d="M9 18V5l10-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="16" cy="16" r="3"/></symbol>
  <symbol id="i-eye" viewBox="0 0 24 24"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></symbol>
  <symbol id="i-download" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></symbol>
  <symbol id="i-upload" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 9 12 4 17 9"/><line x1="12" y1="4" x2="12" y2="16"/></symbol>
  <symbol id="i-edit" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/></symbol>
  <symbol id="i-move" viewBox="0 0 24 24"><polyline points="5 9 2 12 5 15"/><polyline points="9 5 12 2 15 5"/><polyline points="15 19 12 22 9 19"/><polyline points="19 9 22 12 19 15"/><line x1="2" y1="12" x2="22" y2="12"/><line x1="12" y1="2" x2="12" y2="22"/></symbol>
  <symbol id="i-trash" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></symbol>
  <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></symbol>
  <symbol id="i-users" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></symbol>
</svg>

<?php /* The toolbar (breadcrumb PATH + upload / new-folder / trash actions) now lives in the backend
         shell header band — hc1 (upload) + hc2 (path + actions), auto-loaded from module-backend's
         Documents/DriveController/list.hc{1,2}.tpl.php. The breadcrumb pane keeps its `.dms-drive__breadcrumb`
         class + server-built data-urls there, so DriveControllerTrait::panes still refreshes it in place.
         `data-drive-scope` marks the fragment (tree/list/preview links + document actions) for drive.js;
         the header slots carry the same marker. */ ?>
<?php /* Layout comes from the SHARED `z77-split` primitive (kernel/shared) as of 2026-08-08;
         `.dms-drive` keeps only the frame (border, radius, background). Two classes per pane:
         `.dms-drive__*` for the look, `.z77-split__pane` for the geometry.
         The pane classes MUST live in the partials, not here — `panes()` replaces those roots
         via `replace-html` (outerHTML), so a class added on this page only would be gone after
         the first folder click.
         Handle count = panes − 1; the pane order (pane, handle, pane, handle, pane) is what the
         `nth-child` width rules in `_split.scss` read, so do not insert other elements between
         them. The backdrop belongs to the narrow-screen overlay of the preview pane. */ ?>
<?php /* `dms--fill`: the Drive is a full-height workspace, so the fragment must pass the host's
         remaining height down instead of growing with the file list. Without it `.dms-drive`'s
         `height: 100%` has no definite parent to resolve against, the panes grow, and the shell
         column scrolls instead of each pane. Opt-in — a `.dms` fragment embedded as a block in
         a page keeps its content height. */ ?>
<div class="dms dms--fill">
  <div class="dms-drive z77-split" data-drive-scope data-z77-split-root
       style="--z77-split-1: 16rem; --z77-split-3: 22rem">

    <?= $this->partial($ns . '_tree', [
        'roots'            => $roots,
        'rootCount'        => $rootCount,
        'rootActive'       => $rootActive,
        'selectedFolderId' => $selectedFolderId,
        'selectedDoc'      => $selectedDoc,
        'base'             => $base,
    ], $tplNs) ?>

    <span class="z77-split__handle" title="Breite ziehen"
          data-z77-split="--z77-split-1" data-z77-split-min="180" data-z77-split-max="480"></span>

    <?= $this->partial($ns . '_list', ['files' => $files, 'selectedFolderId' => $selectedFolderId, 'selectedDoc' => $selectedDoc, 'base' => $base], $tplNs) ?>

    <span class="z77-split__handle" title="Breite ziehen"
          data-z77-split="--z77-split-3" data-z77-split-min="220" data-z77-split-max="560"></span>

    <?= $this->partial($ns . '_preview', ['preview' => $preview, 'base' => $base], $tplNs) ?>

    <div class="z77-split__backdrop" data-z77-split-close></div>

  </div>
</div>
