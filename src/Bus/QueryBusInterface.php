<?php

declare(strict_types=1);

namespace App\Bus;

use App\Entity\LeaveBalance;

interface QueryBusInterface
{
    /**
     * Ask a read-only query and return its handler's result.
     */
    public function ask(QueryInterface $query): mixed;
}
