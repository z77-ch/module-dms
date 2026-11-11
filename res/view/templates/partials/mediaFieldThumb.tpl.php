<?php
/**
 * What a media field shows of its value — the ONE markup of it: rendered by
 * `partials/mediaField` and by the picker's choice answer (`replace-html` of
 * `[data-media-field-thumb]`), so the field and the answer cannot drift. The empty state is also
 * the field's `<template data-media-field-empty>`, which «Entfernen» puts back (no markup from a
 * string, Rule 7).
 *
 * `data-empty` marks a field without a value — the CSS hides «Entfernen» by it, no script.
 *
 * @var array{state: 'empty'|'doc'|'missing', id: int|null, name: string, thumbUrl: string|null} $thumb
 *      ({@see \Z77\Module\Dms\Ui\MediaField::thumb()})
 */
$state = $thumb['state'] ?? 'empty';
?>
<span class="dms-media-field__thumb is-<?= e($state) ?>" data-media-field-thumb<?= $state === 'empty' ? ' data-empty' : '' ?>>
    <?php if ($state === 'doc'): ?>
    <span class="dms-media-field__frame">
        <?php if ($thumb['thumbUrl'] !== null): ?>
        <img src="<?= e($thumb['thumbUrl']) ?>" alt="">
        <?php endif; ?>
    </span>
    <span class="dms-media-field__name"><?= e($thumb['name']) ?></span>
    <?php elseif ($state === 'missing'): ?>
    <span class="dms-media-field__frame" aria-hidden="true"></span>
    <span class="dms-media-field__name">Dokument Nr. <?= (int) $thumb['id'] ?> ist nicht verfügbar</span>
    <?php else: ?>
    <span class="dms-media-field__frame" aria-hidden="true"></span>
    <span class="dms-media-field__name">Nichts gewählt</span>
    <?php endif; ?>
</span>
