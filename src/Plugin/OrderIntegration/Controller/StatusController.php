<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Controller;

use Scotty42\OrderIntegration\Exception\OrderNotFoundException;
use Scotty42\OrderIntegration\Exception\ValidationException;
use Scotty42\OrderIntegration\Http\EtagComparator;
use Scotty42\OrderIntegration\Service\IdempotencyService;
use Scotty42\OrderIntegration\Service\OrderMapper;
use Scotty42\OrderIntegration\Service\StateMachineService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class StatusController extends AbstractController
{
    use EnforcesIfMatch;
    use HandlesIdempotency;

    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly StateMachineService $stateMachineService,
        private readonly OrderMapper $orderMapper,
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
        path: '/api/order-integration/v1/orders/{orderId}/status',
        name: 'api.order-integration.orders.status',
        methods: ['PUT']
    )]
    public function setOrderStatus(string $orderId, Request $request, Context $context): JsonResponse
    {
        return $this->withIdempotency($request, function () use ($orderId, $request, $context): JsonResponse {
            $order = $this->loadOrderOrFail($orderId, $context);
            $this->assertIfMatch($request, $this->orderMapper->etagFor($order));
            $targetStatus = $this->getRequiredField($request, 'status');

            $this->stateMachineService->transitionOrder($orderId, $targetStatus, $context);

            return $this->respondWithOrder($orderId, $context);
        });
    }

    #[Route(
        path: '/api/order-integration/v1/orders/{orderId}/payment-status',
        name: 'api.order-integration.orders.payment-status',
        methods: ['PUT']
    )]
    public function setPaymentStatus(string $orderId, Request $request, Context $context): JsonResponse
    {
        return $this->withIdempotency($request, function () use ($orderId, $request, $context): JsonResponse {
            $order = $this->loadOrderOrFail($orderId, $context);
            $this->assertIfMatch($request, $this->orderMapper->etagFor($order));

            $transactions = $order->getTransactions();
            if ($transactions === null || $transactions->count() === 0) {
                return $this->conflict('Order has no transactions.', 'order.no_transactions');
            }

            $transactionId = $transactions->last()->getId();
            $targetStatus = $this->getRequiredField($request, 'status');

            $this->stateMachineService->transitionPayment($transactionId, $targetStatus, $context);

            return $this->respondWithOrder($orderId, $context);
        });
    }

    #[Route(
        path: '/api/order-integration/v1/orders/{orderId}/delivery-status',
        name: 'api.order-integration.orders.delivery-status',
        methods: ['PUT']
    )]
    public function setDeliveryStatus(string $orderId, Request $request, Context $context): JsonResponse
    {
        return $this->withIdempotency($request, function () use ($orderId, $request, $context): JsonResponse {
            $order = $this->loadOrderOrFail($orderId, $context);
            $this->assertIfMatch($request, $this->orderMapper->etagFor($order));

            $deliveries = $order->getDeliveries();
            if ($deliveries === null || $deliveries->count() === 0) {
                return $this->conflict('Order has no deliveries.', 'order.no_deliveries');
            }

            $deliveryId = $deliveries->last()->getId();
            $targetStatus = $this->getRequiredField($request, 'status');

            $this->stateMachineService->transitionDelivery($deliveryId, $targetStatus, $context);

            return $this->respondWithOrder($orderId, $context);
        });
    }

    private function loadOrderOrFail(string $orderId, Context $context): OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociations(['transactions', 'deliveries']);

        $order = $this->orderRepository->search($criteria, $context)->first();

        if (!$order instanceof OrderEntity) {
            throw new OrderNotFoundException($orderId);
        }

        return $order;
    }

    /**
     * Re-loads the order with the full association set and returns the
     * spec-compliant Order payload plus ETag. Used after every successful
     * state-machine transition.
     */
    private function respondWithOrder(string $orderId, Context $context): JsonResponse
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociations(OrderMapper::REQUIRED_ASSOCIATIONS);

        $order = $this->orderRepository->search($criteria, $context)->first();

        if (!$order instanceof OrderEntity) {
            throw new OrderNotFoundException($orderId);
        }

        return new JsonResponse(
            $this->orderMapper->mapOrder($order),
            Response::HTTP_OK,
            ['ETag' => $this->orderMapper->etagFor($order)]
        );
    }

    private function conflict(string $detail, string $code): JsonResponse
    {
        return new JsonResponse(
            [
                'type'   => 'about:blank',
                'title'  => 'Conflict',
                'status' => Response::HTTP_CONFLICT,
                'detail' => $detail,
                'code'   => $code,
            ],
            Response::HTTP_CONFLICT,
            ['Content-Type' => 'application/problem+json']
        );
    }

    /**
     * Bug B1: previously threw \InvalidArgumentException, which is not in
     * ExceptionSubscriber's allow-list and surfaced as a 500. Translating
     * to ValidationException at the source produces the documented 422
     * application/problem+json response with a JSON Pointer.
     */
    private function getRequiredField(Request $request, string $field): string
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || empty($data[$field])) {
            throw new ValidationException([
                ['pointer' => '/' . $field, 'code' => 'required', 'message' => sprintf('%s is required', $field)],
            ]);
        }

        return $data[$field];
    }
}
