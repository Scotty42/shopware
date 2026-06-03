<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Service\OrderMapper;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\Currency\CurrencyEntity;

/**
 * Covers the money/totals and line-item formatting in OrderMapper, which was
 * previously only exercised by the live api_test.sh integration suite (not in
 * CI). Pure formatting — no Shopware kernel required.
 */
final class OrderMoneyMappingTest extends TestCase
{
    private OrderMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new OrderMapper();
    }

    public function testTotalsAreComputedAndRounded(): void
    {
        $payload = $this->mapper->mapOrder($this->orderWithTotals(
            amountTotal: 119.99,
            amountNet: 100.83,
            shippingTotal: 4.99,
            positionPrice: 115.0,
        ));

        self::assertSame(115.0, $payload['subtotal']['amount']);
        self::assertSame(4.99, $payload['shipping']['amount']);
        // tax = amountTotal - amountNet, rounded to 2 decimals
        self::assertSame(19.16, $payload['tax']['amount']);
        self::assertSame(119.99, $payload['total']['amount']);
        self::assertSame('EUR', $payload['total']['currency']);
    }

    public function testLineItemMappingIncludesSkuFromPayload(): void
    {
        $order = $this->orderWithTotals(119.99, 100.83, 4.99, 115.0);

        $item = new OrderLineItemEntity();
        $item->setId(str_repeat('e', 32));
        $item->setProductId(str_repeat('d', 32));
        $item->setType('product');
        $item->setLabel('Widget');
        $item->setQuantity(3);
        $item->setUnitPrice(5.0);
        $item->setTotalPrice(15.0);
        $item->setPayload(['productNumber' => 'SKU-123']);

        $order->setLineItems(new OrderLineItemCollection([$item]));

        $payload = $this->mapper->mapOrder($order);

        self::assertCount(1, $payload['lineItems']);
        $mapped = $payload['lineItems'][0];
        self::assertSame('Widget', $mapped['label']);
        self::assertSame(3, $mapped['quantity']);
        self::assertSame('SKU-123', $mapped['sku']);
        self::assertSame(5.0, $mapped['unitPrice']['amount']);
        self::assertSame(15.0, $mapped['totalPrice']['amount']);
    }

    private function orderWithTotals(
        float $amountTotal,
        float $amountNet,
        float $shippingTotal,
        float $positionPrice,
    ): OrderEntity {
        $order = new OrderEntity();
        $order->setId(str_repeat('a', 32));
        $order->setVersionId(str_repeat('b', 32));
        $order->setOrderNumber('TEST-0001');
        $order->setBillingAddressId(str_repeat('c', 32));
        $order->setCreatedAt(new \DateTimeImmutable('2025-01-01T00:00:00+00:00'));
        $order->setUpdatedAt(new \DateTimeImmutable('2025-06-01T12:00:00+00:00'));
        $order->setAmountTotal($amountTotal);
        $order->setAmountNet($amountNet);
        $order->setShippingTotal($shippingTotal);
        $order->setPositionPrice($positionPrice);

        $currency = new CurrencyEntity();
        $currency->setId('currency-id');
        $currency->setIsoCode('EUR');
        $order->setCurrency($currency);

        return $order;
    }
}
