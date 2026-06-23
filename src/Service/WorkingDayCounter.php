<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Counts the working days a leave request consumes, per docs/LEAVE_POLICY.md §4:
 * Monday–Friday only, excluding the federal state's public holidays, with each
 * flagged half-day reducing the total by 0.5 when its boundary is a working day.
 */
final class WorkingDayCounter implements WorkingDayCounterInterface
{
    public function __construct(
        private readonly PublicHolidayProviderInterface $holidays,
    ) {
    }

    #[\Override]
    public function count(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        string $federalState,
        bool $halfDayStart,
        bool $halfDayEnd,
    ): float {
        $days = 0.0;
        $startCounts = false;
        $endCounts = false;

        $cursor = $start->setTime(0, 0);
        $last = $end->setTime(0, 0);

        while ($cursor <= $last) {
            if ($this->isWorkingDay($cursor, $federalState)) {
                ++$days;

                if ($cursor == $start->setTime(0, 0)) {
                    $startCounts = true;
                }
                if ($cursor == $last) {
                    $endCounts = true;
                }
            }

            $cursor = $cursor->modify('+1 day');
        }

        if ($halfDayStart && $startCounts) {
            $days -= 0.5;
        }
        if ($halfDayEnd && $endCounts) {
            $days -= 0.5;
        }

        return max(0.0, $days);
    }

    private function isWorkingDay(\DateTimeImmutable $date, string $federalState): bool
    {
        $dayOfWeek = (int) $date->format('N');

        return $dayOfWeek <= 5 && !$this->holidays->isPublicHoliday($date, $federalState);
    }
}
