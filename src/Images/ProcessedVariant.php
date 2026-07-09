<?php

namespace Z77\Module\Dms\Images;

/**
 * Result of generating one image derivative (Phase 3): the encoded bytes plus the
 * actual output dimensions and extension. The save path writes {@see $bytes} to
 * `BlobStorage` under the variant name and records `{w, h, bytes}` in
 * `Document.variants` (for the responsive `srcset`).
 */
final class ProcessedVariant
{
    public function __construct(
        public readonly string $name,
        public readonly string $bytes,
        public readonly int $width,
        public readonly int $height,
        public readonly string $ext,
    ) {}

    /**
     * The `Document.variants` metadata row for this derivative. `ext` is recorded because a
     * variant's extension can DIFFER from the document's (a video's original is e.g. `mp4`
     * but its poster derivatives are `jpg`); the delivery/URL/materialization paths read it
     * to locate the right blob instead of assuming the document extension.
     *
     * @return array{w: int, h: int, bytes: int, ext: string}
     */
    public function toMeta(): array
    {
        return ['w' => $this->width, 'h' => $this->height, 'bytes' => strlen($this->bytes), 'ext' => $this->ext];
    }
}
