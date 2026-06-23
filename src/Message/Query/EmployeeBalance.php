<?php

declare(strict_types=1);

namespace App\Message\Query;

use App\Bus\QueryInterface;
use App\Entity\Employee;

/**
 * Find an employee's already-approved leave whose dates overlap a given range.
 *
 * Used both to reject a vacation that clashes with approved leave and, with
 * {@see self::$vacationOnly} set, to find the approved vacation a sick request
 * overlaps for credit-back.
 */
final readonly class EmployeeBalance implements QueryInterface
{
    public function __construct(
        public Employee $employee,
        public int $year,
    ) {
    }
}
