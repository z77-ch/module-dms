<?php
/**
 * THE media field (bauplan `media-picker-bauplan.md` P1, decision 1) — one DMS document chosen in
 * a backend form, stored as its ID. Any backend form renders it (the content editor's `document`
 * kind, a project's own form such as z77.ch «Projekte»):
 *
 *   <?= $this->partial('partials/mediaField', [
 *       'name'  => 'image_id',            // the hidden input's name (required)
 *       'value' => $project->getImageId(), // the chosen document id, or null
 *       'root'  => 'projekte',            // the picker's root: a partition key / slug or a folder id
 *   ], 'Z77\\Module\\Dms') ?>
 *
 * It shows the thumbnail (`document/preview`, variant `s`, domain-gated) or an empty frame,
 * «Auswählen …» and «Entfernen», and carries the id in a hidden input. «Auswählen …» opens the
 * picker as an ADR-047 window (`data-window-open`, core.js); the choice answers with commands
 * addressed to THIS field by its DOM id (`#<id> [data-media-field-input]` / `-thumb]` / `-pick]`)
 * and closes the window. The host page loads the script `documents/media-field` (module-dms) —
 * «Entfernen» and the re-keying of a CLONED field live there (see that file).
 *
 * The id is the address: unique on the page. The host may name it (`id`); without one a fresh id
 * is generated. A field cloned from a `<template>` (a list item) carries its template's id — the
 * script gives it a fresh one at the click on «Auswählen …», before core.js opens the window, so
 * the answer finds the clone and not its sibling.
 *
 * The id is only what the browser sends: the host's SAVE must judge it again (does it exist, may
 * this user read it, is it an image, is it delivered publicly) — the picker's checks are the
 * convenience, not the gate (Rule 7).
 *
 * @var string|null     $name     name of the hidden input — '' / absent: no name (a host that serialises
 *                                its fields itself, the content editor, reads it by `inputAttrs`)
 * @var array<string,string>|null $inputAttrs further attributes of the hidden input (the content
 *                                editor's `data-bf` / `data-bf-sub` + `data-bk`)
 * @var string|null     $class    further classes on the field root
 * @var int|string|null $value    the current document id (null / '' = none)
 * @var string|null     $id       DOM id of the field root; generated when absent or invalid
 * @var string|null     $label    the label (default «Bild»)
 * @var string|int|null $root     picker root: partition key / slug or folder id ('' = every readable partition)
 * @var string|null     $accept   'image' (default) or '' (any kind)
 * @var bool|null       $public   only documents delivered publicly may be chosen (default true)
 * @var bool|null       $required marks the label (the host's save enforces it)
 * @var string|null     $hint     a line under the field
 * @var string|null     $base     the backend documents mount (default `/backend/documents`)
 */
use Z77\Module\Dms\Ui\MediaField;

$base     = (string) ($base ?? '/backend/documents');
$fieldId  = MediaField::target($id ?? '') ?: MediaField::newId();
$accept   = MediaField::accept($accept ?? 'image') ?? 'image';
$public   = (bool) ($public ?? true);
$docId    = is_numeric($value ?? null) && (int) $value > 0 ? (int) $value : null;
$label    = (string) ($label ?? ($accept === 'image' ? 'Bild' : 'Dokument'));
$pickUrl  = MediaField::url($base . '/drive/pick', [
    'target' => $fieldId,
    'root'   => MediaField::root($root ?? ''),
    'accept' => $accept,
    'public' => $public,
]);
$inputAttr = (string) ($name ?? '') !== '' ? ' name="' . e((string) $name) . '"' : '';
foreach ((array) ($inputAttrs ?? []) as $attr => $attrValue) {
    if (preg_match('/^[a-zA-Z][a-zA-Z0-9:_-]*$/', (string) $attr) === 1 && !in_array(strtolower((string) $attr), ['name', 'value', 'type'], true)) {
        $inputAttr .= ' ' . $attr . '="' . e((string) $attrValue) . '"';
    }
}
$rootClass = trim('be-form__field dms-media-field ' . (string) ($class ?? ''));
?>
<div class="<?= e($rootClass) ?>" id="<?= e($fieldId) ?>" data-media-field data-z77-field-wrapper>
    <span class="be-form__label<?= !empty($required) ? ' be-form__label--required' : '' ?>" id="<?= e($fieldId) ?>-label"><?= e($label) ?></span>
    <div class="dms-media-field__body" role="group" aria-labelledby="<?= e($fieldId) ?>-label">
        <?= $this->partial('partials/mediaFieldThumb', ['thumb' => MediaField::thumb($docId, $base)], MediaField::NS) ?>
        <div class="dms-media-field__actions">
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($pickUrl) ?>" data-window-open="<?= e($pickUrl) ?>" data-media-field-pick>Auswählen …</a>
            <button type="button" class="be-btn be-btn--quiet be-btn--sm" data-media-field-clear>Entfernen</button>
        </div>
    </div>
    <input type="hidden"<?= $inputAttr ?> value="<?= $docId !== null ? $docId : '' ?>" data-media-field-input>
    <template data-media-field-empty><?= $this->partial('partials/mediaFieldThumb', ['thumb' => MediaField::thumb(null, $base)], MediaField::NS) ?></template>
    <?php if (!empty($hint)): ?>
    <small class="be-form__hint"><?= e((string) $hint) ?></small>
    <?php endif; ?>
</div>
