<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Idempotency;

/**
 * Process-local store. Used by the unit tests and as a safe default; the
 * production binding is Psr6IdempotencyStore (shared, TTL-backed cache).
 */
final class InMemoryIdempotencyStore implements IdempotencyStoreInterface
{
    /** @var array<string,IdempotencyRecord> */
    private array $records = [];

    public function get(string $key): ?IdempotencyRecord
    {
        return $this->records[$key] ?? null;
    }

    public function put(IdempotencyRecord $record, int $ttlSeconds): void
    {
        $this->records[$record->key] = $record;
    }
}
