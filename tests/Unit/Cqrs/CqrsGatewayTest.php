<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Cqrs;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Cqrs\CqrsGateway;
use Scotty42\OrderIntegration\Cqrs\PdoConnectionProvider;
use Scotty42\OrderIntegration\Cqrs\Read\InMemoryReadProjection;
use Scotty42\OrderIntegration\Cqrs\Write\BackpressurePolicy;
use Scotty42\OrderIntegration\Cqrs\Write\InMemoryWriteQueue;
use Scotty42\OrderIntegration\Cqrs\Write\WriteCommand;
use Symfony\Component\HttpFoundation\Request;

class CqrsGatewayTest extends TestCase
{
    private function gateway(?string $dsn): CqrsGateway
    {
        return new CqrsGateway(
            new InMemoryWriteQueue(),
            new InMemoryReadProjection(),
            new BackpressurePolicy(),
            new PdoConnectionProvider($dsn, null, null),
        );
    }

    /**
     * Footgun guard: with no read/queue DB configured (no DSN), the CQRS paths
     * stay inert even if the env flag is on OR the caller sends
     * Prefer: respond-async — so writes never get routed into a non-existent
     * queue and 500. This is the regression guard for the post-T13 incident.
     */
    public function testNoDsnForcesSynchronousAndDalReads(): void
    {
        $gw = $this->gateway(null);

        $req = Request::create('/api/order-integration/v1/orders', 'POST');
        $req->headers->set('Prefer', 'respond-async');

        self::assertFalse($gw->wantsAsyncWrite($req), 'no DSN must never go async');
        self::assertFalse($gw->projectionReadsEnabled(), 'no DSN must never read from projection');
    }

    public function testRespondSyncHeaderIsHonouredWhenDbConfigured(): void
    {
        // DSN present (not connected — pdo() is never called here).
        $gw = $this->gateway('pgsql:host=localhost;dbname=order_integration');

        $req = Request::create('/api/order-integration/v1/orders', 'POST');
        $req->headers->set('Prefer', 'respond-sync');

        self::assertFalse($gw->wantsAsyncWrite($req), 'Prefer: respond-sync must force the synchronous path');
    }

    public function testWantsAsyncWriteReturnsTrueWhenDsnPresentAndAsyncFlagSet(): void
    {
        $_SERVER['ORDER_INTEGRATION_ASYNC_WRITES'] = 'true';

        try {
            $gw = $this->gateway('sqlite::memory:');

            $req = Request::create('/api/order-integration/v1/orders', 'POST');
            // No Prefer header — falls to env default

            self::assertTrue($gw->wantsAsyncWrite($req), 'DSN present + async flag true must return true');
        } finally {
            unset($_SERVER['ORDER_INTEGRATION_ASYNC_WRITES']);
            putenv('ORDER_INTEGRATION_ASYNC_WRITES');
        }
    }

    public function testWantsAsyncWriteReturnsFalseWithNoPrefHeaderAndFlagOff(): void
    {
        $_SERVER['ORDER_INTEGRATION_ASYNC_WRITES'] = 'false';

        try {
            $gw = $this->gateway('sqlite::memory:');

            $req = Request::create('/api/order-integration/v1/orders', 'POST');
            // No Prefer header — falls to env default (false)

            self::assertFalse($gw->wantsAsyncWrite($req), 'No Prefer header + async flag false must return false');
        } finally {
            unset($_SERVER['ORDER_INTEGRATION_ASYNC_WRITES']);
            putenv('ORDER_INTEGRATION_ASYNC_WRITES');
        }
    }

    public function testEnqueueOrderCreateReturnsWriteCommandWithCorrectType(): void
    {
        $gw = $this->gateway('sqlite::memory:');

        $payload = ['salesChannelId' => str_repeat('a', 32)];
        $command = $gw->enqueueOrderCreate($payload, 'idem-key-1');

        self::assertInstanceOf(WriteCommand::class, $command);
        self::assertSame(WriteCommand::TYPE_ORDER_CREATE, $command->type);
        self::assertSame($payload, $command->payload);
        self::assertSame('idem-key-1', $command->idempotencyKey);
    }

    public function testShouldShedReturnsTrueWhenQueueAtOrAboveMaxDepth(): void
    {
        $queue = new InMemoryWriteQueue();
        // maxQueueDepth=0 means any depth (0) >= 0 triggers shedding
        $backpressure = new BackpressurePolicy(maxQueueDepth: 0);
        $gw = new CqrsGateway(
            $queue,
            new InMemoryReadProjection(),
            $backpressure,
            new PdoConnectionProvider('sqlite::memory:', null, null),
        );

        self::assertTrue($gw->shouldShed());
    }

    public function testShouldShedReturnsFalseWhenQueueBelowThreshold(): void
    {
        $gw = $this->gateway('sqlite::memory:');

        // InMemoryWriteQueue starts empty; default maxQueueDepth=10000
        self::assertFalse($gw->shouldShed());
    }

    public function testRetryAfterSecondsReturnsNonNegativeInteger(): void
    {
        $gw = $this->gateway('sqlite::memory:');

        $seconds = $gw->retryAfterSeconds();

        self::assertIsInt($seconds);
        self::assertGreaterThanOrEqual(0, $seconds);
    }
}
