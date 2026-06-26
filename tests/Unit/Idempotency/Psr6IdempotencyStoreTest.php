<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Idempotency;

use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Scotty42\OrderIntegration\Idempotency\IdempotencyRecord;
use Scotty42\OrderIntegration\Idempotency\Psr6IdempotencyStore;

class Psr6IdempotencyStoreTest extends TestCase
{
    private const RAW_KEY = 'my-idempotency-key';
    // The store namespaces and SHA-256 hashes the key; raw key is never used directly.
    private const EXPECTED_ITEM_KEY = 'order_integration.idempotency.' . \PHP_EOL;

    private function makeRecord(): IdempotencyRecord
    {
        return new IdempotencyRecord(
            key: self::RAW_KEY,
            bodyHash: 'body-hash',
            statusCode: 201,
            rawResponseBody: '{"id":"x"}',
            responseHeaders: ['Location' => '/v1/orders/x'],
        );
    }

    public function testGetCacheHitReturnsIdempotencyRecord(): void
    {
        $record = $this->makeRecord();
        $data   = $record->toArray();

        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn($data);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);

        $store   = new Psr6IdempotencyStore($pool);
        $fetched = $store->get(self::RAW_KEY);

        self::assertNotNull($fetched);
        self::assertSame($record->key, $fetched->key);
        self::assertSame($record->statusCode, $fetched->statusCode);
        self::assertSame($record->rawResponseBody, $fetched->rawResponseBody);
        self::assertSame($record->responseHeaders, $fetched->responseHeaders);
    }

    public function testGetCacheMissReturnsNull(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);

        $store = new Psr6IdempotencyStore($pool);

        self::assertNull($store->get(self::RAW_KEY));
    }

    public function testPutCallsCacheCorrectly(): void
    {
        $record = $this->makeRecord();

        $item = $this->createMock(CacheItemInterface::class);
        $item->expects(self::once())->method('set')->with($record->toArray());
        $item->expects(self::once())->method('expiresAfter')->with(3600);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->expects(self::once())->method('getItem')->willReturn($item);
        $pool->expects(self::once())->method('save')->with($item);

        $store = new Psr6IdempotencyStore($pool);
        $store->put($record, 3600);
    }

    public function testGetItemKeyIsNotTheRawIdempotencyKey(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);

        $passedKey = null;
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturnCallback(function (string $key) use (&$passedKey, $item): CacheItemInterface {
            $passedKey = $key;

            return $item;
        });

        $store = new Psr6IdempotencyStore($pool);
        $store->get(self::RAW_KEY);

        self::assertNotNull($passedKey);
        self::assertNotSame(self::RAW_KEY, $passedKey, 'raw idempotency key must not be used as cache key directly');
        self::assertStringStartsWith('order_integration.idempotency.', $passedKey);
    }
}
