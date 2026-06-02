<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Controller;

use Scotty42\OrderIntegration\Exception\OrderNotFoundException;
use Scotty42\OrderIntegration\Service\StateMachineService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class DeliveryController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderDeliveryRepository,
        private readonly StateMachineService $stateMachineService,
    ) {}

    #[Route(
        path: '/api/order-integration/v1/orders/{orderId}/deliveries',
        name: 'api.order-integration.deliveries.list',
        methods: ['GET']
    )]
    public function list(string $orderId, Context $context): JsonResponse
    {
        $this->assertOrderExists($orderId, $context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addAssociations([
            'stateMachineState',
            'shippingOrderAddress.country',
            'shippingMethod',
            'positions.orderLineItem',
        ]);

        $deliveries = $this->orderDeliveryRepository->search($criteria, $context);

        return new JsonResponse([
            'items' => array_values(array_map(
                fn($d) => $this->mapDelivery($d),
                $deliveries->getElements()
            )),
        ]);
    }

    #[Route(
        path: '/api/order-integration/v1/orders/{orderId}/deliveries/{deliveryId}',
        name: 'api.order-integration.deliveries.get',
        methods: ['GET']
    )]
    public function get(string $orderId, string $deliveryId, Context $context): JsonResponse
    {
        $this->assertOrderExists($orderId, $context);
        $delivery = $this->findDelivery($deliveryId, $orderId, $context);

        return new JsonResponse($this->mapDelivery($delivery));
    }

    #[Route(
        path: '/api/order-integration/v1/orders/{orderId}/deliveries/{deliveryId}',
        name: 'api.order-integration.deliveries.patch',
        methods: ['PATCH']
    )]
    public function patch(string $orderId, string $deliveryId, Request $request, Context $context): JsonResponse
    {
        $this->assertOrderExists($orderId, $context);
        $delivery = $this->findDelivery($deliveryId, $orderId, $context);

        $data = json_decode($request->getContent(), true) ?? [];
        $update = ['id' => $deliveryId];

        if (array_key_exists('trackingCodes', $data)) {
            $update['trackingCodes'] = $data['trackingCodes'];
        }
        if (array_key_exists('shippingMethod', $data)) {
            $update['shippingMethodId'] = $data['shippingMethod'];
        }

        if (count($update) > 1) {
            $this->orderDeliveryRepository->update([$update], $context);
        }

        $delivery = $this->findDelivery($deliveryId, $orderId, $context);

        return new JsonResponse($this->mapDelivery($delivery));
    }

    #[Route(
        path: '/api/order-integration/v1/orders/{orderId}/deliveries/{deliveryId}/status',
        name: 'api.order-integration.deliveries.status',
        methods: ['PUT']
    )]
    public function setStatus(string $orderId, string $deliveryId, Request $request, Context $context): JsonResponse
    {
        $this->assertOrderExists($orderId, $context);
        $this->findDelivery($deliveryId, $orderId, $context);

        $data = json_decode($request->getContent(), true) ?? [];
        $targetStatus = $data['status'] ?? null;

        if (empty($targetStatus)) {
            return new JsonResponse([
                'type'   => 'about:blank',
                'title'  => 'Unprocessable Content',
                'status' => 422,
                'detail' => 'Validation failed.',
                'code'   => 'order.validation_failed',
                'errors' => [['pointer' => '/status', 'code' => 'required', 'message' => 'status is required']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->stateMachineService->transitionDelivery($deliveryId, $targetStatus, $context);

        $delivery = $this->findDelivery($deliveryId, $orderId, $context);

        return new JsonResponse($this->mapDelivery($delivery));
    }

    private function assertOrderExists(string $orderId, Context $context): void
    {
        $criteria = new Criteria([$orderId]);
        $order = $this->orderRepository->search($criteria, $context)->first();

        if ($order === null) {
            throw new OrderNotFoundException($orderId);
        }
    }

    private function findDelivery(string $deliveryId, string $orderId, Context $context)
    {
        $criteria = new Criteria([$deliveryId]);
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addAssociations([
            'stateMachineState',
            'shippingOrderAddress.country',
            'shippingMethod',
            'positions.orderLineItem',
        ]);

        $delivery = $this->orderDeliveryRepository->search($criteria, $context)->first();

        if ($delivery === null) {
            throw new OrderNotFoundException($deliveryId);
        }

        return $delivery;
    }

    private function mapDelivery($delivery): array
    {
        return [
            'id'             => $delivery->getId(),
            'orderId'        => $delivery->getOrderId(),
            'status'         => $delivery->getStateMachineState()?->getTechnicalName(),
            'trackingCodes'  => $delivery->getTrackingCodes() ?? [],
            'shippingMethod' => $delivery->getShippingMethod()?->getName(),
            'shippingAddress' => $this->mapAddress($delivery->getShippingOrderAddress()),
            'createdAt'      => $delivery->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt'      => $delivery->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function mapAddress($address): ?array
    {
        if ($address === null) {
            return null;
        }

        return [
            'firstName'   => $address->getFirstName(),
            'lastName'    => $address->getLastName(),
            'street'      => $address->getStreet(),
            'zipcode'     => $address->getZipcode(),
            'city'        => $address->getCity(),
            'countryCode' => $address->getCountry()?->getIso(),
        ];
    }
}
