<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Cqrs\Command;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Cqrs\Command\DrainWriteQueueCommand;
use Scotty42\OrderIntegration\Cqrs\Write\CommandHandlerInterface;
use Scotty42\OrderIntegration\Cqrs\Write\InMemoryWriteQueue;
use Scotty42\OrderIntegration\Cqrs\Write\RetryPolicy;
use Scotty42\OrderIntegration\Cqrs\Write\WriteCommand;
use Scotty42\OrderIntegration\Cqrs\Write\WriteQueueWorker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class DrainWriteQueueCommandTest extends TestCase
{
    private function noopHandler(): CommandHandlerInterface
    {
        return new class implements CommandHandlerInterface {
            public function handle(WriteCommand $command): array
            {
                return [];
            }
        };
    }

    private function worker(InMemoryWriteQueue $queue, ?CommandHandlerInterface $handler = null): WriteQueueWorker
    {
        return new WriteQueueWorker($queue, $handler ?? $this->noopHandler(), new RetryPolicy());
    }

    public function testZeroClaimedExitsSuccessfully(): void
    {
        $queue = new InMemoryWriteQueue();
        // Empty queue → 0 claimed

        $tester = new CommandTester(new DrainWriteQueueCommand($this->worker($queue)));
        $code   = $tester->execute(['--once' => true, '--sleep' => '0']);

        self::assertSame(Command::SUCCESS, $code);
    }

    public function testPositiveClaimedMentionsCountInOutput(): void
    {
        $queue = new InMemoryWriteQueue();
        $queue->enqueue(new WriteCommand('id-1', WriteCommand::TYPE_ORDER_CREATE, ['x' => 1]));
        $queue->enqueue(new WriteCommand('id-2', WriteCommand::TYPE_ORDER_CREATE, ['x' => 2]));

        $tester = new CommandTester(new DrainWriteQueueCommand($this->worker($queue)));
        $tester->execute(['--once' => true]);

        // The command outputs the claimed count; 2 items were enqueued
        self::assertStringContainsString('2', $tester->getDisplay());
    }

    public function testMaxBatchesOneStopsAfterOneDrainCall(): void
    {
        $queue = new InMemoryWriteQueue();
        $queue->enqueue(new WriteCommand('id-a', WriteCommand::TYPE_ORDER_CREATE, ['a' => 1]));

        $tester = new CommandTester(new DrainWriteQueueCommand($this->worker($queue)));
        $code   = $tester->execute(['--max-batches' => '1']);

        self::assertSame(Command::SUCCESS, $code);
        // After one batch the command must have terminated
        self::assertSame(WriteCommand::STATUS_SUCCEEDED, $queue->get('id-a')?->status);
    }
}
