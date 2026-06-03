<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Cqrs\Write;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Cqrs\Write\InMemoryWriteQueue;
use Scotty42\OrderIntegration\Cqrs\Write\WriteCommand;

class InMemoryWriteQueueTest extends TestCase
{
    private function cmd(string $id, ?string $idem = null, ?\DateTimeImmutable $availableAt = null): WriteCommand
    {
        return new WriteCommand(
            id: $id,
            type: WriteCommand::TYPE_ORDER_CREATE,
            payload: ['x' => 1],
            idempotencyKey: $idem,
            availableAt: $availableAt,
        );
    }

    public function testEnqueueAndGet(): void
    {
        $q = new InMemoryWriteQueue();
        $q->enqueue($this->cmd('a'));
        self::assertSame('a', $q->get('a')?->id);
        self::assertSame(1, $q->depth());
    }

    public function testIdempotentEnqueueReturnsExisting(): void
    {
        $q = new InMemoryWriteQueue();
        $first = $q->enqueue($this->cmd('a', 'key-1'));
        $second = $q->enqueue($this->cmd('b', 'key-1'));
        self::assertSame($first->id, $second->id);
        self::assertSame(1, $q->depth());
    }

    public function testClaimMarksInProgressAndIncrementsAttempts(): void
    {
        $q = new InMemoryWriteQueue();
        $q->enqueue($this->cmd('a'));
        $now = new \DateTimeImmutable();

        $claimed = $q->claim(10, $now);
        self::assertCount(1, $claimed);
        self::assertSame(WriteCommand::STATUS_IN_PROGRESS, $claimed[0]->status);
        self::assertSame(1, $claimed[0]->attempts);
        self::assertSame(0, $q->depth(), 'claimed item is no longer queued');
    }

    public function testClaimNeverHandsSameCommandTwice(): void
    {
        $q = new InMemoryWriteQueue();
        $q->enqueue($this->cmd('a'));
        $now = new \DateTimeImmutable();

        self::assertCount(1, $q->claim(10, $now));
        self::assertCount(0, $q->claim(10, $now), 'second claim must not re-hand the in-progress command');
    }

    public function testClaimRespectsAvailableAt(): void
    {
        $q = new InMemoryWriteQueue();
        $future = (new \DateTimeImmutable())->modify('+1 hour');
        $q->enqueue($this->cmd('a', null, $future));

        self::assertCount(0, $q->claim(10, new \DateTimeImmutable()), 'not yet due');
        self::assertCount(1, $q->claim(10, $future->modify('+1 second')), 'now due');
    }

    public function testCompleteRetryDeadletter(): void
    {
        $q = new InMemoryWriteQueue();
        $now = new \DateTimeImmutable();

        $q->enqueue($this->cmd('a'));
        $q->claim(10, $now);
        $q->complete('a', ['orderId' => 'o1'], $now);
        self::assertSame(WriteCommand::STATUS_SUCCEEDED, $q->get('a')?->status);
        self::assertSame('o1', $q->get('a')?->result['orderId']);

        $q->enqueue($this->cmd('b'));
        $q->claim(10, $now);
        $q->retryLater('b', 'boom', $now->modify('+30 seconds'), $now);
        self::assertSame(WriteCommand::STATUS_QUEUED, $q->get('b')?->status);
        self::assertSame('boom', $q->get('b')?->lastError);

        $q->deadletter('b', 'gave up', $now);
        self::assertSame(WriteCommand::STATUS_DEAD, $q->get('b')?->status);
    }
}
