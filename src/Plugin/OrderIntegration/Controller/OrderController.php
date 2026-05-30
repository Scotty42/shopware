<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class OrderController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
    ) {}

    #[Route(
        path: '/api/order-integration/v1/orders',
        name: 'api.order-integration.orders.list',
        methods: ['GET']
    )]
    public function list(Request $request, Context $context): JsonResponse
    {
        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('orderDate', FieldSorting::DESCENDING));
        $criteria->addAssociations([
            'lineItems',
            'deliveries',
            'transactions',
            'addresses',
            'stateMachineState',
            'currency',
        ]);
        $criteria->setLimit($request->query->getInt('limit', 50));

        $orders = $this->orderRepository->search($criteria, $context);

        $items = array_map(
            fn($order) => $this->mapOrder($order),
            $orders->getElements()
        );

        return new JsonResponse([
            'items' => array_values($items),
            'page'  => [
                'total' => $orders->getTotal(),
                'limit' => $criteria->getLimit(),
            ],
        ]);
    }

    private function mapOrder($order): array
    {
        return [
            'id'          => $order->getId(),
            'orderNumber' => $order->getOrderNumber(),
            'status'      => $order->getStateMachineState()?->getTechnicalName(),
            'total'       => [
                'amount'   => $order->getAmountTotal(),
                'currency' => $order->getCurrency()?->getIsoCode(),
            ],
            'createdAt'   => $order->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt'   => $order->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
