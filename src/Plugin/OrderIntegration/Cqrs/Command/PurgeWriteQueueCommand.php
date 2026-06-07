<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs\Command;

use Scotty42\OrderIntegration\Cqrs\Write\Retention;
use Scotty42\OrderIntegration\Cqrs\Write\WriteCommand;
use Scotty42\OrderIntegration\Cqrs\Write\WriteQueueInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Retention cleanup for the write queue. The worker marks finished commands
 * `succeeded` / `dead` but never deletes them, so the table grows with every
 * async write. Run this periodically (cron / systemd timer) to drop old
 * terminal rows. Active rows (queued / in_progress) are never touched.
 *
 *   bin/console order-integration:write-queue:purge --succeeded-after=7d --dead-after=30d
 */
#[AsCommand(
    name: 'order-integration:write-queue:purge',
    description: 'Removes old terminal (succeeded/dead) rows from the write queue.'
)]
final class PurgeWriteQueueCommand extends Command
{
    public function __construct(private readonly WriteQueueInterface $queue)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('succeeded-after', null, InputOption::VALUE_REQUIRED,
                'Delete succeeded rows older than this (e.g. 7d, 24h).', '7d')
            ->addOption('dead-after', null, InputOption::VALUE_REQUIRED,
                'Delete dead-letter rows older than this (kept longer for triage).', '30d');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable();

        $succeededAfter = (string) $input->getOption('succeeded-after');
        $deadAfter      = (string) $input->getOption('dead-after');

        $succeeded = $this->queue->purge(WriteCommand::STATUS_SUCCEEDED, Retention::cutoff($succeededAfter, $now));
        $dead      = $this->queue->purge(WriteCommand::STATUS_DEAD, Retention::cutoff($deadAfter, $now));

        $output->writeln(sprintf(
            'purged: succeeded=%d (older than %s), dead=%d (older than %s)',
            $succeeded, $succeededAfter, $dead, $deadAfter
        ));

        return Command::SUCCESS;
    }
}
