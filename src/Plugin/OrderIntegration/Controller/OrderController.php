<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Controller;

use Scotty42\OrderIntegration\Exception\OrderNotFoundException;
use Scotty42\OrderIntegration\Exception\ValidationException;
use Scotty42\OrderIntegration\Http\EtagComparator;
use Scotty42\OrderIntegration\Service\IdempotencyService;
use Scotty42\OrderIntegration\Service\OrderCreationService;
use Scotty42\OrderIntegration\Service\OrderMapper;
use Scotty42\OrderIntegration\Service\OrderPatchService;
use Scotty42\OrderIntegration\Service\SoftDeletePolicy;
use Scotty42\OrderIntegration\Service\StateMachineService;
use Scotty42\OrderIntegration\Validator\OrderCreateValidator;
use Scotty42\OrderIntegration\Validator\QueryValidator;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
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
    use EnforcesIfMatch;
    use HandlesIdempotency;

    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly QueryValidator $queryValidator,
        private readonly OrderCreateValidator $orderCreateValidator,
        private readonly OrderCreationService $orderCreationService,
        private readonly OrderPatchService $orderPatchService,
        private readonly OrderMapper $orderMapper,
        private readonly StateMachineService $stateMachineService,
        private readonly IdempotencyService $idempotency,
        private readonly EtagComparator $etagComparator,
    ) {}

    protected function getIdempotencyService(): IdempotencyService
    {
        return $this->idempotency;
    }

    protected function getEtagComparator(): EtagComparator
    {
        return $this->etagComparator;
    }

    #[Route(
        path: '/api/order-integration/v1/orders',
        name: 'api.order-integration.orders.list',
        methods: ['GET']
    )]
    public function list(Request $request, Context $context): JsonResponse
    {
        $this->queryValidator->validateListParams($request->query->all());

        $limit = min($request->query->getInt('limit', 50), 200);

        // `sort` is validated by QueryValidator (whitelist <field>:<asc|desc>).
        $sortParam = (string) $request->query->get('sort', 'createdAt:desc');
        [$sortField, $sortDir] = explode(':', $sortParam) + [1 => 'desc'];
        $direction = strtolower($sortDir) === 'asc'
            ? FieldSorting::ASCENDING
            : FieldSorting::DESCENDING;

        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting($sortField, $direction));
        $criteria->addSorting(new FieldSorting('id', $direction)); // deterministic tiebreaker
        $criteria->addAssociations(OrderMapper::REQUIRED_ASSOCIATIONS);
        $criteria->setLimit($limit + 1);

        if ($status = $request->query->get('status')) {
            $criteria->addFilter(new EqualsFilter('stateMachineState.technicalName', $status));
        }
        if ($customerId = $request->query->get('customerId')) {
            $criteria->addFilter(new EqualsFilter(
                'orderCustomer.customerId',
                \Scotty42\OrderIntegration\Validator\QueryValidator::normalizeId($customerId)
            ));
        }
        if ($salesChannelId = $request->query->get('salesChannelId')) {
            $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        }
        if ($createdAfter = $request->query->get('createdAfter')) {
            $criteria->addFilter(new RangeFilter('createdAt', [RangeFilter::GTE => $createdAfter]));
        }
        if ($createdBefore = $request->query->get('createdBefore')) {
            $criteria->addFilter(new RangeFilter('createdAt', [RangeFilter::LTE => $createdBefore]));
        }

        if ($cursorRaw = $request->query->get('cursor')) {
            $cursor = json_decode(base64_decode($cursorRaw), true);

            // Keyset pagination over the active sort field (+ id tiebreaker),
            // honouring the sort direction. Falls back to the legacy
            // {createdAt, id} cursor shape for backward compatibility.
            $cursorField = $cursor['field'] ?? 'createdAt';
            $cursorValue = $cursor['value'] ?? ($cursor['createdAt'] ?? null);
            $cursorId    = $cursor['id'];
            $cursorDir   = $cursor['dir'] ?? $sortDir;
            $rangeOp     = strtolower((string) $cursorDir) === 'asc'
                ? RangeFilter::GT
                : RangeFilter::LT;

            $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                new RangeFilter($cursorField, [$rangeOp => $cursorValue]),
                new MultiFilter(MultiFilter::CONNECTION_AND, [
                    new EqualsFilter($cursorField, $cursorValue),
                    new RangeFilter('id', [$rangeOp => $cursorId]),
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
                'field' => $sortField,
                'value' => $this->sortValue($last, $sortField),
                'id'    => $last->getId(),
                'dir'   => $sortDir,
            ]));
        }

        return new JsonResponse([
            'items' => array_map(fn(OrderEntity $order) => $this->orderMapper->mapOrder($order), $elements),
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
        return $this->withIdempotency($request, function () use ($request, $context): JsonResponse {
            $data = json_decode($request->getContent(), true);

            if (!is_array($data)) {
                throw new ValidationException([
                    ['pointer' => '/', 'code' => 'invalid_json', 'message' => 'Request body must be a JSON object.'],
                ]);
            }

            $this->orderCreateValidator->validate($data);

            $orderId = $this->orderCreationService->createOrder($data, $context);
            $order = $this->findOrder($orderId, $context);

            return new JsonResponse(
                $this->orderMapper->mapOrder($order),
                Response::HTTP_CREATED,
                [
                    'Location' => sprintf('/api/order-integration/v1/orders/%s', $orderId),
                    'ETag'     => $this->orderMapper->etagFor($order),
                ]
            );
        });
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
        return $this->withIdempotency($request, function () use ($orderId, $request, $context): JsonResponse {
            $order = $this->findOrder($orderId, $context); // assert exists
            $this->assertIfMatch($request, $this->orderMapper->etagFor($order));

            $data = json_decode($request->getContent(), true) ?? [];

            $allowed = ['customerComment', 'customFields', 'tags', 'billingAddress', 'shippingAddress'];
            $data = array_intersect_key($data, array_flip($allowed));

            if (empty($data)) {
                throw new ValidationException([
                    ['pointer' => '/', 'code' => 'no_mutable_fields', 'message' => 'No mutable fields provided.'],
                ]);
            }

            $this->orderPatchService->patch($orderId, $data, $context);

            $order = $this->findOrder($orderId, $context);

            return new JsonResponse(
                $this->orderMapper->mapOrder($order),
                Response::HTTP_OK,
                ['ETag' => $this->orderMapper->etagFor($order)]
            );
        });
    }

    #[Route(
        path: '/api/order-integration/v1/orders/{orderId}',
        name: 'api.order-integration.orders.delete',
        methods: ['DELETE']
    )]
    public function delete(string $orderId, Request $request, Context $context): JsonResponse
    {
        return $this->withIdempotency($request, function () use ($orderId, $request, $context): JsonResponse {
            $order = $this->findOrder($orderId, $context);
            $this->assertIfMatch($request, $this->orderMapper->etagFor($order));

            $hard = $request->query->getBoolean('hard', false);

            if ($hard) {
                return new JsonResponse([
                    'type'   => 'about:blank',
                    'title'  => 'Forbidden',
                    'status' => 403,
                    'detail' => 'Hard delete requires scope orders:hard_delete. Not yet implemented.',
                    'code'   => 'order.hard_delete_not_permitted',
                ], Response::HTTP_FORBIDDEN, ['Content-Type' => 'application/problem+json']);
            }

            // Soft delete = transition to cancelled. Only an already-cancelled
            // order is an idempotent no-op; any other illegal transition (e.g.
            // from `completed`) must surface as 409 rather than a fake 204.
            $currentStatus = $order->getStateMachineState()?->getTechnicalName();
            if (!SoftDeletePolicy::isAlreadyCancelled($currentStatus)) {
                $this->stateMachineService->transitionOrder($orderId, 'cancelled', $context);
            }

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        });
    }

    /**
     * Returns the comparable value of $field on the order, used to build the
     * keyset pagination cursor. Mirrors the whitelist in QueryValidator.
     */
    private function sortValue(OrderEntity $order, string $field): ?string
    {
        return match ($field) {
            'updatedAt'   => $order->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'orderNumber' => $order->getOrderNumber(),
            default       => $order->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        };
    }

    private function findOrder(string $orderId, Context $context): OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociations(OrderMapper::REQUIRED_ASSOCIATIONS);

        $order = $this->orderRepository->search($criteria, $context)->first();

        if (!$order instanceof OrderEntity) {
            throw new OrderNotFoundException($orderId);
        }

        return $order;
    }
}
