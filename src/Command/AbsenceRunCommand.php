<?php

declare(strict_types=1);

namespace App\Command;

use App\Bus\CommandBusInterface;
use App\Dto\DispatchSummary;
use App\Dto\RunInput;
use App\Message\Command\RunAbsence;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:absence:run',
    description: 'Process pending leave requests and post decisions to the HR system.',
)]
final class AbsenceRunCommand extends Command
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'date',
            null,
            InputOption::VALUE_REQUIRED,
            'Run date in Y-m-d format. Defaults to today.',
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dateOption = $input->getOption('date');
        $dateString = \is_string($dateOption) && '' !== $dateOption
            ? $dateOption
            : (new \DateTimeImmutable('today'))->format('Y-m-d');

        $runInput = new RunInput($dateString);
        $violations = $this->validator->validate($runInput);

        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error((string) $violation->getMessage());
            }

            return Command::INVALID;
        }

        $runDate = new \DateTimeImmutable($runInput->date);
        $io->title(sprintf('Absence run — %s', $runDate->format('Y-m-d')));

        $summary = $this->commandBus->execute(new RunAbsence($runDate));
        \assert($summary instanceof DispatchSummary);

        if (0 === $summary->dispatched) {
            $io->success('No pending requests to process.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Dispatched %d request(s) for processing. Run "bin/console messenger:consume async" to process them.',
            $summary->dispatched,
        ));

        return Command::SUCCESS;
    }
}
