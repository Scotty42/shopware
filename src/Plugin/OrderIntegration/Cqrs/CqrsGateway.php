<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs;

use Scotty42\OrderIntegration\Cqrs\Read\ReadProjectionInterface;
use Scotty42\OrderIntegration\Cqrs\Write\BackpressurePolicy;
use Scotty42\OrderIntegration\Cqrs\Write\WriteCommand;
use Scotty42\OrderIntegration\Cqrs\Write\WriteQueueInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

/**
 * Thin facade the controllers call to opt into the CQRS paths without knowing
 * about the queue/projection internals. Both paths are feature-flagged via env
 * so the plugin keeps its synchronous, DAL-backed behaviour by default:
 *
 *   ORDER_INTEGRATION_ASYNC_WRITES=true       enqueue writes, return 202
 *   ORDER_INTEGRATION_PROJECTION_READS=true   serve reads from the projection
 *
 * A per-request `Prefer: respond-async|respond-sync` header overrides the write
 * default.
 */
final class CqrsGateway
{
    private readonly bool $dbConfigured;
    private readonly bool $asyncWritesDefault;
    private readonly bool $projectionReadsEnabled;

    public function __construct(
        private readonly WriteQueueInterface $queue,
        private readonly ReadProjectionInterface $projection,
        private readonly BackpressurePolicy $backpressure,
        PdoConnectionProvider $connection,
    ) {
        // The CQRS paths are inert unless a read/queue DB is actually configured.
        // This prevents the footgun where ORDER_INTEGRATION_ASYNC_WRITES=true is
        // set without ORDER_INTEGRATION_DB_DSN — which would otherwise route every
        // write into the queue and fail with 500. No DSN => stay fully synchronous.
        $this->dbConfigured = $connection->isConfigured();
        $this->asyncWritesDefault = $this->dbConfigured && self::flag('ORDER_INTEGRATION_ASYNC_WRITES');
        $this->projectionReadsEnabled = $this->dbConfigured && self::flag('ORDER_INTEGRATION_PROJECTION_READS');
    }

    public function wantsAsyncWrite(Request $request): bool
    {
        if (!$this->dbConfigured) {
            return false; // no read/queue DB configured -> always synchronous
        }

        $prefer = strtolower((string) $request->headers->get('Prefer', ''));
        if (str_contains($prefer, 'respond-async')) {
            return true;
        }
        if (str_contains($prefer, 'respond-sync')) {
            return false;
        }

        return $this->asyncWritesDefault;
    }

    public function enqueueOrderCreate(array $payload, ?string $idempotencyKey): WriteCommand
    {
        return $this->queue->enqueue(new WriteCommand(
            id: Uuid::randomHex(),
            type: WriteCommand::TYPE_ORDER_CREATE,
            payload: $payload,
            idempotencyKey: $idempotencyKey,
        ));
    }

    public function shouldShed(): bool
    {
        return $this->backpressure->shouldShed($this->queue->depth(), 0.0);
    }

    public function retryAfterSeconds(): int
    {
        return $this->backpressure->retryAfterSeconds($this->queue->depth());
    }

    public function projectionReadsEnabled(): bool
    {
        return $this->projectionReadsEnabled;
    }

    public function getProjectedOrder(string $id): ?array
    {
        return $this->projection->get($id);
    }

    public function listProjected(array $filters, int $limit, ?string $cursor): array
    {
        return $this->projection->list($filters, $limit, $cursor);
    }

    private static function flag(string $key): bool
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
