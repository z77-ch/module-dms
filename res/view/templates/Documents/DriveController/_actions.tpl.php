<?php
/**
 * DMS Drive — per-row context menu (⋮, core.js «context menu», owner 2026-10-22; the R6c hub
 * was a dialog until then). Opened by the `⋮` in the tree (folders) / list (documents) via
 * `data-menu-open`. Each item opens its dialog via `data-fetch-get` (core-wired when the menu
 * opens); the inline `active` switch stays a real switch row (`data-fetch-toggle` →
 * {@see DriveController::actionsAction} `&op=active`) — toggling it refreshes the panes behind
 * and keeps the menu open. The delivery hint is a `.be-menu__note` line (`--warn` for public).
 *
 * The menu box lives in <body>, OUTSIDE the `.dms` wrapper — like the dialog before it, it
 * takes the host's look (`.be-menu`, `.be-switch`, `.be-icon`), not `--dms-*` (css-dms.md).
 *
 * @var 'document'|'folder' $type
 * @var int      $id
 * @var string   $name
 * @var bool     $isActive
 * @var bool     $isDriveRoot  the lifecycle-locked root (ADR-021) — see below
 * @var string   $delivery     effective delivery: public|protected|sealed|none
 * @var int|null $folder  current drive selection (so the active toggle refreshes that view)
 * @var int|null $doc     current selected document
 * @var array|null $chunk  large document: chunked-download data ({@see DriveControllerTrait::chunkedDownload}), else null
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
// [label, sentence, warns] — a public delivery warns (`.be-menu__note--warn`).
$deliveryInfo = [
    'public'    => ['Öffentlich', 'Inhalte sind ohne Anmeldung über /media erreichbar.', true],
    'protected' => ['Geschützt',  'Nur mit Leserecht — nichts wird öffentlich ausgeliefert.', false],
    'sealed'    => ['Versiegelt', 'Auslieferung für den ganzen Teilbaum gesperrt.', false],
][$delivery] ?? null;

// `be-icon` (backend) over the drive page's inline `#i-*` sprite (same document DOM — the
// menu is only opened from the Drive).
// A large document comes down in pieces (DMS-CHUNK-DL-001): drive.js hands a link with
// `data-dms-chunk-download` to `documents/chunk-download.js`; the href stays the fallback.
$chunkAttr = !empty($chunk)
    ? ' data-dms-chunk-download="' . e($chunk['url']) . '" data-dms-chunk-name="' . e($chunk['name']) . '" data-dms-chunk-bytes="' . (int) $chunk['bytes'] . '"'
    : '';

$ic = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
?>
<div class="be-menu" role="menu" aria-label="Aktionen — «<?= e($name) ?>»">
    <?php if ($isDriveRoot): ?>
    <p class="be-menu__note">Der Drive ist die Wurzel: nicht verschiebbar, nicht löschbar, speichert selbst keine Dateien. Unterordner darunter sind die Bereiche.</p>
    <?php else: ?>
    <div class="be-menu__switch" role="none">
        <label class="be-switch be-switch--block">
            <span class="be-switch__label">Aktiv <small>ausgeliefert / sichtbar</small></span>
            <input type="checkbox" class="be-switch__input" data-fetch-toggle="<?= e($drive . 'actions' . $q . '&op=active' . $ctx) ?>"<?= $isActive ? ' checked' : '' ?>>
            <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
        </label>
    </div>
    <?php endif; ?>

    <?php if ($deliveryInfo !== null): ?>
    <p class="be-menu__note<?= $deliveryInfo[2] ? ' be-menu__note--warn' : '' ?>">
        <strong>Auslieferung: <?= e($deliveryInfo[0]) ?></strong> — <?= e($deliveryInfo[1]) ?>
        <?php if ($delivery === 'public'): ?>
        Gilt geerbt für alles darunter, solange kein Unterordner es enger setzt.
        <?php endif; ?>
    </p>
    <?php endif; ?>

    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="<?= e($editUrl) ?>"><?= $ic('i-edit') ?> Bearbeiten</button>
    <?php if (!$isDriveRoot): ?>
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="<?= e($moveUrl) ?>"><?= $ic('i-move') ?> Verschieben</button>
    <?php endif; ?>
    <?php if ($type === 'folder'): ?>
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="<?= e($drive . 'folder-add?parent=' . $id) ?>"><?= $ic('i-folder-plus') ?> <?= $isDriveRoot ? 'Neuer Bereich' : 'Neuer Unterordner' ?></button>
    <?php if (!$isDriveRoot): ?>
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="<?= e($drive . 'add?folder=' . $id) ?>"><?= $ic('i-upload') ?> Datei hochladen</button>
    <?php endif; ?>
    <?php else: ?>
    <a class="be-menu__item" role="menuitem" href="<?= e($docBase . 'preview?id=' . $id) ?>" target="_blank" rel="noopener"<?= !empty($chunk['open']) ? $chunkAttr : '' ?>><?= $ic('i-eye') ?> Öffnen</a>
    <a class="be-menu__item" role="menuitem" href="<?= e($docBase . 'download?id=' . $id) ?>"<?= $chunkAttr ?>><?= $ic('i-download') ?> Download</a>
    <?php if (!empty($publicUrl)): ?>
    <button type="button" class="be-menu__item" role="menuitem" data-copy="#dms-public-url-<?= (int) $id ?>"><?= $ic('i-copy') ?> Pfad kopieren</button>
    <?php /* The copy's source, not shown (owner 2026-10-23). Off-screen, not display:none — over plain http the copy SELECTS it (core.js clipboard). */ ?>
    <input type="text" id="dms-public-url-<?= (int) $id ?>" value="<?= e($publicUrl) ?>" readonly tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0">

    <?php endif; ?>
    <?php endif; ?>
    <?php if (!$isDriveRoot): ?>
    <button type="button" class="be-menu__item be-menu__item--danger" role="menuitem" data-fetch-get="<?= e($delUrl) ?>"><?= $ic('i-trash') ?> Löschen</button>
    <?php endif; ?>
</div>
