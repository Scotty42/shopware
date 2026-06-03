<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs\Command;

use Scotty42\OrderIntegration\Cqrs\Write\WriteQueueWorker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Long-running worker that drains the write queue. Run several instances for
 * more throughput — SKIP LOCKED keeps them from colliding.
 *
 *   bin/console order-integration:write-queue:drain --sleep=1
 */
#[AsCommand(
    name: 'order-integration:write-queue:drain',
    description: 'Drains the order write queue, dispatching commands to Shopware.'
)]
final class DrainWriteQueueCommand extends Command
{
    public function __construct(private readonly WriteQueueWorker $worker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process a single batch and exit.')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to sleep when the queue is empty.', '1')
            ->addOption('max-batches', null, InputOption::VALUE_REQUIRED, 'Stop after N batches (0 = unlimited).', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $once = (bool) $input->getOption('once');
        $sleep = max(0, (int) $input->getOption('sleep'));
        $maxBatches = max(0, (int) $input->getOption('max-batches'));

        $batches = 0;
        do {
            $summary = $this->worker->drainOnce(new \DateTimeImmutable());
            $batches++;

            if ($summary['claimed'] > 0) {
                $output->writeln(sprintf(
                    '<info>batch %d</info>: claimed=%d succeeded=%d retried=%d dead=%d',
                    $batches,
                    $summary['claimed'],
                    $summary['succeeded'],
                    $summary['retried'],
                    $summary['dead'],
                ));
            }

            if ($once) {
                break;
            }
            if ($maxBatches > 0 && $batches >= $maxBatches) {
                break;
            }
            if ($summary['claimed'] === 0 && $sleep > 0) {
                sleep($sleep);
            }
        } while (true);

        return Command::SUCCESS;
    }
}
