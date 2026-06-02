<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Service;

use Scotty42\OrderIntegration\Exception\IdempotencyConflictException;
use Scotty42\OrderIntegration\Exception\MissingIdempotencyKeyException;
use Scotty42\OrderIntegration\Idempotency\IdempotencyRecord;
use Scotty42\OrderIntegration\Idempotency\IdempotencyStoreInterface;

/**
 * Idempotency for mutating endpoints (docs/order-api-concept.md §5.2).
 *
 * Flow per request:
 *   $key  = normalizeKey($request->headers->get('Idempotency-Key'));
 *   $hash = hash($request->getContent());
 *   if ($record = begin($key, $hash)) { replay $record; }
 *   else { run side effect; complete($key, $hash, ...); }
 *
 * Pure of any framework/Shopware dependency so it is fully unit-testable.
 */
class IdempotencyService
{
    /** Accept UUIDs and other opaque tokens; reject trivially short keys. */
    private const KEY_PATTERN = '/^[A-Za-z0-9._:-]{8,255}$/';

    public function __construct(
        private readonly IdempotencyStoreInterface $store,
        private readonly int $ttlSeconds = 86400,
    ) {}

    public function normalizeKey(?string $key): string
    {
        if ($key === null || $key === '') {
            throw new MissingIdempotencyKeyException();
        }

        if (!preg_match(self::KEY_PATTERN, $key)) {
            throw new MissingIdempotencyKeyException(
                'Idempotency-Key is malformed: expected 8-255 chars from [A-Za-z0-9._:-].'
            );
        }

        return $key;
    }

    public function hash(string $rawBody): string
    {
        return hash('sha256', $rawBody);
    }

    /**
     * Returns a stored record to replay, or null to proceed with the side
     * effect. Throws 409 when the key was used with a different body.
     */
    public function begin(string $key, string $bodyHash): ?IdempotencyRecord
    {
        $existing = $this->store->get($key);
        if ($existing === null) {
            return null;
        }

        if (!hash_equals($existing->bodyHash, $bodyHash)) {
            throw new IdempotencyConflictException($key);
        }

        return $existing;
    }

    /**
     * @param array<string,string> $responseHeaders
     */
    public function complete(
        string $key,
        string $bodyHash,
        int $statusCode,
        string $rawResponseBody,
        array $responseHeaders = [],
    ): void {
        $this->store->put(
            new IdempotencyRecord($key, $bodyHash, $statusCode, $rawResponseBody, $responseHeaders),
            $this->ttlSeconds,
        );
    }
}
