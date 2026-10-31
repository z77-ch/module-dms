<?php
/**
 * DMS Drive — player dialog for a LARGE video / audio (DMS-CHUNK-DL-001, owner 2026-10-31).
 * Opening such a file in a new tab sends no `Range` → one answer over the host's body cap
 * (cyon: 500 MB) → 413. Inside this dialog the browser's own player asks the same gated
 * preview URL in ranges (`bytes=0-`, then on), and `FileResponse` caps every range at
 * `RANGE_MAX_BYTES` — so every answer stays below the cap, seeking included. No script.
 * Whether the browser can PLAY the format is the browser's business (AVI / WMV: no —
 * the dialog says so and offers the download).
 *
 * @var array{name: string, src: string, kind: string}|null $player null = not found / no access
 */
?>
<div class="be-modal__header"><h2 class="be-modal__title"><?= $player !== null ? e($player['name']) : 'Abspielen' ?></h2></div>
<?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen'], 'Z77\\Shared') ?>
<div class="be-modal__body">
<?php if ($player === null): ?>
    <p>Dokument nicht gefunden.</p>
<?php elseif ($player['kind'] === 'audio'): ?>
    <audio controls preload="metadata" src="<?= e($player['src']) ?>" style="width:100%"></audio>
<?php else: ?>
    <video controls preload="metadata" src="<?= e($player['src']) ?>" style="width:100%;max-height:70vh;background:#000"></video>
<?php endif; ?>
<?php if ($player !== null): ?>
    <p style="font-size:.8rem;color:var(--be-muted,#94a3b8)">Spielt der Browser das Format nicht ab (z.B. AVI, WMV), bitte herunterladen.</p>
<?php endif; ?>
</div>
