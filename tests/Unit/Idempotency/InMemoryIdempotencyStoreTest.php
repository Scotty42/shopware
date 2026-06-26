<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Idempotency;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Idempotency\IdempotencyRecord;
use Scotty42\OrderIntegration\Idempotency\InMemoryIdempotencyStore;

class InMemoryIdempotencyStoreTest extends TestCase
{
    public function testGetMissingKeyReturnsNull(): void
    {
        $store = new InMemoryIdempotencyStore();

        self::assertNull($store->get('does-not-exist'));
    }

    public function testPutThenGetReturnsSameRecord(): void
    {
        $store = new InMemoryIdempotencyStore();
        $record = new IdempotencyRecord(
            key: 'some-key',
            bodyHash: 'abc',
            statusCode: 201,
            rawResponseBody: '{"id":"1"}',
            responseHeaders: ['Location' => '/v1/orders/1'],
        );

        $store->put($record, 3600);

        $fetched = $store->get('some-key');
        self::assertSame($record, $fetched);
    }

    public function testPutAcceptsTtlArgument(): void
    {
        $store = new InMemoryIdempotencyStore();
        $record = new IdempotencyRecord('ttl-key', 'hash', 200, '{}');

        // Should not throw for any TTL value
        $store->put($record, 0);
        $store->put($record, 86400);

        self::assertSame($record, $store->get('ttl-key'));
    }
}
