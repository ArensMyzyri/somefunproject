<?php

declare(strict_types=1);

namespace App\Message\Query;

use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class FindPendingRequestIdsHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Returns scalar rows (no entity hydration), oldest submission first.
     *
     * @return list<array{id: int, submittedAt: \DateTimeImmutable}>
     */
    public function __invoke(FindPendingRequestIds $query): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('r.id', 'r.submittedAt')
            ->from(LeaveRequest::class, 'r')
            ->where('r.status = :status')
            ->setParameter('status', LeaveStatus::PENDING)
            ->orderBy('r.submittedAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->setMaxResults($query->limit);

        if (null !== $query->afterSubmittedAt) {
            $qb->andWhere('(r.submittedAt > :afterSubmittedAt OR (r.submittedAt = :afterSubmittedAt AND r.id > :afterId))')
                ->setParameter('afterSubmittedAt', $query->afterSubmittedAt, Types::DATE_IMMUTABLE)
                ->setParameter('afterId', $query->afterId);
        }

        return $qb->getQuery()->getArrayResult();
    }
}
