<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Bus\QueryBusInterface;
use App\Entity\Employee;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Enum\LeaveType;
use App\Message\Command\ProcessLeaveRequest;
use App\Message\Query\FindPendingRequestIds;

/**
 * Phase 3 behaviour under the queued model: dispatch granularity, stable idempotency,
 * re-run safety, partial-failure resilience, skipping bad data, and logging. Per-request
 * behaviour is exercised through processOne(), the unit a worker runs for one message.
 */
final class ReRunAndResilienceTest extends AbsenceRunTestCase
{
    private const string RUN_DATE = '2025-04-15';

    public function testDispatchesOneMessagePerPendingRequest(): void
    {
        $employee = $this->employee();
        $balance = new LeaveBalance($employee, 2025);
        $first = $this->vacation($employee, '2025-05-05', '2025-05-09', '2025-04-04');
        $second = $this->vacation($employee, '2025-06-02', '2025-06-06', '2025-04-05');
        $this->persist($employee, $balance, $first, $second);

        $summary = $this->processor()->processPending(new \DateTimeImmutable(self::RUN_DATE));

        self::assertSame(2, $summary->dispatched);
        self::assertCount(2, $this->dispatchedCommands);
        self::assertContainsOnlyInstancesOf(ProcessLeaveRequest::class, $this->dispatchedCommands);
        // Dispatch only — nothing decided or reported yet.
        self::assertSame(LeaveStatus::PENDING, $first->getStatus());
        self::assertCount(0, $this->hrCalls);
    }

    public function testIdempotencyKeyIsStablePerRequest(): void
    {
        $employee = $this->employee();
        $balance = new LeaveBalance($employee, 2025);
        $request = $this->vacation($employee, '2025-05-05', '2025-05-09');
        $this->persist($employee, $balance, $request);

        $this->processor()->processOne((int) $request->getId(), $this->runDate());

        self::assertSame('leave-decision-'.$request->getId(), $this->hrCalls[0]['key']);
    }

    public function testReRunPostsNoDuplicateAndDoesNotDoubleDeduct(): void
    {
        $employee = $this->employee();
        $balance = new LeaveBalance($employee, 2025);
        $request = $this->vacation($employee, '2025-05-05', '2025-05-09');
        $this->persist($employee, $balance, $request);

        $this->processAll($this->runDate());
        self::assertSame(1, $this->hrCreatedCount());
        self::assertSame(5.0, $balance->getUsedDays());

        $this->processAll($this->runDate()); // nothing pending now
        self::assertSame(1, $this->hrCreatedCount());
        self::assertSame(5.0, $balance->getUsedDays());
    }

    public function testHrFailureLeavesRequestPendingThenReRunFinishesIt(): void
    {
        $employee = $this->employee();
        $balance = new LeaveBalance($employee, 2025);
        $first = $this->vacation($employee, '2025-05-05', '2025-05-09', '2025-04-04');
        $second = $this->vacation($employee, '2025-06-02', '2025-06-06', '2025-04-05');
        $this->persist($employee, $balance, $first, $second);

        // The HR failure must be logged at error level.
        $this->expectLogged('error', 'Failed to report decision to HR');

        $processor = $this->processor();
        $processor->processOne((int) $first->getId(), $this->runDate());

        $this->hrFailKeys = ['leave-decision-'.$second->getId()];
        try {
            $processor->processOne((int) $second->getId(), $this->runDate());
            self::fail('Expected the HR failure to propagate for the worker to retry.');
        } catch (\RuntimeException) {
            // expected — Messenger would retry the message
        }

        self::assertSame(LeaveStatus::APPROVED, $first->getStatus());
        self::assertSame(LeaveStatus::PENDING, $second->getStatus());
        self::assertSame(5.0, $balance->getUsedDays());
        self::assertSame(1, $this->hrCreatedCount());

        $this->hrFailKeys = [];
        $processor->processOne((int) $second->getId(), $this->runDate());

        self::assertSame(LeaveStatus::APPROVED, $second->getStatus());
        self::assertSame(10.0, $balance->getUsedDays());
        self::assertSame(2, $this->hrCreatedCount());
    }

    public function testMissingBalanceIsSkippedAndLeftPending(): void
    {
        $employee = $this->employee();
        $request = $this->vacation($employee, '2025-05-05', '2025-05-09');
        $this->persist($employee, $request); // no LeaveBalance persisted

        // Unprocessable data must be logged as a warning and the request left pending.
        $this->expectLogged('warning', 'Skipping leave request');

        $this->processor()->processOne((int) $request->getId(), $this->runDate());

        self::assertSame(LeaveStatus::PENDING, $request->getStatus());
        self::assertCount(0, $this->hrCalls);
    }

    public function testAlreadyDecidedRequestIsNotReprocessed(): void
    {
        $employee = $this->employee();
        $balance = new LeaveBalance($employee, 2025, 0.0, null, 99.0);
        $request = $this->vacation($employee, '2025-05-05', '2025-05-09');
        $request->markDecided(LeaveStatus::REJECTED, new \DateTimeImmutable(self::RUN_DATE), 'insufficient balance');
        $this->persist($employee, $balance, $request);

        $this->processor()->processOne((int) $request->getId(), $this->runDate());

        self::assertSame(LeaveStatus::REJECTED, $request->getStatus());
        self::assertCount(0, $this->hrCalls);
    }

    public function testPendingIdsArePagedInSubmissionOrderViaKeysetCursor(): void
    {
        $employee = $this->employee();
        $balance = new LeaveBalance($employee, 2025);
        $a = $this->vacation($employee, '2025-05-05', '2025-05-09', '2025-04-01');
        $b = $this->vacation($employee, '2025-06-02', '2025-06-06', '2025-04-02');
        $c = $this->vacation($employee, '2025-07-07', '2025-07-11', '2025-04-03');
        $this->persist($employee, $balance, $a, $b, $c);

        $queryBus = self::getContainer()->get(QueryBusInterface::class);
        \assert($queryBus instanceof QueryBusInterface);

        $page1 = $queryBus->ask(new FindPendingRequestIds(null, 0, 2));
        self::assertSame([$a->getId(), $b->getId()], array_column($page1, 'id'));

        // Next page from the cursor returns only the remainder — no skip, no duplicate.
        $cursor = $page1[\count($page1) - 1];
        $page2 = $queryBus->ask(new FindPendingRequestIds($cursor['submittedAt'], $cursor['id'], 2));
        self::assertSame([$c->getId()], array_column($page2, 'id'));
    }

    private function runDate(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::RUN_DATE);
    }

    private function employee(): Employee
    {
        return new Employee('Test', new \DateTimeImmutable('2018-01-01'), 5, 'BE', 28);
    }

    private function vacation(Employee $employee, string $start, string $end, string $submitted = '2025-04-10'): LeaveRequest
    {
        return new LeaveRequest(
            $employee,
            LeaveType::VACATION,
            new \DateTimeImmutable($start),
            new \DateTimeImmutable($end),
            new \DateTimeImmutable($submitted),
        );
    }
}
