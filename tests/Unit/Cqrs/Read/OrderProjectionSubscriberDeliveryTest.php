<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Cqrs\Read;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Scotty42\OrderIntegration\Cqrs\PdoConnectionProvider;
use Scotty42\OrderIntegration\Cqrs\Read\InMemoryReadProjection;
use Scotty42\OrderIntegration\Cqrs\Read\OrderProjectionSubscriber;
use Scotty42\OrderIntegration\Cqrs\Read\OrderProjectionWriter;
use Scotty42\OrderIntegration\Service\OrderMapper;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

/**
 * Verifies that OrderProjectionSubscriber::onDeliveryWritten() reloads and
 * upserts the affected parent orders whenever a delivery is written.
 */
class OrderProjectionSubscriberDeliveryTest extends TestCase
{
    private function makeOrder(string $id, string $salesChannelId = 'sc-1'): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId($id);
        $order->setVersionId(str_repeat('b', 32));
        $order->setOrderNumber('TEST-001');
        $order->setSalesChannelId($salesChannelId);
        $order->setBillingAddressId(str_repeat('c', 32));
        $order->setAmountTotal(100.0);
        $order->setAmountNet(80.0);
        $order->setShippingTotal(5.0);
        $order->setPositionPrice(95.0);
        $order->setCreatedAt(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $order->setUpdatedAt(new \DateTimeImmutable('2026-06-01T12:00:00+00:00'));

        $currency = new CurrencyEntity();
        $currency->setId('cur-1');
        $currency->setIsoCode('EUR');
        $order->setCurrency($currency);

        $state = new StateMachineStateEntity();
        $state->setId('state-1');
        $state->setTechnicalName('open');
        $order->setStateMachineState($state);

        return $order;
    }

    private function makeDelivery(string $deliveryId, string $orderId): OrderDeliveryEntity
    {
        $delivery = new OrderDeliveryEntity();
        $delivery->setId($deliveryId);
        $delivery->setOrderId($orderId);

        return $delivery;
    }

    private function makeDeliveryWrittenEvent(array $deliveryIds, Context $context): EntityWrittenEvent
    {
        $results = array_map(
            static fn(string $id) => new EntityWriteResult($id, [], 'order_delivery', EntityWriteResult::OPERATION_UPDATE),
            $deliveryIds
        );

        return new EntityWrittenEvent('order_delivery', $results, $context);
    }

    private function makeConfiguredConnection(): PdoConnectionProvider
    {
        return new PdoConnectionProvider('pgsql:host=localhost', null, null);
    }

    public function testDeliveryWrittenTriggersParentOrderUpsert(): void
    {
        $orderId    = str_repeat('a', 32);
        $deliveryId = str_repeat('d', 32);
        $context    = Context::createDefaultContext();

        $delivery = $this->makeDelivery($deliveryId, $orderId);
        $order    = $this->makeOrder($orderId);

        // order_delivery.repository mock
        $deliverySearchResult = $this->createMock(EntitySearchResult::class);
        $deliverySearchResult->method('getEntities')
            ->willReturn(new OrderDeliveryCollection([$delivery]));

        $deliveryRepo = $this->createMock(EntityRepository::class);
        $deliveryRepo->expects(self::once())
            ->method('search')
            ->willReturn($deliverySearchResult);

        // order.repository mock
        $orderEntities = new class([$order]) extends \ArrayObject {
            public function getEntities(): array { return $this->getArrayCopy(); }
        };
        $orderSearchResult = $this->createMock(EntitySearchResult::class);
        $orderSearchResult->method('getEntities')->willReturn([$order]);

        $orderRepo = $this->createMock(EntityRepository::class);
        $orderRepo->expects(self::once())
            ->method('search')
            ->willReturn($orderSearchResult);

        $projection = new InMemoryReadProjection();
        $writer     = new OrderProjectionWriter($projection, new OrderMapper());

        $subscriber = new OrderProjectionSubscriber(
            $orderRepo,
            $writer,
            $this->makeConfiguredConnection(),
            new NullLogger(),
            $deliveryRepo,
        );

        $event = $this->makeDeliveryWrittenEvent([$deliveryId], $context);
        $subscriber->onDeliveryWritten($event);

        $stored = $projection->get($orderId);
        self::assertNotNull($stored, 'order must be upserted into projection after delivery write');
        self::assertSame($orderId, $stored['id']);
    }

    public function testDeliveryWrittenIsNoOpWhenDbNotConfigured(): void
    {
        $connection = new PdoConnectionProvider(null, null, null);

        $deliveryRepo = $this->createMock(EntityRepository::class);
        $deliveryRepo->expects(self::never())->method('search');

        $orderRepo = $this->createMock(EntityRepository::class);
        $orderRepo->expects(self::never())->method('search');

        $subscriber = new OrderProjectionSubscriber(
            $orderRepo,
            new OrderProjectionWriter(new InMemoryReadProjection(), new OrderMapper()),
            $connection,
            new NullLogger(),
            $deliveryRepo,
        );

        $event = $this->makeDeliveryWrittenEvent([str_repeat('d', 32)], Context::createDefaultContext());
        $subscriber->onDeliveryWritten($event); // must not throw
        $this->addToAssertionCount(1);
    }

    public function testDeliveryWrittenIsNoOpForEmptyIds(): void
    {
        $deliveryRepo = $this->createMock(EntityRepository::class);
        $deliveryRepo->expects(self::never())->method('search');

        $orderRepo = $this->createMock(EntityRepository::class);
        $orderRepo->expects(self::never())->method('search');

        $subscriber = new OrderProjectionSubscriber(
            $orderRepo,
            new OrderProjectionWriter(new InMemoryReadProjection(), new OrderMapper()),
            $this->makeConfiguredConnection(),
            new NullLogger(),
            $deliveryRepo,
        );

        $event = $this->makeDeliveryWrittenEvent([], Context::createDefaultContext());
        $subscriber->onDeliveryWritten($event);
        $this->addToAssertionCount(1);
    }

    public function testDeliveryWrittenLogsAndContinuesOnProjectionError(): void
    {
        $orderId    = str_repeat('a', 32);
        $deliveryId = str_repeat('d', 32);
        $context    = Context::createDefaultContext();

        $delivery = $this->makeDelivery($deliveryId, $orderId);

        $deliverySearchResult = $this->createMock(EntitySearchResult::class);
        $deliverySearchResult->method('getEntities')
            ->willReturn(new OrderDeliveryCollection([$delivery]));

        $deliveryRepo = $this->createMock(EntityRepository::class);
        $deliveryRepo->method('search')->willReturn($deliverySearchResult);

        // order repo throws — subscriber must swallow and log
        $orderRepo = $this->createMock(EntityRepository::class);
        $orderRepo->method('search')->willThrowException(new \RuntimeException('db gone'));

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $subscriber = new OrderProjectionSubscriber(
            $orderRepo,
            new OrderProjectionWriter(new InMemoryReadProjection(), new OrderMapper()),
            $this->makeConfiguredConnection(),
            $logger,
            $deliveryRepo,
        );

        $event = $this->makeDeliveryWrittenEvent([$deliveryId], $context);
        $subscriber->onDeliveryWritten($event); // must NOT throw
        $this->addToAssertionCount(1);
    }

    public function testSubscribedEventsIncludesDeliveryWritten(): void
    {
        $events = OrderProjectionSubscriber::getSubscribedEvents();
        self::assertArrayHasKey('order_delivery.written', $events);
    }
}
