<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Service;

use Shopware\Core\Checkout\Order\Aggregate\OrderAddress\OrderAddressEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * Maps a Shopware OrderEntity to the spec-compliant JSON shape declared
 * in docs/order-api-openapi.yaml (#/components/schemas/Order).
 *
 * Required associations on the caller side: see REQUIRED_ASSOCIATIONS.
 * Every controller / service that loads an order for mapping MUST pass
 * this constant to Criteria::addAssociations() — otherwise fields like
 * `billingAddress.countryCode`, `tags`, and `paymentStatus` come back
 * empty even though they would be valid on the entity.
 *
 * Keep this class free of Shopware DAL queries — it formats only.
 */
class OrderMapper
{
    /**
     * Single source of truth for the association tree needed to render a
     * spec-compliant Order payload. Centralised here so OrderController,
     * StatusController, and OrderCreationService can't drift apart.
     *
     * @var list<string>
     */
    public const REQUIRED_ASSOCIATIONS = [
        'stateMachineState',
        'currency',
        'lineItems',
        'transactions.stateMachineState',
        'deliveries.stateMachineState',
        'deliveries.shippingOrderAddress.country',
        'addresses.country',
        'orderCustomer',
        'tags',
    ];

    /**
     * @return array<string,mixed>
     */
    public function mapOrder(OrderEntity $order): array
    {
        $currency = $order->getCurrency()?->getIsoCode();

        return [
            'id'             => $order->getId(),
            'orderNumber'    => $order->getOrderNumber(),
            'version'        => $order->getVersionId(),
            'status'         => $order->getStateMachineState()?->getTechnicalName(),
            'paymentStatus'  => $this->extractPaymentStatus($order),
            'deliveryStatus' => $this->extractDeliveryStatus($order),
            'currency'       => $currency,
            'subtotal'       => $this->money($order->getPositionPrice(), $currency),
            'shipping'       => $this->money($order->getShippingTotal(), $currency),
            'tax'            => $this->money($order->getAmountTotal() - $order->getAmountNet(), $currency),
            'total'          => $this->money($order->getAmountTotal(), $currency),
            'customer'       => $this->mapCustomer($order),
            'billingAddress' => $this->mapAddressById($order, $order->getBillingAddressId()),
            'shippingAddress'=> $this->mapShippingAddress($order),
            'lineItems'      => $this->mapLineItems($order),
            'deliveries'     => $this->mapDeliveriesSummary($order),
            'customerComment'=> $order->getCustomerComment(),
            'tags'           => $this->mapTags($order),
            'customFields'   => $order->getCustomFields(),
            'createdAt'      => $order->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt'      => $order->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Weak ETag derived from versionId + updatedAt. Stable per logical revision
     * of the order, sufficient for If-Match optimistic concurrency.
     *
     * Note: Shopware's versionId for live orders is always Defaults::LIVE_VERSION
     * (a fixed UUID), not an incrementing write counter. ETag uniqueness therefore
     * depends entirely on updatedAt microsecond precision. Two writes that complete
     * within the same microsecond would produce the same ETag; this is an accepted
     * limitation of the optimistic-concurrency scheme used here. The write locks
     * introduced in OrderController and StatusController make same-microsecond
     * collisions practically impossible under normal load.
     */
    public function etagFor(OrderEntity $order): string
    {
        $material = $order->getId()
            . '|' . ($order->getVersionId() ?? '')
            . '|' . ($order->getUpdatedAt()?->format('U.u') ?? $order->getCreatedAt()?->format('U.u') ?? '');

        return 'W/"' . sha1($material) . '"';
    }

    private function money(?float $amount, ?string $currency): array
    {
        return [
            'amount'   => $amount !== null ? round($amount, 2) : null,
            'currency' => $currency,
        ];
    }

    private function extractPaymentStatus(OrderEntity $order): ?string
    {
        $transactions = $order->getTransactions();
        if ($transactions === null || $transactions->count() === 0) {
            return null;
        }

        // Convention: the most recently created transaction reflects the current
        // payment status. Aligns with StatusController::setPaymentStatus().
        $last = $transactions->last();

        return $last?->getStateMachineState()?->getTechnicalName();
    }

    private function extractDeliveryStatus(OrderEntity $order): ?string
    {
        // Use the same "primary delivery" as mapShippingAddress() so the
        // order-level deliveryStatus and shippingAddress always describe the
        // same delivery (was inconsistent: last() here vs first() there).
        return $this->primaryDelivery($order)?->getStateMachineState()?->getTechnicalName();
    }

    /**
     * The order-level delivery view (deliveryStatus + shippingAddress) is
     * derived from the first delivery. Full per-delivery detail is served by
     * the dedicated /orders/{id}/deliveries sub-resource.
     */
    private function primaryDelivery(OrderEntity $order): ?OrderDeliveryEntity
    {
        $deliveries = $order->getDeliveries();
        if ($deliveries === null || $deliveries->count() === 0) {
            return null;
        }

        return $deliveries->first();
    }

    private function mapCustomer(OrderEntity $order): ?array
    {
        $orderCustomer = $order->getOrderCustomer();
        if ($orderCustomer === null) {
            return null;
        }

        return [
            'id'        => $orderCustomer->getCustomerId(),
            'email'     => $orderCustomer->getEmail(),
            'firstName' => $orderCustomer->getFirstName(),
            'lastName'  => $orderCustomer->getLastName(),
            'guest'     => method_exists($orderCustomer, 'getCustomer') && $orderCustomer->getCustomer()
                ? (bool) $orderCustomer->getCustomer()->getGuest()
                : null,
        ];
    }

    private function mapAddressById(OrderEntity $order, ?string $addressId): ?array
    {
        if ($addressId === null) {
            return null;
        }

        $addresses = $order->getAddresses();
        if ($addresses === null) {
            return null;
        }

        $address = $addresses->get($addressId);
        if (!$address instanceof OrderAddressEntity) {
            return null;
        }

        return $this->mapAddress($address);
    }

    private function mapShippingAddress(OrderEntity $order): ?array
    {
        // The order does not carry a single shippingAddressId; deliveries do.
        // Report the primary (first) delivery's shipping address — the same
        // delivery that extractDeliveryStatus() reports the status of.
        $address = $this->primaryDelivery($order)?->getShippingOrderAddress();

        return $address instanceof OrderAddressEntity ? $this->mapAddress($address) : null;
    }

    private function mapAddress(OrderAddressEntity $address): array
    {
        return [
            'salutation'             => null, // salutation is referenced by id; skip resolving in Phase 1
            'title'                  => $address->getTitle(),
            'firstName'              => $address->getFirstName(),
            'lastName'               => $address->getLastName(),
            'company'                => $address->getCompany(),
            'street'                 => $address->getStreet(),
            'additionalAddressLine1' => $address->getAdditionalAddressLine1(),
            'additionalAddressLine2' => $address->getAdditionalAddressLine2(),
            'zipcode'                => $address->getZipcode(),
            'city'                   => $address->getCity(),
            'countryCode'            => $address->getCountry()?->getIso(),
            'stateCode'              => null,
            'phoneNumber'            => $address->getPhoneNumber(),
            'vatId'                  => $address->getVatId(),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function mapLineItems(OrderEntity $order): array
    {
        $items = $order->getLineItems();
        if ($items === null) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof OrderLineItemEntity) {
                continue;
            }
            $currency = $order->getCurrency()?->getIsoCode();

            $out[] = [
                'id'         => $item->getId(),
                'productId'  => $item->getProductId(),
                'type'       => $item->getType(),
                'label'      => $item->getLabel(),
                'quantity'   => $item->getQuantity(),
                'unitPrice'  => $this->money($item->getUnitPrice(), $currency),
                'totalPrice' => $this->money($item->getTotalPrice(), $currency),
                'sku'        => $item->getPayload()['productNumber'] ?? null,
                'payload'    => $item->getPayload(),
            ];
        }

        return $out;
    }

    /**
     * Compact embedded representation of deliveries — full detail goes through
     * the dedicated /orders/{id}/deliveries sub-resource (Phase 3).
     *
     * @return array<int,array<string,mixed>>
     */
    private function mapDeliveriesSummary(OrderEntity $order): array
    {
        $deliveries = $order->getDeliveries();
        if ($deliveries === null) {
            return [];
        }

        $out = [];
        foreach ($deliveries as $delivery) {
            $out[] = [
                'id'             => $delivery->getId(),
                'orderId'        => $order->getId(),
                'status'         => $delivery->getStateMachineState()?->getTechnicalName(),
                'trackingCodes'  => array_map(
                    static fn(string $code): array => ['code' => $code],
                    $delivery->getTrackingCodes() ?? []
                ),
                'shippingAddress' => $delivery->getShippingOrderAddress() instanceof OrderAddressEntity
                    ? $this->mapAddress($delivery->getShippingOrderAddress())
                    : null,
                'plannedShipDate' => $delivery->getShippingDateEarliest()?->format(\DateTimeInterface::ATOM),
                'createdAt'       => $delivery->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'updatedAt'       => $delivery->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return $out;
    }

    /**
     * @return array<int,string>
     */
    private function mapTags(OrderEntity $order): array
    {
        $tags = $order->getTags();
        if ($tags === null) {
            return [];
        }

        $out = [];
        foreach ($tags as $tag) {
            $name = $tag->getName();
            if ($name !== null) {
                $out[] = $name;
            }
        }

        return $out;
    }
}
