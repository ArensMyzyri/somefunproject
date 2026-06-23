<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\DispatchSummary;

interface LeaveRequestProcessorInterface
{
    /**
     * Enqueue every pending leave request for asynchronous processing as of the run
     * date, returning how many were dispatched.
     */
    public function processPending(\DateTimeImmutable $runDate): DispatchSummary;

    /**
     * Decide a single pending request: count and evaluate it, report the decision to
     * the HR system, then persist the decision and any balance change. A request that
     * is no longer pending is left untouched (re-run safety).
     */
    public function processOne(int $requestId, \DateTimeImmutable $runDate): void;
}
