<?php

declare(strict_types=1);

namespace App\Bus;

interface CommandBusInterface
{
    /**
     * Execute a state-changing command synchronously and return its handler's result.
     */
    public function execute(CommandInterface $command): mixed;

    /**
     * Dispatch a command without waiting for a result — used for commands routed to an
     * asynchronous transport (the handler runs later, in a worker).
     */
    public function dispatch(CommandInterface $command): void;
}
