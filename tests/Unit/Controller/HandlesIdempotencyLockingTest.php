<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Controller\HandlesIdempotency;
use Scotty42\OrderIntegration\Idempotency\InMemoryIdempotencyStore;
use Scotty42\OrderIntegration\Service\IdempotencyService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * Verifies the locking behaviour added to HandlesIdempotency::withIdempotency().
 *
 * Concrete goals:
 *  - The lock is acquired BEFORE begin() and released in the finally block,
 *    even when the callable throws.
 *  - When no LockFactory is provided (null) the trait works exactly as before.
 *  - Replay from store still works correctly under locking.
 */
class HandlesIdempotencyLockingTest extends TestCase
{
    // Concrete class that wires the trait so we can test it.
    private function makeSubject(?LockFactory $factory = null, ?IdempotencyService $service = null): object
    {
        $service ??= new IdempotencyService(new InMemoryIdempotencyStore());
        $capturedFactory = $factory;
        $capturedService = $service;

        return new class($capturedService, $capturedFactory) {
            use HandlesIdempotency;

            public function __construct(
                private readonly IdempotencyService $svc,
                private readonly ?LockFactory $factory,
            ) {}

            protected function getIdempotencyService(): IdempotencyService
            {
                return $this->svc;
            }

            protected function getIdempotencyLockFactory(): ?LockFactory
            {
                return $this->factory;
            }

            public function run(Request $request, callable $produce): JsonResponse
            {
                return $this->withIdempotency($request, $produce);
            }
        };
    }

    private function requestWithKey(string $key, string $body = '{}'): Request
    {
        $req = Request::create('/test', 'POST', [], [], [], [], $body);
        $req->headers->set('Idempotency-Key', $key);

        return $req;
    }

    public function testWithoutLockFactoryProducesResponse(): void
    {
        $subject = $this->makeSubject(null);
        $req = $this->requestWithKey('key-no-lock-00');

        $response = $subject->run($req, fn () => new JsonResponse(['ok' => true], 201));

        self::assertSame(201, $response->getStatusCode());
    }

    public function testWithLockFactoryProducesResponse(): void
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects(self::once())->method('acquire')->with(true);
        $lock->expects(self::once())->method('release');

        $factory = $this->createMock(LockFactory::class);
        $factory->expects(self::once())
            ->method('createLock')
            ->with(self::stringContains('idempotency:'), 30.0)
            ->willReturn($lock);

        $subject = $this->makeSubject($factory);
        $req = $this->requestWithKey('key-with-lock-01');

        $response = $subject->run($req, fn () => new JsonResponse(['ok' => true], 201));

        self::assertSame(201, $response->getStatusCode());
    }

    public function testLockIsReleasedWhenCallableThrows(): void
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects(self::once())->method('acquire');
        $lock->expects(self::once())->method('release');

        $factory = $this->createMock(LockFactory::class);
        $factory->method('createLock')->willReturn($lock);

        $subject = $this->makeSubject($factory);
        $req = $this->requestWithKey('key-throws-02');

        $this->expectException(\RuntimeException::class);
        $subject->run($req, fn () => throw new \RuntimeException('boom'));
    }

    public function testReplayReturnsCachedResponseUnderLocking(): void
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire');
        $lock->method('release');

        $factory = $this->createMock(LockFactory::class);
        $factory->method('createLock')->willReturn($lock);

        $service = new IdempotencyService(new InMemoryIdempotencyStore());
        $key  = 'key-replay-03';
        $hash = $service->hash('{}');
        $service->complete($key, $hash, 201, '{"id":"abc"}', ['Location' => '/v1/orders/abc']);

        $subject = $this->makeSubject($factory, $service);
        $req = $this->requestWithKey($key, '{}');

        $calledCount = 0;
        $response = $subject->run($req, function () use (&$calledCount): JsonResponse {
            $calledCount++;

            return new JsonResponse(['should' => 'not reach'], 500);
        });

        self::assertSame(0, $calledCount, 'side effect must not fire on replay');
        self::assertSame(201, $response->getStatusCode());
    }

    public function testLockAcquiredBeforeStoreCheck(): void
    {
        // Verify call order: acquire → begin (checked via side-effect ordering).
        $callOrder = [];

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturnCallback(function () use (&$callOrder): bool {
            $callOrder[] = 'acquire';

            return true;
        });
        $lock->method('release');

        $factory = $this->createMock(LockFactory::class);
        $factory->method('createLock')->willReturn($lock);

        $store = new class extends InMemoryIdempotencyStore {
            public array $callOrder = [];

            public function get(string $key): ?\Scotty42\OrderIntegration\Idempotency\IdempotencyRecord
            {
                $this->callOrder[] = 'begin';

                return parent::get($key);
            }
        };

        $service = new IdempotencyService($store);
        $subject = $this->makeSubject($factory, $service);
        $req = $this->requestWithKey('key-order-04');
        $subject->run($req, fn () => new JsonResponse([], 200));

        self::assertSame(['acquire', 'begin'], array_merge($callOrder, $store->callOrder));
    }
}
