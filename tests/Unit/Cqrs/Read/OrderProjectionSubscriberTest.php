<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Cqrs\Read;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Scotty42\OrderIntegration\Cqrs\PdoConnectionProvider;
use Scotty42\OrderIntegration\Cqrs\Read\InMemoryReadProjection;
use Scotty42\OrderIntegration\Cqrs\Read\OrderProjectionSubscriber;
use Scotty42\OrderIntegration\Cqrs\Read\OrderProjectionWriter;
use Scotty42\OrderIntegration\Cqrs\Read\ReadProjectionInterface;
use Scotty42\OrderIntegration\Service\OrderMapper;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

/**
 * Covers OrderProjectionSubscriber::onOrderWritten() and onOrderDeleted().
 * onDeliveryWritten() is covered in OrderProjectionSubscriberDeliveryTest.
 *
 * OrderProjectionWriter is final and cannot be mocked. Tests verify behaviour
 * via observable side-effects: repository call counts and projection state.
 */
class OrderProjectionSubscriberTest extends TestCase
{
    private function makeConfigured(): PdoConnectionProvider
    {
        return new PdoConnectionProvider('pgsql:host=localhost', null, null);
    }

    private function makeUnconfigured(): PdoConnectionProvider
    {
        return new PdoConnectionProvider(null, null, null);
    }

    private function makeOrderWrittenEvent(array $ids, Context $context): EntityWrittenEvent
    {
        $results = array_map(
            static fn(string $id) => new EntityWriteResult($id, [], 'order', EntityWriteResult::OPERATION_UPDATE),
            $ids,
        );

        return new EntityWrittenEvent('order', $results, $context);
    }

    private function makeOrderDeletedEvent(array $ids, Context $context): EntityDeletedEvent
    {
        $results = array_map(
            static fn(string $id) => new EntityWriteResult($id, [], 'order', EntityWriteResult::OPERATION_DELETE),
            $ids,
        );

        return new EntityDeletedEvent('order', $results, $context);
    }

    /** Build a fully-hydrated OrderEntity suitable for OrderMapper::mapOrder(). */
    private function makeOrder(string $id): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId($id);
        $order->setVersionId(str_repeat('b', 32));
        $order->setOrderNumber('TEST-001');
        $order->setSalesChannelId('sc-1');
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

    private function makeWriter(InMemoryReadProjection $projection): OrderProjectionWriter
    {
        return new OrderProjectionWriter($projection, new OrderMapper());
    }

    // ── onOrderWritten ────────────────────────────────────────────────────────

    public function testOnOrderWrittenIsInertWhenNotConfigured(): void
    {
        $orderRepo = $this->createMock(EntityRepository::class);
        $orderRepo->expects(self::never())->method('search');

        $subscriber = new OrderProjectionSubscriber(
            $orderRepo,
            $this->makeWriter(new InMemoryReadProjection()),
            $this->makeUnconfigured(),
            new NullLogger(),
            $this->createStub(EntityRepository::class),
        );

        $event = $this->makeOrderWrittenEvent([str_repeat('a', 32)], Context::createDefaultContext());
        $subscriber->onOrderWritten($event);
        $this->addToAssertionCount(1);
    }

    public function testOnOrderWrittenSkipsEmptyIds(): void
    {
        $orderRepo = $this->createMock(EntityRepository::class);
        $orderRepo->expects(self::never())->method('search');

        $subscriber = new OrderProjectionSubscriber(
            $orderRepo,
            $this->makeWriter(new InMemoryReadProjection()),
            $this->makeConfigured(),
            new NullLogger(),
            $this->createStub(EntityRepository::class),
        );

        $event = $this->makeOrderWrittenEvent([], Context::createDefaultContext());
        $subscriber->onOrderWritten($event);
        $this->addToAssertionCount(1);
    }

    public function testOnOrderWrittenAppliesOrderForEachId(): void
    {
        $orderId = str_repeat('a', 32);
        $order   = $this->makeOrder($orderId);

        $searchResult = $this->createStub(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn(new OrderCollection([$order]));

        $orderRepo = $this->createMock(EntityRepository::class);
        $orderRepo->expects(self::once())
            ->method('search')
            ->willReturn($searchResult);

        $projection = new InMemoryReadProjection();

        $subscriber = new OrderProjectionSubscriber(
            $orderRepo,
            $this->makeWriter($projection),
            $this->makeConfigured(),
            new NullLogger(),
            $this->createStub(EntityRepository::class),
        );

        $event = $this->makeOrderWrittenEvent([$orderId], Context::createDefaultContext());
        $subscriber->onOrderWritten($event);

        self::assertNotNull($projection->get($orderId), 'order must be upserted into projection');
    }

    public function testOnOrderWrittenSwallowsExceptions(): void
    {
        // Make orderRepository->search() throw to trigger the catch block.
        $orderRepo = $this->createMock(EntityRepository::class);
        $orderRepo->method('search')->willThrowException(new \RuntimeException('db gone'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $subscriber = new OrderProjectionSubscriber(
            $orderRepo,
            $this->makeWriter(new InMemoryReadProjection()),
            $this->makeConfigured(),
            $logger,
            $this->createStub(EntityRepository::class),
        );

        $event = $this->makeOrderWrittenEvent([str_repeat('a', 32)], Context::createDefaultContext());
        $subscriber->onOrderWritten($event); // must not throw
        $this->addToAssertionCount(1);
    }

    // ── onOrderDeleted ────────────────────────────────────────────────────────

    public function testOnOrderDeletedIsInertWhenNotConfigured(): void
    {
        $orderId    = str_repeat('a', 32);
        $projection = new InMemoryReadProjection();
        // Pre-populate so we can verify nothing is removed.
        $projection->upsert(['id' => $orderId, 'createdAt' => '2026-01-01T00:00:00+00:00']);

        $subscriber = new OrderProjectionSubscriber(
            $this->createStub(EntityRepository::class),
            $this->makeWriter($projection),
            $this->makeUnconfigured(),
            new NullLogger(),
            $this->createStub(EntityRepository::class),
        );

        $event = $this->makeOrderDeletedEvent([$orderId], Context::createDefaultContext());
        $subscriber->onOrderDeleted($event);

        self::assertNotNull($projection->get($orderId), 'entry must remain — subscriber is inert when not configured');
    }

    public function testOnOrderDeletedRemovesEachId(): void
    {
        $id1 = str_repeat('a', 32);
        $id2 = str_repeat('b', 32);

        $projection = new InMemoryReadProjection();
        $projection->upsert(['id' => $id1, 'createdAt' => '2026-01-01T00:00:00+00:00']);
        $projection->upsert(['id' => $id2, 'createdAt' => '2026-01-01T00:00:00+00:00']);

        $subscriber = new OrderProjectionSubscriber(
            $this->createStub(EntityRepository::class),
            $this->makeWriter($projection),
            $this->makeConfigured(),
            new NullLogger(),
            $this->createStub(EntityRepository::class),
        );

        $event = $this->makeOrderDeletedEvent([$id1, $id2], Context::createDefaultContext());
        $subscriber->onOrderDeleted($event);

        self::assertNull($projection->get($id1), 'id1 must be removed');
        self::assertNull($projection->get($id2), 'id2 must be removed');
    }

    public function testOnOrderDeletedSwallowsExceptions(): void
    {
        // Implement ReadProjectionInterface directly so we can throw on delete()
        // without extending the final InMemoryReadProjection class.
        $throwingProjection = new class implements ReadProjectionInterface {
            public function upsert(array $snapshot): void {}
            public function get(string $id): ?array { return null; }
            public function list(array $filters, int $limit, ?string $cursor): array { return ['items' => [], 'nextCursor' => null]; }
            public function delete(string $id): void
            {
                throw new \RuntimeException('projection delete failed');
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        $subscriber = new OrderProjectionSubscriber(
            $this->createStub(EntityRepository::class),
            new OrderProjectionWriter($throwingProjection, new OrderMapper()),
            $this->makeConfigured(),
            $logger,
            $this->createStub(EntityRepository::class),
        );

        $event = $this->makeOrderDeletedEvent([str_repeat('a', 32)], Context::createDefaultContext());
        $subscriber->onOrderDeleted($event); // must not throw
        $this->addToAssertionCount(1);
    }
}
