<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs\Write;

use Scotty42\OrderIntegration\Service\OrderCreationService;
use Shopware\Core\Framework\Context;

/**
 * Production handler: applies a WriteCommand to Shopware via the in-process
 * services. Runs inside the worker (CLI), so a Shopware kernel is available.
 */
final class ShopwareCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly OrderCreationService $orderCreationService,
    ) {}

    public function handle(WriteCommand $command): array
    {
        $context = Context::createDefaultContext();

        return match ($command->type) {
            WriteCommand::TYPE_ORDER_CREATE => [
                'orderId' => $this->orderCreationService->createOrder($command->payload, $context),
            ],
            default => throw new \RuntimeException(sprintf('Unsupported command type "%s".', $command->type)),
        };
    }
}
