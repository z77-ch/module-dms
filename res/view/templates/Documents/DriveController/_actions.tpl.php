<?php
/**
 * DMS Drive — per-row action hub (R6c). Opened by the `⋮` in the tree (folders) / list
 * (documents). Launches the specific modals via `data-fetch-get` (core-wired on popup
 * show, so a click replaces this hub with the target modal) and carries the inline
 * `active` switch (`data-fetch-toggle` → {@see DriveController::actionsAction} `&op=active`).
 *
 * @var 'document'|'folder' $type
 * @var int      $id
 * @var string   $name
 * @var bool     $isActive
 * @var int|null $folder  current drive selection (so the active toggle refreshes that view)
 * @var int|null $doc     current selected document
 */
$drive = $base . '/drive/';
$docBase = $base . '/document/';
$q     = '?type=' . $type . '&id=' . $id;
$ctx   = '&folder=' . ($folder ?? '') . '&doc=' . ($doc ?? '');

$editUrl = $type === 'folder' ? $drive . 'folder-edit?id=' . $id           : $drive . 'edit?id=' . $id;
$moveUrl = $type === 'folder' ? $drive . 'folder-move?id=' . $id           : $drive . 'move?id=' . $id;
$delUrl  = $type === 'folder' ? $drive . 'folder-confirm-delete?id=' . $id : $drive . 'confirm-delete?id=' . $id;
?>
<div class="dms-actions">
    <div class="be-modal__header"><h2 class="be-modal__title">Aktionen — «<?= e($name) ?>»</h2></div>
    <div class="be-modal__body">
        <label class="be-switch be-switch--block">
            <span class="be-switch__label">Aktiv <small>ausgeliefert / sichtbar</small></span>
            <input type="checkbox" class="be-switch__input" data-fetch-toggle="<?= e($drive . 'actions' . $q . '&op=active' . $ctx) ?>"<?= $isActive ? ' checked' : '' ?>>
            <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
        </label>

        <?php
        // `be-icon` (backend, works in the popup) over the drive page's inline `#i-*` sprite
        // (same document DOM — the hub is only opened from the Drive). Left-aligned rows.
        $ic = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
        ?>
        <div style="display:flex;flex-direction:column;gap:.35rem;margin-top:.65rem;align-items:stretch">
            <button type="button" class="be-btn be-btn--ghost" style="justify-content:flex-start" data-fetch-get="<?= e($editUrl) ?>"><?= $ic('i-edit') ?> Bearbeiten</button>
            <button type="button" class="be-btn be-btn--ghost" style="justify-content:flex-start" data-fetch-get="<?= e($moveUrl) ?>"><?= $ic('i-move') ?> Verschieben</button>
            <?php if ($type === 'folder'): ?>
            <button type="button" class="be-btn be-btn--ghost" style="justify-content:flex-start" data-fetch-get="<?= e($drive . 'folder-add?parent=' . $id) ?>"><?= $ic('i-folder-plus') ?> Neuer Unterordner</button>
            <button type="button" class="be-btn be-btn--ghost" style="justify-content:flex-start" data-fetch-get="<?= e($drive . 'add?folder=' . $id) ?>"><?= $ic('i-upload') ?> Datei hochladen</button>
            <?php else: ?>
            <a class="be-btn be-btn--ghost" style="justify-content:flex-start" href="<?= e($docBase . 'preview?id=' . $id) ?>" target="_blank" rel="noopener"><?= $ic('i-eye') ?> Öffnen</a>
            <a class="be-btn be-btn--ghost" style="justify-content:flex-start" href="<?= e($docBase . 'download?id=' . $id) ?>"><?= $ic('i-download') ?> Download</a>
            <?php endif; ?>
            <button type="button" class="be-btn be-btn--danger" style="justify-content:flex-start" data-fetch-get="<?= e($delUrl) ?>"><?= $ic('i-trash') ?> Löschen</button>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
    </div>
</div>
