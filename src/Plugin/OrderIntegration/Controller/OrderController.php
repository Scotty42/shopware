<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Controller;

use Scotty42\OrderIntegration\Exception\OrderNotFoundException;
use Scotty42\OrderIntegration\Service\OrderCreationService;
use Scotty42\OrderIntegration\Service\OrderMapper;
use Scotty42\OrderIntegration\Exception\InvalidTransitionException;
use Scotty42\OrderIntegration\Service\OrderPatchService;
use Scotty42\OrderIntegration\Service\StateMachineService;
use Scotty42\OrderIntegration\Validator\QueryValidator;
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
    private const ASSOCIATIONS = [
        'lineItems',
        'deliveries',
        'transactions',
        'deliveries.stateMachineState',
        'transactions.stateMachineState',
        'orderCustomer',
        'deliveries.stateMachineState',
        'transactions.stateMachineState',
        'orderCustomer',
        'addresses',
        'stateMachineState',
        'currency',
    ];

    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly QueryValidator $queryValidator,
        private readonly OrderCreationService $orderCreationService,
        private readonly OrderPatchService $orderPatchService,
        private readonly OrderMapper $orderMapper,
        private readonly StateMachineService $stateMachineService,
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

        $nextCursor = null;
        if ($hasMore && !empty($elements)) {
            $last = end($elements);
            $nextCursor = base64_encode(json_encode([
                'createdAt' => $last->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'id'        => $last->getId(),
            ]));
        }

        return new JsonResponse([
            'items' => array_map(fn($order) => $this->orderMapper->mapOrder($order), $elements),
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

        $order = $this->orderCreationService->createOrder($data, $context);

        return new JsonResponse($order, Response::HTTP_CREATED);
    }

    #[Route(
        path: '/api/order-integration/v1/orders/{orderId}',
        name: 'api.order-integration.orders.get',
        methods: ['GET']
    )]
    public function get(string $orderId, Context $context): JsonResponse
    {
        $order = $this->findOrder($orderId, $context);

        return new JsonResponse(
            $this->orderMapper->mapOrder($order),
            Response::HTTP_OK,
            ['ETag' => $this->orderMapper->etagFor($order)]
        );
    }

    #[Route(
        path: '/api/order-integration/v1/orders/{orderId}',
        name: 'api.order-integration.orders.patch',
        methods: ['PATCH']
    )]
    public function patch(string $orderId, Request $request, Context $context): JsonResponse
    {
        $this->findOrder($orderId, $context); // assert exists

        $data = json_decode($request->getContent(), true) ?? [];

        $allowed = ['customerComment', 'customFields', 'tags', 'billingAddress', 'shippingAddress'];
        $data = array_intersect_key($data, array_flip($allowed));

        if (empty($data)) {
            return $this->validationError('/', 'no_mutable_fields', 'No mutable fields provided.');
        }

        $this->orderPatchService->patch($orderId, $data, $context);

        $order = $this->findOrder($orderId, $context);

        return new JsonResponse(
            $this->orderMapper->mapOrder($order),
            Response::HTTP_OK,
            ['ETag' => $this->orderMapper->etagFor($order)]
        );
    }


    #[Route(
        path: '/api/order-integration/v1/orders/{orderId}',
        name: 'api.order-integration.orders.delete',
        methods: ['DELETE']
    )]
    public function delete(string $orderId, Request $request, Context $context): JsonResponse
    {
        $this->findOrder($orderId, $context);

        $hard = $request->query->getBoolean('hard', false);

        if ($hard) {
            return new JsonResponse([
                'type'   => 'about:blank',
                'title'  => 'Forbidden',
                'status' => 403,
                'detail' => 'Hard delete requires scope orders:hard_delete. Not yet implemented.',
                'code'   => 'order.hard_delete_not_permitted',
            ], Response::HTTP_FORBIDDEN);
        }

        // Soft delete: transition to cancelled
        try {
            $this->stateMachineService->transitionOrder($orderId, 'cancelled', $context);
        } catch (\Scotty42\OrderIntegration\Exception\InvalidTransitionException $e) {
            // Already cancelled — idempotent, treat as success
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function findOrder(string $orderId, Context $context)
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociations(self::ASSOCIATIONS);

        $order = $this->orderRepository->search($criteria, $context)->first();

        if ($order === null) {
            throw new OrderNotFoundException($orderId);
        }

        return $order;
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

    private function validationError(string $pointer, string $code, string $message): JsonResponse
    {
        return new JsonResponse([
            'type'   => 'about:blank',
            'title'  => 'Unprocessable Content',
            'status' => 422,
            'detail' => 'Validation failed.',
            'code'   => 'order.validation_failed',
            'errors' => [['pointer' => $pointer, 'code' => $code, 'message' => $message]],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
