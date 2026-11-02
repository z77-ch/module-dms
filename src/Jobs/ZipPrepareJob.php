<?php

namespace Z77\Module\Dms\Jobs;

use Z77\Module\Dms\Services\ZipBundleService;
use Z77\Shared\Jobs\Job;
use Z77\Shared\Jobs\JobContext;
use Z77\Shared\Jobs\JobResult;

/**
 * Computes the CRC-32 of the originals behind the waiting ZIP bundles (DMS-ZIP-001), oldest
 * bundle first, and sends the mail of a bundle that got ready. A slice stops when the budget
 * is spent, mid-file if need be; the cursor lives in each bundle's manifest, not in the queue
 * entry, so a queue that lost its entry loses nothing: a bundle with no live holder is queued
 * again by its owner's next look at it, or by any next opening, a refused one too.
 *
 * Reads blobs without the owner's rights and outputs nothing but CRCs: the rights are asked at
 * creation and on every piece ({@see ZipBundleService}). No schedule, a new bundle queues it.
 *
 * Takes no payload. The service argument is a harness seam, the runner calls `new self()`.
 */
final class ZipPrepareJob implements Job
{
    public function __construct(private ?ZipBundleService $service = null) {}

    public function run(JobContext $context): JobResult
    {
        $counts = ($this->service ?? ZipBundleService::create())->prepare(static fn (): bool => $context->hasTimeLeft());

        $note = sprintf('%d ready, %d failed, %d still open', $counts['ready'], $counts['failed'], $counts['open']);

        return $counts['open'] > 0 ? JobResult::again(null, 0, $note) : JobResult::done($note);
    }
}
