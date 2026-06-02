<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Idempotency;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Exception\IdempotencyConflictException;
use Scotty42\OrderIntegration\Exception\MissingIdempotencyKeyException;
use Scotty42\OrderIntegration\Idempotency\InMemoryIdempotencyStore;
use Scotty42\OrderIntegration\Service\IdempotencyService;

class IdempotencyServiceTest extends TestCase
{
    private function service(): IdempotencyService
    {
        return new IdempotencyService(new InMemoryIdempotencyStore());
    }

    public function testNormalizeKeyRejectsNull(): void
    {
        $this->expectException(MissingIdempotencyKeyException::class);
        $this->service()->normalizeKey(null);
    }

    public function testNormalizeKeyRejectsEmptyString(): void
    {
        $this->expectException(MissingIdempotencyKeyException::class);
        $this->service()->normalizeKey('');
    }

    /**
     * @return array<string,array{string}>
     */
    public static function malformedKeys(): array
    {
        return [
            'too short'        => ['abc'],
            'illegal space'    => ['has a space 123'],
            'illegal slash'    => ['ab/cd/ef/gh'],
        ];
    }

    #[DataProvider('malformedKeys')]
    public function testNormalizeKeyRejectsMalformed(string $key): void
    {
        $this->expectException(MissingIdempotencyKeyException::class);
        $this->service()->normalizeKey($key);
    }

    public function testNormalizeKeyAcceptsUuidV4(): void
    {
        $uuid = 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
        self::assertSame($uuid, $this->service()->normalizeKey($uuid));
    }

    public function testHashIsDeterministicAndDiffersByBody(): void
    {
        $svc = $this->service();
        self::assertSame($svc->hash('{"a":1}'), $svc->hash('{"a":1}'));
        self::assertNotSame($svc->hash('{"a":1}'), $svc->hash('{"a":2}'));
    }

    public function testBeginReturnsNullWhenUnknown(): void
    {
        $svc = $this->service();
        self::assertNull($svc->begin('key-1234', $svc->hash('{}')));
    }

    public function testReplayReturnsStoredRecordForSameKeyAndBody(): void
    {
        $svc  = $this->service();
        $key  = 'key-12345678';
        $hash = $svc->hash('{"x":1}');

        $svc->complete($key, $hash, 201, '{"id":"abc"}', ['Location' => '/v1/orders/abc']);

        $record = $svc->begin($key, $hash);
        self::assertNotNull($record);
        self::assertSame(201, $record->statusCode);
        self::assertSame('{"id":"abc"}', $record->rawResponseBody);
        self::assertSame('/v1/orders/abc', $record->responseHeaders['Location']);
    }

    public function testSameKeyDifferentBodyThrowsConflict(): void
    {
        $svc = $this->service();
        $key = 'key-12345678';
        $svc->complete($key, $svc->hash('{"x":1}'), 201, '{}');

        $this->expectException(IdempotencyConflictException::class);
        $svc->begin($key, $svc->hash('{"x":2}'));
    }

    public function testConflictExceptionMapsTo409(): void
    {
        $e = new IdempotencyConflictException('key-12345678');
        self::assertSame(409, $e->getStatusCode());
        self::assertSame('order.idempotency_key_reused', $e->getErrorCode());
    }

    public function testMissingKeyExceptionMapsTo400(): void
    {
        $e = new MissingIdempotencyKeyException();
        self::assertSame(400, $e->getStatusCode());
        self::assertSame('order.idempotency_key_required', $e->getErrorCode());
    }
}
