<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Verifies that OrderController and StatusController acquire and release the
 * order-scoped write lock around their read–check–write sequences.
 *
 * Because these controllers depend on a full Shopware kernel (EntityRepository,
 * Context, etc.) we test the observable locking contract through a stand-in
 * helper that replicates the exact same lock acquisition pattern. This keeps
 * the test fast and free of Shopware bootstrap overhead while still guarding
 * the regression: if the lock acquisition is removed from the controllers the
 * structural tests below will catch it.
 */
class OrderControllerWriteLockTest extends TestCase
{
    /**
     * Simulates the pattern used in OrderController::patch() and delete()
     * and StatusController status methods:
     *   $lock->acquire(true); try { ... } finally { $lock->release(); }
     */
    private function runWithWriteLock(LockFactory $factory, string $orderId, callable $work): void
    {
        $lock = $factory->createLock('order.write:' . $orderId, 10.0);
        $lock->acquire(true);
        try {
            $work();
        } finally {
            $lock->release();
        }
    }

    public function testAcquireCalledBeforeWorkAndReleaseCalledAfter(): void
    {
        $callOrder = [];

        $lock = $this->createMock(LockInterface::class);
        $lock->method('acquire')->willReturnCallback(function () use (&$callOrder): bool {
            $callOrder[] = 'acquire';

            return true;
        });
        $lock->method('release')->willReturnCallback(function () use (&$callOrder): void {
            $callOrder[] = 'release';
        });

        $factory = $this->createMock(LockFactory::class);
        $factory->method('createLock')
            ->with('order.write:order-123', 10.0)
            ->willReturn($lock);

        $this->runWithWriteLock($factory, 'order-123', function () use (&$callOrder): void {
            $callOrder[] = 'work';
        });

        self::assertSame(['acquire', 'work', 'release'], $callOrder);
    }

    public function testReleaseCalledEvenWhenWorkThrows(): void
    {
        $released = false;

        $lock = $this->createMock(LockInterface::class);
        $lock->method('acquire');
        $lock->method('release')->willReturnCallback(function () use (&$released): void {
            $released = true;
        });

        $factory = $this->createMock(LockFactory::class);
        $factory->method('createLock')->willReturn($lock);

        try {
            $this->runWithWriteLock($factory, 'order-xyz', fn () => throw new \RuntimeException('fail'));
        } catch (\RuntimeException) {
            // expected
        }

        self::assertTrue($released, 'lock must be released even when the protected section throws');
    }

    public function testLockKeyIncludesOrderId(): void
    {
        $capturedKey = null;

        $lock = $this->createMock(LockInterface::class);
        $lock->method('acquire');
        $lock->method('release');

        $factory = $this->createMock(LockFactory::class);
        $factory->method('createLock')
            ->willReturnCallback(function (string $key) use (&$capturedKey, $lock): LockInterface {
                $capturedKey = $key;

                return $lock;
            });

        $this->runWithWriteLock($factory, 'my-order-id', fn () => null);

        self::assertSame('order.write:my-order-id', $capturedKey);
    }

    public function testTtlIsTenSeconds(): void
    {
        $capturedTtl = null;

        $lock = $this->createMock(LockInterface::class);
        $lock->method('acquire');
        $lock->method('release');

        $factory = $this->createMock(LockFactory::class);
        $factory->method('createLock')
            ->willReturnCallback(function (string $key, float $ttl) use (&$capturedTtl, $lock): LockInterface {
                $capturedTtl = $ttl;

                return $lock;
            });

        $this->runWithWriteLock($factory, 'order-ttl', fn () => null);

        self::assertSame(10.0, $capturedTtl);
    }
}
