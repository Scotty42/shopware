<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Controller\ErpSyncController;
use Scotty42\OrderIntegration\Erp\ErpSyncService;
use Scotty42\OrderIntegration\Exception\ValidationException;
use Scotty42\OrderIntegration\Service\OrderMapper;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers ErpSyncController::pull() validation and response shape.
 * Acknowledge tests are in ErpSyncControllerAcknowledgeTest.
 */
class ErpSyncControllerTest extends TestCase
{
    private const ORDER_ID_1 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1';
    private const ORDER_ID_2 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa2';

    private function makeController(
        ?EntityRepository $orderRepo = null,
        ?ErpSyncService   $service   = null,
        ?OrderMapper      $mapper    = null,
    ): ErpSyncController {
        return new ErpSyncController(
            $orderRepo ?? $this->createStub(EntityRepository::class),
            $service   ?? $this->createStub(ErpSyncService::class),
            $mapper    ?? $this->createStub(OrderMapper::class),
        );
    }

    private function makeOrderMock(string $id): OrderEntity
    {
        $order = $this->createMock(OrderEntity::class);
        $order->method('getId')->willReturn($id);
        $order->method('getUniqueIdentifier')->willReturn($id);
        $order->method('getCreatedAt')->willReturn(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        return $order;
    }

    /** Build an EntitySearchResult stub that returns the given elements from getElements(). */
    private function makeSearchResult(array $elements): EntitySearchResult
    {
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getElements')->willReturn($elements);

        return $result;
    }

    private function makeOrderRepo(array $elements): EntityRepository
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturn($this->makeSearchResult($elements));

        return $repo;
    }

    /** Mapper stub that returns ['id' => $order->getId()] for any order. */
    private function makeMapper(): OrderMapper
    {
        $mapper = $this->createMock(OrderMapper::class);
        $mapper->method('mapOrder')
            ->willReturnCallback(static fn(OrderEntity $o) => ['id' => $o->getId()]);

        return $mapper;
    }

    // ── pull() — response shape ───────────────────────────────────────────────

    public function testPullReturnsEmptyItems(): void
    {
        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo([]),
            mapper:    $this->makeMapper(),
        );

        $response = $controller->pull(
            Request::create('/test', 'GET'),
            Context::createDefaultContext(),
        );

        $body = json_decode($response->getContent(), true);
        self::assertSame([], $body['items']);
        self::assertNull($body['page']['nextCursor']);
    }

    public function testPullReturnsItems(): void
    {
        $order1 = $this->makeOrderMock(self::ORDER_ID_1);
        $order2 = $this->makeOrderMock(self::ORDER_ID_2);

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo([$order1, $order2]),
            mapper:    $this->makeMapper(),
        );

        $response = $controller->pull(
            Request::create('/test', 'GET', ['limit' => '2']),
            Context::createDefaultContext(),
        );

        $body = json_decode($response->getContent(), true);
        self::assertCount(2, $body['items']);
        self::assertSame(self::ORDER_ID_1, $body['items'][0]['id']);
        self::assertSame(self::ORDER_ID_2, $body['items'][1]['id']);
    }

    public function testPullHasMoreAddsNextCursor(): void
    {
        // limit=2 → repo must return 3 elements (limit+1) to signal hasMore
        $order1 = $this->makeOrderMock(self::ORDER_ID_1);
        $order2 = $this->makeOrderMock(self::ORDER_ID_2);
        $order3 = $this->makeOrderMock(str_repeat('a', 31) . '3');

        $controller = $this->makeController(
            orderRepo: $this->makeOrderRepo([$order1, $order2, $order3]),
            mapper:    $this->makeMapper(),
        );

        $response = $controller->pull(
            Request::create('/test', 'GET', ['limit' => '2']),
            Context::createDefaultContext(),
        );

        $body = json_decode($response->getContent(), true);
        // last element is stripped — only 2 items returned
        self::assertCount(2, $body['items']);
        // cursor must be a non-null base64 string
        $cursor = $body['page']['nextCursor'];
        self::assertNotNull($cursor);
        $decoded = json_decode(base64_decode($cursor), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('id', $decoded);
        self::assertArrayHasKey('createdAt', $decoded);
    }

    // ── pull() — validation ───────────────────────────────────────────────────

    public function testPullInvalidStatusThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeController()->pull(
            Request::create('/test', 'GET', ['status' => 'invalid']),
            Context::createDefaultContext(),
        );
    }

    public function testPullInvalidLimitZeroThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeController()->pull(
            Request::create('/test', 'GET', ['limit' => '0']),
            Context::createDefaultContext(),
        );
    }

    public function testPullValidStatusFilter(): void
    {
        $orderRepo = $this->createMock(EntityRepository::class);
        $orderRepo->expects(self::once())
            ->method('search')
            ->willReturn($this->makeSearchResult([]));

        $controller = $this->makeController(
            orderRepo: $orderRepo,
            mapper:    $this->makeMapper(),
        );

        $response = $controller->pull(
            Request::create('/test', 'GET', ['status' => 'open']),
            Context::createDefaultContext(),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    // ── acknowledge() — additional validation ────────────────────────────────

    private function request(array $body): Request
    {
        return new Request([], [], [], [], [], [], json_encode($body));
    }

    private static function orderId(): string
    {
        return str_repeat('a', 32);
    }

    public function testAcknowledgeMissingOrderIdsThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeController()->acknowledge(
            $this->request([]),
            Context::createDefaultContext(),
        );
    }

    public function testAcknowledgeEmptyOrderIdsThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeController()->acknowledge(
            $this->request(['orderIds' => []]),
            Context::createDefaultContext(),
        );
    }

    public function testAcknowledgeBatchTooLargeThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);

        $ids = array_fill(0, ErpSyncService::MAX_BATCH + 1, self::orderId());

        $this->makeController()->acknowledge(
            $this->request(['orderIds' => $ids]),
            Context::createDefaultContext(),
        );
    }

    public function testAcknowledgeInvalidIdThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeController()->acknowledge(
            $this->request(['orderIds' => ['not-a-valid-id']]),
            Context::createDefaultContext(),
        );
    }

    public function testAcknowledgeHappyPath(): void
    {
        $id = self::orderId();

        $service = $this->createMock(ErpSyncService::class);
        $service->method('acknowledge')
            ->willReturn(['acknowledged' => [$id], 'alreadySynced' => [], 'notFound' => []]);

        $controller = $this->makeController(service: $service);

        $response = $controller->acknowledge(
            $this->request(['orderIds' => [$id]]),
            Context::createDefaultContext(),
        );

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        self::assertSame([$id], $body['acknowledged']);
        self::assertSame([], $body['alreadySynced']);
        self::assertSame([], $body['notFound']);
        self::assertSame(1, $body['counts']['acknowledged']);
        self::assertSame(0, $body['counts']['alreadySynced']);
        self::assertSame(0, $body['counts']['notFound']);
    }

    public function testAcknowledgeErpOrderIdsNotArrayThrowsValidationException(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeController()->acknowledge(
            $this->request(['orderIds' => [self::orderId()], 'erpOrderIds' => 'not-an-array']),
            Context::createDefaultContext(),
        );
    }

    public function testAcknowledgeErpOrderIdsInvalidValueThrowsValidationException(): void
    {
        $id = self::orderId();
        $this->expectException(ValidationException::class);

        $this->makeController()->acknowledge(
            $this->request(['orderIds' => [$id], 'erpOrderIds' => [$id => '']]),
            Context::createDefaultContext(),
        );
    }
}
