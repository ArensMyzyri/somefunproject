<?php

declare(strict_types=1);

namespace App\Service;

interface WorkingDayCounterInterface
{
    /**
     * The working days a leave request consumes over the inclusive date range:
     * Monday–Friday only, excluding the federal state's public holidays, with each
     * flagged half-day reducing the total by 0.5 when its boundary is a working day.
     *
     * @param string $federalState federal state code, e.g. "BY", "BE"
     *
     * @return float non-negative count of consumed working days
     */
    public function count(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        string $federalState,
        bool $halfDayStart,
        bool $halfDayEnd,
    ): float;
}
