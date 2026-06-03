<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Cqrs\Write;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Cqrs\Write\CommandHandlerInterface;
use Scotty42\OrderIntegration\Cqrs\Write\InMemoryWriteQueue;
use Scotty42\OrderIntegration\Cqrs\Write\RetryPolicy;
use Scotty42\OrderIntegration\Cqrs\Write\WriteCommand;
use Scotty42\OrderIntegration\Cqrs\Write\WriteQueueWorker;

class WriteQueueWorkerTest extends TestCase
{
    private function okHandler(): CommandHandlerInterface
    {
        return new class implements CommandHandlerInterface {
            public function handle(WriteCommand $command): array
            {
                return ['orderId' => 'order-' . $command->id];
            }
        };
    }

    private function failingHandler(): CommandHandlerInterface
    {
        return new class implements CommandHandlerInterface {
            public function handle(WriteCommand $command): array
            {
                throw new \RuntimeException('downstream failure');
            }
        };
    }

    public function testSuccessfulCommandIsCompleted(): void
    {
        $queue = new InMemoryWriteQueue();
        $queue->enqueue(new WriteCommand('a', WriteCommand::TYPE_ORDER_CREATE, ['x' => 1]));

        $worker = new WriteQueueWorker($queue, $this->okHandler(), new RetryPolicy());
        $summary = $worker->drainOnce(new \DateTimeImmutable());

        self::assertSame(['claimed' => 1, 'succeeded' => 1, 'retried' => 0, 'dead' => 0], $summary);
        self::assertSame(WriteCommand::STATUS_SUCCEEDED, $queue->get('a')?->status);
        self::assertSame('order-a', $queue->get('a')?->result['orderId']);
    }

    public function testFailureSchedulesRetry(): void
    {
        $queue = new InMemoryWriteQueue();
        $queue->enqueue(new WriteCommand('a', WriteCommand::TYPE_ORDER_CREATE, ['x' => 1], maxAttempts: 5));

        $worker = new WriteQueueWorker($queue, $this->failingHandler(), new RetryPolicy(baseDelaySeconds: 10));
        $now = new \DateTimeImmutable('2026-06-03T10:00:00+00:00');
        $summary = $worker->drainOnce($now);

        self::assertSame(1, $summary['retried']);
        $cmd = $queue->get('a');
        self::assertSame(WriteCommand::STATUS_QUEUED, $cmd?->status);
        self::assertSame('downstream failure', $cmd?->lastError);
        self::assertNotNull($cmd?->availableAt);
        self::assertGreaterThan($now, $cmd->availableAt);
    }

    public function testExhaustedRetriesAreDeadlettered(): void
    {
        $queue = new InMemoryWriteQueue();
        $queue->enqueue(new WriteCommand('a', WriteCommand::TYPE_ORDER_CREATE, ['x' => 1], maxAttempts: 1));

        $worker = new WriteQueueWorker($queue, $this->failingHandler(), new RetryPolicy());
        $summary = $worker->drainOnce(new \DateTimeImmutable());

        self::assertSame(1, $summary['dead']);
        self::assertSame(WriteCommand::STATUS_DEAD, $queue->get('a')?->status);
    }
}
