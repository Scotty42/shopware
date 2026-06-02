<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Controller;

use Scotty42\OrderIntegration\Service\IdempotencyService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Wraps a mutating controller action so it becomes idempotent. The consuming
 * controller must expose an IdempotencyService via getIdempotencyService().
 *
 * Replay is byte-faithful: the original status code, JSON body, and the
 * relevant headers (Location/ETag/Content-Type) are restored.
 */
trait HandlesIdempotency
{
    abstract protected function getIdempotencyService(): IdempotencyService;

    /** Headers worth restoring on a replayed response. */
    private const REPLAYABLE_HEADERS = ['Location', 'ETag', 'Content-Type'];

    /**
     * @param callable():JsonResponse $produce
     */
    private function withIdempotency(Request $request, callable $produce): JsonResponse
    {
        $service = $this->getIdempotencyService();

        $key  = $service->normalizeKey($request->headers->get('Idempotency-Key'));
        $hash = $service->hash($request->getContent() ?: '');

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
    }
}
