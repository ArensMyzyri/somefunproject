<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use Doctrine\DBAL\Types\Types;

/**
 * End-to-end run over the seeded sample period, asserting the full expected decision
 * set for all six employees plus the resulting balances.
 */
final class SeededPeriodScenarioTest extends AbsenceRunTestCase
{
    public function testSeededPeriodResolvesAsExpected(): void
    {
        (new AppFixtures())->load($this->em);

        $this->processAll(new \DateTimeImmutable('2025-04-15'));

        // Anna — May vacation exceeds remaining (carryover lapsed); April half-day fits; special always approved.
        self::assertStatus(LeaveStatus::REJECTED, 'Anna Becker', '2025-05-19');
        self::assertStatus(LeaveStatus::APPROVED, 'Anna Becker', '2025-04-28');
        self::assertStatus(LeaveStatus::APPROVED, 'Anna Becker', '2025-06-02');

        // Part-timer and mid-year joiner both exceed their reduced entitlement.
        self::assertStatus(LeaveStatus::REJECTED, 'Bjarne Vogt', '2025-07-07');
        self::assertStatus(LeaveStatus::REJECTED, 'Carla Roth', '2025-07-07');

        // Certified sick during approved vacation is approved and credited back.
        self::assertStatus(LeaveStatus::APPROVED, 'Dilan Yilmaz', '2025-03-24');

        // Unpaid recorded; June vacation fits.
        self::assertStatus(LeaveStatus::APPROVED, 'Eva Klein', '2025-05-05');
        self::assertStatus(LeaveStatus::APPROVED, 'Eva Klein', '2025-06-05');

        // First vacation approved, the overlapping second rejected.
        self::assertStatus(LeaveStatus::APPROVED, 'Felix Wolf', '2025-05-26');
        self::assertStatus(LeaveStatus::REJECTED, 'Felix Wolf', '2025-05-28');

        self::assertSame(7.0, $this->balanceUsed('Dilan Yilmaz'));  // 10 - 3 credited back
        self::assertSame(8.5, $this->balanceUsed('Eva Klein'));     // 5 + 3.5 June vacation
    }

    private function assertStatus(LeaveStatus $expected, string $name, string $start): void
    {
        $rows = $this->em->getRepository(LeaveRequest::class)->createQueryBuilder('r')
            ->join('r.employee', 'e')
            ->where('e.name = :name')
            ->andWhere('r.startDate = :start')
            ->setParameter('name', $name)
            ->setParameter('start', new \DateTimeImmutable($start), Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();

        self::assertCount(1, $rows, sprintf('Expected one request for %s starting %s.', $name, $start));
        self::assertSame($expected, $rows[0]->getStatus(), sprintf('%s starting %s', $name, $start));
    }

    private function balanceUsed(string $name): float
    {
        $balance = $this->em->getRepository(LeaveBalance::class)->createQueryBuilder('b')
            ->join('b.employee', 'e')
            ->where('e.name = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();

        self::assertInstanceOf(LeaveBalance::class, $balance);

        return $balance->getUsedDays();
    }
}
