<?php
/**
 * DMS Drive — trash panel (R6c). Lists the area's soft-deleted documents with restore /
 * permanent delete. Self-refreshing: each row form posts back to the source URL
 * ({@see DriveController::trashAction}), which applies the op and re-renders this panel
 * into the popup. Purge respects `retentionUntil`; restore needs the original folder.
 *
 * @var list<array{id:int, name:string, deletedAt:?string, entityCsrf:string}> $items
 */
?>
<div class="dms-trash">
    <div class="be-modal__header"><h2 class="be-modal__title">Papierkorb</h2></div>
    <div class="be-modal__body">
        <?php if ($items === []): ?>
        <p style="font-size:.85rem;color:var(--be-muted,#94a3b8);margin:0">Der Papierkorb ist leer.</p>
        <?php else: ?>
        <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);margin:0 0 .6rem">Gelöschte Dokumente bleiben erhalten (Aufbewahrung). Wiederherstellen legt sie zurück in den Ordner; endgültiges Löschen entfernt Datei + Eintrag unwiderruflich.</p>
        <div style="display:flex;flex-direction:column;gap:.4rem">
            <?php foreach ($items as $it): ?>
            <div style="display:flex;align-items:center;gap:.5rem">
                <span style="flex:1;min-width:0">
                    <span style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($it['name']) ?></span>
                    <small style="color:var(--be-muted,#94a3b8)">gelöscht <?= e(substr((string) $it['deletedAt'], 0, 10)) ?></small>
                </span>
                <form data-fetch-post style="margin:0">
                    <input type="hidden" name="entity_csrf" value="<?= e($it['entityCsrf']) ?>">
                    <input type="hidden" name="op"          value="restore">
                    <input type="hidden" name="id"          value="<?= (int) $it['id'] ?>">
                    <button type="submit" class="be-btn be-btn--ghost">Wiederherstellen</button>
                </form>
                <form data-fetch-post style="margin:0">
                    <input type="hidden" name="entity_csrf" value="<?= e($it['entityCsrf']) ?>">
                    <input type="hidden" name="op"          value="purge">
                    <input type="hidden" name="id"          value="<?= (int) $it['id'] ?>">
                    <button type="submit" class="be-btn be-btn--danger">Endgültig löschen</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php /* Empty the whole trash — irreversible, so the submit hides behind a
                 JS-free <details> confirm step. Retention-blocked documents survive
                 the purge loop and stay listed (the flash reports them). */ ?>
        <details style="margin-top:.8rem">
            <summary style="cursor:pointer;font-size:.85rem;color:var(--be-danger,#dc2626)">Papierkorb leeren …</summary>
            <div style="display:flex;align-items:center;gap:.6rem;margin-top:.5rem">
                <span style="flex:1;font-size:.8rem;color:var(--be-muted,#94a3b8)">Alle Dokumente endgültig löschen — unwiderruflich. Laufende Aufbewahrungsfristen bleiben erhalten.</span>
                <form data-fetch-post style="margin:0">
                    <input type="hidden" name="op" value="purgeAll">
                    <button type="submit" class="be-btn be-btn--danger">Endgültig löschen</button>
                </form>
            </div>
        </details>
        <?php endif; ?>
    </div>
    <div class="be-modal__footer">
        <button type="button" class="be-btn be-btn--ghost" data-popup-close>Schliessen</button>
    </div>
</div>
