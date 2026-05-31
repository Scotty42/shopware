<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Controller;

use Scotty42\OrderIntegration\Exception\InvalidTransitionException;
use Scotty42\OrderIntegration\Exception\OrderNotFoundException;
use Scotty42\OrderIntegration\Service\StateMachineService;
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
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly StateMachineService $stateMachineService,
    ) {}

    #[Route(
        path: '/api/order-integration/v1/orders/{orderId}/status',
        name: 'api.order-integration.orders.status',
        methods: ['PUT']
    )]
    public function setOrderStatus(string $orderId, Request $request, Context $context): JsonResponse
    {
        $this->assertOrderExists($orderId, $context);
        $targetStatus = $this->getRequiredField($request, 'status');

        $this->stateMachineService->transitionOrder($orderId, $targetStatus, $context);

        return new JsonResponse(['orderId' => $orderId, 'status' => $targetStatus], Response::HTTP_OK);
    }

    #[Route(
        path: '/api/order-integration/v1/orders/{orderId}/payment-status',
        name: 'api.order-integration.orders.payment-status',
        methods: ['PUT']
    )]
    public function setPaymentStatus(string $orderId, Request $request, Context $context): JsonResponse
    {
        $this->assertOrderExists($orderId, $context);
        $order = $this->getOrder($orderId, $context);

        $transactions = $order->getTransactions();
        if ($transactions === null || $transactions->count() === 0) {
            return new JsonResponse([
                'type'   => 'about:blank',
                'title'  => 'Conflict',
                'status' => 409,
                'detail' => 'Order has no transactions.',
                'code'   => 'order.no_transactions',
            ], Response::HTTP_CONFLICT);
        }

        $transactionId = $transactions->last()->getId();
        $targetStatus = $this->getRequiredField($request, 'status');

        $this->stateMachineService->transitionPayment($transactionId, $targetStatus, $context);

        return new JsonResponse(['orderId' => $orderId, 'paymentStatus' => $targetStatus], Response::HTTP_OK);
    }

    #[Route(
        path: '/api/order-integration/v1/orders/{orderId}/delivery-status',
        name: 'api.order-integration.orders.delivery-status',
        methods: ['PUT']
    )]
    public function setDeliveryStatus(string $orderId, Request $request, Context $context): JsonResponse
    {
        $this->assertOrderExists($orderId, $context);
        $order = $this->getOrder($orderId, $context);

        $deliveries = $order->getDeliveries();
        if ($deliveries === null || $deliveries->count() === 0) {
            return new JsonResponse([
                'type'   => 'about:blank',
                'title'  => 'Conflict',
                'status' => 409,
                'detail' => 'Order has no deliveries.',
                'code'   => 'order.no_deliveries',
            ], Response::HTTP_CONFLICT);
        }

        $deliveryId = $deliveries->last()->getId();
        $targetStatus = $this->getRequiredField($request, 'status');

        $this->stateMachineService->transitionDelivery($deliveryId, $targetStatus, $context);

        return new JsonResponse(['orderId' => $orderId, 'deliveryStatus' => $targetStatus], Response::HTTP_OK);
    }

    private function assertOrderExists(string $orderId, Context $context): void
    {
        $criteria = new Criteria([$orderId]);
        $order = $this->orderRepository->search($criteria, $context)->first();

        if ($order === null) {
            throw new OrderNotFoundException($orderId);
        }
    }

    private function getOrder(string $orderId, Context $context)
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociations(['transactions', 'deliveries']);

        return $this->orderRepository->search($criteria, $context)->first();
    }

    private function getRequiredField(Request $request, string $field): string
    {
        $data = json_decode($request->getContent(), true);
        if (empty($data[$field])) {
            throw new \InvalidArgumentException(sprintf('Field "%s" is required.', $field));
        }

        return $data[$field];
    }
}
