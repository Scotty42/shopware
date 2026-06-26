<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Integration\Cqrs;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Cqrs\PdoConnectionProvider;
use Scotty42\OrderIntegration\Cqrs\Write\PdoWriteQueue;
use Scotty42\OrderIntegration\Cqrs\Write\WriteCommand;

class PdoWriteQueueTest extends TestCase
{
    private static ?PdoConnectionProvider $connectionProvider = null;
    private PdoWriteQueue $queue;

    public static function setUpBeforeClass(): void
    {
        $dsn = $_SERVER['ORDER_INTEGRATION_DB_DSN'] ?? $_ENV['ORDER_INTEGRATION_DB_DSN'] ?? getenv('ORDER_INTEGRATION_DB_DSN');
        if ($dsn === false || $dsn === null || $dsn === '') {
            return;
        }
        $user     = $_SERVER['ORDER_INTEGRATION_DB_USER'] ?? $_ENV['ORDER_INTEGRATION_DB_USER'] ?? getenv('ORDER_INTEGRATION_DB_USER') ?: null;
        $password = $_SERVER['ORDER_INTEGRATION_DB_PASSWORD'] ?? $_ENV['ORDER_INTEGRATION_DB_PASSWORD'] ?? getenv('ORDER_INTEGRATION_DB_PASSWORD') ?: null;
        self::$connectionProvider = new PdoConnectionProvider((string) $dsn, $user ?: null, $password ?: null);
    }

    protected function setUp(): void
    {
        $dsn = $_SERVER['ORDER_INTEGRATION_DB_DSN'] ?? $_ENV['ORDER_INTEGRATION_DB_DSN'] ?? getenv('ORDER_INTEGRATION_DB_DSN');
        if ($dsn === false || $dsn === null || $dsn === '') {
            $this->markTestSkipped('ORDER_INTEGRATION_DB_DSN is not set — skipping PDO integration tests.');
        }

        self::$connectionProvider->pdo()->exec('TRUNCATE TABLE order_write_queue');
        $this->queue = new PdoWriteQueue(self::$connectionProvider);
    }

    private function makeCommand(string $id, ?string $idempotencyKey = null): WriteCommand
    {
        return new WriteCommand(
            id: $id,
            type: WriteCommand::TYPE_ORDER_CREATE,
            payload: ['ref' => $id],
            idempotencyKey: $idempotencyKey,
        );
    }

    public function testEnqueueAndGet(): void
    {
        $cmd = $this->makeCommand('cmd-1', 'idem-1');
        $this->queue->enqueue($cmd);

        $fetched = $this->queue->get('cmd-1');

        self::assertNotNull($fetched);
        self::assertSame('cmd-1', $fetched->id);
        self::assertSame(WriteCommand::STATUS_QUEUED, $fetched->status);
        self::assertSame(0, $fetched->attempts);
    }

    public function testEnqueueIdempotency(): void
    {
        $first  = $this->makeCommand('cmd-2a', 'idem-2');
        $second = $this->makeCommand('cmd-2b', 'idem-2');

        $resultA = $this->queue->enqueue($first);
        $resultB = $this->queue->enqueue($second);

        // Both calls must return the same logical row (same id).
        self::assertSame($resultA->id, $resultB->id);
        self::assertSame('cmd-2a', $resultB->id);
    }

    public function testClaimReturnsOneCommand(): void
    {
        $this->queue->enqueue($this->makeCommand('cmd-3a'));
        $this->queue->enqueue($this->makeCommand('cmd-3b'));

        $now     = new \DateTimeImmutable();
        $claimed = $this->queue->claim(1, $now);

        self::assertCount(1, $claimed);
        self::assertSame(WriteCommand::STATUS_IN_PROGRESS, $claimed[0]->status);
        self::assertSame(1, $claimed[0]->attempts);
    }

    public function testClaimSkipsInProgress(): void
    {
        $this->queue->enqueue($this->makeCommand('cmd-4'));

        $now = new \DateTimeImmutable();
        $this->queue->claim(1, $now);

        // No unclaimed commands remain.
        $second = $this->queue->claim(1, $now);
        self::assertCount(0, $second);
    }

    public function testComplete(): void
    {
        $this->queue->enqueue($this->makeCommand('cmd-5'));

        $now     = new \DateTimeImmutable();
        $claimed = $this->queue->claim(1, $now);

        $this->queue->complete($claimed[0]->id, ['orderId' => 'abc'], $now);

        $fetched = $this->queue->get('cmd-5');
        self::assertSame(WriteCommand::STATUS_SUCCEEDED, $fetched->status);
        self::assertSame('abc', $fetched->result['orderId'] ?? null);
    }

    public function testRetryLater(): void
    {
        $this->queue->enqueue($this->makeCommand('cmd-6'));

        $now         = new \DateTimeImmutable();
        $claimed     = $this->queue->claim(1, $now);
        $futureRetry = $now->modify('+1 hour');

        $this->queue->retryLater($claimed[0]->id, 'timeout', $futureRetry, $now);

        $fetched = $this->queue->get('cmd-6');
        self::assertSame(WriteCommand::STATUS_QUEUED, $fetched->status);
        self::assertSame('timeout', $fetched->lastError);
        self::assertNotNull($fetched->availableAt);
        self::assertGreaterThan($now, $fetched->availableAt);
        self::assertSame(1, $fetched->attempts);

        // claim(1, now) must NOT re-claim it because available_at is in the future.
        $reClaimed = $this->queue->claim(1, $now);
        self::assertCount(0, $reClaimed);
    }

    public function testDeadLetter(): void
    {
        $this->queue->enqueue($this->makeCommand('cmd-7'));

        $now     = new \DateTimeImmutable();
        $claimed = $this->queue->claim(1, $now);

        $this->queue->deadletter($claimed[0]->id, 'fatal', $now);

        $fetched = $this->queue->get('cmd-7');
        self::assertSame(WriteCommand::STATUS_DEAD, $fetched->status);
        self::assertSame('fatal', $fetched->lastError);
    }

    public function testPurge(): void
    {
        $this->queue->enqueue($this->makeCommand('cmd-8a'));
        $this->queue->enqueue($this->makeCommand('cmd-8b'));

        $now     = new \DateTimeImmutable();
        $claimed = $this->queue->claim(2, $now);
        foreach ($claimed as $c) {
            $this->queue->complete($c->id, [], $now);
        }

        // Use a threshold 1 second in the future so updated_at < threshold is satisfied.
        $deleted = $this->queue->purge(WriteCommand::STATUS_SUCCEEDED, $now->modify('+1 second'));

        self::assertSame(2, $deleted);

        $pdo  = self::$connectionProvider->pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM order_write_queue');
        $stmt->execute();
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testDepth(): void
    {
        $this->queue->enqueue($this->makeCommand('cmd-9a'));
        $this->queue->enqueue($this->makeCommand('cmd-9b'));
        $this->queue->enqueue($this->makeCommand('cmd-9c'));

        self::assertSame(3, $this->queue->depth());

        $now = new \DateTimeImmutable();
        $this->queue->claim(1, $now);

        self::assertSame(2, $this->queue->depth());

        // Claim the remaining 2 — all 3 rows are now in_progress, so depth()
        // must return 0, proving it excludes in_progress rows.
        $this->queue->claim(2, $now);
        self::assertSame(0, $this->queue->depth());
    }
}
