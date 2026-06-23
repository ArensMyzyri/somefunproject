<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Employee;
use App\Entity\LeaveBalance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LeaveBalance>
 */
class LeaveBalanceRepository extends ServiceEntityRepository implements LeaveBalanceRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaveBalance::class);
    }

    #[\Override]
    public function findForEmployeeAndYear(Employee $employee, int $year): ?LeaveBalance
    {
        return $this->findOneBy(['employee' => $employee, 'year' => $year]);
    }
}
