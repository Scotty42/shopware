<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Controller;

use Scotty42\OrderIntegration\Erp\ErpSyncPolicy;
use Scotty42\OrderIntegration\Erp\ErpSyncService;
use Scotty42\OrderIntegration\Exception\ValidationException;
use Scotty42\OrderIntegration\Service\OrderMapper;
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

/**
 * Pull-based ERP integration (concept docs/erp-pull-sync-concept.md).
 *
 * The ERP iPaaS pulls orders in a given state that it has not yet forwarded,
 * then acknowledges them so they drop out of the pull queue.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class ErpSyncController extends AbstractController
{
    private const VALID_STATUSES = ['open', 'in_progress', 'completed', 'cancelled'];
    private const ID_PATTERN = '/^([0-9a-f]{32}|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})$/';

    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly ErpSyncService $erpSyncService,
        private readonly OrderMapper $orderMapper,
    ) {}

    /**
     * Pull queue: orders not yet acknowledged by the ERP, optionally filtered
     * by domain status (e.g. ?status=cancelled). FIFO (oldest first), cursor
     * paginated.
     */
    #[Route(
        path: '/api/order-integration/v1/erp/orders',
        name: 'api.order-integration.erp.orders.pull',
        methods: ['GET']
    )]
    public function pull(Request $request, Context $context): JsonResponse
    {
        $errors = [];

        $status = $request->query->get('status');
        if ($status !== null && !in_array($status, self::VALID_STATUSES, true)) {
            $errors[] = ['pointer' => '/status', 'code' => 'invalid_status', 'message' => sprintf('status must be one of: %s', implode(', ', self::VALID_STATUSES))];
        }

        $limitRaw = $request->query->get('limit');
        if ($limitRaw !== null && (filter_var($limitRaw, FILTER_VALIDATE_INT) === false || (int) $limitRaw < 1 || (int) $limitRaw > 200)) {
            $errors[] = ['pointer' => '/limit', 'code' => 'invalid_limit', 'message' => 'limit must be an integer between 1 and 200'];
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $limit = min($request->query->getInt('limit', 50), 200);

        $criteria = new Criteria();
        $criteria->addAssociations(OrderMapper::REQUIRED_ASSOCIATIONS);
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING)); // FIFO
        $criteria->addSorting(new FieldSorting('id', FieldSorting::ASCENDING));
        $criteria->setLimit($limit + 1);

        // not yet acknowledged by the ERP
        $criteria->addFilter(new EqualsFilter('customFields.' . ErpSyncPolicy::FIELD, null));

        if ($status !== null) {
            $criteria->addFilter(new EqualsFilter('stateMachineState.technicalName', $status));
        }

        if ($cursorRaw = $request->query->get('cursor')) {
            $cursor = json_decode(base64_decode((string) $cursorRaw), true);
            if (is_array($cursor) && !empty($cursor['createdAt']) && !empty($cursor['id'])) {
                $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                    new RangeFilter('createdAt', [RangeFilter::GT => $cursor['createdAt']]),
                    new MultiFilter(MultiFilter::CONNECTION_AND, [
                        new EqualsFilter('createdAt', $cursor['createdAt']),
                        new RangeFilter('id', [RangeFilter::GT => $cursor['id']]),
                    ]),
                ]));
            }
        }

        $result = $this->orderRepository->search($criteria, $context);
        $elements = array_values($result->getElements());

        $hasMore = count($elements) > $limit;
        if ($hasMore) {
            array_pop($elements);
        }

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
            'items' => array_map(fn(OrderEntity $order) => $this->orderMapper->mapOrder($order), $elements),
            'page'  => [
                'limit'      => $limit,
                'nextCursor' => $nextCursor,
            ],
        ]);
    }

    /**
     * Acknowledge a batch of orders as forwarded to the ERP. Idempotent: ids
     * already synced keep their first timestamp; unknown ids are reported.
     */
    #[Route(
        path: '/api/order-integration/v1/erp/orders/acknowledge',
        name: 'api.order-integration.erp.orders.acknowledge',
        methods: ['POST']
    )]
    public function acknowledge(Request $request, Context $context): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || !isset($data['orderIds']) || !is_array($data['orderIds']) || $data['orderIds'] === []) {
            throw new ValidationException([
                ['pointer' => '/orderIds', 'code' => 'required', 'message' => 'orderIds must be a non-empty array'],
            ]);
        }

        if (count($data['orderIds']) > ErpSyncService::MAX_BATCH) {
            throw new ValidationException([
                ['pointer' => '/orderIds', 'code' => 'batch_too_large', 'message' => sprintf('at most %d orderIds per request', ErpSyncService::MAX_BATCH)],
            ]);
        }

        $errors = [];
        $normalized = [];
        foreach ($data['orderIds'] as $i => $id) {
            if (!is_string($id) || !preg_match(self::ID_PATTERN, $id)) {
                $errors[] = ['pointer' => "/orderIds/{$i}", 'code' => 'invalid_id', 'message' => 'must be a 32-char hex id or a canonical UUID'];
                continue;
            }
            $normalized[] = strtolower(str_replace('-', '', $id));
        }

        // optional: map of shopwareId => erpOrderId (the ERP's own order number)
        $rawErpOrderIds = $data['erpOrderIds'] ?? null;
        $erpOrderIds = [];
        if ($rawErpOrderIds !== null) {
            if (!is_array($rawErpOrderIds)) {
                $errors[] = ['pointer' => '/erpOrderIds', 'code' => 'invalid_type', 'message' => 'erpOrderIds must be an object mapping order ids to ERP order ids'];
            } else {
                foreach ($rawErpOrderIds as $shopwareId => $erpId) {
                    if (!is_string($shopwareId) || !preg_match(self::ID_PATTERN, $shopwareId)) {
                        $errors[] = ['pointer' => "/erpOrderIds/{$shopwareId}", 'code' => 'invalid_id', 'message' => 'key must be a 32-char hex id or a canonical UUID'];
                        continue;
                    }
                    if (!is_string($erpId) || $erpId === '' || strlen($erpId) > 100) {
                        $errors[] = ['pointer' => "/erpOrderIds/{$shopwareId}", 'code' => 'invalid_erp_order_id', 'message' => 'ERP order id must be a non-empty string of at most 100 characters'];
                        continue;
                    }
                    $erpOrderIds[strtolower(str_replace('-', '', $shopwareId))] = $erpId;
                }
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $summary = $this->erpSyncService->acknowledge($normalized, new \DateTimeImmutable(), $context, $erpOrderIds);

        return new JsonResponse([
            'acknowledged'  => $summary['acknowledged'],
            'alreadySynced' => $summary['alreadySynced'],
            'notFound'      => $summary['notFound'],
            'counts'        => [
                'acknowledged'  => count($summary['acknowledged']),
                'alreadySynced' => count($summary['alreadySynced']),
                'notFound'      => count($summary['notFound']),
            ],
        ], Response::HTTP_OK);
    }
}
