<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Cqrs\Write;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Cqrs\Write\ShopwareCommandHandler;
use Scotty42\OrderIntegration\Cqrs\Write\WriteCommand;
use Scotty42\OrderIntegration\Service\OrderCreationService;

class ShopwareCommandHandlerTest extends TestCase
{
    public function testHandleOrderCreateCallsOrderCreationServiceAndReturnsOrderId(): void
    {
        $creationService = $this->createMock(OrderCreationService::class);
        $creationService
            ->expects(self::once())
            ->method('createOrder')
            ->with(['salesChannelId' => str_repeat('a', 32)], self::anything())
            ->willReturn(str_repeat('b', 32));

        $handler = new ShopwareCommandHandler($creationService);

        $command = new WriteCommand(
            id: str_repeat('0', 32),
            type: WriteCommand::TYPE_ORDER_CREATE,
            payload: ['salesChannelId' => str_repeat('a', 32)],
        );

        $result = $handler->handle($command);

        self::assertSame(str_repeat('b', 32), $result['orderId']);
    }

    public function testHandleUnknownTypeThrowsRuntimeException(): void
    {
        $creationService = $this->createMock(OrderCreationService::class);
        $creationService->expects(self::never())->method('createOrder');

        $handler = new ShopwareCommandHandler($creationService);

        $command = new WriteCommand(
            id: str_repeat('0', 32),
            type: 'order.unknown',
            payload: [],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/order\.unknown/');
        $handler->handle($command);
    }
}
