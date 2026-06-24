<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Erp;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Erp\ErpSyncPolicy;
use Scotty42\OrderIntegration\Erp\ErpSyncService;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

/**
 * Verifies that ErpSyncService::acknowledge() passes erpOrderIds through to
 * ErpSyncPolicy and that update() carries the erpOrderId in the patch.
 *
 * ErpSyncPolicy is final, so we use the real class and assert observable
 * effects on the repository mock (the patch written to update()).
 */
class ErpSyncServiceTest extends TestCase
{
    private function makeOrder(string $orderId, ?array $customFields): object
    {
        $order = $this->createMock(\Shopware\Core\Checkout\Order\OrderEntity::class);
        $order->method('getId')->willReturn($orderId);
        $order->method('getUniqueIdentifier')->willReturn($orderId);
        $order->method('getCustomFields')->willReturn($customFields);

        return $order;
    }

    private function makeSearchResult(array $orders): EntitySearchResult
    {
        $collection = new OrderCollection($orders);
        $result = $this->createStub(EntitySearchResult::class);
        $result->method('getEntities')->willReturn($collection);

        return $result;
    }

    public function testErpOrderIdAppearsInRepositoryUpdatePatch(): void
    {
        $orderId    = str_repeat('a', 32);
        $erpOrderId = 'SO-12345';

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($this->makeSearchResult([$this->makeOrder($orderId, null)]));
        $repository->expects(self::once())
            ->method('update')
            ->with(
                self::callback(static function (array $patches) use ($erpOrderId): bool {
                    return count($patches) === 1
                        && ($patches[0]['customFields'][ErpSyncPolicy::FIELD_ERP_ID] ?? null) === $erpOrderId;
                }),
                self::anything(),
            );

        $service = new ErpSyncService($repository, new ErpSyncPolicy());
        $result  = $service->acknowledge(
            [$orderId],
            new \DateTimeImmutable(),
            Context::createDefaultContext(),
            [$orderId => $erpOrderId],
        );

        self::assertSame([$orderId], $result['acknowledged']);
    }

    public function testWithoutErpOrderIdsNoErpOrderIdInPatch(): void
    {
        $orderId = str_repeat('b', 32);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($this->makeSearchResult([$this->makeOrder($orderId, null)]));
        $repository->expects(self::once())
            ->method('update')
            ->with(
                self::callback(static function (array $patches): bool {
                    return count($patches) === 1
                        && !array_key_exists(ErpSyncPolicy::FIELD_ERP_ID, $patches[0]['customFields']);
                }),
                self::anything(),
            );

        $service = new ErpSyncService($repository, new ErpSyncPolicy());
        $result  = $service->acknowledge([$orderId], new \DateTimeImmutable(), Context::createDefaultContext());

        self::assertSame([$orderId], $result['acknowledged']);
    }
}
