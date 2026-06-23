<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Employee;
use App\Entity\LeaveBalance;

interface LeaveBalanceRepositoryInterface
{
    public function findForEmployeeAndYear(Employee $employee, int $year): ?LeaveBalance;
}
