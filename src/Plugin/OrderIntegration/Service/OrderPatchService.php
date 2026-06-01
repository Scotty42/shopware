<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class OrderPatchService
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderAddressRepository,
        private readonly EntityRepository $tagRepository,
    ) {}

    public function patch(string $orderId, array $data, Context $context): void
    {
        $orderUpdate = ['id' => $orderId];
        $hasOrderUpdate = false;

        if (array_key_exists('customerComment', $data)) {
            $orderUpdate['customerComment'] = $data['customerComment'];
            $hasOrderUpdate = true;
        }

        if (array_key_exists('customFields', $data)) {
            $orderUpdate['customFields'] = $data['customFields'];
            $hasOrderUpdate = true;
        }

        if (!empty($data['tags'])) {
            $orderUpdate['tags'] = array_map(
                fn(string $name) => ['name' => $name],
                $data['tags']
            );
            $hasOrderUpdate = true;
        }

        if ($hasOrderUpdate) {
            $this->orderRepository->update([$orderUpdate], $context);
        }

        if (!empty($data['billingAddress'])) {
            $this->updateBillingAddress($orderId, $data['billingAddress'], $context);
        }

        if (!empty($data['shippingAddress'])) {
            $this->updateShippingAddress($orderId, $data['shippingAddress'], $context);
        }
    }

    private function updateBillingAddress(string $orderId, array $address, Context $context): void
    {
        // Fetch order to get billingAddressId
        $criteria = new Criteria([$orderId]);
        $order = $this->orderRepository->search($criteria, $context)->first();

        if ($order === null) {
            return;
        }

        $this->orderAddressRepository->update([[
            'id'     => $order->getBillingAddressId(),
            ...$this->mapAddress($address),
        ]], $context);
    }

    private function updateShippingAddress(string $orderId, array $address, Context $context): void
    {
        // Fetch the delivery shipping address
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addFilter(new EqualsFilter('type', 'shipping'));

        $addresses = $this->orderAddressRepository->search($criteria, $context);

        if ($addresses->count() === 0) {
            return;
        }

        $this->orderAddressRepository->update([[
            'id'     => $addresses->first()->getId(),
            ...$this->mapAddress($address),
        ]], $context);
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
