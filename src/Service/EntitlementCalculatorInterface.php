<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Employee;
use App\Entity\LeaveBalance;

interface EntitlementCalculatorInterface
{
    /**
     * The employee's earned annual leave entitlement for the given year, in working
     * days, after pro-rata and part-time scaling.
     */
    public function entitlementFor(Employee $employee, int $year): float;

    /**
     * The carried-over days still valid at the run date (0.0 once they have lapsed).
     */
    public function validCarriedOverDays(LeaveBalance $balance, \DateTimeImmutable $runDate): float;
}
