<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Controller\OrderController;
use Scotty42\OrderIntegration\Cqrs\CqrsGateway;
use Scotty42\OrderIntegration\Cqrs\PdoConnectionProvider;
use Scotty42\OrderIntegration\Cqrs\Read\InMemoryReadProjection;
use Scotty42\OrderIntegration\Cqrs\Write\BackpressurePolicy;
use Scotty42\OrderIntegration\Cqrs\Write\InMemoryWriteQueue;
use Scotty42\OrderIntegration\Exception\IdempotencyConflictException;
use Scotty42\OrderIntegration\Exception\InvalidTransitionException;
use Scotty42\OrderIntegration\Exception\MissingIdempotencyKeyException;
use Scotty42\OrderIntegration\Exception\OrderNotFoundException;
use Scotty42\OrderIntegration\Exception\PreconditionFailedException;
use Scotty42\OrderIntegration\Exception\PreconditionRequiredException;
use Scotty42\OrderIntegration\Exception\ValidationException;
use Scotty42\OrderIntegration\Http\EtagComparator;
use Scotty42\OrderIntegration\Idempotency\InMemoryIdempotencyStore;
use Scotty42\OrderIntegration\Service\IdempotencyService;
use Scotty42\OrderIntegration\Service\OrderCreationService;
use Scotty42\OrderIntegration\Service\OrderMapper;
use Scotty42\OrderIntegration\Service\OrderPatchService;
use Scotty42\OrderIntegration\Service\StateMachineService;
use Scotty42\OrderIntegration\Validator\OrderCreateValidator;
use Scotty42\OrderIntegration\Validator\QueryValidator;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

/**
 * Unit tests for OrderController covering list, get, create, patch, and delete.
 *
 * The existing OrderControllerWriteLockTest covers write-lock concurrency paths —
 * those cases are not duplicated here.
 *
 * Uses InMemoryIdempotencyStore (not a mock) for all idempotency assertions.
 * Uses real CqrsGateway (final class) wired with InMemory* dependencies and
 * controlled via env-var flags set/unset per-test.
 */
class OrderControllerTest extends TestCase
{
    private const ORDER_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1';
    private const ETAG     = 'W/"abc123"';

    /** Env vars touched by makeCqrs() — wiped in tearDown to prevent pollution. */
    private const CQRS_ENV_VARS = [
        'ORDER_INTEGRATION_ASYNC_WRITES',
        'ORDER_INTEGRATION_PROJECTION_READS',
    ];

    protected function tearDown(): void
    {
        foreach (self::CQRS_ENV_VARS as $var) {
            unset($_SERVER[$var], $_ENV[$var]);
            putenv($var);
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function makeNoOpLockFactory(): LockFactory
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);

        $factory = $this->createMock(LockFactory::class);
        $factory->method('createLock')->willReturn($lock);

        return $factory;
    }

    /**
     * Construct a real CqrsGateway with InMemory* implementations so we don't
     * have to mock a final class. Flags are injected via $_SERVER (read at
     * construction time by CqrsGateway). dbConfigured=true is set by passing
     * a non-null DSN directly to PdoConnectionProvider — not via an env var.
     */
    private function makeCqrs(
        bool $projectionReads = false,
        bool $asyncWrites = false,
        bool $shouldShed = false,
        ?InMemoryReadProjection $projection = null,
        ?InMemoryWriteQueue $queue = null,
    ): CqrsGateway {
        $_SERVER['ORDER_INTEGRATION_ASYNC_WRITES']     = $asyncWrites     ? 'true' : 'false';
        $_SERVER['ORDER_INTEGRATION_PROJECTION_READS'] = $projectionReads ? 'true' : 'false';

        $writtenQueue = $queue ?? new InMemoryWriteQueue();

        // To force shedding: use maxQueueDepth=0 so depth()=0 still triggers it.
        $backpressure = $shouldShed
            ? new BackpressurePolicy(maxQueueDepth: 0)
            : new BackpressurePolicy();

        return new CqrsGateway(
            $writtenQueue,
            $projection ?? new InMemoryReadProjection(),
            $backpressure,
            new PdoConnectionProvider('sqlite::memory:', null, null),
        );
    }

    private function makeController(
        EntityRepository $orderRepo,
        QueryValidator $queryValidator,
        OrderCreateValidator $createValidator,
        OrderCreationService $creationService,
        OrderPatchService $patchService,
        OrderMapper $orderMapper,
        StateMachineService $stateMachine,
        IdempotencyService $idempotency,
        CqrsGateway $cqrs,
        ?LockFactory $lockFactory = null,
    ): OrderController {
        return new OrderController(
            $orderRepo,
            $queryValidator,
            $createValidator,
            $creationService,
            $patchService,
            $orderMapper,
            $stateMachine,
            $idempotency,
            new EtagComparator(),
            $cqrs,
            $lockFactory ?? $this->makeNoOpLockFactory(),
        );
    }

    /**
     * Controller wired with sensible no-op defaults. Override what you need.
     */
    private function makeDefaultController(
        ?EntityRepository $orderRepo = null,
        ?QueryValidator $queryValidator = null,
        ?OrderCreateValidator $createValidator = null,
        ?OrderCreationService $creationService = null,
        ?OrderPatchService $patchService = null,
        ?OrderMapper $orderMapper = null,
        ?StateMachineService $stateMachine = null,
        ?IdempotencyService $idempotency = null,
        ?CqrsGateway $cqrs = null,
        ?LockFactory $lockFactory = null,
    ): OrderController {
        return $this->makeController(
            $orderRepo      ?? $this->makeOrderRepo(null),
            $queryValidator ?? $this->createStub(QueryValidator::class),
            $createValidator ?? $this->createStub(OrderCreateValidator::class),
            $creationService ?? $this->createStub(OrderCreationService::class),
            $patchService   ?? $this->createStub(OrderPatchService::class),
            $orderMapper    ?? $this->makeOrderMapper(),
            $stateMachine   ?? $this->createStub(StateMachineService::class),
            $idempotency    ?? new IdempotencyService(new InMemoryIdempotencyStore()),
            $cqrs           ?? $this->makeCqrs(),
            $lockFactory    ?? $this->makeNoOpLockFactory(),
        );
    }

    /**
     * Build a mock EntityRepository that returns the given order on every search().
     * Passing null simulates "order not found".
     */
    private function makeOrderRepo(?OrderEntity $order): EntityRepository
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('first')->willReturn($order);
        $result->method('getElements')->willReturn($order !== null ? [$order] : []);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturn($result);

        return $repo;
    }

    private function makeOrderEntity(
        string $id = self::ORDER_ID,
        ?string $stateName = 'open',
    ): OrderEntity {
        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($id);

        if ($stateName !== null) {
            $state = $this->createStub(
                \Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity::class
            );
            $state->method('getTechnicalName')->willReturn($stateName);
            $order->method('getStateMachineState')->willReturn($state);
        } else {
            $order->method('getStateMachineState')->willReturn(null);
        }

        return $order;
    }

    private function makeOrderMapper(string $etag = self::ETAG): OrderMapper
    {
        $mapper = $this->createMock(OrderMapper::class);
        $mapper->method('etagFor')->willReturn($etag);
        $mapper->method('mapOrder')->willReturn(['id' => self::ORDER_ID, 'status' => 'open']);

        return $mapper;
    }

    private function makeRequest(
        string $method = 'GET',
        string $body = '',
        array $query = [],
        ?string $idempotencyKey = null,
        ?string $ifMatch = null,
        ?string $prefer = null,
    ): Request {
        $request = Request::create('/test', $method, $query, [], [], [], $body ?: null);
        if ($idempotencyKey !== null) {
            $request->headers->set('Idempotency-Key', $idempotencyKey);
        }
        if ($ifMatch !== null) {
            $request->headers->set('If-Match', $ifMatch);
        }
        if ($prefer !== null) {
            $request->headers->set('Prefer', $prefer);
        }

        return $request;
    }

    private function context(): Context
    {
        return Context::createDefaultContext();
    }

    /** Build a minimal valid create body. */
    private function validCreateBody(): string
    {
        return json_encode([
            'salesChannelId' => str_repeat('a', 32),
            'lineItems'      => [['productId' => str_repeat('b', 32), 'quantity' => 1]],
            'customer'       => ['id' => str_repeat('c', 32)],
        ]);
    }

    // ── list() ────────────────────────────────────────────────────────────────

    public function testListInvalidQueryParamThrowsValidationException(): void
    {
        $queryValidator = $this->createMock(QueryValidator::class);
        $queryValidator->method('validateListParams')->willThrowException(
            new ValidationException([['pointer' => '/limit', 'code' => 'invalid_limit', 'message' => 'bad']])
        );

        $controller = $this->makeDefaultController(queryValidator: $queryValidator);

        $this->expectException(ValidationException::class);
        $controller->list($this->makeRequest('GET', '', ['limit' => '-1']), $this->context());
    }

    public function testListDalPathReturns200WithItemsAndPage(): void
    {
        $order = $this->makeOrderEntity();
        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            cqrs: $this->makeCqrs(projectionReads: false),
        );

        $response = $controller->list($this->makeRequest(), $this->context());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertArrayHasKey('items', $body);
        self::assertArrayHasKey('page', $body);
        self::assertArrayHasKey('limit', $body['page']);
    }

    public function testListDalPathRespectsDefaultLimit50(): void
    {
        $order = $this->makeOrderEntity();
        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            cqrs: $this->makeCqrs(projectionReads: false),
        );

        $response = $controller->list($this->makeRequest(), $this->context());

        $body = json_decode($response->getContent(), true);
        self::assertSame(50, $body['page']['limit']);
    }

    public function testListDalPathCustomLimitIsRespected(): void
    {
        $order = $this->makeOrderEntity();
        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            cqrs: $this->makeCqrs(projectionReads: false),
        );

        $response = $controller->list($this->makeRequest('GET', '', ['limit' => '10']), $this->context());

        $body = json_decode($response->getContent(), true);
        self::assertSame(10, $body['page']['limit']);
    }

    public function testListDalPathLimitCappedAt200(): void
    {
        $order = $this->makeOrderEntity();
        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            cqrs: $this->makeCqrs(projectionReads: false),
        );

        $response = $controller->list($this->makeRequest('GET', '', ['limit' => '500']), $this->context());

        $body = json_decode($response->getContent(), true);
        self::assertSame(200, $body['page']['limit']); // 200 is the max page size, not an HTTP status code
    }

    public function testListCqrsProjectionPathReturns200WithItemsAndPage(): void
    {
        $projection = new InMemoryReadProjection();
        $projection->upsert(['id' => self::ORDER_ID, 'status' => 'open', '_etag' => 'W/"etag1"', 'createdAt' => '2024-01-01T00:00:00+00:00']);

        $cqrs = $this->makeCqrs(projectionReads: true, projection: $projection);
        $controller = $this->makeDefaultController(cqrs: $cqrs);

        $response = $controller->list($this->makeRequest(), $this->context());

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertArrayHasKey('items', $body);
        self::assertArrayHasKey('page', $body);
    }

    public function testListCqrsProjectionStripsInternalEtagField(): void
    {
        $projection = new InMemoryReadProjection();
        $projection->upsert(['id' => self::ORDER_ID, 'status' => 'open', '_etag' => 'W/"secret"', 'createdAt' => '2024-01-01T00:00:00+00:00']);

        $cqrs = $this->makeCqrs(projectionReads: true, projection: $projection);
        $controller = $this->makeDefaultController(cqrs: $cqrs);

        $response = $controller->list($this->makeRequest(), $this->context());

        $body = json_decode($response->getContent(), true);
        foreach ($body['items'] as $item) {
            self::assertArrayNotHasKey('_etag', $item);
        }
    }

    // ── get() ─────────────────────────────────────────────────────────────────

    public function testGetOrderNotFoundDalPathThrows(): void
    {
        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo(null),
            cqrs: $this->makeCqrs(projectionReads: false),
        );

        $this->expectException(OrderNotFoundException::class);
        $controller->get(self::ORDER_ID, $this->context());
    }

    public function testGetOrderFoundDalPathReturns200WithEtag(): void
    {
        $order = $this->makeOrderEntity();
        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            cqrs: $this->makeCqrs(projectionReads: false),
        );

        $response = $controller->get(self::ORDER_ID, $this->context());

        self::assertSame(200, $response->getStatusCode());
        self::assertNotEmpty($response->headers->get('ETag'));
    }

    public function testGetOrderFoundDalPathBodyContainsExpectedFields(): void
    {
        $order = $this->makeOrderEntity();
        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            cqrs: $this->makeCqrs(projectionReads: false),
        );

        $response = $controller->get(self::ORDER_ID, $this->context());

        $body = json_decode($response->getContent(), true);
        self::assertSame(self::ORDER_ID, $body['id']);
    }

    public function testGetCqrsProjectionFoundReturns200WithEtag(): void
    {
        $projection = new InMemoryReadProjection();
        $projection->upsert(['id' => self::ORDER_ID, 'status' => 'open', '_etag' => 'W/"proj1"']);

        $cqrs = $this->makeCqrs(projectionReads: true, projection: $projection);
        $controller = $this->makeDefaultController(cqrs: $cqrs);

        $response = $controller->get(self::ORDER_ID, $this->context());

        self::assertSame(200, $response->getStatusCode());
        self::assertNotEmpty($response->headers->get('ETag'));
    }

    public function testGetCqrsProjectionStripsInternalEtagFromBody(): void
    {
        $projection = new InMemoryReadProjection();
        $projection->upsert(['id' => self::ORDER_ID, 'status' => 'open', '_etag' => 'W/"proj1"']);

        $cqrs = $this->makeCqrs(projectionReads: true, projection: $projection);
        $controller = $this->makeDefaultController(cqrs: $cqrs);

        $response = $controller->get(self::ORDER_ID, $this->context());

        $body = json_decode($response->getContent(), true);
        self::assertArrayNotHasKey('_etag', $body);
    }

    public function testGetCqrsProjectionNotFoundFallsThroughToDal(): void
    {
        // Projection has no entry for the order → falls through to DAL
        $order = $this->makeOrderEntity();
        $cqrs = $this->makeCqrs(projectionReads: true, projection: new InMemoryReadProjection());

        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            cqrs: $cqrs,
        );

        $response = $controller->get(self::ORDER_ID, $this->context());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testGetCqrsProjectionNotFoundAndDalNotFoundThrows(): void
    {
        $cqrs = $this->makeCqrs(projectionReads: true, projection: new InMemoryReadProjection());

        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo(null),
            cqrs: $cqrs,
        );

        $this->expectException(OrderNotFoundException::class);
        $controller->get(self::ORDER_ID, $this->context());
    }

    // ── create() ─────────────────────────────────────────────────────────────

    public function testCreateMissingIdempotencyKeyThrows(): void
    {
        $controller = $this->makeDefaultController(
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        // No Idempotency-Key header
        $request = $this->makeRequest('POST', $this->validCreateBody());

        $this->expectException(MissingIdempotencyKeyException::class);
        $controller->create($request, $this->context());
    }

    public function testCreateInvalidJsonBodyThrowsValidationException(): void
    {
        $controller = $this->makeDefaultController(
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $request = $this->makeRequest('POST', 'not-valid-json', [], 'idem-key-create-001');

        $this->expectException(ValidationException::class);
        $controller->create($request, $this->context());
    }

    public function testCreateNonObjectJsonBodyThrowsValidationExceptionViaValidator(): void
    {
        // json_decode('["item1"]', true) returns a PHP array, which passes the
        // is_array() check. The OrderCreateValidator then rejects it because
        // the required fields (salesChannelId, lineItems, customer) are absent.
        $controller = $this->makeDefaultController(
            createValidator: new OrderCreateValidator(),
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        // JSON array is valid JSON but not a JSON object — lacks required fields
        $request = $this->makeRequest('POST', '["item1"]', [], 'idem-key-create-002');

        $this->expectException(ValidationException::class);
        $controller->create($request, $this->context());
    }

    public function testCreateValidatorFailureThrowsValidationException(): void
    {
        $createValidator = $this->createMock(OrderCreateValidator::class);
        $createValidator->method('validate')->willThrowException(
            new ValidationException([['pointer' => '/salesChannelId', 'code' => 'required', 'message' => 'required']])
        );

        $controller = $this->makeDefaultController(
            createValidator: $createValidator,
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $request = $this->makeRequest('POST', '{"salesChannelId":""}', [], 'idem-key-create-003');

        $this->expectException(ValidationException::class);
        $controller->create($request, $this->context());
    }

    public function testCreateAsyncWritePathReturns202WithLocationHeader(): void
    {
        // asyncWrites: true in the env flag routes to the async path
        $queue = new InMemoryWriteQueue();
        $cqrs = $this->makeCqrs(asyncWrites: true, shouldShed: false, queue: $queue);

        $controller = $this->makeDefaultController(
            cqrs: $cqrs,
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $request = $this->makeRequest('POST', $this->validCreateBody(), [], 'idem-key-async-001');

        $response = $controller->create($request, $this->context());

        self::assertSame(202, $response->getStatusCode());
        self::assertNotEmpty($response->headers->get('Location'));
        $responseBody = json_decode($response->getContent(), true);
        self::assertArrayHasKey('jobId', $responseBody);
        self::assertArrayHasKey('status', $responseBody);
    }

    public function testCreateAsyncWritePathLocationHeaderPointsToJobRoute(): void
    {
        $queue = new InMemoryWriteQueue();
        $cqrs = $this->makeCqrs(asyncWrites: true, shouldShed: false, queue: $queue);

        $controller = $this->makeDefaultController(
            cqrs: $cqrs,
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $request = $this->makeRequest('POST', $this->validCreateBody(), [], 'idem-key-async-002');

        $response = $controller->create($request, $this->context());

        self::assertStringContainsString('/api/order-integration/v1/jobs/', $response->headers->get('Location'));
    }

    public function testCreateShedPathReturns503WithRetryAfterHeader(): void
    {
        $cqrs = $this->makeCqrs(asyncWrites: true, shouldShed: true);

        $controller = $this->makeDefaultController(
            cqrs: $cqrs,
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $request = $this->makeRequest('POST', $this->validCreateBody(), [], 'idem-key-shed-001', prefer: 'respond-async');

        $response = $controller->create($request, $this->context());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('0', $response->headers->get('Retry-After'));
    }

    public function testCreateShedPathResponseBodyContainsBackpressureCode(): void
    {
        $cqrs = $this->makeCqrs(asyncWrites: true, shouldShed: true);

        $controller = $this->makeDefaultController(
            cqrs: $cqrs,
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $request = $this->makeRequest('POST', $this->validCreateBody(), [], 'idem-key-shed-002', prefer: 'respond-async');

        $response = $controller->create($request, $this->context());

        $responseBody = json_decode($response->getContent(), true);
        self::assertSame('order.backpressure', $responseBody['code']);
    }

    public function testCreateSyncPathReturns201WithLocationAndEtag(): void
    {
        $orderId = self::ORDER_ID;
        $order = $this->makeOrderEntity($orderId);

        $creationService = $this->createMock(OrderCreationService::class);
        $creationService->method('createOrder')->willReturn($orderId);

        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            creationService: $creationService,
            cqrs: $this->makeCqrs(asyncWrites: false),
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $request = $this->makeRequest('POST', $this->validCreateBody(), [], 'idem-key-sync-001');

        $response = $controller->create($request, $this->context());

        self::assertSame(201, $response->getStatusCode());
        self::assertStringContainsString($orderId, $response->headers->get('Location'));
        self::assertNotEmpty($response->headers->get('ETag'));
    }

    public function testCreateSyncPathResponseBodyContainsOrderId(): void
    {
        $orderId = self::ORDER_ID;
        $order = $this->makeOrderEntity($orderId);

        $creationService = $this->createMock(OrderCreationService::class);
        $creationService->method('createOrder')->willReturn($orderId);

        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            creationService: $creationService,
            cqrs: $this->makeCqrs(asyncWrites: false),
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $request = $this->makeRequest('POST', $this->validCreateBody(), [], 'idem-key-sync-002');

        $response = $controller->create($request, $this->context());

        $responseBody = json_decode($response->getContent(), true);
        self::assertSame($orderId, $responseBody['id']);
    }

    public function testCreateSyncPathOrderCreationServiceCalledOnce(): void
    {
        $orderId = self::ORDER_ID;
        $order = $this->makeOrderEntity($orderId);

        $creationService = $this->createMock(OrderCreationService::class);
        $creationService->expects(self::once())->method('createOrder')->willReturn($orderId);

        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            creationService: $creationService,
            cqrs: $this->makeCqrs(asyncWrites: false),
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $request = $this->makeRequest('POST', $this->validCreateBody(), [], 'idem-key-sync-003');

        $controller->create($request, $this->context());
    }

    public function testCreateIdempotencyReplayReturnsCachedResponse(): void
    {
        $store = new InMemoryIdempotencyStore();
        $idempotency = new IdempotencyService($store);

        $idemKey = 'idem-key-replay-create-001';
        $requestBody = $this->validCreateBody();
        $hash = $idempotency->hash($requestBody);
        $idempotency->complete($idemKey, $hash, 201, '{"id":"cached-order"}', [
            'Location' => '/api/order-integration/v1/orders/cached-order',
            'ETag'     => 'W/"cached-etag"',
        ]);

        $creationService = $this->createMock(OrderCreationService::class);
        $creationService->expects(self::never())->method('createOrder');

        $controller = $this->makeDefaultController(
            creationService: $creationService,
            idempotency: $idempotency,
        );

        $request = $this->makeRequest('POST', $requestBody, [], $idemKey);

        $response = $controller->create($request, $this->context());

        self::assertSame(201, $response->getStatusCode());
        $responseBody = json_decode($response->getContent(), true);
        self::assertSame('cached-order', $responseBody['id']);
    }

    public function testCreateIdempotencyConflictThrowsConflictException(): void
    {
        $store = new InMemoryIdempotencyStore();
        $idempotency = new IdempotencyService($store);

        // Pre-seed the store with a record whose body hash does NOT match the body we'll send.
        $idemKey = 'idem-conflict-001';
        $idempotency->complete($idemKey, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 201, '{"id":"old-order"}', []);

        $creationService = $this->createMock(OrderCreationService::class);
        $creationService->expects(self::never())->method('createOrder');

        $controller = $this->makeDefaultController(
            creationService: $creationService,
            idempotency: $idempotency,
        );

        // Send a different body — its SHA-256 will not match the stored fake hash.
        $request = $this->makeRequest('POST', $this->validCreateBody(), [], $idemKey);

        $this->expectException(IdempotencyConflictException::class);
        $controller->create($request, $this->context());
    }

    // ── patch() ───────────────────────────────────────────────────────────────

    public function testPatchMissingIdempotencyKeyThrows(): void
    {
        $controller = $this->makeDefaultController(
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $request = $this->makeRequest('PATCH', '{"customerComment":"test"}');

        $this->expectException(MissingIdempotencyKeyException::class);
        $controller->patch(self::ORDER_ID, $request, $this->context());
    }

    public function testPatchOrderNotFoundThrows(): void
    {
        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo(null),
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $request = $this->makeRequest('PATCH', '{"customerComment":"test"}', [], 'idem-patch-001', self::ETAG);

        $this->expectException(OrderNotFoundException::class);
        $controller->patch(self::ORDER_ID, $request, $this->context());
    }

    public function testPatchMissingIfMatchThrows(): void
    {
        $order = $this->makeOrderEntity();
        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        // No If-Match header
        $request = $this->makeRequest('PATCH', '{"customerComment":"test"}', [], 'idem-patch-002');

        $this->expectException(PreconditionRequiredException::class);
        $controller->patch(self::ORDER_ID, $request, $this->context());
    }

    public function testPatchStaleIfMatchThrows(): void
    {
        $order = $this->makeOrderEntity();
        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            orderMapper: $this->makeOrderMapper(self::ETAG),
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $request = $this->makeRequest('PATCH', '{"customerComment":"test"}', [], 'idem-patch-003', 'W/"stale-etag"');

        $this->expectException(PreconditionFailedException::class);
        $controller->patch(self::ORDER_ID, $request, $this->context());
    }

    public function testPatchEmptyMutableFieldsThrowsValidationException(): void
    {
        $order = $this->makeOrderEntity();
        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            orderMapper: $this->makeOrderMapper(self::ETAG),
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        // Body has no fields from the allowed list
        $body = json_encode(['orderId' => 'some-id', 'unknownField' => 'value']);
        $request = $this->makeRequest('PATCH', $body, [], 'idem-patch-004', self::ETAG);

        $this->expectException(ValidationException::class);
        $controller->patch(self::ORDER_ID, $request, $this->context());
    }

    public function testPatchHappyPathCallsPatchService(): void
    {
        $order = $this->makeOrderEntity();

        $patchService = $this->createMock(OrderPatchService::class);
        $patchService->expects(self::once())->method('patch');

        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            orderMapper: $this->makeOrderMapper(self::ETAG),
            patchService: $patchService,
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $body = json_encode(['customerComment' => 'updated comment']);
        $request = $this->makeRequest('PATCH', $body, [], 'idem-patch-happy-001', self::ETAG);

        $controller->patch(self::ORDER_ID, $request, $this->context());
    }

    public function testPatchHappyPathReturns200WithEtag(): void
    {
        $order = $this->makeOrderEntity();

        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            orderMapper: $this->makeOrderMapper(self::ETAG),
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $body = json_encode(['customerComment' => 'updated comment']);
        $request = $this->makeRequest('PATCH', $body, [], 'idem-patch-happy-002', self::ETAG);

        $response = $controller->patch(self::ORDER_ID, $request, $this->context());

        self::assertSame(200, $response->getStatusCode());
        self::assertNotEmpty($response->headers->get('ETag'));
    }

    public function testPatchIdempotencyReplayReturnsCachedResponse(): void
    {
        $store = new InMemoryIdempotencyStore();
        $idempotency = new IdempotencyService($store);

        $idemKey = 'idem-patch-replay-001';
        $requestBody = json_encode(['customerComment' => 'cached comment']);
        $hash = $idempotency->hash($requestBody);
        $idempotency->complete($idemKey, $hash, 200, '{"id":"cached-order"}', ['ETag' => 'W/"cached"']);

        $patchService = $this->createMock(OrderPatchService::class);
        $patchService->expects(self::never())->method('patch');

        $controller = $this->makeDefaultController(
            patchService: $patchService,
            idempotency: $idempotency,
        );

        $request = $this->makeRequest('PATCH', $requestBody, [], $idemKey, self::ETAG);

        $response = $controller->patch(self::ORDER_ID, $request, $this->context());

        self::assertSame(200, $response->getStatusCode());
        $responseBody = json_decode($response->getContent(), true);
        self::assertSame('cached-order', $responseBody['id']);
    }

    // ── delete() ─────────────────────────────────────────────────────────────

    public function testDeleteMissingIdempotencyKeyThrows(): void
    {
        $controller = $this->makeDefaultController(
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        // No Idempotency-Key header
        $request = $this->makeRequest('DELETE', '', [], null, self::ETAG);

        $this->expectException(MissingIdempotencyKeyException::class);
        $controller->delete(self::ORDER_ID, $request, $this->context());
    }

    public function testDeleteOrderNotFoundThrows(): void
    {
        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo(null),
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $request = $this->makeRequest('DELETE', '', [], 'idem-delete-001', self::ETAG);

        $this->expectException(OrderNotFoundException::class);
        $controller->delete(self::ORDER_ID, $request, $this->context());
    }

    public function testDeleteMissingIfMatchThrows(): void
    {
        $order = $this->makeOrderEntity();
        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        // No If-Match header
        $request = $this->makeRequest('DELETE', '', [], 'idem-delete-002');

        $this->expectException(PreconditionRequiredException::class);
        $controller->delete(self::ORDER_ID, $request, $this->context());
    }

    public function testDeleteStaleIfMatchThrows(): void
    {
        $order = $this->makeOrderEntity();
        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            orderMapper: $this->makeOrderMapper(self::ETAG),
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $request = $this->makeRequest('DELETE', '', [], 'idem-delete-003', 'W/"stale-etag"');

        $this->expectException(PreconditionFailedException::class);
        $controller->delete(self::ORDER_ID, $request, $this->context());
    }

    public function testDeleteHardParamReturns403Forbidden(): void
    {
        $order = $this->makeOrderEntity();
        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            orderMapper: $this->makeOrderMapper(self::ETAG),
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        // Query params on DELETE must be in the URI, not the third positional arg
        $request = Request::create('/test?hard=true', 'DELETE');
        $request->headers->set('Idempotency-Key', 'idem-delete-hard-001');
        $request->headers->set('If-Match', self::ETAG);

        $response = $controller->delete(self::ORDER_ID, $request, $this->context());

        self::assertSame(403, $response->getStatusCode());
    }

    public function testDeleteHardParamResponseBodyContainsForbiddenCode(): void
    {
        $order = $this->makeOrderEntity();
        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            orderMapper: $this->makeOrderMapper(self::ETAG),
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $request = Request::create('/test?hard=true', 'DELETE');
        $request->headers->set('Idempotency-Key', 'idem-delete-hard-002');
        $request->headers->set('If-Match', self::ETAG);

        $response = $controller->delete(self::ORDER_ID, $request, $this->context());

        $body = json_decode($response->getContent(), true);
        self::assertSame('order.hard_delete_not_permitted', $body['code']);
    }

    public function testDeleteAlreadyCancelledOrderSkipsTransitionAndReturns204(): void
    {
        $order = $this->makeOrderEntity(stateName: 'cancelled');

        $stateMachine = $this->createMock(StateMachineService::class);
        $stateMachine->expects(self::never())->method('transitionOrder');

        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            orderMapper: $this->makeOrderMapper(self::ETAG),
            stateMachine: $stateMachine,
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $request = $this->makeRequest('DELETE', '', [], 'idem-delete-cancelled-001', self::ETAG);

        $response = $controller->delete(self::ORDER_ID, $request, $this->context());

        self::assertSame(204, $response->getStatusCode());
    }

    public function testDeleteHappyPathTransitionsOrderToCancelled(): void
    {
        $order = $this->makeOrderEntity(stateName: 'open');

        $stateMachine = $this->createMock(StateMachineService::class);
        $stateMachine->expects(self::once())->method('transitionOrder')
            ->with(self::ORDER_ID, 'cancelled', self::anything());

        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            orderMapper: $this->makeOrderMapper(self::ETAG),
            stateMachine: $stateMachine,
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $request = $this->makeRequest('DELETE', '', [], 'idem-delete-happy-001', self::ETAG);

        $response = $controller->delete(self::ORDER_ID, $request, $this->context());

        self::assertSame(204, $response->getStatusCode());
    }

    public function testDeleteIllegalTransitionExceptionPropagates(): void
    {
        $order = $this->makeOrderEntity(stateName: 'completed');

        $stateMachine = $this->createMock(StateMachineService::class);
        $stateMachine->method('transitionOrder')->willThrowException(
            new InvalidTransitionException('order', 'cancelled', ['open', 'in_progress'])
        );

        $controller = $this->makeDefaultController(
            orderRepo: $this->makeOrderRepo($order),
            orderMapper: $this->makeOrderMapper(self::ETAG),
            stateMachine: $stateMachine,
            idempotency: new IdempotencyService(new InMemoryIdempotencyStore()),
        );

        $request = $this->makeRequest('DELETE', '', [], 'idem-delete-illegal-001', self::ETAG);

        $this->expectException(InvalidTransitionException::class);
        $controller->delete(self::ORDER_ID, $request, $this->context());
    }

    public function testDeleteIdempotencyReplayReturnsCachedResponse(): void
    {
        $store = new InMemoryIdempotencyStore();
        $idempotency = new IdempotencyService($store);

        $idemKey = 'idem-delete-replay-001';
        $hash = $idempotency->hash('');
        $idempotency->complete($idemKey, $hash, 204, '', []);

        $stateMachine = $this->createMock(StateMachineService::class);
        $stateMachine->expects(self::never())->method('transitionOrder');

        $controller = $this->makeDefaultController(
            stateMachine: $stateMachine,
            idempotency: $idempotency,
        );

        $request = $this->makeRequest('DELETE', '', [], $idemKey, self::ETAG);

        $response = $controller->delete(self::ORDER_ID, $request, $this->context());

        self::assertSame(204, $response->getStatusCode());
    }
}
