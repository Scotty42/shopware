<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Exception\InvalidTransitionException;
use Scotty42\OrderIntegration\Service\StateMachineService;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\StateMachineException;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

/**
 * Unit tests for the domain-status -> Shopware-action mapping and the
 * exception translation wrapper. Mocks StateMachineRegistry so no
 * Shopware kernel is required.
 */
final class StateMachineServiceTest extends TestCase
{
    private StateMachineRegistry $registry;
    private StateMachineService $service;
    private Context $context;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(StateMachineRegistry::class);
        $this->service  = new StateMachineService($this->registry);
        $this->context  = $this->createMock(Context::class);
    }

    #[DataProvider('validOrderTransitionProvider')]
    public function testTransitionOrderTranslatesDomainStatusToShopwareAction(
        string $domain,
        string $expectedAction,
    ): void {
        $this->registry
            ->expects(self::once())
            ->method('transition')
            ->with(self::callback(function (Transition $t) use ($expectedAction): bool {
                return $t->getEntityName() === 'order'
                    && $t->getEntityId()   === 'order-id-123'
                    && $t->getTransitionName() === $expectedAction
                    && $t->getStateFieldName() === 'stateId';
            }), self::identicalTo($this->context));

        $this->service->transitionOrder('order-id-123', $domain, $this->context);
    }

    public static function validOrderTransitionProvider(): array
    {
        return [
            'open -> reopen'        => ['open',        'reopen'],
            'in_progress -> process'=> ['in_progress', 'process'],
            'completed -> complete' => ['completed',   'complete'],
            'cancelled -> cancel'   => ['cancelled',   'cancel'],
        ];
    }

    public function testTransitionOrderThrowsForUnknownStatus(): void
    {
        $this->registry->expects(self::never())->method('transition');

        try {
            $this->service->transitionOrder('order-id', 'unknown', $this->context);
            $this->fail('Expected InvalidTransitionException');
        } catch (InvalidTransitionException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('order.invalid_state_transition', $e->getErrorCode());
            $this->assertStringContainsString('unknown', $e->getMessage());
            $this->assertStringContainsString('open', $e->getMessage());
        }
    }

    public function testTransitionPaymentRoutesToOrderTransactionEntity(): void
    {
        $this->registry
            ->expects(self::once())
            ->method('transition')
            ->with(self::callback(fn(Transition $t): bool =>
                $t->getEntityName() === 'order_transaction'
                && $t->getEntityId()   === 'tx-id-456'
                && $t->getTransitionName() === 'paid'
            ));

        $this->service->transitionPayment('tx-id-456', 'paid', $this->context);
    }

    public function testTransitionDeliveryRoutesToOrderDeliveryEntity(): void
    {
        $this->registry
            ->expects(self::once())
            ->method('transition')
            ->with(self::callback(fn(Transition $t): bool =>
                $t->getEntityName() === 'order_delivery'
                && $t->getEntityId()   === 'delivery-id-789'
                && $t->getTransitionName() === 'ship'
            ));

        $this->service->transitionDelivery('delivery-id-789', 'shipped', $this->context);
    }

    public function testLegacyIllegalTransitionExceptionIsMappedTo409(): void
    {
        $shopwareException = $this->createMock(IllegalTransitionException::class);

        $this->registry
            ->method('transition')
            ->willThrowException($shopwareException);

        try {
            $this->service->transitionOrder('order-id', 'completed', $this->context);
            $this->fail('Expected InvalidTransitionException');
        } catch (InvalidTransitionException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('order.invalid_state_transition', $e->getErrorCode());
            $this->assertSame(
                $shopwareException,
                $e->getPrevious(),
                'Original Shopware exception should be preserved in the chain'
            );
        }
    }

    public function testStateMachineExceptionWithIllegalCodeIsMappedTo409(): void
    {
        $shopwareException = $this->createMock(StateMachineException::class);
        $shopwareException->method('getErrorCode')
            ->willReturn('SYSTEM__ILLEGAL_STATE_TRANSITION');

        $this->registry
            ->method('transition')
            ->willThrowException($shopwareException);

        try {
            $this->service->transitionOrder('order-id', 'completed', $this->context);
            $this->fail('Expected InvalidTransitionException');
        } catch (InvalidTransitionException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function testStateMachineExceptionWithOtherCodeBubblesUp(): void
    {
        $shopwareException = $this->createMock(StateMachineException::class);
        $shopwareException->method('getErrorCode')
            ->willReturn('SYSTEM__STATE_MACHINE_NOT_FOUND');

        $this->registry
            ->method('transition')
            ->willThrowException($shopwareException);

        $this->expectException(StateMachineException::class);
        $this->service->transitionOrder('order-id', 'completed', $this->context);
    }
}
