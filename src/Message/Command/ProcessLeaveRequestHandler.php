<?php

declare(strict_types=1);

namespace App\Message\Command;

use App\Service\LeaveRequestProcessorInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ProcessLeaveRequestHandler
{
    public function __construct(
        private LeaveRequestProcessorInterface $processor,
    ) {
    }

    public function __invoke(ProcessLeaveRequest $command): void
    {
        $this->processor->processOne($command->requestId, $command->runDate);
    }
}
