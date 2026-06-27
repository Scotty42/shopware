<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Service\OrderPatchService;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

/**
 * Verifies that OrderPatchService issues exactly ONE orderRepository::update()
 * call regardless of which fields are patched, and that address data is
 * embedded inline (no separate address repository calls).
 */
class OrderPatchServiceAtomicTest extends TestCase
{
    private function makeOrder(string $billingAddressId = 'billing-addr-1', string $deliveryAddressId = 'shipping-addr-1'): OrderEntity
    {
        $delivery = new OrderDeliveryEntity();
        $delivery->setId('delivery-1');
        $delivery->setShippingOrderAddressId($deliveryAddressId);

        $order = new OrderEntity();
        $order->setId('order-1');
        $order->setBillingAddressId($billingAddressId);
        $order->setDeliveries(new OrderDeliveryCollection([$delivery]));

        return $order;
    }

    private function makeRepo(OrderEntity $order): EntityRepository
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn($order);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturn($result);

        return $repo;
    }

    public function testScalarFieldsUpdateInSingleCall(): void
    {
        $repo = $this->makeRepo($this->makeOrder());

        $repo->expects(self::once())
            ->method('update')
            ->with(self::callback(function (array $payload): bool {
                $update = $payload[0];
                self::assertSame('order-1', $update['id']);
                self::assertSame('hello', $update['customerComment']);
                self::assertArrayNotHasKey('addresses', $update);

                return true;
            }), self::anything());

        $service = new OrderPatchService($repo);
        $service->patch('order-1', ['customerComment' => 'hello'], Context::createDefaultContext());
    }

    public function testBillingAddressEmbeddedInSingleCall(): void
    {
        $repo = $this->makeRepo($this->makeOrder('billing-123'));

        $updatePayload = null;
        $repo->expects(self::once())
            ->method('update')
            ->with(self::callback(function (array $payload) use (&$updatePayload): bool {
                $updatePayload = $payload[0];

                return true;
            }), self::anything());

        // search() called once to resolve address IDs
        $repo->expects(self::once())->method('search');

        $service = new OrderPatchService($repo);
        $service->patch('order-1', ['billingAddress' => ['street' => 'Main St 1']], Context::createDefaultContext());

        self::assertNotNull($updatePayload);
        self::assertArrayHasKey('addresses', $updatePayload);
        self::assertCount(1, $updatePayload['addresses']);
        self::assertSame('billing-123', $updatePayload['addresses'][0]['id']);
        self::assertSame('Main St 1', $updatePayload['addresses'][0]['street']);
    }

    public function testShippingAddressEmbeddedInSingleCall(): void
    {
        $repo = $this->makeRepo($this->makeOrder('billing-x', 'shipping-789'));

        $updatePayload = null;
        $repo->method('update')
            ->with(self::callback(function (array $p) use (&$updatePayload): bool {
                $updatePayload = $p[0];

                return true;
            }), self::anything());

        $service = new OrderPatchService($repo);
        $service->patch('order-1', ['shippingAddress' => ['city' => 'Berlin']], Context::createDefaultContext());

        self::assertNotNull($updatePayload);
        self::assertArrayHasKey('addresses', $updatePayload);
        $addr = $updatePayload['addresses'][0];
        self::assertSame('shipping-789', $addr['id']);
        self::assertSame('Berlin', $addr['city']);
    }

    public function testBothAddressesEmbeddedInSingleCall(): void
    {
        $repo = $this->makeRepo($this->makeOrder('b-addr', 's-addr'));

        $updatePayload = null;
        $repo->method('update')
            ->with(self::callback(function (array $p) use (&$updatePayload): bool {
                $updatePayload = $p[0];

                return true;
            }), self::anything());

        $service = new OrderPatchService($repo);
        $service->patch('order-1', [
            'billingAddress'  => ['street' => 'A'],
            'shippingAddress' => ['street' => 'B'],
        ], Context::createDefaultContext());

        self::assertCount(2, $updatePayload['addresses'] ?? []);
        $ids = array_column($updatePayload['addresses'], 'id');
        self::assertContains('b-addr', $ids);
        self::assertContains('s-addr', $ids);
    }

    public function testNoReadWhenOnlyScalarFieldsPatched(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->expects(self::never())->method('search');
        $repo->expects(self::once())->method('update');

        $service = new OrderPatchService($repo);
        $service->patch('order-1', ['customerComment' => 'x'], Context::createDefaultContext());
    }

    public function testTagsEncodedInlineAsNameObjects(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->expects(self::never())->method('search');

        $updatePayload = null;
        $repo->method('update')
            ->with(self::callback(function (array $p) use (&$updatePayload): bool {
                $updatePayload = $p[0];

                return true;
            }), self::anything());

        $service = new OrderPatchService($repo);
        $service->patch('order-1', ['tags' => ['urgent', 'vip']], Context::createDefaultContext());

        self::assertSame([['name' => 'urgent'], ['name' => 'vip']], $updatePayload['tags']);
    }

    public function testNullOrderSearchResultSkipsAddressesKeyInUpdate(): void
    {
        // When search()->first() returns null (order not in DB at address-lookup time),
        // patch() must still call update() but without an 'addresses' key in the payload.
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('first')->willReturn(null);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('search')->willReturn($result);

        $updatePayload = null;
        $repo->expects(self::once())
            ->method('update')
            ->with(self::callback(function (array $p) use (&$updatePayload): bool {
                $updatePayload = $p[0];

                return true;
            }), self::anything());

        $service = new OrderPatchService($repo);
        $service->patch('order-1', ['billingAddress' => ['street' => 'Main St 1']], Context::createDefaultContext());

        self::assertNotNull($updatePayload);
        self::assertArrayNotHasKey('addresses', $updatePayload);
    }
}
