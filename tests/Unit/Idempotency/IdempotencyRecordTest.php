<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Idempotency;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Idempotency\IdempotencyRecord;

class IdempotencyRecordTest extends TestCase
{
    public function testRoundTripWithResponseHeaders(): void
    {
        $record = new IdempotencyRecord(
            key: 'key-abc-123',
            bodyHash: 'sha256-of-body',
            statusCode: 201,
            rawResponseBody: '{"id":"order-1"}',
            responseHeaders: ['Location' => '/v1/orders/order-1', 'ETag' => '"abc"'],
        );

        $restored = IdempotencyRecord::fromArray($record->toArray());

        self::assertSame($record->key, $restored->key);
        self::assertSame($record->bodyHash, $restored->bodyHash);
        self::assertSame($record->statusCode, $restored->statusCode);
        self::assertSame($record->rawResponseBody, $restored->rawResponseBody);
        self::assertSame($record->responseHeaders, $restored->responseHeaders);
    }

    public function testRoundTripWithoutResponseHeaders(): void
    {
        $record = new IdempotencyRecord(
            key: 'key-no-headers',
            bodyHash: 'abc123',
            statusCode: 204,
            rawResponseBody: '',
        );

        $restored = IdempotencyRecord::fromArray($record->toArray());

        self::assertSame($record->key, $restored->key);
        self::assertSame($record->bodyHash, $restored->bodyHash);
        self::assertSame(204, $restored->statusCode);
        self::assertSame('', $restored->rawResponseBody);
        self::assertSame([], $restored->responseHeaders);
    }

    public function testFromArrayWithMinimalData(): void
    {
        $data = [
            'key'             => 'min-key',
            'bodyHash'        => 'hash',
            'statusCode'      => 200,
            'rawResponseBody' => '{}',
        ];

        $record = IdempotencyRecord::fromArray($data);

        self::assertSame('min-key', $record->key);
        self::assertSame('hash', $record->bodyHash);
        self::assertSame(200, $record->statusCode);
        self::assertSame('{}', $record->rawResponseBody);
        self::assertSame([], $record->responseHeaders);
    }
}
