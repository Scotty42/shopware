<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs\Read;

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
 */
class OrderProjectionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly OrderProjectionWriter $writer,
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
        $ids = $event->getIds();
        if ($ids === []) {
            return;
        }

        $criteria = new Criteria($ids);
        $criteria->addAssociations(OrderMapper::REQUIRED_ASSOCIATIONS);

        $orders = $this->orderRepository->search($criteria, $event->getContext());
        foreach ($orders->getEntities() as $order) {
            /** @var OrderEntity $order */
            $this->writer->apply($order);
        }
    }

    public function onOrderDeleted(EntityDeletedEvent $event): void
    {
        foreach ($event->getIds() as $id) {
            $this->writer->remove($id);
        }
    }
}
