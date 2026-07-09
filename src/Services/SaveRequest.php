<?php

namespace Z77\Module\Dms\Services;

/**
 * The source-independent input to {@see SaveService::save()} — everything about a new
 * document except its bytes. Built by `UploadService` (from an `UploadedFile`) or by a
 * module that generates a file (e.g. an invoice PDF). `mimeType` is the server-side
 * sniff (the trusted type). The location IS the `folderId` (ADR-020) — there is no
 * `area` label; a module obtains its partition via `DocumentService::rootFolder($key)`
 * and saves into (a subfolder of) it.
 */
final class SaveRequest
{
    public function __construct(
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly ?int $folderId = null,
        public readonly string $displayName = '',
        public readonly string $source = 'uploaded',
        public readonly ?string $profile = null,
        public readonly bool $showOriginal = false,
        public readonly ?int $createdBy = null,
        public readonly ?string $retentionUntil = null,
        public readonly array $meta = [],
        // DMS ownership + delivery (ADR-017). `ownerId` null → SaveService falls back to
        // `createdBy` (the acting user is the owner at creation). `active` defaults live
        // (the "publish-on-approval" option is deferred — ADR-017 open point). The
        // `deliveryMode` is not set at save time — it inherits the folder chain (effective
        // default `protected`) and is changed later via the management surface (R6).
        public readonly ?int $ownerId = null,
        public readonly bool $active = true,
        // Optional browser-extracted poster (video upload, P4): ONE image the save path runs
        // through the GD/`ImageProfile` pipeline so a video document gets `s/m/l/xl` variants
        // like an image. Consumed only when the kind {@see DocumentKind::acceptsPoster()};
        // ignored otherwise. Small (a downscaled JPEG), so it is passed as bytes.
        public readonly ?string $posterBytes = null,
    ) {}
}
