<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Cqrs\Write;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Cqrs\Write\BackpressurePolicy;

class BackpressurePolicyTest extends TestCase
{
    public function testShedsWhenQueueTooDeep(): void
    {
        $p = new BackpressurePolicy(maxQueueDepth: 100, maxErrorRate: 0.5);
        self::assertFalse($p->shouldShed(99, 0.0));
        self::assertTrue($p->shouldShed(100, 0.0));
        self::assertTrue($p->shouldShed(250, 0.0));
    }

    public function testShedsWhenErrorRateTooHigh(): void
    {
        $p = new BackpressurePolicy(maxQueueDepth: 100, maxErrorRate: 0.5);
        self::assertFalse($p->shouldShed(0, 0.49));
        self::assertTrue($p->shouldShed(0, 0.5));
    }

    public function testRetryAfterScalesWithOverflow(): void
    {
        $p = new BackpressurePolicy(maxQueueDepth: 100, maxErrorRate: 0.5);
        self::assertSame(1, $p->retryAfterSeconds(50));   // under limit
        self::assertGreaterThanOrEqual(5, $p->retryAfterSeconds(100));
        self::assertLessThanOrEqual(300, $p->retryAfterSeconds(100000));
    }
}
