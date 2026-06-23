<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\PublicHolidayProvider;
use App\Service\WorkingDayCounter;
use PHPUnit\Framework\TestCase;

final class WorkingDayCounterTest extends TestCase
{
    private WorkingDayCounter $counter;

    #[\Override]
    protected function setUp(): void
    {
        $this->counter = new WorkingDayCounter(new PublicHolidayProvider());
    }

    public function testExcludesWeekends(): void
    {
        // Mon 2025-05-19 .. Sun 2025-05-25: only the five weekdays count.
        self::assertSame(5.0, $this->consumed('2025-05-19', '2025-05-25', 'BE'));
    }

    public function testExcludesHolidayWithinRange(): void
    {
        // Berlin, 26-30 May 2025; 29 May (Ascension) is a holiday => 4 working days.
        self::assertSame(4.0, $this->consumed('2025-05-26', '2025-05-30', 'BE'));
    }

    public function testHolidayIsStateSpecific(): void
    {
        // 19 June 2025 (Corpus Christi) is a Bavarian holiday but not a Berlin one.
        self::assertSame(4.0, $this->consumed('2025-06-16', '2025-06-20', 'BY'));
        self::assertSame(5.0, $this->consumed('2025-06-16', '2025-06-20', 'BE'));
    }

    public function testHalfDayStartAndEnd(): void
    {
        // Mon-Wed 2025-04-28..30 (Bavaria, no holiday) = 3 days.
        self::assertSame(2.5, $this->consumed('2025-04-28', '2025-04-30', 'BY', halfStart: true));
        self::assertSame(2.0, $this->consumed('2025-04-28', '2025-04-30', 'BY', halfStart: true, halfEnd: true));
    }

    public function testHalfDayOnNonWorkingBoundaryIsIgnored(): void
    {
        // Range starts on Sat 2025-05-24; the half-day flag has no working day to halve.
        self::assertSame(2.0, $this->consumed('2025-05-24', '2025-05-27', 'BE', halfStart: true));
    }

    public function testNeverNegative(): void
    {
        // A single Saturday with both half-day flags must not go below zero.
        self::assertSame(0.0, $this->consumed('2025-05-24', '2025-05-24', 'BE', halfStart: true, halfEnd: true));
    }

    private function consumed(string $start, string $end, string $state, bool $halfStart = false, bool $halfEnd = false): float
    {
        return $this->counter->count(
            new \DateTimeImmutable($start),
            new \DateTimeImmutable($end),
            $state,
            $halfStart,
            $halfEnd,
        );
    }
}
