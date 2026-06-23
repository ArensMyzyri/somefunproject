<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * The result of an absence run dispatch: how many pending requests were enqueued for
 * asynchronous processing.
 */
final readonly class DispatchSummary
{
    public function __construct(
        public int $dispatched,
    ) {
    }
}
