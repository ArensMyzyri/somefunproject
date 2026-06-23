<?php

declare(strict_types=1);

namespace App\Bus;

use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerQueryBus implements QueryBusInterface
{
    use HandleTrait;

    public function __construct(MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    #[\Override]
    public function ask(QueryInterface $query): mixed
    {
        return $this->handle($query);
    }
}
