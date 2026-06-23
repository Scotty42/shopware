<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Erp;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * DAL-facing side of the ERP pull/acknowledge workflow. The decision logic
 * lives in ErpSyncPolicy (pure, unit-tested); this class only loads orders and
 * applies the planned patches.
 */
class ErpSyncService
{
    /** Maximum order ids accepted in a single acknowledge call. */
    public const MAX_BATCH = 500;

    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly ErpSyncPolicy $policy,
    ) {}

    /**
     * Marks the given orders as synced. Idempotent: orders already synced keep
     * their original timestamp; unknown ids are reported, not failed.
     *
     * @param list<string>          $orderIds
     * @param array<string,string>  $erpOrderIds optional map of shopwareId => erpOrderId
     *
     * @return array{acknowledged:list<string>,alreadySynced:list<string>,notFound:list<string>}
     */
    public function acknowledge(array $orderIds, \DateTimeInterface $now, Context $context, array $erpOrderIds = []): array
    {
        $orderIds = array_values(array_unique($orderIds));

        $criteria = new Criteria($orderIds);
        $orders = $this->orderRepository->search($criteria, $context);

        $existing = [];
        foreach ($orders->getEntities() as $order) {
            /** @var OrderEntity $order */
            $existing[$order->getId()] = $order->getCustomFields();
        }

        $plan = $this->policy->planAcknowledgement($existing, $orderIds, $now, $erpOrderIds);

        if (!empty($plan['patches'])) {
            $this->orderRepository->update($plan['patches'], $context);
        }

        return [
            'acknowledged'  => $plan['acknowledged'],
            'alreadySynced' => $plan['alreadySynced'],
            'notFound'      => $plan['notFound'],
        ];
    }
}
