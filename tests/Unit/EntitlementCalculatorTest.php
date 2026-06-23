<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Employee;
use App\Entity\LeaveBalance;
use App\Service\EntitlementCalculator;
use PHPUnit\Framework\TestCase;

final class EntitlementCalculatorTest extends TestCase
{
    private EntitlementCalculator $calc;

    #[\Override]
    protected function setUp(): void
    {
        $this->calc = new EntitlementCalculator();
    }

    public function testFullTimeFullYearEqualsContractual(): void
    {
        self::assertSame(28.0, $this->calc->entitlementFor($this->employee('2018-01-01', 5, 28), 2025));
    }

    public function testMidYearJoinerProRated(): void
    {
        // Joins 1 March 2025 => 10 full months; 30 * 10/12 = 25.0.
        self::assertSame(25.0, $this->calc->entitlementFor($this->employee('2025-03-01', 5, 30), 2025));
    }

    public function testPartTimeScaled(): void
    {
        // 28 * 3/5 = 16.8, rounded up to 17.0.
        self::assertSame(17.0, $this->calc->entitlementFor($this->employee('2017-01-01', 3, 28), 2025));
    }

    public function testJoinerAndPartTimeRoundUpOnce(): void
    {
        // Joins 1 June 2025 (7 months), 3-day week, 25 contractual: 25*7/12*3/5 = 8.75 => 9.0.
        self::assertSame(9.0, $this->calc->entitlementFor($this->employee('2025-06-01', 3, 25), 2025));
    }

    public function testLeaverProRated(): void
    {
        // Leaves 30 June 2025 => Jan-Jun = 6 full months; 28 * 6/12 = 14.0.
        self::assertSame(14.0, $this->calc->entitlementFor($this->employee('2018-01-01', 5, 28, '2025-06-30'), 2025));
    }

    public function testScalingBelowStatutoryMinimumIsNotFloored(): void
    {
        // 2-day week, 28 contractual: 28 * 2/5 = 11.2 => 11.5, left below 20.
        self::assertSame(11.5, $this->calc->entitlementFor($this->employee('2018-01-01', 2, 28), 2025));
    }

    public function testValidCarryoverIncludedBeforeExpiry(): void
    {
        $balance = new LeaveBalance($this->employee('2018-01-01', 5, 28), 2025, 6.0, new \DateTimeImmutable('2025-03-31'));
        self::assertSame(6.0, $this->calc->validCarriedOverDays($balance, new \DateTimeImmutable('2025-03-15')));
    }

    public function testCarryoverLapsedAfterExpiry(): void
    {
        $balance = new LeaveBalance($this->employee('2018-01-01', 5, 28), 2025, 6.0, new \DateTimeImmutable('2025-03-31'));
        self::assertSame(0.0, $this->calc->validCarriedOverDays($balance, new \DateTimeImmutable('2025-04-15')));
    }

    public function testCarryoverWithoutExpiryAlwaysValid(): void
    {
        $balance = new LeaveBalance($this->employee('2018-01-01', 5, 28), 2025, 4.0, null);
        self::assertSame(4.0, $this->calc->validCarriedOverDays($balance, new \DateTimeImmutable('2025-12-31')));
    }

    private function employee(string $start, int $workingDays, int $contractual, ?string $end = null): Employee
    {
        return new Employee(
            'Test',
            new \DateTimeImmutable($start),
            $workingDays,
            'BE',
            $contractual,
            null === $end ? null : new \DateTimeImmutable($end),
        );
    }
}
