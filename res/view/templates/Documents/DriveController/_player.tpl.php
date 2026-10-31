<?php
/**
 * DMS Drive — player dialog for a LARGE video / audio (DMS-CHUNK-DL-001, owner 2026-10-31).
 * Opening such a file in a new tab sends no `Range` → one answer over the host's body cap
 * (cyon: 500 MB) → 413. Inside this dialog the browser's own player asks the same gated
 * preview URL in ranges (`bytes=0-`, then on), and `FileResponse` caps every range at
 * `RANGE_MAX_BYTES` — so every answer stays below the cap, seeking included. No script.
 *
 * A format no browser plays (WMV, AVI, …; `DriveControllerTrait::BROWSER_PLAYABLE`) gets
 * no player — a player stuck at 0:00 explains nothing (owner 2026-10-31) — but the hint
 * and the download (chunked from `chunkedDownloadFrom` on, drive.js picks the link up).
 *
 * @var array{name: string, src: string, kind: string, playable: bool, chunk: ?array}|null $player
 *      null = not found / no access
 */
?>
<div class="be-modal__header"><h2 class="be-modal__title"><?= $player !== null ? e($player['name']) : 'Abspielen' ?></h2></div>
<?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen'], 'Z77\\Shared') ?>
<div class="be-modal__body">
<?php if ($player === null): ?>
    <p>Dokument nicht gefunden.</p>
<?php elseif (!$player['playable']): ?>
    <?php
    $chunk     = $player['chunk'];
    $chunkAttr = $chunk !== null
        ? ' data-dms-chunk-download="' . e($chunk['url']) . '" data-dms-chunk-name="' . e($chunk['name']) . '" data-dms-chunk-bytes="' . (int) $chunk['bytes'] . '"'
        : '';
    $download  = $chunk !== null ? $chunk['url'] : str_replace('/document/preview?', '/document/download?', $player['src']);
    ?>
    <p>Dieses Format kann der Browser nicht abspielen. Bitte herunterladen und mit einem Programm wie VLC öffnen — oder vor dem Hochladen in MP4 umwandeln.</p>
    <p><a class="dms-btn dms-btn--ghost" href="<?= e($download) ?>"<?= $chunkAttr ?>><svg class="dms-icon"><use href="#i-download"/></svg> Download</a></p>
<?php elseif ($player['kind'] === 'audio'): ?>
    <audio controls preload="metadata" src="<?= e($player['src']) ?>" style="width:100%"></audio>
<?php else: ?>
    <video controls preload="metadata" src="<?= e($player['src']) ?>" style="width:100%;max-height:70vh;background:#000"></video>
<?php endif; ?>
</div>
