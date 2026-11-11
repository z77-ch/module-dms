<?php
/**
 * The media picker (`Ui\MediaPickerTrait::pickAction()`, bauplan P1) — fetched, a WINDOW over the
 * form it was opened from (ADR-047, mask `dms-pick`, entity `media-field:<target>`: one picker
 * per field); without the script the same markup is a page. Action row first (ADR-049):
 * Schliessen only — a click on an image is the choice.
 *
 * Left the folder tree below the picker's root (readable folders only, RF-4), right the shown
 * folder: its delivery, the upload component (THE upload, `drop` shape — into THIS folder, through
 * the user path) and the grid. A folder click loads the picker anew into this window
 * (`data-window-link`), so the upload is bound again with the new folder in its form; an upload
 * replaces only the grid. A grid item is a submit of the ONE choose form (`doc=<id>`): the answer
 * fills the opener's media field and closes the window. A document that may not be chosen
 * (`public=1` and not delivered openly) is shown disabled with its reason.
 *
 * @var string      $target        DOM id of the media field the choice goes to ('' = browse only)
 * @var string      $origin        where the picker was opened from (`_origin`, ADR-047)
 * @var array<string, string> $keep what every link and form carries (target, root, accept, public, _origin)
 * @var string      $pickUrl
 * @var string      $rootDomId     id of this content's root (the upload's bind / the grid's target)
 * @var string      $formId        id of the choose form
 * @var bool        $window
 * @var string      $title
 * @var list<array<string, mixed>> $nodes  folder nodes (MediaPickerTrait / DriveControllerTrait::folderNodes)
 * @var array{id:int, name:string, mode:string}|null $folder  the shown folder
 * @var list<array<string, mixed>> $items  grid items
 * @var \Z77\Shared\Upload\UploadPolicy|null $uploadPolicy
 * @var string|null $uploadNote    why there is no upload here (shown instead)
 * @var bool        $public
 * @var string      $accept
 * @var string|null $treeNote      shown when no folder can be offered
 * @var string      $csrfToken     provided by html()
 */
$window  = !empty($window);
$winAttr = $window
    ? ' data-window="dms-pick" data-window-entity="media-field:' . e($target !== '' ? $target : 'browse') . '"'
      . ' data-window-title="' . e($title) . '" data-window-width="62rem"'
    : '';
$link = static fn(int $folderId): string => \Z77\Module\Dms\Ui\MediaField::url($pickUrl, $keep + ['folder' => $folderId]);

$renderNodes = function (array $nodes) use (&$renderNodes, $link): void {
    foreach ($nodes as $n) {
        $hasChildren = !empty($n['children']);
        $current     = !empty($n['active']) ? ' aria-current="true"' : '';
        $classes     = 'dms-pick__folder' . (!empty($n['active']) ? ' is-current' : '') . (!empty($n['inactive']) ? ' is-inactive' : '');
        ?>
        <li>
            <?php if ($hasChildren): ?>
            <?php // A disclosure per folder with sub-folders, open on the way to the shown one —
                  // no script. The link inside the summary navigates; the marker toggles. ?>
            <details<?= !empty($n['onPath']) ? ' open' : '' ?>>
                <summary><a class="<?= $classes ?>" href="<?= e($link((int) $n['id'])) ?>" data-window-link<?= $current ?>><?= e($n['name']) ?></a></summary>
                <ul><?php $renderNodes($n['children']); ?></ul>
            </details>
            <?php else: ?>
            <a class="<?= $classes ?> dms-pick__folder--leaf" href="<?= e($link((int) $n['id'])) ?>" data-window-link<?= $current ?>><?= e($n['name']) ?></a>
            <?php endif; ?>
        </li>
        <?php
    }
};
$modeLabel = ['public' => 'öffentlich', 'protected' => 'geschützt', 'sealed' => 'versiegelt'];
$modeBadge = ['public' => 'badge--success', 'protected' => 'badge--muted', 'sealed' => 'badge--warning'];
?>
<div class="dms-pick" id="<?= e($rootDomId) ?>"<?= $winAttr ?>>
    <?php if ($window): ?>
    <?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen'], 'Z77\\Shared') ?>
    <?php endif; ?>
    <div class="dms-pick__body">
        <nav class="dms-pick__tree" aria-label="Ordner">
            <?php if ($nodes === []): ?>
            <p class="be-list__empty"><?= e($treeNote ?? 'Kein Ordner, den du sehen darfst.') ?></p>
            <?php else: ?>
            <ul class="dms-pick__folders"><?php $renderNodes($nodes); ?></ul>
            <?php endif; ?>
        </nav>
        <section class="dms-pick__main" aria-label="<?= e($folder !== null ? 'Ordner «' . $folder['name'] . '»' : 'Kein Ordner gewählt') ?>">
            <?php if ($folder === null): ?>
            <p class="be-list__empty">Links einen Ordner wählen.</p>
            <?php else: ?>
            <div class="dms-pick__head">
                <strong class="dms-pick__title"><?= e($folder['name']) ?></strong>
                <span class="badge <?= e($modeBadge[$folder['mode']] ?? 'badge--muted') ?>" title="Auslieferung dieses Ordners"><?= e($modeLabel[$folder['mode']] ?? $folder['mode']) ?></span>
            </div>
            <?php if ($uploadPolicy !== null): ?>
            <?php // The upload's own fields travel with every file (UPLOAD-001): the shown folder and
                  // the picker's state, so the answer can refresh THIS grid. The server checks
                  // every one of them again (`pickUploadAction`). ?>
            <?php ob_start(); ?>
            <input type="hidden" name="folder_id" value="<?= (int) $folder['id'] ?>">
            <?php foreach ($keep as $name => $value): ?>
            <input type="hidden" name="<?= e($name) ?>" value="<?= e((string) $value) ?>">
            <?php endforeach; ?>
            <?php $uploadFields = (string) ob_get_clean(); ?>
            <?= $this->partial('partials/upload', ['policy' => $uploadPolicy, 'fields' => $uploadFields], 'Z77\\Shared') ?>
            <?php elseif ($uploadNote !== null): ?>
            <p class="be-form__hint dms-pick__note"><?= e($uploadNote) ?></p>
            <?php endif; ?>
            <?php endif; ?>
            <form method="post" action="<?= e($pickUrl) ?>" id="<?= e($formId) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
                <?php foreach ($keep as $name => $value): ?>
                <input type="hidden" name="<?= e($name) ?>" value="<?= e((string) $value) ?>">
                <?php endforeach; ?>
                <?php if ($folder !== null): ?>
                <input type="hidden" name="folder" value="<?= (int) $folder['id'] ?>">
                <?php endif; ?>
            </form>
            <?= $this->partial('Documents/DriveController/_pickGrid', [
                'items'  => $items,
                'formId' => $formId,
                'folder' => $folder,
                'accept' => $accept,
                'fresh'  => null,
            ], 'Z77\\Module\\Dms') ?>
        </section>
    </div>
</div>
