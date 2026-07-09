<?php
/**
 * DMS Drive — left folder hierarchy pane (R6c). Self-contained partial shared by the
 * full-page render and the in-place `replace-html` pane update ({@see DriveController::paneAction}).
 * Each folder link carries `href` (full-reload fallback, no JS) AND `data-pane` (the
 * fetch endpoint `drive.js` calls for an in-place update). URLs are built server-side —
 * the client never constructs them (conventions.md#javascript).
 *
 * @var array    $roots            nested folder nodes (id,name,count,active,onPath,inactive,children)
 * @var int      $rootCount        live docs at the area root
 * @var bool     $rootActive       whether the area root is selected
 * @var int|null $selectedFolderId
 * @var int|null $selectedDoc       current selected document (context for the ⋮ action hub)
 */
$listBase = $base . '/drive/list';
$paneBase = $base . '/drive/pane';
$listUrl  = fn(?int $id) => $listBase . ($id ? '?folder=' . $id : '');
$paneUrl  = fn(?int $id) => $paneBase . ($id ? '?folder=' . $id : '');
// Current drive selection appended to the ⋮ hub URL, so the hub's active toggle can
// refresh THIS view in place (strikethrough) without losing the folder/doc context.
$ctx = '&folder=' . ($selectedFolderId ?? '') . '&doc=' . ($selectedDoc ?? '');

$renderNodes = function (array $nodes, int $depth) use (&$renderNodes, $listUrl, $paneUrl, $ctx, $base): void {
    foreach ($nodes as $n) {
        $hasChildren = !empty($n['children']);
        $classes = 'dms-tree__node';
        if ($hasChildren)           { $classes .= ' dms-tree__node--has-children'; }
        if (!empty($n['onPath']))   { $classes .= ' is-open'; }
        if (!empty($n['active']))   { $classes .= ' dms-tree__node--active'; }
        if (!empty($n['inactive'])) { $classes .= ' dms-tree__node--inactive'; }
        ?>
        <li class="<?= $classes ?>">
            <div class="dms-tree__row" style="--dms-depth:<?= $depth ?>">
                <span class="dms-tree__toggle"><svg class="dms-icon"><use href="#i-chevron"/></svg></span>
                <svg class="dms-icon dms-tree__icon"><use href="#i-folder"/></svg>
                <button type="button" class="dms-rowmenu" data-modal="<?= $base ?>/drive/actions?type=folder&id=<?= (int) $n['id'] . $ctx ?>" title="Aktionen">⋮</button>
                <a class="dms-tree__name" href="<?= e($listUrl($n['id'])) ?>" data-pane="<?= e($paneUrl($n['id'])) ?>"><?= e($n['name']) ?></a>
                <?php if ($n['count'] > 0): ?><span class="dms-tree__count"><?= (int) $n['count'] ?></span><?php endif; ?>
            </div>
            <?php if ($hasChildren): ?>
            <ul class="dms-tree__children"><?php $renderNodes($n['children'], $depth + 1); ?></ul>
            <?php endif; ?>
        </li>
        <?php
    }
};
?>
<nav class="dms-drive__tree">
  <ul class="dms-tree">
    <?php if ($roots === []): ?>
    <li class="dms-tree__empty" style="padding:.5rem .75rem;font-size:.85rem;color:var(--dms-muted,#94a3b8)">Noch keine Ordner — oben «Neuer Ordner».</li>
    <?php else: ?>
    <?php $renderNodes($roots, 0); ?>
    <?php endif; ?>
  </ul>
</nav>
