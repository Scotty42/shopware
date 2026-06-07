<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs\Read;

use Scotty42\OrderIntegration\Service\OrderMapper;
use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * Builds the projection snapshot from a Shopware OrderEntity and upserts it.
 * The snapshot reuses the canonical Order payload (OrderMapper) plus the
 * salesChannelId routing key used for filtering.
 */
final class OrderProjectionWriter
{
    public function __construct(
        private readonly ReadProjectionInterface $projection,
        private readonly OrderMapper $mapper,
    ) {}

    public function apply(OrderEntity $order): void
    {
        $snapshot = $this->mapper->mapOrder($order);
        $snapshot['salesChannelId'] = $order->getSalesChannelId();
        // Store the exact DAL ETag so a projection-served GET returns the SAME
        // ETag the mutation endpoints validate If-Match against (etagFor uses
        // microsecond precision that the ATOM updatedAt in the snapshot loses).
        $snapshot['_etag'] = $this->mapper->etagFor($order);

        $this->projection->upsert($snapshot);
    }

    public function remove(string $orderId): void
    {
        $this->projection->delete($orderId);
    }
}
