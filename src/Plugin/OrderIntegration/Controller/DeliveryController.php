<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Controller;

use Scotty42\OrderIntegration\Exception\OrderNotFoundException;
use Scotty42\OrderIntegration\Service\StateMachineService;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Loader\InitialStateIdLoader;
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
        private readonly EntityRepository $orderAddressRepository,
        private readonly StateMachineService $stateMachineService,
        private readonly InitialStateIdLoader $initialStateIdLoader,
        private readonly EntityRepository $countryRepository,
        private readonly EntityRepository $salutationRepository,
    ) {}

    #[Route(
        path: '/api/order-integration/v1/orders/{orderId}/deliveries',
        name: 'api.order-integration.deliveries.create',
        methods: ['POST']
    )]
    public function create(string $orderId, Request $request, Context $context): JsonResponse
    {
        $order = $this->findOrderWithDetails($orderId, $context);
        $data = json_decode($request->getContent(), true) ?? [];

        $shippingAddressId = $order->getBillingAddressId();

        if (!empty($data['shippingAddress'])) {
            $newAddressId = Uuid::randomHex();
            $address = $data['shippingAddress'];
            $this->orderAddressRepository->create([[
                'id'             => $newAddressId,
                'orderId'        => $orderId,
                'orderVersionId' => $order->getVersionId(),
                'firstName'      => $address['firstName'] ?? '',
                'lastName'       => $address['lastName'] ?? '',
                'street'         => $address['street'] ?? '',
                'zipcode'        => $address['zipcode'] ?? '',
                'city'           => $address['city'] ?? '',
                'countryId'      => $this->resolveCountryId($address['countryCode'] ?? 'DE', $context),
                'salutationId'   => $this->resolveSalutationId($context),
            ]], $context);
            $shippingAddressId = $newAddressId;
        }

        $initialStateId = $this->initialStateIdLoader->get(OrderDeliveryStates::STATE_MACHINE);
        $now = new \DateTimeImmutable();
        $deliveryId = Uuid::randomHex();

        $this->orderDeliveryRepository->create([[
            'id'                             => $deliveryId,
            'orderId'                        => $orderId,
            'orderVersionId'                 => $order->getVersionId(),
            'shippingOrderAddressId'         => $shippingAddressId,
            'shippingOrderAddressVersionId'  => $order->getVersionId(),
            'shippingMethodId'               => $data['shippingMethodId'] ?? $order->getDeliveries()?->first()?->getShippingMethodId(),
            'stateId'                        => $initialStateId,
            'trackingCodes'                  => $data['trackingCodes'] ?? [],
            'shippingDateEarliest'           => $data['plannedShipDate'] ?? $now->format(\DateTimeInterface::ATOM),
            'shippingDateLatest'             => $data['expectedDeliveryDate'] ?? $now->modify('+7 days')->format(\DateTimeInterface::ATOM),
            'shippingCosts'                  => [
                'unitPrice'       => 0,
                'totalPrice'      => 0,
                'quantity'        => 1,
                'calculatedTaxes' => [],
                'taxRules'        => [],
            ],
        ]], $context);

        $delivery = $this->findDelivery($deliveryId, $orderId, $context);

        return new JsonResponse($this->mapDelivery($delivery), Response::HTTP_CREATED);
    }

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
        $this->findDelivery($deliveryId, $orderId, $context);

        $data = json_decode($request->getContent(), true) ?? [];
        $update = ['id' => $deliveryId];

        if (array_key_exists('trackingCodes', $data)) {
            $update['trackingCodes'] = $data['trackingCodes'];
        }
        if (array_key_exists('shippingMethodId', $data)) {
            $update['shippingMethodId'] = $data['shippingMethodId'];
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

    private function findOrderWithDetails(string $orderId, Context $context)
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociations(['deliveries', 'addresses']);
        $order = $this->orderRepository->search($criteria, $context)->first();
        if ($order === null) {
            throw new OrderNotFoundException($orderId);
        }
        return $order;
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

    private function resolveCountryId(string $iso, Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('iso', $iso));
        $result = $this->countryRepository->search($criteria, $context)->first();
        return $result?->getId() ?? '';
    }

    private function resolveSalutationId(Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salutationKey', 'not_specified'));
        $result = $this->salutationRepository->search($criteria, $context)->first();
        return $result?->getId() ?? '';
    }

    private function mapDelivery($delivery): array
    {
        return [
            'id'              => $delivery->getId(),
            'orderId'         => $delivery->getOrderId(),
            'status'          => $delivery->getStateMachineState()?->getTechnicalName(),
            'trackingCodes'   => $delivery->getTrackingCodes() ?? [],
            'shippingMethod'  => $delivery->getShippingMethod()?->getName(),
            'shippingAddress' => $this->mapAddress($delivery->getShippingOrderAddress()),
            'createdAt'       => $delivery->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt'       => $delivery->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
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
