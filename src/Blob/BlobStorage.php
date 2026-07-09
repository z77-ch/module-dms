<?php

namespace Z77\Module\Dms\Blob;

/**
 * Byte storage for documents — the second of the "two separate worlds" (ADR-016):
 * metadata lives in the entity layer, the raw bytes live here, in a store that is
 * deliberately separate from the metadata driver. `FileStorage` (JSON collections)
 * is never abused for bytes.
 *
 * The store is **id-addressed** (blob layout B): a logical document is identified by
 * its auto-increment `id`, and every blob of that document — the original plus any
 * image derivatives (variants) — lives under one per-id directory:
 *
 *   data/blobs/<shard>/<id>/<variant>.<ext>
 *
 * Consequences of id-addressing (ADR-016):
 * - **Move/rename is metadata-only** — the bytes never move, so a folder reparent of
 *   1'000 documents costs zero file operations here.
 * - **No user input in the path** — path traversal is structurally excluded.
 * - **Purge is one directory removal** — {@see delete()} drops all variants at once.
 *
 * The store is **variant-aware but profile-agnostic**: it knows how to put/get a named
 * variant, but the set of variants (the image profiles) is decided in
 * `Z77\Shared\Documents`, not here. `ext` is passed explicitly on every call so the
 * store never has to guess a file extension from disk; v1 derivatives keep the source
 * format, a later per-variant format (WebP/AVIF) just passes a different `ext`.
 *
 * Driver-capable: {@see LocalBlobStorage} is the local-filesystem implementation; an
 * S3/object-store implementation can follow the same contract (persistence philosophy).
 */
interface BlobStorage
{
    /** Variant name of the untouched, uploaded/generated original. */
    public const ORIGINAL = 'orig';

    /**
     * Write the bytes of one variant, creating the per-id directory if needed.
     * Overwrites an existing variant (replace = overwrite bytes, OPEN-6).
     *
     * @param int    $id      Document id (the blob key).
     * @param string $variant Variant name ({@see ORIGINAL} or a profile variant, e.g. `s`).
     * @param string $ext     File extension without dot (e.g. `jpg`).
     * @param string $bytes   Raw file contents.
     *
     * @throws \InvalidArgumentException on an invalid id / variant / ext.
     * @throws \RuntimeException         when the bytes cannot be written.
     */
    public function put(int $id, string $variant, string $ext, string $bytes): void;

    /**
     * Store one variant by MOVING an existing file into the blob slot, instead of taking
     * its bytes in memory ({@see put}). This is how a large upload original is stored — the
     * PHP temp file is moved straight in, so a multi-GB file never enters PHP memory.
     *
     * @param string $sourcePath Absolute path of the source file (e.g. an upload temp file).
     * @param bool   $isUpload   True for an HTTP upload temp file → `move_uploaded_file`
     *                           (validates it really is an upload); false → a plain rename/copy.
     *
     * @throws \InvalidArgumentException on an invalid id / variant / ext.
     * @throws \RuntimeException         when the source cannot be moved into place.
     */
    public function putFile(int $id, string $variant, string $ext, string $sourcePath, bool $isUpload = true): void;

    /**
     * Read the bytes of one variant, or null if it does not exist.
     */
    public function get(int $id, string $variant, string $ext): ?string;

    /**
     * Absolute filesystem path of one variant, or null if it does not exist.
     * Used by the delivery layer to stream the file with Range support
     * (portable PHP stream — see ADR-017).
     */
    public function path(int $id, string $variant, string $ext): ?string;

    /**
     * Whether the given variant exists on the store.
     */
    public function exists(int $id, string $variant, string $ext): bool;

    /**
     * Size of one variant in bytes, or null if it does not exist.
     */
    public function size(int $id, string $variant, string $ext): ?int;

    /**
     * Remove **all** variants of a document (the whole per-id directory).
     * A no-op if nothing is stored for the id. Idempotent.
     */
    public function delete(int $id): void;
}
