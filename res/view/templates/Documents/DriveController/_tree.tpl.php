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
        if (!empty($n['active']))   { $classes .= ' dms-tree__node--active'; }
        if (!empty($n['inactive'])) { $classes .= ' dms-tree__node--inactive'; }
        $switchId = 'dms-folder-' . (int) $n['id'];
        ?>
        <li class="<?= $classes ?>">
            <?php /* Expanding a node is a CSS disclosure: this checkbox holds the state, the caret
                     is its label. Expanding and SELECTING are now two different actions — before,
                     the caret did nothing and children only appeared once you navigated into the
                     parent, so getting one level deeper always cost a page load. Server-checked
                     when the node is on the path to the selection, so navigating still unfolds
                     the way there. The checkbox must precede the row: the CSS reads it with `~`. */ ?>
            <?php if ($hasChildren): ?>
            <input type="checkbox" class="dms-tree__switch" id="<?= e($switchId) ?>"
                   <?= !empty($n['onPath']) ? 'checked' : '' ?>
                   aria-label="Unterordner von «<?= e($n['name']) ?>» ein- oder ausklappen">
            <?php endif; ?>
            <div class="dms-tree__row" style="--dms-depth:<?= $depth ?>">
                <?php if ($hasChildren): ?>
                <label class="dms-tree__toggle" for="<?= e($switchId) ?>"><svg class="dms-icon"><use href="#i-chevron"/></svg></label>
                <?php else: ?>
                <span class="dms-tree__toggle" aria-hidden="true"></span>
                <?php endif; ?>
                <svg class="dms-icon dms-tree__icon"><use href="#i-folder"/></svg>
                <button type="button" class="dms-rowmenu" data-modal="<?= $base ?>/drive/actions?type=folder&id=<?= (int) $n['id'] . $ctx ?>" title="Aktionen">⋮</button>
                <?php /* `data-z77-split-close`: picking a folder COMPLETES what the nav overlay was
                         opened for, so it shuts on a narrow Drive. Wide, there is no overlay and
                         the attribute does nothing. It does not interfere with the link's own
                         behaviour — drive.js listens separately. */ ?>
                <a class="dms-tree__name" href="<?= e($listUrl($n['id'])) ?>" data-pane="<?= e($paneUrl($n['id'])) ?>" data-z77-split-close><?= e($n['name']) ?></a>
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
<?php /* `--nav`: the orientation pane of the workspace. Below 40rem it becomes an overlay from
         the left instead of squeezing the file list into a second narrow column — the shared
         narrow-screen contract, see `_split.scss`. Its trigger lives in the list pane. */ ?>
<nav class="dms-drive__tree z77-split__pane z77-split__pane--nav">
  <ul class="dms-tree">
    <?php if ($roots === []): ?>
    <li class="dms-tree__empty" style="padding:.5rem .75rem;font-size:.85rem;color:var(--dms-muted,#94a3b8)">Noch keine Ordner — oben «Neuer Ordner».</li>
    <?php else: ?>
    <?php $renderNodes($roots, 0); ?>
    <?php endif; ?>
  </ul>
</nav>
