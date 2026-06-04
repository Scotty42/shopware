<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Cqrs;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Cqrs\CqrsGateway;
use Scotty42\OrderIntegration\Cqrs\PdoConnectionProvider;
use Scotty42\OrderIntegration\Cqrs\Read\InMemoryReadProjection;
use Scotty42\OrderIntegration\Cqrs\Write\BackpressurePolicy;
use Scotty42\OrderIntegration\Cqrs\Write\InMemoryWriteQueue;
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
}
