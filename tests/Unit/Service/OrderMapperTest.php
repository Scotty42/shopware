<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Service\OrderMapper;
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
}
