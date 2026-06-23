<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Dto\DispatchSummary;
use App\Service\LeaveRequestProcessorInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunAbsenceHandler
{
    public function __construct(
        private LeaveRequestProcessorInterface $processor,
    ) {
    }

    public function __invoke(RunAbsence $command): DispatchSummary
    {
        return $this->processor->processPending($command->runDate);
    }
}
