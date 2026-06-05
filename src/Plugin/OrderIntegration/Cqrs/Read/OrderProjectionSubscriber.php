<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs\Read;

use Psr\Log\LoggerInterface;
use Scotty42\OrderIntegration\Cqrs\PdoConnectionProvider;
use Scotty42\OrderIntegration\Service\OrderMapper;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeletedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps the read projection in sync from Shopware business events (concept §2,
 * Phase 2 — "CDC / business events"). On every order write the affected orders
 * are reloaded and upserted into the projection; on delete they are removed.
 *
 * The subscriber is registered unconditionally, but it MUST be inert when no
 * read/queue DB is configured — otherwise every order write would try to upsert
 * into a non-existent Postgres and fail with 500. It therefore:
 *   - no-ops entirely when ORDER_INTEGRATION_DB_DSN is not configured, and
 *   - never lets a projection-DB error break the primary order write (the
 *     projection is a derived, eventually-consistent store; failures are logged
 *     and can be reconciled by a backfill).
 */
class OrderProjectionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly OrderProjectionWriter $writer,
        private readonly PdoConnectionProvider $connection,
        private readonly LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_WRITTEN_EVENT => 'onOrderWritten',
            OrderEvents::ORDER_DELETED_EVENT => 'onOrderDeleted',
        ];
    }

    public function onOrderWritten(EntityWrittenEvent $event): void
    {
        if (!$this->connection->isConfigured()) {
            return; // no projection DB configured -> projection is inert
        }

        $ids = $event->getIds();
        if ($ids === []) {
            return;
        }

        try {
            $criteria = new Criteria($ids);
            $criteria->addAssociations(OrderMapper::REQUIRED_ASSOCIATIONS);

            $orders = $this->orderRepository->search($criteria, $event->getContext());
            foreach ($orders->getEntities() as $order) {
                /** @var OrderEntity $order */
                $this->writer->apply($order);
            }
        } catch (\Throwable $e) {
            // Never fail the primary order write because of the derived projection.
            $this->logger->error('Order projection upsert failed', ['exception' => $e]);
        }
    }

    public function onOrderDeleted(EntityDeletedEvent $event): void
    {
        if (!$this->connection->isConfigured()) {
            return;
        }

        try {
            foreach ($event->getIds() as $id) {
                $this->writer->remove($id);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Order projection delete failed', ['exception' => $e]);
        }
    }
}
