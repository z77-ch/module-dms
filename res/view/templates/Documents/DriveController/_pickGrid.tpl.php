<?php
/**
 * The media picker's grid — the documents of the shown folder that pass `accept`, in the editor's
 * order (`listByFolder`, DMS-SORT-001). Rendered in the window ({@see _pick.tpl.php}) AND as the
 * answer of an upload in the picker (`replace-html` of `[data-dms-pick-grid]`), so the two
 * cannot drift.
 *
 * Every item is a SUBMIT of the choose form (`form="<formId>" name="doc"`): the click is the
 * choice, Enter / Space too. One that may not be chosen is `disabled` and names its reason.
 *
 * @var list<array{id:int, name:string, ext:string, thumbUrl:?string, dims:?string, choosable:bool, reason:?string}> $items
 * @var string     $formId
 * @var array|null $folder  the shown folder (null: none chosen)
 * @var string     $accept
 * @var int|null   $fresh   id of a document this upload just added (marked)
 */
$noun = $accept === 'image' ? 'Bild' : 'Dokument';
?>
<div class="dms-pick__grid" data-dms-pick-grid>
    <?php if ($folder !== null && $items === []): ?>
    <p class="be-list__empty">In diesem Ordner liegt noch kein <?= e($noun) ?>.</p>
    <?php endif; ?>
    <?php foreach ($items as $item): ?>
    <?php $isFresh = $fresh !== null && $item['id'] === $fresh; ?>
    <button type="submit" form="<?= e($formId) ?>" name="doc" value="<?= (int) $item['id'] ?>"
            class="dms-pick__item<?= $item['choosable'] ? '' : ' is-disabled' ?><?= $isFresh ? ' is-fresh' : '' ?>"
            <?= $item['choosable'] ? '' : 'disabled' ?>
            title="<?= e($item['name'] . ($item['reason'] !== null ? ' — ' . $item['reason'] : '')) ?>"
            <?= $isFresh ? 'data-dms-pick-fresh' : '' ?>>
        <span class="dms-pick__thumb">
            <?php if ($item['thumbUrl'] !== null): ?>
            <img src="<?= e($item['thumbUrl']) ?>" alt="" loading="lazy">
            <?php else: ?>
            <span class="dms-pick__ext" aria-hidden="true"><?= e($item['ext'] ?? '') ?></span>
            <?php endif; ?>
        </span>
        <span class="dms-pick__name"><?= e($item['name']) ?></span>
        <?php if ($item['reason'] !== null): ?>
        <span class="dms-pick__reason"><?= e($item['reason']) ?></span>
        <?php elseif ($item['dims'] !== null): ?>
        <span class="dms-pick__meta"><?= e($item['dims']) ?></span>
        <?php endif; ?>
    </button>
    <?php endforeach; ?>
</div>
