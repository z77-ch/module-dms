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
 * @var bool     $isDriveRoot  the lifecycle-locked root (ADR-021) — see below
 * @var string   $delivery     effective delivery: public|protected|sealed|none
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

// The drive root is lifecycle-locked (ADR-021, documents.md rule «drive root»): it can
// never be moved or deleted, it stores no documents, and it is always active. The domain
// throws on all four — offering the controls anyway only produces errors (DMS-ROOT-001).
// A subfolder IS offered: a child of the root is a new partition, which is exactly how
// partitions are created (`FolderService::add`, SUPER_USER-gated).
$deliveryInfo = [
    'public'    => ['Öffentlich', 'Inhalte sind ohne Anmeldung über /media erreichbar.', '#f59e0b'],
    'protected' => ['Geschützt',  'Nur mit Leserecht — nichts wird öffentlich ausgeliefert.', ''],
    'sealed'    => ['Versiegelt', 'Auslieferung für den ganzen Teilbaum gesperrt.', ''],
][$delivery] ?? null;
?>
<div class="dms-actions">
    <div class="be-modal__header"><h2 class="be-modal__title">Aktionen — «<?= e($name) ?>»</h2></div>
    <div class="be-modal__body">
        <?php if ($isDriveRoot): ?>
        <p style="font-size:.75rem;color:var(--be-muted,#94a3b8);margin:0 0 .65rem">
            Der Drive ist die Wurzel: sie lässt sich nicht verschieben, nicht löschen und
            speichert selbst keine Dateien. Unterordner darunter sind die Bereiche.
        </p>
        <?php else: ?>
        <label class="be-switch be-switch--block">
            <span class="be-switch__label">Aktiv <small>ausgeliefert / sichtbar</small></span>
            <input type="checkbox" class="be-switch__input" data-fetch-toggle="<?= e($drive . 'actions' . $q . '&op=active' . $ctx) ?>"<?= $isActive ? ' checked' : '' ?>>
            <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
        </label>
        <?php endif; ?>

        <?php if ($deliveryInfo !== null): ?>
        <p style="font-size:.75rem;margin:.5rem 0 0<?= $deliveryInfo[2] !== '' ? ';color:' . $deliveryInfo[2] : ';color:var(--be-muted,#94a3b8)' ?>">
            <strong>Auslieferung: <?= e($deliveryInfo[0]) ?></strong> — <?= e($deliveryInfo[1]) ?>
            <?php if ($delivery === 'public'): ?>
            <br>Gilt geerbt für alles darunter, solange kein Unterordner es enger setzt.
            <?php endif; ?>
        </p>
        <?php endif; ?>

        <?php
        // `be-icon` (backend, works in the popup) over the drive page's inline `#i-*` sprite
        // (same document DOM — the hub is only opened from the Drive). Left-aligned rows.
        $ic = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
        ?>
        <div style="display:flex;flex-direction:column;gap:.35rem;margin-top:.65rem;align-items:stretch">
            <button type="button" class="be-btn be-btn--ghost" style="justify-content:flex-start" data-fetch-get="<?= e($editUrl) ?>"><?= $ic('i-edit') ?> Bearbeiten</button>
            <?php if (!$isDriveRoot): ?>
            <button type="button" class="be-btn be-btn--ghost" style="justify-content:flex-start" data-fetch-get="<?= e($moveUrl) ?>"><?= $ic('i-move') ?> Verschieben</button>
            <?php endif; ?>
            <?php if ($type === 'folder'): ?>
            <button type="button" class="be-btn be-btn--ghost" style="justify-content:flex-start" data-fetch-get="<?= e($drive . 'folder-add?parent=' . $id) ?>"><?= $ic('i-folder-plus') ?> <?= $isDriveRoot ? 'Neuer Bereich' : 'Neuer Unterordner' ?></button>
            <?php if (!$isDriveRoot): ?>
            <button type="button" class="be-btn be-btn--ghost" style="justify-content:flex-start" data-fetch-get="<?= e($drive . 'add?folder=' . $id) ?>"><?= $ic('i-upload') ?> Datei hochladen</button>
            <?php endif; ?>
            <?php else: ?>
            <a class="be-btn be-btn--ghost" style="justify-content:flex-start" href="<?= e($docBase . 'preview?id=' . $id) ?>" target="_blank" rel="noopener"><?= $ic('i-eye') ?> Öffnen</a>
            <a class="be-btn be-btn--ghost" style="justify-content:flex-start" href="<?= e($docBase . 'download?id=' . $id) ?>"><?= $ic('i-download') ?> Download</a>
            <?php endif; ?>
            <?php if (!$isDriveRoot): ?>
            <button type="button" class="be-btn be-btn--danger" style="justify-content:flex-start" data-fetch-get="<?= e($delUrl) ?>"><?= $ic('i-trash') ?> Löschen</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
    </div>
</div>
