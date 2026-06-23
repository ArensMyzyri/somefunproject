<?php

declare(strict_types=1);

namespace App\Service;

use App\Bus\CommandBusInterface;
use App\Bus\QueryBusInterface;
use App\Dto\DispatchSummary;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Enum\LeaveType;
use App\Hr\HrApiClientInterface;
use App\Message\Command\ProcessLeaveRequest;
use App\Message\Query\FindApprovedOverlapping;
use App\Message\Query\FindPendingRequestIds;
use App\Repository\LeaveBalanceRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Drives the absence run. {@see self::processPending()} enqueues each pending request
 * as its own queued unit of work; {@see self::processOne()} decides a single request,
 * reports it to the HR system, and persists the outcome.
 */
final class LeaveRequestProcessor implements LeaveRequestProcessorInterface
{
    /** How many pending ids to fetch and enqueue per page (keeps memory bounded). */
    private const int DISPATCH_BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
        private readonly LeaveBalanceRepositoryInterface $leaveBalances,
        private readonly HrApiClientInterface $hrApi,
        private readonly WorkingDayCounterInterface $workingDays,
        private readonly EntitlementCalculatorInterface $entitlement,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function processPending(\DateTimeImmutable $runDate): DispatchSummary
    {
        $dispatched = 0;
        $afterSubmittedAt = null;
        $afterId = 0;

        // Page through the pending set by a (submittedAt, id) keyset cursor so the whole
        // set is never held in memory, while preserving oldest-submission-first order.
        do {
            /** @var list<array{id: int, submittedAt: \DateTimeImmutable}> $page */
            $page = $this->queryBus->ask(
                new FindPendingRequestIds($afterSubmittedAt, $afterId, self::DISPATCH_BATCH_SIZE),
            );

            foreach ($page as $row) {
                $this->commandBus->dispatch(new ProcessLeaveRequest($row['id'], $runDate));
                $afterSubmittedAt = $row['submittedAt'];
                $afterId = $row['id'];
                ++$dispatched;
            }
        } while (\count($page) === self::DISPATCH_BATCH_SIZE);

        $this->logger->info('Absence run dispatched.', [
            'runDate' => $runDate->format('Y-m-d'),
            'dispatched' => $dispatched,
        ]);

        return new DispatchSummary($dispatched);
    }

    #[\Override]
    public function processOne(int $requestId, \DateTimeImmutable $runDate): void
    {
        $request = $this->entityManager->find(LeaveRequest::class, $requestId);

        if (null === $request || LeaveStatus::PENDING !== $request->getStatus()) {
            return; // already decided (re-run safety) or no longer present
        }

        try {
            $decision = $this->decide($request, $runDate);
        } catch (\Throwable $e) {
            // Unprocessable data (e.g. a missing balance): leave it pending, do not retry.
            $this->logger->warning('Skipping leave request with unprocessable data.', [
                'requestId' => $requestId,
                'exception' => $e,
            ]);

            return;
        }

        try {
            $response = $this->hrApi->postDecision(
                [
                    'employeeId' => $request->getEmployee()->getId(),
                    'requestId' => $requestId,
                    'decision' => $decision->status->value,
                    'days' => $decision->consumedDays,
                    'reason' => $decision->reason,
                ],
                $this->idempotencyKey($requestId),
            );
        } catch (\Throwable $e) {
            // HR is unreachable: leave the request pending and let the worker retry the message.
            $this->logger->error('Failed to report decision to HR; leaving request pending.', [
                'requestId' => $requestId,
                'exception' => $e,
            ]);

            throw $e;
        }

        $request->markDecided($decision->status, $runDate, $decision->reason);
        $request->setExternalReference(\is_string($response['id'] ?? null) ? $response['id'] : null);
        $this->applyEffects($request, $decision);
        $this->entityManager->flush();
    }

    /**
     * Decide a single request according to its leave type.
     */
    private function decide(LeaveRequest $request, \DateTimeImmutable $runDate): Decision
    {
        return match ($request->getType()) {
            LeaveType::SPECIAL => $this->decideSpecial($request),
            LeaveType::UNPAID => new Decision(LeaveStatus::APPROVED, 0.0, 'unpaid leave'),
            LeaveType::SICK => new Decision(LeaveStatus::APPROVED, 0.0, 'sick leave'),
            LeaveType::VACATION => $this->decideVacation($request, $runDate),
        };
    }

    private function decideSpecial(LeaveRequest $request): Decision
    {
        return new Decision(LeaveStatus::APPROVED, $this->countWorkingDays($request), 'special leave');
    }

    private function decideVacation(LeaveRequest $request, \DateTimeImmutable $runDate): Decision
    {
        if ($this->overlapsApprovedLeave($request)) {
            return new Decision(LeaveStatus::REJECTED, 0.0, 'overlaps approved leave');
        }

        $employee = $request->getEmployee();
        $balance = $this->balanceFor($request);
        $year = (int) $request->getStartDate()->format('Y');

        $consumed = $this->countWorkingDays($request);

        $remaining = $this->entitlement->entitlementFor($employee, $year)
            + $this->entitlement->validCarriedOverDays($balance, $runDate)
            - $balance->getUsedDays();

        return $consumed <= $remaining
            ? new Decision(LeaveStatus::APPROVED, $consumed, 'within balance')
            : new Decision(LeaveStatus::REJECTED, 0.0, 'insufficient balance');
    }

    private function applyEffects(LeaveRequest $request, Decision $decision): void
    {
        if (LeaveType::VACATION === $request->getType() && LeaveStatus::APPROVED === $decision->status) {
            $this->balanceFor($request)->addUsedDays($decision->consumedDays);
        }

        if (LeaveType::SICK === $request->getType() && $request->hasMedicalCertificate()) {
            $this->creditBackSickDuringVacation($request);
        }
    }

    private function overlapsApprovedLeave(LeaveRequest $request): bool
    {
        /** @var list<LeaveRequest> $approved */
        $approved = $this->queryBus->ask(
            new FindApprovedOverlapping($request->getEmployee(), $request->getStartDate(), $request->getEndDate()),
        );

        return [] !== $approved;
    }

    private function creditBackSickDuringVacation(LeaveRequest $sick): void
    {
        $employee = $sick->getEmployee();

        /** @var list<LeaveRequest> $vacations */
        $vacations = $this->queryBus->ask(
            new FindApprovedOverlapping($employee, $sick->getStartDate(), $sick->getEndDate(), vacationOnly: true),
        );

        foreach ($vacations as $vacation) {
            $overlapStart = max($sick->getStartDate(), $vacation->getStartDate());
            $overlapEnd = min($sick->getEndDate(), $vacation->getEndDate());

            if ($overlapStart > $overlapEnd) {
                continue;
            }

            $credit = $this->workingDays->count(
                $overlapStart,
                $overlapEnd,
                $employee->getFederalState(),
                false,
                false,
            );

            $year = (int) $vacation->getStartDate()->format('Y');
            $balance = $this->leaveBalances->findForEmployeeAndYear($employee, $year);

            if (null === $balance) {
                $this->logger->warning('No balance to credit sick-during-vacation days back to.', [
                    'employeeId' => $employee->getId(),
                    'year' => $year,
                ]);

                continue;
            }

            $balance->addUsedDays(-$credit);
        }
    }

    private function countWorkingDays(LeaveRequest $request): float
    {
        return $this->workingDays->count(
            $request->getStartDate(),
            $request->getEndDate(),
            $request->getEmployee()->getFederalState(),
            $request->isHalfDayStart(),
            $request->isHalfDayEnd(),
        );
    }

    private function idempotencyKey(int $requestId): string
    {
        return 'leave-decision-'.$requestId;
    }

    private function balanceFor(LeaveRequest $request): LeaveBalance
    {
        $year = (int) $request->getStartDate()->format('Y');
        $balance = $this->leaveBalances->findForEmployeeAndYear($request->getEmployee(), $year);

        if (null === $balance) {
            throw new \RuntimeException(sprintf(
                'No leave balance for employee #%d in %d.',
                (int) $request->getEmployee()->getId(),
                $year,
            ));
        }

        return $balance;
    }
}
