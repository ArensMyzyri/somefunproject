<?php

declare(strict_types=1);

namespace App\Message\Query;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use App\Entity\LeaveBalance;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class EmployeeBalanceHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(EmployeeBalance $query): ?LeaveBalance
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(LeaveBalance::class, 'b')
            ->andWhere('b.employee = :employee')
            ->andWhere('b.year = :year')
            ->setParameter('employee', $query->employee)
            ->setParameter('year', $query->year);


        return $qb->getQuery()->getOneOrNullResult();
    }
}
