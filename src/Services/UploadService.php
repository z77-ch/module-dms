<?php

namespace Z77\Module\Dms\Services;

use Z77\Module\Dms\Images\DocumentKind;
use Z77\Module\Dms\Entities\Document;
use Z77\Shared\ValueObjects\UploadedFile;

/**
 * HTTP-side adapter for the save path (Phase 3): turns a validated {@see UploadedFile}
 * into a {@see SaveRequest} and delegates to {@see SaveService}. It owns the upload-time
 * gates — error check, size limit, and the MIME allowlist ({@see DocumentKind::fromMime})
 * on a server-side sniff — but stays HTTP-agnostic (it never touches `$_FILES`; the
 * `Request` bridge produced the VO). The location IS the target `folderId` (ADR-020);
 * write access on it is enforced in the domain (session principal).
 *
 * Same-name handling (decided 2026-07-02):
 *  - same original name + IDENTICAL bytes (checksum) → {@see DuplicateUploadException}
 *    (dedupe — the caller surfaces an informational skip);
 *  - same original name + different bytes → {@see NameConflictException} unless the
 *    caller passes `overwrite: true` (the user confirmed) — then the existing document
 *    is replaced IN PLACE ({@see SaveService::replace}: id/slug/URL/ACL/mode stay).
 *    Overwrite additionally requires `manage` on the existing document (a folder-write
 *    holder must not destroy someone else's content) and is refused for an effectively
 *    `sealed` document or one under an active retention period.
 */
final class UploadService
{
    /**
     * Memory head-room reserved for the running request (framework + PHP baseline) before
     * an image's decoded pixels + GD working copies must fit. Subtracted from `memory_limit`
     * in {@see effectiveMaxUploadBytes}. A conservative fixed proxy for `memory_get_usage()`
     * (which is not meaningful outside a request), tunable after seeing real backend memory.
     */
    private const BASE_RESERVE = 64 * 1024 * 1024;

    public function __construct(
        private SaveService $saveService,
        private Authz $authz,
        private int $maxBytes = PHP_INT_MAX,
    ) {}

    /**
     * The default cap is the server's own effective per-file limit
     * ({@see serverMaxBytes}) — there is no separate app knob to drift out of sync with
     * PHP. A caller may still pass a stricter ceiling.
     */
    public static function create(?int $maxBytes = null): self
    {
        return new self(SaveService::create(), Authz::create(), $maxBytes ?? self::serverMaxBytes());
    }

    /**
     * The server's effective per-file upload ceiling in bytes: the smaller of
     * `upload_max_filesize` and `post_max_size` (a `0`/unset limit = unlimited). This is
     * the real limit PHP enforces, so the app cap follows it — raising the ceiling is a
     * PHP-config change (php.ini / `.user.ini`), not a code change. NOTE: this is only the
     * transport ceiling; a large file is still loaded whole into memory during save, so
     * `memory_limit` is the tighter gate for big files (guarded in {@see save}).
     */
    public static function serverMaxBytes(): int
    {
        return min(self::iniBytes('upload_max_filesize'), self::iniBytes('post_max_size'));
    }

    /**
     * The stricter per-file ceiling for files whose bytes must enter RAM — i.e. IMAGES
     * (GD decodes the pixels) and a client poster. Since P0 a non-image upload original is
     * MOVED into the blob and never read, so it is bounded by {@see serverMaxBytes} (the
     * transport cap) alone; a big video passes on cyon regardless of `memory_limit`.
     *
     * = `min( serverMaxBytes(), floor( (memory_limit - BASE_RESERVE) / 1.2 ) )`. The `/1.2`
     * mirrors {@see fitsMemory} (the decoded string plus overhead); an unlimited
     * `memory_limit` (-1) falls back to the transport cap. Clamped ≥ 0. The client applies
     * this as `data-max-image-bytes` to `image/*` only; the server's `fitsMemory` guard in
     * {@see save} remains the authoritative second line.
     */
    public static function effectiveMaxUploadBytes(): int
    {
        $transport = self::serverMaxBytes();
        $memLimit  = self::iniBytes('memory_limit');
        if ($memLimit === PHP_INT_MAX) {
            return $transport; // unlimited memory → bounded only by transport
        }
        $usable = $memLimit - self::BASE_RESERVE;
        if ($usable <= 0) {
            return 0;
        }

        return min($transport, intdiv($usable * 5, 6)); // *5/6 ≈ /1.2
    }

    /**
     * Whether a file of $size bytes can be loaded whole into the remaining memory budget.
     * Needs ~1.2× the size (the byte string plus PHP/framework overhead); an unlimited
     * `memory_limit` (-1) always fits. Measured from USED bytes, not reserved — see
     * {@see GdImageProcessor::fitsMemory} (DMS-MEM-001): `memory_get_usage(true)` counts
     * ZendMM's reusable cached chunks and yields false negatives in a persistent worker.
     */
    private function fitsMemory(int $size): bool
    {
        $limit = self::iniBytes('memory_limit');
        if ($limit === PHP_INT_MAX) {
            return true; // unlimited
        }
        $needed    = $size + intdiv($size, 5); // 1.2×
        $available = $limit - memory_get_usage(false);

        return $needed <= $available;
    }

    /** An ini byte-size directive in bytes; empty / `0` / `-1` (unlimited) → PHP_INT_MAX. */
    private static function iniBytes(string $key): int
    {
        $raw = trim((string) ini_get($key));
        if ($raw === '' || $raw === '0' || $raw === '-1') {
            return PHP_INT_MAX;
        }
        $value = (int) $raw;

        return match (strtoupper(substr($raw, -1))) {
            'G'     => $value * 1024 ** 3,
            'M'     => $value * 1024 ** 2,
            'K'     => $value * 1024,
            default => $value,
        };
    }

    /**
     * Validate and store one uploaded file.
     *
     * @throws DuplicateUploadException identical bytes already live in the folder (skip)
     * @throws NameConflictException    same name, different bytes, no overwrite consent
     * @throws \RuntimeException        on an upload error, an oversize file, an unreadable
     *                                  temp file, a non-allowlisted type, or a blocked overwrite
     */
    public function save(
        UploadedFile $file,
        ?int $folderId = null,
        ?string $profile = null,
        bool $showOriginal = false,
        ?int $createdBy = null,
        bool $overwrite = false,
        ?UploadedFile $poster = null,
    ): Document {
        // Upload = write into the target folder (a document always lives in a folder;
        // ADR-020/021 — SaveService additionally rejects the drive root as a target).
        // Enforced in the domain, session principal.
        if ($folderId === null) {
            throw new \RuntimeException('UploadService: a target folder is required.');
        }
        $this->authz->require('folder', $folderId, 'write');

        if (!$file->isOk()) {
            throw new \RuntimeException("UploadService: upload failed (error {$file->error}).");
        }
        if ($file->size > $this->maxBytes) {
            throw new \RuntimeException(
                "UploadService: file exceeds the {$this->maxBytes}-byte limit ({$file->size})."
            );
        }

        $mime = $file->sniffMime();
        $kind = DocumentKind::fromMime($mime, $file->extension());
        if ($kind === null) {
            throw new \RuntimeException("UploadService: file type '{$mime}' is not allowed.");
        }

        // The upload original is MOVED into the blob (never read into memory). Only an IMAGE
        // original is decoded in RAM (GD needs the pixels), so the memory pre-check applies
        // ONLY to images — a big video is bounded by the transport cap, not by memory_limit.
        // The check pre-empts an OOM fatal that would orphan the Document row (ARCH-A003).
        if ($kind->hasImageVariants() && !$this->fitsMemory($file->size)) {
            throw new \RuntimeException(
                "UploadService: image too large for the available memory ({$file->size} bytes) — "
                . 'raise memory_limit.'
            );
        }

        // Streamed checksum (no whole-file read) for the duplicate / conflict decision;
        // reused by SaveService so the file is hashed only once.
        $checksum = $file->sha256();
        if ($checksum === null) {
            throw new \RuntimeException('UploadService: cannot read the uploaded temp file.');
        }

        $request = new SaveRequest(
            originalName: $file->originalName,
            mimeType: $mime,
            folderId: $folderId,
            source: 'uploaded',
            profile: $profile,
            showOriginal: $showOriginal,
            createdBy: $createdBy,
            posterBytes: $this->posterBytesFor($kind, $poster),
        );

        $existing = $this->findLiveByName($folderId, $file->originalName);
        if ($existing !== null) {
            if ($existing->getChecksum() === $checksum) {
                throw new DuplicateUploadException(
                    "«{$file->originalName}» ist in diesem Ordner bereits vorhanden (identischer Inhalt)."
                );
            }
            if (!$overwrite) {
                throw new NameConflictException(
                    "«{$file->originalName}» existiert in diesem Ordner bereits mit anderem Inhalt."
                );
            }

            return $this->replaceExisting($existing, $file, $request, $checksum);
        }

        return $this->saveService->saveFromUpload($file, $request, $checksum);
    }

    /**
     * Overwrite path (user-confirmed): guards, then in-place replace. `manage` on the
     * existing document (owner/ACL/admin), never a plain folder-write; an effectively
     * `sealed` document never leaves/changes its vault state via an upload; a running
     * retention period blocks content destruction just like a purge.
     */
    private function replaceExisting(Document $existing, UploadedFile $file, SaveRequest $request, string $checksum): Document
    {
        $id = (int) $existing->getId();
        $this->authz->require('document', $id, 'manage');

        $docs = DocumentService::create();
        if ($docs->effectiveDeliveryMode($existing) === 'sealed') {
            throw new \RuntimeException('Ein versiegeltes Dokument kann nicht überschrieben werden.');
        }
        $until = $existing->getRetentionUntil();
        if ($until !== null && strtotime($until) > time()) {
            throw new \RuntimeException("Aufbewahrungsfrist läuft noch (bis {$until}) — Überschreiben gesperrt.");
        }

        return $this->saveService->replaceFromUpload($existing, $file, $request, $checksum);
    }

    /**
     * The poster bytes to hand to the save path, or null. A poster is accepted ONLY for a
     * kind that {@see DocumentKind::acceptsPoster()} (video) and MUST itself sniff to an
     * image — a bad/oversized/non-image poster is ignored, NEVER an error (it must not fail
     * the video upload). The poster is small (a downscaled JPEG) but still memory-guarded.
     */
    private function posterBytesFor(DocumentKind $kind, ?UploadedFile $poster): ?string
    {
        if ($poster === null || !$poster->isOk() || !$kind->acceptsPoster()) {
            return null;
        }
        $posterKind = DocumentKind::fromMime($poster->sniffMime(), $poster->extension());
        if ($posterKind !== DocumentKind::Image || !$this->fitsMemory($poster->size)) {
            return null;
        }

        return $poster->bytes();
    }

    /** The live document with this original name in the folder, or null. */
    private function findLiveByName(?int $folderId, string $originalName): ?Document
    {
        foreach (DocumentService::create()->listByFolder($folderId) as $doc) {
            if ($doc->getOriginalName() === $originalName) {
                return $doc;
            }
        }

        return null;
    }
}
