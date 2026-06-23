<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Controller\ErpSyncController;
use Scotty42\OrderIntegration\Erp\ErpSyncService;
use Scotty42\OrderIntegration\Exception\ValidationException;
use Scotty42\OrderIntegration\Service\OrderMapper;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Covers the erpOrderIds validation and forwarding path in
 * ErpSyncController::acknowledge(). The base orderIds path and
 * the live HTTP contract are covered by tests/api_test.sh.
 */
class ErpSyncControllerAcknowledgeTest extends TestCase
{
    private ErpSyncController $controller;
    private ErpSyncService $service;
    private Context $context;

    protected function setUp(): void
    {
        $this->service = $this->createMock(ErpSyncService::class);
        $this->controller = new ErpSyncController(
            $this->createMock(EntityRepository::class),
            $this->service,
            $this->createMock(OrderMapper::class),
        );
        $this->context = Context::createDefaultContext();
    }

    private function request(array $body): Request
    {
        return new Request([], [], [], [], [], [], json_encode($body));
    }

    private static function orderId(): string
    {
        return str_repeat('a', 32);
    }

    private function stubServiceSuccess(string $orderId): void
    {
        $this->service->method('acknowledge')
            ->willReturn(['acknowledged' => [$orderId], 'alreadySynced' => [], 'notFound' => []]);
    }

    // ── erpOrderIds absent ───────────────────────────────────────────────────

    public function testWithoutErpOrderIdsPassesEmptyMapToService(): void
    {
        $id = self::orderId();
        $this->service->expects(self::once())
            ->method('acknowledge')
            ->with([$id], self::anything(), self::anything(), [])
            ->willReturn(['acknowledged' => [$id], 'alreadySynced' => [], 'notFound' => []]);

        $response = $this->controller->acknowledge($this->request(['orderIds' => [$id]]), $this->context);
        self::assertSame(200, $response->getStatusCode());
    }

    // ── valid erpOrderIds ────────────────────────────────────────────────────

    public function testValidHexKeyAndValueForwardedToService(): void
    {
        $id = self::orderId();
        $this->service->expects(self::once())
            ->method('acknowledge')
            ->with([$id], self::anything(), self::anything(), [$id => 'SO-12345'])
            ->willReturn(['acknowledged' => [$id], 'alreadySynced' => [], 'notFound' => []]);

        $response = $this->controller->acknowledge(
            $this->request(['orderIds' => [$id], 'erpOrderIds' => [$id => 'SO-12345']]),
            $this->context,
        );
        self::assertSame(200, $response->getStatusCode());
    }

    public function testUuidKeyIsNormalizedToHex(): void
    {
        $hexId = self::orderId(); // aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
        $uuidId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

        $this->service->expects(self::once())
            ->method('acknowledge')
            ->with([$hexId], self::anything(), self::anything(), [$hexId => 'NAV-99'])
            ->willReturn(['acknowledged' => [$hexId], 'alreadySynced' => [], 'notFound' => []]);

        $this->controller->acknowledge(
            $this->request(['orderIds' => [$hexId], 'erpOrderIds' => [$uuidId => 'NAV-99']]),
            $this->context,
        );
    }

    // ── invalid erpOrderIds ──────────────────────────────────────────────────

    public function testErpOrderIdsNotAnObjectThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->controller->acknowledge(
            $this->request(['orderIds' => [self::orderId()], 'erpOrderIds' => 'not-an-object']),
            $this->context,
        );
    }

    public function testErpOrderIdsInvalidKeyThrows(): void
    {
        $this->expectException(ValidationException::class);
        $this->controller->acknowledge(
            $this->request(['orderIds' => [self::orderId()], 'erpOrderIds' => ['bad-key!' => 'SO-1']]),
            $this->context,
        );
    }

    public function testErpOrderIdsEmptyValueThrows(): void
    {
        $id = self::orderId();
        $this->expectException(ValidationException::class);
        $this->controller->acknowledge(
            $this->request(['orderIds' => [$id], 'erpOrderIds' => [$id => '']]),
            $this->context,
        );
    }

    public function testErpOrderIdsValueOver100CharsThrows(): void
    {
        $id = self::orderId();
        $this->expectException(ValidationException::class);
        $this->controller->acknowledge(
            $this->request(['orderIds' => [$id], 'erpOrderIds' => [$id => str_repeat('x', 101)]]),
            $this->context,
        );
    }

    public function testErpOrderIdsValueExactly100CharsIsAccepted(): void
    {
        $id = self::orderId();
        $this->stubServiceSuccess($id);

        $response = $this->controller->acknowledge(
            $this->request(['orderIds' => [$id], 'erpOrderIds' => [$id => str_repeat('x', 100)]]),
            $this->context,
        );
        self::assertSame(200, $response->getStatusCode());
    }
}
