<?php
/**
 * DMS Drive — confirm step of «Papierkorb leeren» (DMS-FORM-ACTIONS-001, 2026-10-10). Opened
 * from the trash panel's action row (GET `drive/trash?…&confirm=purge-all`). A field-less
 * confirm, so its bar stays at the bottom (`end`, the one exception ADR-049 allows). The POST
 * goes back to the source URL — {@see DriveController::trashAction} applies `op=purgeAll`
 * (the global Fetch CSRF gate is the authority, DMS-SEC-001) and answers with the trash panel.
 * «Zurück» returns to the panel without emptying it.
 *
 * @var int    $count     documents in the trash (what «alle» means)
 * @var string $trashUrl  the trash panel's own address
 */
$back = '<button type="button" class="be-btn be-btn--ghost" data-fetch-get="' . e($trashUrl) . '">Zurück</button>';
?>
<form data-fetch-post>
    <input type="hidden" name="op" value="purgeAll">
    <div class="be-modal__header"><h2 class="be-modal__title">Papierkorb leeren</h2></div>
    <div class="be-modal__body">
        <p><?= $count === 1 ? '1 Dokument' : (int) $count . ' Dokumente' ?> endgültig löschen?</p>
        <p style="font-size:.8rem;color:var(--be-muted,#94a3b8)">Datei und Eintrag werden unwiderruflich entfernt. Dokumente mit laufender Aufbewahrungsfrist bleiben im Papierkorb.</p>
    </div>
    <?= $this->partial('partials/modalActions', ['submit' => 'Endgültig löschen', 'kind' => 'danger', 'cancel' => '', 'extra' => $back, 'end' => true], 'Z77\\Shared') ?>
</form>
