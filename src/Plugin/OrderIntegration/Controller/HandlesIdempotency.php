<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Controller;

use Scotty42\OrderIntegration\Service\IdempotencyService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;

/**
 * Wraps a mutating controller action so it becomes idempotent. The consuming
 * controller must expose an IdempotencyService via getIdempotencyService().
 *
 * Replay is byte-faithful: the original status code, JSON body, and the
 * relevant headers (Location/ETag/Content-Type) are restored.
 *
 * Concurrency: when a LockFactory is provided via getIdempotencyLockFactory(),
 * a per-key mutex serialises concurrent requests that share the same
 * Idempotency-Key. Without the lock two simultaneous requests with the same
 * key can both pass begin() before either calls complete(), producing duplicate
 * side effects. Controllers that need this guarantee should inject a
 * LockFactory and override getIdempotencyLockFactory().
 */
trait HandlesIdempotency
{
    abstract protected function getIdempotencyService(): IdempotencyService;

    /** Headers worth restoring on a replayed response. */
    private const REPLAYABLE_HEADERS = ['Location', 'ETag', 'Content-Type'];

    /**
     * Override to provide a LockFactory for per-key serialisation.
     * Returns null by default (backwards-compatible, no locking).
     */
    protected function getIdempotencyLockFactory(): ?LockFactory
    {
        return null;
    }

    /**
     * @param callable():JsonResponse $produce
     */
    private function withIdempotency(Request $request, callable $produce): JsonResponse
    {
        $service = $this->getIdempotencyService();

        $key  = $service->normalizeKey($request->headers->get('Idempotency-Key'));
        $hash = $service->hash($request->getContent() ?: '');

        // Serialise concurrent requests with the same key so only the first
        // one executes the side effect; subsequent ones replay from the store.
        $lock = $this->getIdempotencyLockFactory()?->createLock('idempotency:' . $key, 30.0);
        $lock?->acquire(true);

        try {
            $cached = $service->begin($key, $hash);
            if ($cached !== null) {
                return new JsonResponse(
                    $cached->rawResponseBody !== '' ? $cached->rawResponseBody : 'null',
                    $cached->statusCode,
                    $cached->responseHeaders,
                    true, // body is already-encoded JSON
                );
            }

            $response = $produce();

            $headers = [];
            foreach (self::REPLAYABLE_HEADERS as $name) {
                if ($response->headers->has($name)) {
                    $headers[$name] = (string) $response->headers->get($name);
                }
            }

            $service->complete(
                $key,
                $hash,
                $response->getStatusCode(),
                $response->getContent() ?: '',
                $headers,
            );

            return $response;
        } finally {
            $lock?->release();
        }
    }
}
