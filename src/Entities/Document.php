<?php

namespace Z77\Module\Dms\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * A stored document — metadata only; the bytes live in `BlobStorage` keyed by this
 * entity's `id` (layout B, ADR-016). Collection-mode entity so the auto-increment `id`
 * is available as the blob key.
 *
 * Most fields are **server-controlled** (no `#[Clean]`): they come from the upload/save
 * pipeline (sniffed MIME, classified kind, size, checksum, source) or from controlled
 * operations (delivery mode / ACL, move, soft-delete), never from a crafted edit form.
 * The only user-editable fields are `displayName` (rename), `showOriginal` (the
 * "serve the original untouched" toggle, OPEN-8), and the localized image texts `alt` /
 * `caption` (per-language maps, set via the Drive edit modal through the gated
 * `DocumentService::setImageText()`) — all still validated server-side.
 * There is NO `area` field (ADR-020): a document's scope IS its position in the folder
 * tree — the root its `folderId` chain leads to, derived, never stored.
 *
 * Access & delivery follow the ownership + ACL model (ADR-017): `ownerId`, `active`, and
 * the `deliveryMode` ladder (`sealed|protected|public`). The pre-R5 `visibility`/`publicPath`
 * fields are gone — public access is a delivery mode, not a per-document flag.
 *
 * Image fields (`profile`, `variants`, `showOriginal`) are only meaningful for
 * `kind = image`. Timestamps and retention/delete markers are ISO-8601 strings
 * (Doctrine-portable). The blob key is the `id` itself — there is no separate
 * `storageKey` (it would only ever duplicate `id`).
 */
#[Entity('file', 'documents/documents.json', invalidatesCache: true)]
class Document
{
    use ArrayMappable;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    private ?int $id = null;

    /** Containing folder — a document ALWAYS lives in a folder (ADR-020; roots are folders). */
    private ?int $folderId = null;

    #[Clean('text')]
    private string $displayName = '';

    /** Original filename as uploaded (sanitised server-side). */
    private string $originalName = '';

    private string $ext = '';
    private string $mimeType = '';

    /** {@see \Z77\Module\Dms\Images\DocumentKind} value (the allowlisted type). */
    private string $kind = '';

    private int $sizeBytes = 0;

    /** sha256 of the original bytes (integrity — layout B has none built in). */
    private string $checksum = '';

    /** `uploaded` | `generated`. */
    private string $source = 'uploaded';

    /** Legal retention deadline (ISO date); a purge must respect it (OPEN-3). */
    private ?string $retentionUntil = null;

    /** Soft-delete marker (ISO datetime); null = live (OPEN-3). */
    private ?string $deletedAt = null;

    /** Image profile applied (OPEN-8); null for non-image / admin-only. */
    private ?string $profile = null;

    /** Serve the original untouched instead of GD derivatives (OPEN-8). */
    #[Clean('bool')]
    private bool $showOriginal = false;

    /** Generated derivatives: `{ name: {w, h, bytes} }`, for srcset. */
    private array $variants = [];

    /** Module-specific free data (e.g. invoice number) — avoids schema churn. */
    private array $meta = [];

    /** Localized alt text (images): `{ lang: text }`. User-editable via the Drive edit modal. */
    private array $alt = [];

    /** Localized caption / figcaption (images): `{ lang: text }`. User-editable via the Drive edit modal. */
    private array $caption = [];

    // ── DMS ownership + ACL + delivery (ADR-017) ───────────────────────────────

    /** Owning principal (user id) — implicit full access (ADR-017). Server-controlled. */
    private ?int $ownerId = null;

    /** Output gate: a deleted-or-inactive document is never served. Server-validated. */
    private bool $active = true;

    /** URL-safe identifier within the folder; basis of the materialization path (R4). */
    private string $slug = '';

    /** Original image dimensions in px (images only); null otherwise. Server-controlled. */
    private ?int $width = null;
    private ?int $height = null;

    /** `sealed|protected|public`; null = inherit from the folder chain (ADR-017). */
    private ?string $deliveryMode = null;

    private ?int $createdBy = null;
    private ?string $createdAt = null;
    private ?string $updatedAt = null;

    public function getId(): ?int { return $this->id; }
    public function getFolderId(): ?int { return $this->folderId; }
    public function getDisplayName(): string { return $this->displayName; }
    public function getOriginalName(): string { return $this->originalName; }
    public function getExt(): string { return $this->ext; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getKind(): string { return $this->kind; }
    public function getSizeBytes(): int { return $this->sizeBytes; }
    public function getChecksum(): string { return $this->checksum; }
    public function getSource(): string { return $this->source; }
    public function getRetentionUntil(): ?string { return $this->retentionUntil; }
    public function getDeletedAt(): ?string { return $this->deletedAt; }
    public function isDeleted(): bool { return $this->deletedAt !== null; }
    public function getProfile(): ?string { return $this->profile; }
    public function showOriginal(): bool { return $this->showOriginal; }
    public function getVariants(): array { return $this->variants; }
    public function getMeta(): array { return $this->meta; }
    public function getAltMap(): array { return $this->alt; }
    public function getCaptionMap(): array { return $this->caption; }
    public function getOwnerId(): ?int { return $this->ownerId; }
    public function isActive(): bool { return $this->active; }
    public function getSlug(): string { return $this->slug; }
    public function getWidth(): ?int { return $this->width; }
    public function getHeight(): ?int { return $this->height; }
    public function getDeliveryMode(): ?string { return $this->deliveryMode; }
    public function getCreatedBy(): ?int { return $this->createdBy; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getUpdatedAt(): ?string { return $this->updatedAt; }

    public function setFolderId(?int $folderId): void
    {
        $this->folderId = ($folderId !== null && $folderId > 0) ? $folderId : null;
    }
    public function setDisplayName(string $displayName): void { $this->displayName = $displayName; }
    public function setOriginalName(string $originalName): void
    {
        // Sanitise server-side: strip any path component (a client filename must never carry
        // directories) and remove control characters (CR/LF/NUL/…) — the value flows into a
        // `Content-Disposition` filename on download and into the tool UI. Multibyte (UTF-8)
        // characters are preserved (control-byte class only). Empty input stays empty.
        $name = basename(str_replace('\\', '/', $originalName));
        $this->originalName = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
    }
    public function setExt(string $ext): void { $this->ext = strtolower(ltrim($ext, '.')); }
    public function setMimeType(string $mimeType): void { $this->mimeType = $mimeType; }
    public function setKind(string $kind): void { $this->kind = $kind; }
    public function setSizeBytes(int $sizeBytes): void { $this->sizeBytes = max(0, $sizeBytes); }
    public function setChecksum(string $checksum): void { $this->checksum = $checksum; }
    public function setSource(string $source): void { $this->source = $source; }
    public function setRetentionUntil(?string $retentionUntil): void { $this->retentionUntil = $retentionUntil ?: null; }
    public function setDeletedAt(?string $deletedAt): void { $this->deletedAt = $deletedAt ?: null; }
    public function setProfile(?string $profile): void { $this->profile = $profile ?: null; }
    public function setShowOriginal(bool $showOriginal): void { $this->showOriginal = $showOriginal; }
    public function setVariants(mixed $variants): void { $this->variants = is_array($variants) ? $variants : []; }
    public function setMeta(mixed $meta): void { $this->meta = is_array($meta) ? $meta : []; }
    public function setAlt(mixed $alt): void { $this->alt = is_array($alt) ? $alt : []; }
    public function setCaption(mixed $caption): void { $this->caption = is_array($caption) ? $caption : []; }
    public function setOwnerId(?int $ownerId): void
    {
        $this->ownerId = ($ownerId !== null && $ownerId > 0) ? $ownerId : null;
    }
    public function setActive(bool $active): void { $this->active = $active; }
    public function setSlug(string $slug): void { $this->slug = $slug; }
    public function setWidth(?int $width): void { $this->width = ($width !== null && $width > 0) ? $width : null; }
    public function setHeight(?int $height): void { $this->height = ($height !== null && $height > 0) ? $height : null; }
    public function setDeliveryMode(?string $deliveryMode): void
    {
        $this->deliveryMode = in_array($deliveryMode, ['sealed', 'protected', 'public'], true) ? $deliveryMode : null;
    }
    public function setCreatedBy(?int $createdBy): void
    {
        $this->createdBy = ($createdBy !== null && $createdBy > 0) ? $createdBy : null;
    }
    public function setCreatedAt(?string $createdAt): void { $this->createdAt = $createdAt ?: null; }
    public function setUpdatedAt(?string $updatedAt): void { $this->updatedAt = $updatedAt ?: null; }
}
