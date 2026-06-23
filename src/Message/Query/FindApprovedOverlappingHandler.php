<?php

declare(strict_types=1);

namespace App\Message\Query;

use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Enum\LeaveType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class FindApprovedOverlappingHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<LeaveRequest>
     */
    public function __invoke(FindApprovedOverlapping $query): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(LeaveRequest::class, 'r')
            ->andWhere('r.employee = :employee')
            ->andWhere('r.status = :status')
            ->andWhere('r.startDate <= :end')
            ->andWhere('r.endDate >= :start')
            ->setParameter('employee', $query->employee)
            ->setParameter('status', LeaveStatus::APPROVED)
            ->setParameter('start', $query->start, Types::DATE_IMMUTABLE)
            ->setParameter('end', $query->end, Types::DATE_IMMUTABLE)
            ->orderBy('r.startDate', 'ASC');

        if ($query->vacationOnly) {
            $qb->andWhere('r.type = :type')->setParameter('type', LeaveType::VACATION);
        }

        return $qb->getQuery()->getResult();
    }
}
