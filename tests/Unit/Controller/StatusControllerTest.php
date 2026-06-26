<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Controller\StatusController;
use Scotty42\OrderIntegration\Exception\MissingIdempotencyKeyException;
use Scotty42\OrderIntegration\Exception\OrderNotFoundException;
use Scotty42\OrderIntegration\Exception\PreconditionFailedException;
use Scotty42\OrderIntegration\Exception\PreconditionRequiredException;
use Scotty42\OrderIntegration\Exception\ValidationException;
use Scotty42\OrderIntegration\Http\EtagComparator;
use Scotty42\OrderIntegration\Idempotency\InMemoryIdempotencyStore;
use Scotty42\OrderIntegration\Service\IdempotencyService;
use Scotty42\OrderIntegration\Service\OrderMapper;
use Scotty42\OrderIntegration\Service\StateMachineService;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * Unit tests for StatusController covering setOrderStatus(), setPaymentStatus(),
 * and setDeliveryStatus(). Uses InMemoryIdempotencyStore (no mock) and mocks only
 * the Shopware infrastructure (EntityRepository, StateMachineService, OrderMapper).
 */
class StatusControllerTest extends TestCase
{
    private const ORDER_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1';
    private const ETAG     = 'W/"abc123"';

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Build a controller wired with real IdempotencyService + InMemoryStore
     * and a mock LockFactory that returns a no-op lock.
     */
    private function makeController(
        EntityRepository $orderRepo,
        StateMachineService $stateMachine,
        OrderMapper $orderMapper,
    ): StatusController {
        $idempotency = new IdempotencyService(new InMemoryIdempotencyStore());

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        return new StatusController(
            $orderRepo,
            $stateMachine,
            $orderMapper,
            $idempotency,
            new EtagComparator(),
            $lockFactory,
        );
    }

    /**
     * Build a request with the given body JSON, and the mandatory idempotency
     * and If-Match headers.
     */
    private function makeRequest(
        string $path,
        array $body,
        ?string $idempotencyKey = 'idem-key-00001',
        ?string $ifMatch = self::ETAG,
    ): Request {
        $encoded = json_encode($body);
        $request = new Request([], [], [], [], [], [], $encoded);
        $request->setMethod('PUT');
        if ($idempotencyKey !== null) {
            $request->headers->set('Idempotency-Key', $idempotencyKey);
        }
        if ($ifMatch !== null) {
            $request->headers->set('If-Match', $ifMatch);
        }

        return $request;
    }

    /**
     * Build a mock EntityRepository that returns the given order on every search().
     * A null value makes search return an empty result (order not found).
     */
    private function makeOrderRepo(?OrderEntity $order): EntityRepository
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('first')->willReturn($order);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturn($result);

        return $repo;
    }

    /**
     * Build a minimal mock OrderEntity with the given transaction/delivery state.
     * The OrderMapper is also mocked, so we only need enough methods for the
     * controller's own logic (getTransactions / getDeliveries).
     */
    private function makeOrderEntity(
        ?OrderTransactionCollection $transactions = null,
        ?OrderDeliveryCollection $deliveries = null,
    ): OrderEntity {
        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn(self::ORDER_ID);
        $order->method('getTransactions')->willReturn($transactions);
        $order->method('getDeliveries')->willReturn($deliveries);

        return $order;
    }

    /**
     * Build an OrderMapper stub that returns a fixed ETag for any order and
     * an empty array for mapOrder().
     */
    private function makeOrderMapper(string $etag = self::ETAG): OrderMapper
    {
        $mapper = $this->createMock(OrderMapper::class);
        $mapper->method('etagFor')->willReturn($etag);
        $mapper->method('mapOrder')->willReturn(['id' => self::ORDER_ID]);

        return $mapper;
    }

    private function makeStateMachine(): StateMachineService
    {
        return $this->createMock(StateMachineService::class);
    }

    private function makeTransaction(string $id = 'tx-001'): OrderTransactionEntity
    {
        $tx = $this->createMock(OrderTransactionEntity::class);
        $tx->method('getId')->willReturn($id);
        $tx->method('getUniqueIdentifier')->willReturn($id);

        return $tx;
    }

    private function makeDelivery(string $id = 'dlv-001'): OrderDeliveryEntity
    {
        $delivery = $this->createMock(OrderDeliveryEntity::class);
        $delivery->method('getId')->willReturn($id);
        $delivery->method('getUniqueIdentifier')->willReturn($id);

        return $delivery;
    }

    private function context(): Context
    {
        return Context::createDefaultContext();
    }

    // ── TC6.1 — setOrderStatus ───────────────────────────────────────────────

    public function testSetOrderStatusMissingIdempotencyKeyThrows(): void
    {
        $controller = $this->makeController(
            $this->makeOrderRepo(null),
            $this->makeStateMachine(),
            $this->makeOrderMapper(),
        );

        $request = $this->makeRequest('/order-status', ['status' => 'completed'], idempotencyKey: null);

        $this->expectException(MissingIdempotencyKeyException::class);
        $controller->setOrderStatus(self::ORDER_ID, $request, $this->context());
    }

    public function testSetOrderStatusOrderNotFoundThrows(): void
    {
        $controller = $this->makeController(
            $this->makeOrderRepo(null),
            $this->makeStateMachine(),
            $this->makeOrderMapper(),
        );

        $request = $this->makeRequest('/order-status', ['status' => 'completed']);

        $this->expectException(OrderNotFoundException::class);
        $controller->setOrderStatus(self::ORDER_ID, $request, $this->context());
    }

    public function testSetOrderStatusMissingIfMatchThrows(): void
    {
        $order = $this->makeOrderEntity();
        $controller = $this->makeController(
            $this->makeOrderRepo($order),
            $this->makeStateMachine(),
            $this->makeOrderMapper(),
        );

        $request = $this->makeRequest('/order-status', ['status' => 'completed'], ifMatch: null);

        $this->expectException(PreconditionRequiredException::class);
        $controller->setOrderStatus(self::ORDER_ID, $request, $this->context());
    }

    public function testSetOrderStatusStaleIfMatchThrows(): void
    {
        $order = $this->makeOrderEntity();
        // Controller etag is ETAG, but request sends a different value
        $controller = $this->makeController(
            $this->makeOrderRepo($order),
            $this->makeStateMachine(),
            $this->makeOrderMapper(self::ETAG),
        );

        $request = $this->makeRequest('/order-status', ['status' => 'completed'], ifMatch: 'W/"stale-etag"');

        $this->expectException(PreconditionFailedException::class);
        $controller->setOrderStatus(self::ORDER_ID, $request, $this->context());
    }

    public function testSetOrderStatusMissingStatusFieldThrows(): void
    {
        $order = $this->makeOrderEntity();
        $controller = $this->makeController(
            $this->makeOrderRepo($order),
            $this->makeStateMachine(),
            $this->makeOrderMapper(),
        );

        $request = $this->makeRequest('/order-status', []); // no status field

        $this->expectException(ValidationException::class);
        $controller->setOrderStatus(self::ORDER_ID, $request, $this->context());
    }

    public function testSetOrderStatusHappyPathReturns200WithEtag(): void
    {
        $order = $this->makeOrderEntity();
        $stateMachine = $this->createMock(StateMachineService::class);
        $stateMachine->expects(self::once())->method('transitionOrder');

        $controller = $this->makeController(
            $this->makeOrderRepo($order),
            $stateMachine,
            $this->makeOrderMapper(),
        );

        $request = $this->makeRequest('/order-status', ['status' => 'completed']);

        $response = $controller->setOrderStatus(self::ORDER_ID, $request, $this->context());

        self::assertSame(200, $response->getStatusCode());
        self::assertNotEmpty($response->headers->get('ETag'));
    }

    // ── TC6.2 — setPaymentStatus ─────────────────────────────────────────────

    public function testSetPaymentStatusMissingIdempotencyKeyThrows(): void
    {
        $controller = $this->makeController(
            $this->makeOrderRepo(null),
            $this->makeStateMachine(),
            $this->makeOrderMapper(),
        );

        $request = $this->makeRequest('/payment-status', ['status' => 'paid'], idempotencyKey: null);

        $this->expectException(MissingIdempotencyKeyException::class);
        $controller->setPaymentStatus(self::ORDER_ID, $request, $this->context());
    }

    public function testSetPaymentStatusOrderNotFoundThrows(): void
    {
        $controller = $this->makeController(
            $this->makeOrderRepo(null),
            $this->makeStateMachine(),
            $this->makeOrderMapper(),
        );

        $request = $this->makeRequest('/payment-status', ['status' => 'paid']);

        $this->expectException(OrderNotFoundException::class);
        $controller->setPaymentStatus(self::ORDER_ID, $request, $this->context());
    }

    public function testSetPaymentStatusMissingIfMatchThrows(): void
    {
        // assertIfMatch fires before the empty-collection check, so PreconditionRequiredException (428)
        // is thrown first regardless of collection state.
        $order = $this->makeOrderEntity(new OrderTransactionCollection());
        $controller = $this->makeController(
            $this->makeOrderRepo($order),
            $this->makeStateMachine(),
            $this->makeOrderMapper(),
        );

        $request = $this->makeRequest('/payment-status', ['status' => 'paid'], ifMatch: null);

        $this->expectException(PreconditionRequiredException::class);
        $controller->setPaymentStatus(self::ORDER_ID, $request, $this->context());
    }

    public function testSetPaymentStatusStaleIfMatchThrows(): void
    {
        $order = $this->makeOrderEntity(new OrderTransactionCollection());
        $controller = $this->makeController(
            $this->makeOrderRepo($order),
            $this->makeStateMachine(),
            $this->makeOrderMapper(self::ETAG),
        );

        $request = $this->makeRequest('/payment-status', ['status' => 'paid'], ifMatch: 'W/"stale"');

        $this->expectException(PreconditionFailedException::class);
        $controller->setPaymentStatus(self::ORDER_ID, $request, $this->context());
    }

    public function testSetPaymentStatusNoTransactionsReturnsConflict(): void
    {
        $order = $this->makeOrderEntity(new OrderTransactionCollection()); // empty collection
        $controller = $this->makeController(
            $this->makeOrderRepo($order),
            $this->makeStateMachine(),
            $this->makeOrderMapper(),
        );

        $request = $this->makeRequest('/payment-status', ['status' => 'paid']);

        $response = $controller->setPaymentStatus(self::ORDER_ID, $request, $this->context());

        self::assertSame(409, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame('order.no_transactions', $body['code']);
    }

    public function testSetPaymentStatusNullTransactionsReturnsConflict(): void
    {
        $order = $this->makeOrderEntity(null); // null transactions
        $controller = $this->makeController(
            $this->makeOrderRepo($order),
            $this->makeStateMachine(),
            $this->makeOrderMapper(),
        );

        $request = $this->makeRequest('/payment-status', ['status' => 'paid'], idempotencyKey: 'idem-null-tx-01');

        $response = $controller->setPaymentStatus(self::ORDER_ID, $request, $this->context());

        self::assertSame(409, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame('order.no_transactions', $body['code']);
    }

    public function testSetPaymentStatusMissingStatusFieldThrows(): void
    {
        $txCollection = new OrderTransactionCollection([$this->makeTransaction()]);
        $order = $this->makeOrderEntity($txCollection);
        $controller = $this->makeController(
            $this->makeOrderRepo($order),
            $this->makeStateMachine(),
            $this->makeOrderMapper(),
        );

        $request = $this->makeRequest('/payment-status', []);

        $this->expectException(ValidationException::class);
        $controller->setPaymentStatus(self::ORDER_ID, $request, $this->context());
    }

    public function testSetPaymentStatusHappyPathReturns200WithEtag(): void
    {
        $txCollection = new OrderTransactionCollection([$this->makeTransaction()]);
        $order = $this->makeOrderEntity($txCollection);
        $stateMachine = $this->createMock(StateMachineService::class);
        $stateMachine->expects(self::once())->method('transitionPayment');

        $controller = $this->makeController(
            $this->makeOrderRepo($order),
            $stateMachine,
            $this->makeOrderMapper(),
        );

        $request = $this->makeRequest('/payment-status', ['status' => 'paid'], idempotencyKey: 'idem-tx-happy-01');

        $response = $controller->setPaymentStatus(self::ORDER_ID, $request, $this->context());

        self::assertSame(200, $response->getStatusCode());
        self::assertNotEmpty($response->headers->get('ETag'));
    }

    // ── TC6.3 — setDeliveryStatus ────────────────────────────────────────────

    public function testSetDeliveryStatusMissingIdempotencyKeyThrows(): void
    {
        $controller = $this->makeController(
            $this->makeOrderRepo(null),
            $this->makeStateMachine(),
            $this->makeOrderMapper(),
        );

        $request = $this->makeRequest('/delivery-status', ['status' => 'shipped'], idempotencyKey: null);

        $this->expectException(MissingIdempotencyKeyException::class);
        $controller->setDeliveryStatus(self::ORDER_ID, $request, $this->context());
    }

    public function testSetDeliveryStatusOrderNotFoundThrows(): void
    {
        $controller = $this->makeController(
            $this->makeOrderRepo(null),
            $this->makeStateMachine(),
            $this->makeOrderMapper(),
        );

        $request = $this->makeRequest('/delivery-status', ['status' => 'shipped']);

        $this->expectException(OrderNotFoundException::class);
        $controller->setDeliveryStatus(self::ORDER_ID, $request, $this->context());
    }

    public function testSetDeliveryStatusMissingIfMatchThrows(): void
    {
        // assertIfMatch fires before the empty-collection check, so PreconditionRequiredException (428)
        // is thrown first regardless of collection state.
        $order = $this->makeOrderEntity(null, new OrderDeliveryCollection());
        $controller = $this->makeController(
            $this->makeOrderRepo($order),
            $this->makeStateMachine(),
            $this->makeOrderMapper(),
        );

        $request = $this->makeRequest('/delivery-status', ['status' => 'shipped'], ifMatch: null);

        $this->expectException(PreconditionRequiredException::class);
        $controller->setDeliveryStatus(self::ORDER_ID, $request, $this->context());
    }

    public function testSetDeliveryStatusStaleIfMatchThrows(): void
    {
        $order = $this->makeOrderEntity(null, new OrderDeliveryCollection());
        $controller = $this->makeController(
            $this->makeOrderRepo($order),
            $this->makeStateMachine(),
            $this->makeOrderMapper(self::ETAG),
        );

        $request = $this->makeRequest('/delivery-status', ['status' => 'shipped'], ifMatch: 'W/"stale"');

        $this->expectException(PreconditionFailedException::class);
        $controller->setDeliveryStatus(self::ORDER_ID, $request, $this->context());
    }

    public function testSetDeliveryStatusNoDeliveriesReturnsConflict(): void
    {
        $order = $this->makeOrderEntity(null, new OrderDeliveryCollection()); // empty
        $controller = $this->makeController(
            $this->makeOrderRepo($order),
            $this->makeStateMachine(),
            $this->makeOrderMapper(),
        );

        $request = $this->makeRequest('/delivery-status', ['status' => 'shipped']);

        $response = $controller->setDeliveryStatus(self::ORDER_ID, $request, $this->context());

        self::assertSame(409, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame('order.no_deliveries', $body['code']);
    }

    public function testSetDeliveryStatusNullDeliveriesReturnsConflict(): void
    {
        $order = $this->makeOrderEntity(null, null);
        $controller = $this->makeController(
            $this->makeOrderRepo($order),
            $this->makeStateMachine(),
            $this->makeOrderMapper(),
        );

        $request = $this->makeRequest('/delivery-status', ['status' => 'shipped'], idempotencyKey: 'idem-null-dlv-01');

        $response = $controller->setDeliveryStatus(self::ORDER_ID, $request, $this->context());

        self::assertSame(409, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame('order.no_deliveries', $body['code']);
    }

    public function testSetDeliveryStatusMissingStatusFieldThrows(): void
    {
        $dlvCollection = new OrderDeliveryCollection([$this->makeDelivery()]);
        $order = $this->makeOrderEntity(null, $dlvCollection);
        $controller = $this->makeController(
            $this->makeOrderRepo($order),
            $this->makeStateMachine(),
            $this->makeOrderMapper(),
        );

        $request = $this->makeRequest('/delivery-status', []);

        $this->expectException(ValidationException::class);
        $controller->setDeliveryStatus(self::ORDER_ID, $request, $this->context());
    }

    public function testSetDeliveryStatusHappyPathReturns200WithEtag(): void
    {
        $dlvCollection = new OrderDeliveryCollection([$this->makeDelivery()]);
        $order = $this->makeOrderEntity(null, $dlvCollection);
        $stateMachine = $this->createMock(StateMachineService::class);
        $stateMachine->expects(self::once())->method('transitionDelivery');

        $controller = $this->makeController(
            $this->makeOrderRepo($order),
            $stateMachine,
            $this->makeOrderMapper(),
        );

        $request = $this->makeRequest('/delivery-status', ['status' => 'shipped'], idempotencyKey: 'idem-dlv-happy-01');

        $response = $controller->setDeliveryStatus(self::ORDER_ID, $request, $this->context());

        self::assertSame(200, $response->getStatusCode());
        self::assertNotEmpty($response->headers->get('ETag'));
    }

    // ── TC6.4 — idempotency replay ───────────────────────────────────────────

    public function testSetOrderStatusIdempotencyReplayReturnsCachedResponse(): void
    {
        $store = new InMemoryIdempotencyStore();
        $idempotency = new IdempotencyService($store);

        $key  = 'replay-order-status-01';
        $hash = $idempotency->hash('{"status":"completed"}');
        $idempotency->complete($key, $hash, 200, '{"id":"cached"}', ['ETag' => 'W/"cached"']);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        $stateMachine = $this->createMock(StateMachineService::class);
        $stateMachine->expects(self::never())->method('transitionOrder');

        $controller = new StatusController(
            $this->makeOrderRepo(null), // would 404 if called
            $stateMachine,
            $this->makeOrderMapper(),
            $idempotency,
            new EtagComparator(),
            $lockFactory,
        );

        $request = $this->makeRequest('/order-status', ['status' => 'completed'], idempotencyKey: $key);

        $response = $controller->setOrderStatus(self::ORDER_ID, $request, $this->context());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame('cached', $body['id']);
    }

    public function testSetPaymentStatusIdempotencyReplayReturnsCachedResponse(): void
    {
        $store = new InMemoryIdempotencyStore();
        $idempotency = new IdempotencyService($store);

        $key  = 'replay-payment-status-01';
        $hash = $idempotency->hash('{"status":"paid"}');
        $idempotency->complete($key, $hash, 200, '{"id":"cached"}', ['ETag' => 'W/"cached"']);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        $stateMachine = $this->createMock(StateMachineService::class);
        $stateMachine->expects(self::never())->method('transitionPayment');

        $controller = new StatusController(
            $this->makeOrderRepo(null), // would 404 if called
            $stateMachine,
            $this->makeOrderMapper(),
            $idempotency,
            new EtagComparator(),
            $lockFactory,
        );

        $request = $this->makeRequest('/payment-status', ['status' => 'paid'], idempotencyKey: $key);

        $response = $controller->setPaymentStatus(self::ORDER_ID, $request, $this->context());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame('cached', $body['id']);
    }

    public function testSetDeliveryStatusIdempotencyReplayReturnsCachedResponse(): void
    {
        $store = new InMemoryIdempotencyStore();
        $idempotency = new IdempotencyService($store);

        $key  = 'replay-delivery-status-01';
        $hash = $idempotency->hash('{"status":"shipped"}');
        $idempotency->complete($key, $hash, 200, '{"id":"cached"}', ['ETag' => 'W/"cached"']);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLock')->willReturn($lock);

        $stateMachine = $this->createMock(StateMachineService::class);
        $stateMachine->expects(self::never())->method('transitionDelivery');

        $controller = new StatusController(
            $this->makeOrderRepo(null), // would 404 if called
            $stateMachine,
            $this->makeOrderMapper(),
            $idempotency,
            new EtagComparator(),
            $lockFactory,
        );

        $request = $this->makeRequest('/delivery-status', ['status' => 'shipped'], idempotencyKey: $key);

        $response = $controller->setDeliveryStatus(self::ORDER_ID, $request, $this->context());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame('cached', $body['id']);
    }
}
