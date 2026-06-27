<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Cqrs\Read;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Cqrs\Read\InMemoryReadProjection;
use Scotty42\OrderIntegration\Cqrs\Read\OrderProjectionWriter;
use Scotty42\OrderIntegration\Service\OrderMapper;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\Currency\CurrencyEntity;

class OrderProjectionWriterTest extends TestCase
{
    /**
     * Regression guard: a projection-served GET must return the SAME weak ETag
     * the mutation endpoints validate If-Match against. The writer therefore
     * stores the exact OrderMapper::etagFor() value in the snapshot (_etag);
     * otherwise optimistic concurrency breaks under PROJECTION_READS=true
     * (the snapshot's ATOM updatedAt loses the microseconds etagFor uses).
     */
    public function testStoredEtagMatchesOrderMapperEtag(): void
    {
        $mapper = new OrderMapper();
        $projection = new InMemoryReadProjection();
        $writer = new OrderProjectionWriter($projection, $mapper);

        $order = $this->makeOrder();
        $writer->apply($order);

        $stored = $projection->get($order->getId());
        self::assertNotNull($stored);
        self::assertArrayHasKey('_etag', $stored);
        self::assertSame($mapper->etagFor($order), $stored['_etag']);
        self::assertSame('open', $stored['status'] ?? null, 'snapshot still carries the mapped payload');
    }

    public function testRemoveDeletesFromProjection(): void
    {
        $mapper = new OrderMapper();
        $projection = new InMemoryReadProjection();
        $writer = new OrderProjectionWriter($projection, $mapper);

        $order = $this->makeOrder();
        $writer->apply($order);
        self::assertNotNull($projection->get($order->getId()), 'order must exist after apply');

        $writer->remove($order->getId());
        self::assertNull($projection->get($order->getId()), 'order must be gone after remove');
    }

    private function makeOrder(): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId(str_repeat('a', 32));
        $order->setVersionId(str_repeat('b', 32));
        $order->setOrderNumber('TEST-0001');
        $order->setSalesChannelId(str_repeat('e', 32));
        $order->setBillingAddressId(str_repeat('c', 32));
        $order->setCreatedAt(new \DateTimeImmutable('2026-06-01T00:00:00+00:00'));
        $order->setUpdatedAt(new \DateTimeImmutable('2026-06-04T12:34:56.123456+00:00'));
        $order->setAmountTotal(100.0);
        $order->setAmountNet(80.0);
        $order->setShippingTotal(5.0);
        $order->setPositionPrice(95.0);

        $currency = new CurrencyEntity();
        $currency->setId('currency-id');
        $currency->setIsoCode('EUR');
        $order->setCurrency($currency);

        $state = new \Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity();
        $state->setId('state-id');
        $state->setTechnicalName('open');
        $order->setStateMachineState($state);

        return $order;
    }
}
