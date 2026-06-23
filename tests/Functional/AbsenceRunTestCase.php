<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Bus\CommandBusInterface;
use App\Bus\CommandInterface;
use App\Bus\QueryBusInterface;
use App\Entity\LeaveBalance;
use App\Entity\LeaveRequest;
use App\Enum\LeaveStatus;
use App\Hr\HrApiClientInterface;
use App\Repository\LeaveBalanceRepository;
use App\Service\EntitlementCalculator;
use App\Service\LeaveRequestProcessor;
use App\Service\PublicHolidayProvider;
use App\Service\WorkingDayCounter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base class for processor tests: boots the kernel, gives each test a fresh
 * SQLite schema, and wires the processor against an in-memory HR API client.
 */
abstract class AbsenceRunTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;
    protected HrApiClientInterface $hrApi;
    protected LoggerInterface $logger;
    protected CommandBusInterface $commandBus;

    /** @var list<array{decision: array<string, mixed>, key: string}> */
    protected array $hrCalls = [];
    /** @var list<string> idempotency keys the HR client should fail */
    protected array $hrFailKeys = [];
    /** @var list<CommandInterface> commands dispatched through the command bus */
    protected array $dispatchedCommands = [];
    private int $hrCreated = 0;

    #[\Override]
    protected function setUp(): void
    {
        // Clear any kernel/container/services left over from a previous test, so each
        // test starts from a clean, fully-rebuilt service graph.
        self::ensureKernelShutdown();
        self::bootKernel();

        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $this->hrCalls = [];
        $this->hrFailKeys = [];
        $this->dispatchedCommands = [];
        $this->hrCreated = 0;

        $hrApi = $this->createStub(HrApiClientInterface::class);
        $hrApi->method('postDecision')->willReturnCallback(
            function (array $decision, string $idempotencyKey): array {
                $this->hrCalls[] = ['decision' => $decision, 'key' => $idempotencyKey];

                if (\in_array($idempotencyKey, $this->hrFailKeys, true)) {
                    throw new \RuntimeException('Simulated HR failure for '.$idempotencyKey);
                }

                return ['id' => 'hr_'.(++$this->hrCreated)];
            },
        );
        $this->hrApi = $hrApi;

        $commandBus = $this->createStub(CommandBusInterface::class);
        $commandBus->method('dispatch')->willReturnCallback(
            function (CommandInterface $command): void {
                $this->dispatchedCommands[] = $command;
            },
        );
        $this->commandBus = $commandBus;

        // Default to a no-op logger; tests asserting on logging swap in a mock via expectLogged().
        $this->logger = new NullLogger();
    }

    /** Number of decisions the HR client successfully recorded (replays/failures excluded). */
    protected function hrCreatedCount(): int
    {
        return $this->hrCreated;
    }

    /**
     * Replace the logger with a mock that expects a message at the given level containing
     * the given text. Call before building the processor; verified automatically on teardown.
     */
    protected function expectLogged(string $level, string $messageNeedle): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method($level)
            ->with($this->stringContains($messageNeedle), $this->anything());
        $this->logger = $logger;
    }

    protected function processor(): LeaveRequestProcessor
    {
        $balances = $this->em->getRepository(LeaveBalance::class);
        \assert($balances instanceof LeaveBalanceRepository);

        $queryBus = self::getContainer()->get(QueryBusInterface::class);
        \assert($queryBus instanceof QueryBusInterface);

        return new LeaveRequestProcessor(
            $this->em,
            $this->commandBus,
            $queryBus,
            $balances,
            $this->hrApi,
            new WorkingDayCounter(new PublicHolidayProvider()),
            new EntitlementCalculator(),
            $this->logger,
        );
    }

    /**
     * Process all currently-pending requests in submission order, simulating a single
     * ordered worker draining the queue.
     */
    protected function processAll(\DateTimeImmutable $runDate): void
    {
        $processor = $this->processor();

        $pending = $this->em->getRepository(LeaveRequest::class)
            ->findBy(['status' => LeaveStatus::PENDING], ['submittedAt' => 'ASC', 'id' => 'ASC']);

        foreach ($pending as $request) {
            $processor->processOne((int) $request->getId(), $runDate);
        }
    }

    protected function persist(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }
}
