<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

class OrderPatchService
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
    ) {}

    /**
     * Applies a partial update to an order in a single DAL write call.
     *
     * Address changes are embedded as nested data inside the order update so
     * the DAL issues one atomic write instead of the previous 3-call sequence
     * (order update + billing update + shipping update). This closes the window
     * where a mid-patch failure could leave the order in a partially updated
     * state, and reduces the number of round-trips.
     *
     * Shipping address: resolved from the first delivery (same "primary
     * delivery" convention as OrderMapper). Multi-delivery orders should use
     * the dedicated /orders/{id}/deliveries/{did} sub-resource.
     */
    public function patch(string $orderId, array $data, Context $context): void
    {
        $orderUpdate = ['id' => $orderId];

        if (array_key_exists('customerComment', $data)) {
            $orderUpdate['customerComment'] = $data['customerComment'];
        }
        if (array_key_exists('customFields', $data)) {
            $orderUpdate['customFields'] = $data['customFields'];
        }
        if (!empty($data['tags'])) {
            $orderUpdate['tags'] = array_map(
                fn(string $name) => ['name' => $name],
                $data['tags']
            );
        }

        if (!empty($data['billingAddress']) || !empty($data['shippingAddress'])) {
            $criteria = new Criteria([$orderId]);
            $criteria->addAssociation('deliveries');
            $order = $this->orderRepository->search($criteria, $context)->first();

            if ($order !== null) {
                $addresses = [];

                if (!empty($data['billingAddress'])) {
                    $addresses[] = [
                        'id' => $order->getBillingAddressId(),
                        ...$this->mapAddress($data['billingAddress']),
                    ];
                }

                if (!empty($data['shippingAddress'])) {
                    $deliveryAddressId = $order->getDeliveries()?->first()?->getShippingOrderAddressId();
                    if ($deliveryAddressId !== null) {
                        $addresses[] = [
                            'id' => $deliveryAddressId,
                            ...$this->mapAddress($data['shippingAddress']),
                        ];
                    }
                }

                if ($addresses !== []) {
                    $orderUpdate['addresses'] = $addresses;
                }
            }
        }

        $this->orderRepository->update([$orderUpdate], $context);
    }

    private function mapAddress(array $address): array
    {
        $mapped = [];
        $fields = ['firstName', 'lastName', 'street', 'zipcode', 'city', 'company',
                   'additionalAddressLine1', 'additionalAddressLine2', 'phoneNumber'];

        foreach ($fields as $field) {
            if (array_key_exists($field, $address)) {
                $mapped[$field] = $address[$field];
            }
        }

        return $mapped;
    }
}
