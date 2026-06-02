<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Service;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Order\OrderPersister;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;

/**
 * Builds a cart from the caller's domain payload and persists it as a
 * Shopware order via the standard checkout pipeline.
 *
 * Returns only the new orderId — mapping to the response shape is the
 * controller's responsibility (it re-loads the order with
 * OrderMapper::REQUIRED_ASSOCIATIONS so the response is complete).
 */
class OrderCreationService
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly OrderPersister $orderPersister,
        private readonly AbstractSalesChannelContextFactory $salesChannelContextFactory,
    ) {}

    public function createOrder(array $data, Context $context): string
    {
        $salesChannelId = $data['salesChannelId'];
        $token = Uuid::randomHex();

        $contextOptions = array_filter([
            SalesChannelContextService::CUSTOMER_ID        => $data['customer']['id'] ?? null,
            SalesChannelContextService::PAYMENT_METHOD_ID  => $data['paymentMethodId'] ?? null,
            SalesChannelContextService::SHIPPING_METHOD_ID => $data['shippingMethodId'] ?? null,
        ]);

        $salesChannelContext = $this->salesChannelContextFactory->create(
            $token,
            $salesChannelId,
            $contextOptions
        );

        $cart = $this->cartService->createNew($token);

        foreach ($data['lineItems'] as $item) {
            $lineItem = new LineItem(
                Uuid::randomHex(),
                LineItem::PRODUCT_LINE_ITEM_TYPE,
                $item['productId'],
                $item['quantity'] ?? 1,
            );
            $lineItem->setRemovable(true);
            $lineItem->setStackable(true);
            $cart->add($lineItem);
        }

        $cart = $this->cartService->recalculate($cart, $salesChannelContext);

        if (!empty($data['customerComment'])) {
            $cart->setCustomerComment($data['customerComment']);
        }

        return $this->orderPersister->persist($cart, $salesChannelContext);
    }
}
