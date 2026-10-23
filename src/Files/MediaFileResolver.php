<?php

namespace Z77\Module\Dms\Files;

use Z77\Module\Dms\Services\DocumentService;
use Z77\Shared\Files\LocalFileResolver;

/**
 * The Drive's `/media/…` path (what «Pfad kopieren» copies) → the document's file in the blob
 * store, for server-side use such as the mandator's logo in the PDF and the mail (2026-10-23).
 * Only a document that is PUBLIC and on an active chain resolves — the same answer `/media` gives
 * the world, so a setting cannot reach a protected file through this door.
 */
final class MediaFileResolver implements LocalFileResolver
{
    public function resolve(string $path): ?string
    {
        return DocumentService::create()->publicFileForPath($path);
    }
}
