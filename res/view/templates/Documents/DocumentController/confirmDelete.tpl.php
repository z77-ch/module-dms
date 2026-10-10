<?php
/**
 * A field-less confirm (ADR-049 revision 2026-10-10): its bar stays at the bottom (`end`).
 *
 * @var \Z77\Module\Dms\Entities\Document|null $doc
 * @var string $entityCsrf
 * @var string $removeUrl  submit target (defaults to the legacy documents endpoint; the Drive passes its own)
 */
if ($doc === null): ?>
<div class="be-modal__body"><p>Dokument nicht gefunden.</p></div>
<?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen', 'end' => true], 'Z77\\Shared') ?>
<?php return; endif; ?>

<form data-fetch-post="<?= e($removeUrl ?? $base . '/document/remove') ?>">
    <input type="hidden" name="id"          value="<?= (int)$doc->getId() ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header"><h2 class="be-modal__title">Dokument löschen</h2></div>
    <div class="be-modal__body">
        <p>«<?= e($doc->getDisplayName()) ?>» in den Papierkorb legen?</p>
        <p style="font-size:.8rem;color:var(--be-muted,#94a3b8)">Das Dokument wandert in den Papierkorb (wiederherstellbar); die Datei bleibt erhalten. Endgültiges Löschen erfolgt dort.</p>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Löschen', 'kind' => 'danger', 'end' => true], 'Z77\\Shared') ?>
</form>
