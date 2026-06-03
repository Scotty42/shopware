<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Cqrs\Write;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Cqrs\Write\RetryPolicy;

class RetryPolicyTest extends TestCase
{
    public function testShouldRetryUntilMaxAttempts(): void
    {
        $p = new RetryPolicy();
        self::assertTrue($p->shouldRetry(1, 5));
        self::assertTrue($p->shouldRetry(4, 5));
        self::assertFalse($p->shouldRetry(5, 5));
        self::assertFalse($p->shouldRetry(6, 5));
    }

    public function testExponentialBackoffWithCap(): void
    {
        $p = new RetryPolicy(baseDelaySeconds: 5, maxDelaySeconds: 60);
        self::assertSame(5, $p->nextDelaySeconds(1));
        self::assertSame(10, $p->nextDelaySeconds(2));
        self::assertSame(20, $p->nextDelaySeconds(3));
        self::assertSame(40, $p->nextDelaySeconds(4));
        self::assertSame(60, $p->nextDelaySeconds(5)); // capped (would be 80)
        self::assertSame(60, $p->nextDelaySeconds(6));
    }

    public function testJitterIsApplied(): void
    {
        $p = new RetryPolicy(baseDelaySeconds: 10, maxDelaySeconds: 1000, jitter: static fn (int $d): int => $d + 1);
        self::assertSame(11, $p->nextDelaySeconds(1));
    }

    public function testNextAvailableAtAddsDelay(): void
    {
        $p = new RetryPolicy(baseDelaySeconds: 30, maxDelaySeconds: 3600);
        $now = new \DateTimeImmutable('2026-06-03T10:00:00+00:00');
        self::assertSame('2026-06-03T10:00:30+00:00', $p->nextAvailableAt(1, $now)->format(\DateTimeInterface::ATOM));
    }
}
