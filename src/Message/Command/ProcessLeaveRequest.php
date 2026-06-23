<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Bus\CommandInterface;

/**
 * Decide a single pending leave request. Routed to an asynchronous transport so each
 * request is its own retriable unit of work.
 */
final readonly class ProcessLeaveRequest implements CommandInterface
{
    public function __construct(
        public int $requestId,
        public \DateTimeImmutable $runDate,
    ) {
    }
}
