<?php

namespace Z77\Module\Dms\Jobs;

use Z77\Module\Dms\Services\ChunkedUploadService;
use Z77\Shared\Jobs\Job;
use Z77\Shared\Jobs\JobContext;
use Z77\Shared\Jobs\JobResult;

/**
 * Removes abandoned chunked uploads (F3): an upload directory under `data/dms/chunks/`
 * that received no chunk for 24 h (payload `hours` overrides). Those are transport
 * leftovers of a browser that went away — never a document, never a record; a finished
 * upload removes its own directory. An upload with a chunk or a finish in flight (its
 * manifest locked) is skipped.
 *
 * {@see ChunkedUploadService::begin()} runs the same sweep on every new upload, so the
 * disk is kept in check even while this job is switched off. Single slice: one `scandir`.
 *
 * Payload keys (optional):
 *   hours — inactivity before an upload counts as abandoned (default 24)
 */
final class ChunkCleanupJob implements Job
{
    public function run(JobContext $context): JobResult
    {
        $payload = $context->getPayload();
        $seconds = isset($payload['hours'])
            ? max(1, (int) $payload['hours']) * 3600
            : ChunkedUploadService::TTL_SECONDS;

        $removed = ChunkedUploadService::create()->cleanup($seconds);

        return JobResult::done(sprintf(
            '%d abandoned upload(s) removed (no chunk for %d h)',
            $removed,
            intdiv($seconds, 3600)
        ));
    }
}
