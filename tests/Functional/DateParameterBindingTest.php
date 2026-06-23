<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Employee;
use App\Entity\LeaveRequest;
use App\Enum\LeaveType;
use Doctrine\DBAL\Types\Types;

/**
 * Demonstrates that the Doctrine parameter type changes the result of a date
 * comparison against a DATE column. An untyped DateTimeImmutable is inferred as a
 * datetime and binds as "2025-05-23 00:00:00", which neither equals nor is <= the
 * stored DATE "2025-05-23"; binding it as DATE_IMMUTABLE binds "2025-05-23", which
 * matches. The application's overlap query relies on this for boundary days.
 */
final class DateParameterBindingTest extends AbsenceRunTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->persistRequest('2025-05-19', '2025-05-23');
    }

    public function testExactMatchOnlyWorksWhenBoundAsDate(): void
    {
        $start = new \DateTimeImmutable('2025-05-19');

        self::assertCount(0, $this->findByStart($start, null));
        self::assertCount(1, $this->findByStart($start, Types::DATE_IMMUTABLE));
    }

    public function testBoundaryGreaterOrEqualOnlyWorksWhenBoundAsDate(): void
    {
        // The stored endDate is exactly the boundary; this is the overlap-query case.
        $boundary = new \DateTimeImmutable('2025-05-23');

        self::assertCount(0, $this->findByEndAtLeast($boundary, null));
        self::assertCount(1, $this->findByEndAtLeast($boundary, Types::DATE_IMMUTABLE));
    }

    private function persistRequest(string $start, string $end): void
    {
        $employee = new Employee('Test', new \DateTimeImmutable('2018-01-01'), 5, 'BE', 28);
        $request = new LeaveRequest(
            $employee,
            LeaveType::VACATION,
            new \DateTimeImmutable($start),
            new \DateTimeImmutable($end),
            new \DateTimeImmutable('2025-04-10'),
        );
        $this->persist($employee, $request);
    }

    /** @return list<LeaveRequest> */
    private function findByStart(\DateTimeImmutable $start, ?string $type): array
    {
        $qb = $this->em->getRepository(LeaveRequest::class)->createQueryBuilder('r')->where('r.startDate = :s');
        null === $type ? $qb->setParameter('s', $start) : $qb->setParameter('s', $start, $type);

        return $qb->getQuery()->getResult();
    }

    /** @return list<LeaveRequest> */
    private function findByEndAtLeast(\DateTimeImmutable $end, ?string $type): array
    {
        $qb = $this->em->getRepository(LeaveRequest::class)->createQueryBuilder('r')->where('r.endDate >= :e');
        null === $type ? $qb->setParameter('e', $end) : $qb->setParameter('e', $end, $type);

        return $qb->getQuery()->getResult();
    }
}
