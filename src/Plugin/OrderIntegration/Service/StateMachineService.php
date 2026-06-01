<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Service;

use Scotty42\OrderIntegration\Exception\InvalidTransitionException;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\StateMachineException;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;

class StateMachineService
{
    // Domain status → Shopware action name
    private const ORDER_TRANSITIONS = [
        'in_progress' => 'process',
        'completed'   => 'complete',
        'cancelled'   => 'cancel',
        'open'        => 'reopen',
    ];

    private const PAYMENT_TRANSITIONS = [
        'paid'               => 'paid',
        'authorized'         => 'authorize',
        'refunded'           => 'refund',
        'refunded_partially' => 'refund_partially',
        'failed'             => 'fail',
        'open'               => 'reopen',
        'reminded'           => 'remind',
    ];

    private const DELIVERY_TRANSITIONS = [
        'shipped'            => 'ship',
        'shipped_partially'  => 'ship_partially',
        'returned'           => 'return',
        'returned_partially' => 'return_partially',
        'open'               => 'reopen',
        'cancelled'          => 'cancel',
    ];

    public function __construct(
        private readonly StateMachineRegistry $stateMachineRegistry,
    ) {}

    public function transitionOrder(string $orderId, string $targetStatus, Context $context): void
    {
        $action = $this->resolveAction(self::ORDER_TRANSITIONS, $targetStatus, 'order');
        $this->safeTransition('order', $orderId, $action, $targetStatus, 'order', self::ORDER_TRANSITIONS, $context);
    }

    public function transitionPayment(string $transactionId, string $targetStatus, Context $context): void
    {
        $action = $this->resolveAction(self::PAYMENT_TRANSITIONS, $targetStatus, 'payment');
        $this->safeTransition('order_transaction', $transactionId, $action, $targetStatus, 'payment', self::PAYMENT_TRANSITIONS, $context);
    }

    public function transitionDelivery(string $deliveryId, string $targetStatus, Context $context): void
    {
        $action = $this->resolveAction(self::DELIVERY_TRANSITIONS, $targetStatus, 'delivery');
        $this->safeTransition('order_delivery', $deliveryId, $action, $targetStatus, 'delivery', self::DELIVERY_TRANSITIONS, $context);
    }

    /**
     * @param array<string,string> $map
     */
    private function resolveAction(array $map, string $targetStatus, string $machine): string
    {
        $action = $map[$targetStatus] ?? null;
        if ($action === null) {
            throw new InvalidTransitionException(
                $machine,
                $targetStatus,
                array_keys($map)
            );
        }

        return $action;
    }

    /**
     * Run a Shopware state machine transition and translate Shopware's
     * "valid name but not allowed from current state" exception into our
     * own InvalidTransitionException (→ 409 via ExceptionSubscriber).
     *
     * Without this wrap, callers see a 500 when they request a known but
     * unreachable target (e.g. `completed` from `cancelled`). Shopware
     * 6.7 uses StateMachineException::illegalStateTransition() in newer
     * code paths and the older IllegalTransitionException in older ones;
     * we catch both.
     *
     * @param array<string,string> $map
     */
    private function safeTransition(
        string $entityName,
        string $entityId,
        string $action,
        string $targetStatus,
        string $machine,
        array $map,
        Context $context,
    ): void {
        try {
            $this->stateMachineRegistry->transition(
                new Transition($entityName, $entityId, $action, 'stateId'),
                $context
            );
        } catch (IllegalTransitionException | StateMachineException $e) {
            // StateMachineException covers multiple codes — only the
            // illegal-transition variant maps to 409. Anything else
            // (unknown state machine, missing initial state, etc.) is a
            // real server error and should bubble up.
            if ($e instanceof StateMachineException
                && $e->getErrorCode() !== 'SYSTEM__ILLEGAL_STATE_TRANSITION'
            ) {
                throw $e;
            }

            throw new InvalidTransitionException(
                $machine,
                $targetStatus,
                array_keys($map),
                $e
            );
        }
    }
}
