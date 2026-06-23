<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Employee;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Enum\LeaveType;

/**
 * Policy decisions for Phases 1 and 2: counting/balance, carryover, leave types,
 * sick credit-back, overlaps, and cancellations.
 */
final class LeaveRequestProcessorPolicyTest extends AbsenceRunTestCase
{
    private const string RUN_DATE = '2025-04-15';

    public function testRejectsWhenOverByHalfDay(): void
    {
        $employee = $this->employee('2018-01-01', 5, 'BY', 10);
        $balance = $this->balance($employee, used: 8.0); // entitlement 10, remaining 2.0
        $request = $this->request($employee, LeaveType::VACATION, '2025-04-28', '2025-04-30', halfStart: true); // 2.5
        $this->persist($employee, $balance, $request);

        $this->process();

        self::assertSame(LeaveStatus::REJECTED, $request->getStatus());
        self::assertSame(8.0, $balance->getUsedDays());
    }

    public function testValidCarryoverEnablesApproval(): void
    {
        $employee = $this->employee('2018-01-01', 5, 'BE', 5);
        $balance = $this->balance($employee, carry: 3.0, expires: '2025-12-31', used: 5.0); // remaining 3.0
        $request = $this->request($employee, LeaveType::VACATION, '2025-04-28', '2025-04-30'); // 3.0
        $this->persist($employee, $balance, $request);

        $this->process();

        self::assertSame(LeaveStatus::APPROVED, $request->getStatus());
        self::assertSame(8.0, $balance->getUsedDays());
    }

    public function testLapsedCarryoverCausesRejection(): void
    {
        $employee = $this->employee('2018-01-01', 5, 'BE', 5);
        $balance = $this->balance($employee, carry: 3.0, expires: '2025-03-31', used: 5.0); // carryover lapsed -> remaining 0
        $request = $this->request($employee, LeaveType::VACATION, '2025-04-28', '2025-04-30'); // 3.0
        $this->persist($employee, $balance, $request);

        $this->process();

        self::assertSame(LeaveStatus::REJECTED, $request->getStatus());
        self::assertSame(5.0, $balance->getUsedDays());
    }

    public function testSpecialApprovedReportsCountedDaysWithoutBalanceChange(): void
    {
        $employee = $this->employee('2018-01-01', 5, 'BY', 28);
        $balance = $this->balance($employee, used: 5.0);
        $request = $this->request($employee, LeaveType::SPECIAL, '2025-06-02', '2025-06-02'); // 1 working day
        $this->persist($employee, $balance, $request);

        $this->process();

        self::assertSame(LeaveStatus::APPROVED, $request->getStatus());
        self::assertSame(5.0, $balance->getUsedDays());
        self::assertSame(1.0, $this->hrCalls[0]['decision']['days']);
    }

    public function testUnpaidApprovedWithoutBalanceChange(): void
    {
        $employee = $this->employee('2018-01-01', 5, 'BE', 28);
        $balance = $this->balance($employee, used: 5.0);
        $request = $this->request($employee, LeaveType::UNPAID, '2025-05-05', '2025-05-09');
        $this->persist($employee, $balance, $request);

        $this->process();

        self::assertSame(LeaveStatus::APPROVED, $request->getStatus());
        self::assertSame(5.0, $balance->getUsedDays());
    }

    public function testSickApprovedZeroDaysWithoutBalanceChange(): void
    {
        $employee = $this->employee('2018-01-01', 5, 'BE', 28);
        $balance = $this->balance($employee, used: 5.0);
        $request = $this->request($employee, LeaveType::SICK, '2025-05-05', '2025-05-09', cert: true);
        $this->persist($employee, $balance, $request);

        $this->process();

        self::assertSame(LeaveStatus::APPROVED, $request->getStatus());
        self::assertSame(5.0, $balance->getUsedDays());
        self::assertSame(0.0, $this->hrCalls[0]['decision']['days']);
    }

    public function testCertifiedSickDuringVacationCreditsBackToVacationYear(): void
    {
        $employee = $this->employee('2015-01-01', 5, 'BY', 30);
        $balance = $this->balance($employee, used: 10.0);

        $vacation = $this->request($employee, LeaveType::VACATION, '2025-03-17', '2025-03-28', '2025-02-01');
        $vacation->markDecided(LeaveStatus::APPROVED, new \DateTimeImmutable('2025-02-15'), 'within balance');

        $sick = $this->request($employee, LeaveType::SICK, '2025-03-24', '2025-03-26', '2025-04-01', cert: true);
        $this->persist($employee, $balance, $vacation, $sick);

        $this->process();

        self::assertSame(LeaveStatus::APPROVED, $sick->getStatus());
        self::assertSame(7.0, $balance->getUsedDays()); // 3 working days credited back
    }

    public function testRejectsOverlapWithAlreadyApprovedLeave(): void
    {
        $employee = $this->employee('2018-01-01', 5, 'BE', 28);
        $balance = $this->balance($employee);

        $approved = $this->request($employee, LeaveType::VACATION, '2025-05-05', '2025-05-09', '2025-02-01');
        $approved->markDecided(LeaveStatus::APPROVED, new \DateTimeImmutable('2025-02-15'), 'within balance');

        $pending = $this->request($employee, LeaveType::VACATION, '2025-05-07', '2025-05-13');
        $this->persist($employee, $balance, $approved, $pending);

        $this->process();

        self::assertSame(LeaveStatus::REJECTED, $pending->getStatus());
        self::assertSame('overlaps approved leave', $pending->getDecisionReason());
    }

    public function testRejectsSecondOverlappingRequestInSameRun(): void
    {
        $employee = $this->employee('2018-01-01', 5, 'BE', 28);
        $balance = $this->balance($employee);
        $first = $this->request($employee, LeaveType::VACATION, '2025-05-26', '2025-05-30', '2025-04-04');
        $second = $this->request($employee, LeaveType::VACATION, '2025-05-28', '2025-06-03', '2025-04-05');
        $this->persist($employee, $balance, $first, $second);

        $this->process();

        self::assertSame(LeaveStatus::APPROVED, $first->getStatus());
        self::assertSame(LeaveStatus::REJECTED, $second->getStatus());
    }

    public function testRejectsBoundaryOverlapSharingASingleDay(): void
    {
        $employee = $this->employee('2018-01-01', 5, 'BE', 28);
        $balance = $this->balance($employee);

        $approved = $this->request($employee, LeaveType::VACATION, '2025-05-05', '2025-05-09', '2025-02-01');
        $approved->markDecided(LeaveStatus::APPROVED, new \DateTimeImmutable('2025-02-15'), 'within balance');

        // Starts on the exact day the approved period ends — they share 2025-05-09.
        $pending = $this->request($employee, LeaveType::VACATION, '2025-05-09', '2025-05-13');
        $this->persist($employee, $balance, $approved, $pending);

        $this->process();

        self::assertSame(LeaveStatus::REJECTED, $pending->getStatus());
    }

    public function testTouchingRangesDoNotOverlap(): void
    {
        $employee = $this->employee('2018-01-01', 5, 'BE', 28);
        $balance = $this->balance($employee);

        $approved = $this->request($employee, LeaveType::VACATION, '2025-05-05', '2025-05-09', '2025-02-01');
        $approved->markDecided(LeaveStatus::APPROVED, new \DateTimeImmutable('2025-02-15'), 'within balance');

        // Starts the day after the approved period ends; shares no day.
        $pending = $this->request($employee, LeaveType::VACATION, '2025-05-10', '2025-05-13');
        $this->persist($employee, $balance, $approved, $pending);

        $this->process();

        self::assertSame(LeaveStatus::APPROVED, $pending->getStatus());
    }

    public function testCancelledRequestBlocksNothing(): void
    {
        $employee = $this->employee('2018-01-01', 5, 'BE', 28);
        $balance = $this->balance($employee);

        $cancelled = $this->request($employee, LeaveType::VACATION, '2025-05-05', '2025-05-09', '2025-01-10');
        $cancelled->markDecided(LeaveStatus::CANCELLED, new \DateTimeImmutable('2025-01-20'), 'cancelled by employee');

        $pending = $this->request($employee, LeaveType::VACATION, '2025-05-05', '2025-05-09');
        $this->persist($employee, $balance, $cancelled, $pending);

        $this->process();

        self::assertSame(LeaveStatus::APPROVED, $pending->getStatus());
    }

    private function process(): void
    {
        $this->processAll(new \DateTimeImmutable(self::RUN_DATE));
    }

    private function employee(string $start, int $workingDays, string $state, int $contractual): Employee
    {
        return new Employee('Test', new \DateTimeImmutable($start), $workingDays, $state, $contractual);
    }

    private function balance(Employee $employee, float $carry = 0.0, ?string $expires = null, float $used = 0.0): LeaveBalance
    {
        return new LeaveBalance(
            $employee,
            2025,
            $carry,
            null === $expires ? null : new \DateTimeImmutable($expires),
            $used,
        );
    }

    private function request(
        Employee $employee,
        LeaveType $type,
        string $start,
        string $end,
        string $submitted = '2025-04-10',
        bool $halfStart = false,
        bool $halfEnd = false,
        bool $cert = false,
    ): LeaveRequest {
        $request = new LeaveRequest(
            $employee,
            $type,
            new \DateTimeImmutable($start),
            new \DateTimeImmutable($end),
            new \DateTimeImmutable($submitted),
        );

        return $request
            ->setHalfDayStart($halfStart)
            ->setHalfDayEnd($halfEnd)
            ->setMedicalCertificate($cert);
    }
}
