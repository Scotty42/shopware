<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Idempotency;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Production store backed by a shared PSR-6 cache pool (Shopware's
 * `cache.app`). Keys are namespaced and hashed so arbitrary client-supplied
 * Idempotency-Key values are safe as cache item keys.
 */
final class Psr6IdempotencyStore implements IdempotencyStoreInterface
{
    private const PREFIX = 'order_integration.idempotency.';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {}

    public function get(string $key): ?IdempotencyRecord
    {
        $item = $this->cache->getItem($this->itemKey($key));
        if (!$item->isHit()) {
            return null;
        }

        $data = $item->get();

        return is_array($data) ? IdempotencyRecord::fromArray($data) : null;
    }

    public function put(IdempotencyRecord $record, int $ttlSeconds): void
    {
        $item = $this->cache->getItem($this->itemKey($record->key));
        $item->set($record->toArray());
        $item->expiresAfter($ttlSeconds);
        $this->cache->save($item);
    }

    private function itemKey(string $key): string
    {
        return self::PREFIX . hash('sha256', $key);
    }
}
