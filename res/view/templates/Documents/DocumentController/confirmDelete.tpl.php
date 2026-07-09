<?php
/**
 * @var \Z77\Module\Dms\Entities\Document|null $doc
 * @var string $entityCsrf
 * @var string $removeUrl  submit target (defaults to the legacy documents endpoint; the Drive passes its own)
 */
if ($doc === null): ?>
<div class="be-modal__body"><p>Dokument nicht gefunden.</p></div>
<div class="be-modal__footer">
    <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
</div>
<?php return; endif; ?>

<form data-fetch-post="<?= e($removeUrl ?? $base . '/document/remove') ?>">
    <input type="hidden" name="id"          value="<?= (int)$doc->getId() ?>">
    <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf) ?>">
    <div class="be-modal__header"><h2 class="be-modal__title">Dokument löschen</h2></div>
    <div class="be-modal__body">
        <p>«<?= e($doc->getDisplayName()) ?>» in den Papierkorb legen?</p>
        <p style="font-size:.8rem;color:var(--be-muted,#94a3b8)">Das Dokument wandert in den Papierkorb (wiederherstellbar); die Datei bleibt erhalten. Endgültiges Löschen erfolgt dort.</p>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Abbrechen</button>
        <button type="submit" class="be-btn be-btn--danger">Löschen</button>
    </div>
</form>
