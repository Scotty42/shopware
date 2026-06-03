<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Service\OrderMapper;
use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

/**
 * Minimal unit tests for OrderMapper. Tests the parts that are pure
 * formatting and don't require building a full OrderEntity fixture.
 *
 * Note: REQUIRED_ASSOCIATIONS is exercised implicitly by api_test.sh —
 * the bash integration suite is the authoritative spec-shape test until
 * a richer entity-fixture builder lands.
 */
final class OrderMapperTest extends TestCase
{
    private OrderMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new OrderMapper();
    }

    public function testRequiredAssociationsCoversEverythingTheMapperReads(): void
    {
        $assoc = OrderMapper::REQUIRED_ASSOCIATIONS;

        // Spec-required fields on the Order payload depend on these
        // associations. If you remove one, the corresponding response
        // field silently becomes null/empty.
        $this->assertContains('stateMachineState', $assoc);
        $this->assertContains('currency', $assoc);
        $this->assertContains('lineItems', $assoc);
        $this->assertContains('orderCustomer', $assoc);
        $this->assertContains('addresses.country', $assoc);
        $this->assertContains('tags', $assoc);
        $this->assertContains('transactions.stateMachineState', $assoc);
        $this->assertContains('deliveries.stateMachineState', $assoc);
        $this->assertContains('deliveries.shippingOrderAddress.country', $assoc);
    }

    public function testRequiredAssociationsHasNoDuplicates(): void
    {
        $assoc = OrderMapper::REQUIRED_ASSOCIATIONS;
        $this->assertSame(
            array_values(array_unique($assoc)),
            $assoc,
            'REQUIRED_ASSOCIATIONS must be free of duplicates'
        );
    }

    public function testEtagIsWeakAndDeterministicForSameState(): void
    {
        $order = $this->makeOrder(
            id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            versionId: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            updatedAt: new \DateTimeImmutable('2025-06-01T12:00:00+00:00'),
        );

        $etag1 = $this->mapper->etagFor($order);
        $etag2 = $this->mapper->etagFor($order);

        $this->assertSame($etag1, $etag2);
        $this->assertStringStartsWith('W/"', $etag1);
        $this->assertStringEndsWith('"', $etag1);
    }

    public function testEtagChangesWhenVersionIdChanges(): void
    {
        $base = new \DateTimeImmutable('2025-06-01T12:00:00+00:00');
        $a = $this->makeOrder(
            id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            versionId: 'v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1v1',
            updatedAt: $base,
        );
        $b = $this->makeOrder(
            id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            versionId: 'v2v2v2v2v2v2v2v2v2v2v2v2v2v2v2v2',
            updatedAt: $base,
        );

        $this->assertNotSame($this->mapper->etagFor($a), $this->mapper->etagFor($b));
    }

    public function testEtagChangesWhenUpdatedAtChanges(): void
    {
        $a = $this->makeOrder(
            id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            versionId: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            updatedAt: new \DateTimeImmutable('2025-06-01T12:00:00+00:00'),
        );
        $b = $this->makeOrder(
            id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            versionId: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            updatedAt: new \DateTimeImmutable('2025-06-01T12:00:01+00:00'),
        );

        $this->assertNotSame($this->mapper->etagFor($a), $this->mapper->etagFor($b));
    }

    public function testEtagFallsBackToCreatedAtWhenUpdatedAtNull(): void
    {
        $createdAt = new \DateTimeImmutable('2025-01-01T00:00:00+00:00');
        $order = $this->makeOrder(
            id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            versionId: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            updatedAt: null,
            createdAt: $createdAt,
        );

        $etag = $this->mapper->etagFor($order);
        $this->assertStringStartsWith('W/"', $etag);
        $this->assertNotSame('W/"' . sha1('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa||') . '"', $etag);
    }

    public function testMapOrderReturnsSpecRequiredKeys(): void
    {
        $order = $this->makeOrder(
            id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            versionId: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            updatedAt: new \DateTimeImmutable('2025-06-01T12:00:00+00:00'),
            currencyIso: 'EUR',
            stateTechnicalName: 'open',
        );

        $payload = $this->mapper->mapOrder($order);

        // Spec-required top-level keys (see docs/order-api-openapi.yaml
        // #/components/schemas/Order). Values may be null where the
        // entity has no associations loaded, but the keys must exist.
        foreach (['id', 'orderNumber', 'version', 'status', 'paymentStatus',
                  'deliveryStatus', 'currency', 'subtotal', 'shipping', 'tax',
                  'total', 'customer', 'billingAddress', 'shippingAddress',
                  'lineItems', 'deliveries', 'customerComment', 'tags',
                  'customFields', 'createdAt', 'updatedAt'] as $key) {
            $this->assertArrayHasKey($key, $payload, sprintf('Order payload must include %s', $key));
        }

        $this->assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $payload['id']);
        $this->assertSame('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $payload['version']);
        $this->assertSame('EUR', $payload['currency']);
        $this->assertSame('open', $payload['status']);
        $this->assertSame('EUR', $payload['total']['currency']);
    }

    /**
     * Constructs a minimal OrderEntity with just enough state for the
     * mapper to operate. Shopware OrderEntity exposes public setters
     * that we use here directly — keeps the test free of reflection.
     *
     * Important: every typed non-nullable property read by OrderMapper
     * must be initialised here. PHP 8.2+ raises a fatal "must not be
     * accessed before initialization" otherwise. Currently the mapper
     * reads `billingAddressId` (non-nullable) plus the price totals.
     */
    private function makeOrder(
        string $id,
        string $versionId,
        ?\DateTimeImmutable $updatedAt,
        ?\DateTimeImmutable $createdAt = null,
        ?string $currencyIso = null,
        ?string $stateTechnicalName = null,
    ): OrderEntity {
        $order = new OrderEntity();
        $order->setId($id);
        $order->setVersionId($versionId);
        $order->setOrderNumber('TEST-' . substr($id, 0, 6));
        $order->setBillingAddressId(str_repeat('c', 32));
        $order->setCreatedAt($createdAt ?? new \DateTimeImmutable('2025-01-01T00:00:00+00:00'));
        if ($updatedAt !== null) {
            $order->setUpdatedAt($updatedAt);
        }
        $order->setAmountTotal(100.0);
        $order->setAmountNet(80.0);
        $order->setShippingTotal(5.0);
        $order->setPositionPrice(95.0);

        if ($currencyIso !== null) {
            $currency = new CurrencyEntity();
            $currency->setId('currency-id');
            $currency->setIsoCode($currencyIso);
            $order->setCurrency($currency);
        }

        if ($stateTechnicalName !== null) {
            $state = new StateMachineStateEntity();
            $state->setId('state-id');
            $state->setTechnicalName($stateTechnicalName);
            $order->setStateMachineState($state);
        }

        return $order;
    }

    /**
     * Regression test for the bug where the order-level deliveryStatus was
     * read from the LAST delivery while shippingAddress was read from the
     * FIRST. For a split shipment the two fields then described different
     * deliveries. They must now both reflect the primary (first) delivery.
     */
    public function testDeliveryStatusAndShippingAddressComeFromSameDelivery(): void
    {
        $order = $this->makeOrder(
            id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            versionId: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            updatedAt: new \DateTimeImmutable('2025-06-01T12:00:00+00:00'),
            currencyIso: 'EUR',
            stateTechnicalName: 'open',
        );

        $first = $this->makeDelivery(
            id: str_repeat('1', 32),
            stateTechnicalName: 'shipped',
            zipcode: '11111',
        );
        $second = $this->makeDelivery(
            id: str_repeat('2', 32),
            stateTechnicalName: 'open',
            zipcode: '22222',
        );

        $order->setDeliveries(new OrderDeliveryCollection([$first, $second]));

        $payload = $this->mapper->mapOrder($order);

        // Both derived from the FIRST delivery, not a mix of first + last.
        $this->assertSame('shipped', $payload['deliveryStatus']);
        $this->assertNotNull($payload['shippingAddress']);
        $this->assertSame('11111', $payload['shippingAddress']['zipcode']);
    }

    private function makeDelivery(
        string $id,
        string $stateTechnicalName,
        string $zipcode,
    ): OrderDeliveryEntity {
        $state = new StateMachineStateEntity();
        $state->setId('state-' . substr($id, 0, 4));
        $state->setTechnicalName($stateTechnicalName);

        $address = new OrderAddressEntity();
        $address->setId('addr-' . substr($id, 0, 4));
        $address->setTitle(null);
        $address->setFirstName('Test');
        $address->setLastName('Buyer');
        $address->setCompany(null);
        $address->setStreet('Example Street 1');
        $address->setAdditionalAddressLine1(null);
        $address->setAdditionalAddressLine2(null);
        $address->setZipcode($zipcode);
        $address->setCity('Berlin');
        $address->setPhoneNumber(null);
        $address->setVatId(null);

        $delivery = new OrderDeliveryEntity();
        $delivery->setId($id);
        $delivery->setStateMachineState($state);
        $delivery->setShippingOrderAddress($address);
        $delivery->setTrackingCodes([]);
        // OrderDeliveryEntity has non-nullable-before-init typed date props that
        // mapDeliveriesSummary() reads — initialise them so the test exercises
        // the mapper without hitting "accessed before initialization".
        $delivery->setShippingDateEarliest(new \DateTimeImmutable('2025-06-01T10:00:00+00:00'));
        $delivery->setShippingDateLatest(new \DateTimeImmutable('2025-06-08T10:00:00+00:00'));
        $delivery->setCreatedAt(new \DateTimeImmutable('2025-06-01T10:00:00+00:00'));
        $delivery->setUpdatedAt(new \DateTimeImmutable('2025-06-01T10:00:00+00:00'));

        return $delivery;
    }
}
