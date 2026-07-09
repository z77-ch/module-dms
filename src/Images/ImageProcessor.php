<?php

namespace Z77\Module\Dms\Images;

/**
 * Generates image derivatives from source bytes (Phase 3, OPEN-8). Pure transform:
 * bytes in → encoded derivatives out, with no `BlobStorage` or DI coupling — the
 * caller ({@see \Z77\Module\Dms\Services\SaveService}) decides WHICH specs to produce
 * (admin always; the project profile unless the original is preserved) and writes the
 * returned bytes to the store. This keeps the processor fully unit-testable and lets a
 * different backend (Imagick) drop in behind the same contract.
 *
 * Implementations MUST downscale only (never upscale past the source dimensions) and
 * MUST keep the source format in v1 (a per-variant `format` opt-in comes later).
 */
interface ImageProcessor
{
    /**
     * @param VariantSpec[] $specs
     * @return array<string, ProcessedVariant> keyed by variant name; a spec is skipped
     *         (absent from the result) only if the source cannot be decoded.
     */
    public function generate(string $bytes, string $sourceExt, array $specs): array;
}
