<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Bus\CommandInterface;

/**
 * Process every pending leave request as of the given run date.
 */
final readonly class RunAbsence implements CommandInterface
{
    public function __construct(
        public \DateTimeImmutable $runDate,
    ) {
    }
}
