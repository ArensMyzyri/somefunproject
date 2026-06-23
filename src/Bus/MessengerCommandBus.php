<?php

declare(strict_types=1);

namespace App\Bus;

use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerCommandBus implements CommandBusInterface
{
    use HandleTrait;

    public function __construct(MessageBusInterface $commandBus)
    {
        $this->messageBus = $commandBus;
    }

    #[\Override]
    public function execute(CommandInterface $command): mixed
    {
        return $this->handle($command);
    }

    #[\Override]
    public function dispatch(CommandInterface $command): void
    {
        $this->messageBus->dispatch($command);
    }
}
