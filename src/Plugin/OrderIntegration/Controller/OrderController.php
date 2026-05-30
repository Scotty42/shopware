<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class OrderController extends AbstractController
{
    private const ASSOCIATIONS = [
        'lineItems',
        'deliveries',
        'transactions',
        'addresses',
        'stateMachineState',
        'currency',
    ];

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
        $criteria->addAssociations(self::ASSOCIATIONS);
        $criteria->setLimit(min($request->query->getInt('limit', 50), 200));

        // Filter: status
        if ($status = $request->query->get('status')) {
            $criteria->addFilter(new EqualsFilter('stateMachineState.technicalName', $status));
        }

        // Filter: customerId
        if ($customerId = $request->query->get('customerId')) {
            $criteria->addFilter(new EqualsFilter('orderCustomer.customerId', $customerId));
        }

        // Filter: createdAfter / createdBefore
        if ($createdAfter = $request->query->get('createdAfter')) {
            $criteria->addFilter(new RangeFilter('createdAt', [RangeFilter::GTE => $createdAfter]));
        }
        if ($createdBefore = $request->query->get('createdBefore')) {
            $criteria->addFilter(new RangeFilter('createdAt', [RangeFilter::LTE => $createdBefore]));
        }

        $orders = $this->orderRepository->search($criteria, $context);

        return new JsonResponse([
            'items' => array_values(array_map(
                fn($order) => $this->mapOrder($order),
                $orders->getElements()
            )),
            'page' => [
                'total' => $orders->getTotal(),
                'limit' => $criteria->getLimit(),
            ],
        ]);
    }

    #[Route(
        path: '/api/order-integration/v1/orders/{orderId}',
        name: 'api.order-integration.orders.get',
        methods: ['GET']
    )]
    public function get(string $orderId, Context $context): JsonResponse
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociations(self::ASSOCIATIONS);

        $order = $this->orderRepository->search($criteria, $context)->first();

        if ($order === null) {
            return new JsonResponse([
                'type'   => 'about:blank',
                'title'  => 'Not Found',
                'status' => 404,
                'detail' => sprintf('Order "%s" not found.', $orderId),
                'code'   => 'order.not_found',
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->mapOrder($order));
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
