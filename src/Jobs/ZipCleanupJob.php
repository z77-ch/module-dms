<?php

namespace Z77\Module\Dms\Jobs;

use Z77\Module\Dms\Services\ZipBundleService;
use Z77\Shared\Jobs\Job;
use Z77\Shared\Jobs\JobContext;
use Z77\Shared\Jobs\JobResult;

/**
 * Removes the manifests of expired ZIP bundles and leftovers (DMS-ZIP-001). An expired bundle is
 * refused by the gate whether this job runs or not, but its manifest holds file names and possibly
 * an address, and nothing else clears them if nobody opens a bundle again.
 * {@see ZipBundleService::open()} runs the same sweep on every new bundle, a bounded number per
 * opening. Stops when the budget is spent and comes again.
 *
 * Ships a daily `defaultSchedule` although it deletes: the exception of jobs.md (JOBS-SCHED-001,
 * owner 2026-11-02). Removing personal data on time is a duty, and it deletes only the job's own
 * expired manifests, never a document. The operator can switch the seeded schedule off.
 *
 * The service argument is a harness seam, the runner calls `new self()`.
 */
final class ZipCleanupJob implements Job
{
    public function __construct(private ?ZipBundleService $service = null) {}

    public function run(JobContext $context): JobResult
    {
        $removed = ($this->service ?? ZipBundleService::create())->sweep(static fn (): bool => $context->hasTimeLeft());
        $note    = sprintf('%d expired ZIP bundle(s) removed', $removed);

        return $context->hasTimeLeft() ? JobResult::done($note) : JobResult::again(null, 0, $note);
    }
}
