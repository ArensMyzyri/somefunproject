<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Employee;
use App\Entity\LeaveBalance;

/**
 * Computes an employee's earned annual leave entitlement and the still-valid
 * carried-over days, per docs/LEAVE_POLICY.md §1–§3 and §6.
 */
final class EntitlementCalculator implements EntitlementCalculatorInterface
{
    #[\Override]
    public function entitlementFor(Employee $employee, int $year): float
    {
        $fullMonths = $this->fullMonthsEmployedInYear($employee, $year);

        $raw = $employee->getContractualLeaveDays()
            * ($fullMonths / 12)
            * ($employee->getWorkingDaysPerWeek() / 5);

        return ceil($raw * 2) / 2;
    }

    #[\Override]
    public function validCarriedOverDays(LeaveBalance $balance, \DateTimeImmutable $runDate): float
    {
        $expiresOn = $balance->getCarryoverExpiresOn();

        if (null !== $expiresOn && $runDate > $expiresOn) {
            return 0.0;
        }

        return $balance->getCarriedOverDays();
    }

    private function fullMonthsEmployedInYear(Employee $employee, int $year): int
    {
        $start = $employee->getEmploymentStartDate();
        $end = $employee->getEmploymentEndDate();

        $fullMonths = 0;

        for ($month = 1; $month <= 12; ++$month) {
            $firstOfMonth = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $lastOfMonth = $firstOfMonth->modify('last day of this month');

            if ($start <= $firstOfMonth && (null === $end || $end >= $lastOfMonth)) {
                ++$fullMonths;
            }
        }

        return $fullMonths;
    }
}
