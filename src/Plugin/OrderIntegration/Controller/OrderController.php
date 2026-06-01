<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Controller;

use Scotty42\OrderIntegration\Exception\OrderNotFoundException;
use Scotty42\OrderIntegration\Service\OrderCreationService;
use Scotty42\OrderIntegration\Service\OrderMapper;
use Scotty42\OrderIntegration\Validator\QueryValidator;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
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
    /**
     * Full association set required to render the spec-compliant Order shape.
     * Keep in sync with OrderMapper expectations.
     */
    private const ASSOCIATIONS = [
        'lineItems',
        'deliveries.stateMachineState',
        'deliveries.shippingOrderAddress.country',
        'transactions.stateMachineState',
        'addresses.country',
        'stateMachineState',
        'currency',
        'orderCustomer',
        'tags',
    ];

    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly QueryValidator $queryValidator,
        private readonly OrderCreationService $orderCreationService,
        private readonly OrderMapper $orderMapper,
    ) {}

    #[Route(
        path: '/api/order-integration/v1/orders',
        name: 'api.order-integration.orders.list',
        methods: ['GET']
    )]
    public function list(Request $request, Context $context): JsonResponse
    {
        $this->queryValidator->validateListParams($request->query->all());

        $limit = min($request->query->getInt('limit', 50), 200);

        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->addSorting(new FieldSorting('id', FieldSorting::DESCENDING));
        $criteria->addAssociations(self::ASSOCIATIONS);
        $criteria->setLimit($limit + 1);

        if ($status = $request->query->get('status')) {
            $criteria->addFilter(new EqualsFilter('stateMachineState.technicalName', $status));
        }
        if ($customerId = $request->query->get('customerId')) {
            $criteria->addFilter(new EqualsFilter('orderCustomer.customerId', $customerId));
        }
        if ($createdAfter = $request->query->get('createdAfter')) {
            $criteria->addFilter(new RangeFilter('createdAt', [RangeFilter::GTE => $createdAfter]));
        }
        if ($createdBefore = $request->query->get('createdBefore')) {
            $criteria->addFilter(new RangeFilter('createdAt', [RangeFilter::LTE => $createdBefore]));
        }

        if ($cursorRaw = $request->query->get('cursor')) {
            $cursor = json_decode(base64_decode($cursorRaw), true);
            $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                new RangeFilter('createdAt', [RangeFilter::LT => $cursor['createdAt']]),
                new MultiFilter(MultiFilter::CONNECTION_AND, [
                    new EqualsFilter('createdAt', $cursor['createdAt']),
                    new NotFilter(MultiFilter::CONNECTION_AND, [
                        new EqualsFilter('id', $cursor['id']),
                    ]),
                    new RangeFilter('id', [RangeFilter::LT => $cursor['id']]),
                ]),
            ]));
        }

        $orders = $this->orderRepository->search($criteria, $context);
        $elements = array_values($orders->getElements());

        $hasMore = count($elements) > $limit;
        if ($hasMore) {
            array_pop($elements);
        }

        $items = array_map(
            fn(OrderEntity $order): array => $this->orderMapper->mapOrder($order),
            $elements
        );

        $nextCursor = null;
        if ($hasMore && !empty($elements)) {
            /** @var OrderEntity $last */
            $last = end($elements);
            $nextCursor = base64_encode(json_encode([
                'createdAt' => $last->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'id'        => $last->getId(),
            ]));
        }

        return new JsonResponse([
            'items' => $items,
            'page'  => [
                'limit'      => $limit,
                'nextCursor' => $nextCursor,
            ],
        ]);
    }

    #[Route(
        path: '/api/order-integration/v1/orders',
        name: 'api.order-integration.orders.create',
        methods: ['POST']
    )]
    public function create(Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['salesChannelId'])) {
            return $this->validationError('/salesChannelId', 'required', 'salesChannelId is required');
        }

        if (empty($data['lineItems'])) {
            return $this->validationError('/lineItems', 'required', 'lineItems must not be empty');
        }

        $orderId = $this->orderCreationService->createOrder($data, $context);

        $order = $this->loadOrder($orderId, $context);
        $payload = $this->orderMapper->mapOrder($order);

        return new JsonResponse(
            $payload,
            Response::HTTP_CREATED,
            [
                'Location' => sprintf('/api/order-integration/v1/orders/%s', $orderId),
                'ETag'     => $this->orderMapper->etagFor($order),
            ]
        );
    }

    #[Route(
        path: '/api/order-integration/v1/orders/{orderId}',
        name: 'api.order-integration.orders.get',
        methods: ['GET']
    )]
    public function get(string $orderId, Context $context): JsonResponse
    {
        $order = $this->loadOrder($orderId, $context);

        return new JsonResponse(
            $this->orderMapper->mapOrder($order),
            Response::HTTP_OK,
            ['ETag' => $this->orderMapper->etagFor($order)]
        );
    }

    /**
     * Load an order with all spec-required associations. Throws 404 if missing.
     */
    private function loadOrder(string $orderId, Context $context): OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociations(self::ASSOCIATIONS);

        $order = $this->orderRepository->search($criteria, $context)->first();

        if (!$order instanceof OrderEntity) {
            throw new OrderNotFoundException($orderId);
        }

        return $order;
    }

    private function validationError(string $pointer, string $code, string $message): JsonResponse
    {
        return new JsonResponse(
            [
                'type'   => 'about:blank',
                'title'  => 'Unprocessable Content',
                'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'detail' => 'Validation failed.',
                'code'   => 'order.validation_failed',
                'errors' => [
                    ['pointer' => $pointer, 'code' => $code, 'message' => $message],
                ],
            ],
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['Content-Type' => 'application/problem+json']
        );
    }
}
