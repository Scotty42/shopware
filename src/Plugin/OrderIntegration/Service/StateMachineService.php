<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Service;

use Scotty42\OrderIntegration\Exception\InvalidTransitionException;
use Shopware\Core\Framework\Context;
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
        $action = self::ORDER_TRANSITIONS[$targetStatus] ?? null;

        if ($action === null) {
            throw new InvalidTransitionException(
                'order',
                $targetStatus,
                array_keys(self::ORDER_TRANSITIONS)
            );
        }

        $this->stateMachineRegistry->transition(
            new Transition('order', $orderId, $action, 'stateId'),
            $context
        );
    }

    public function transitionPayment(string $orderId, string $targetStatus, Context $context): void
    {
        $action = self::PAYMENT_TRANSITIONS[$targetStatus] ?? null;

        if ($action === null) {
            throw new InvalidTransitionException(
                'payment',
                $targetStatus,
                array_keys(self::PAYMENT_TRANSITIONS)
            );
        }

        // Payment transition requires the transaction ID
        $this->stateMachineRegistry->transition(
            new Transition('order_transaction', $orderId, $action, 'stateId'),
            $context
        );
    }

    public function transitionDelivery(string $orderId, string $targetStatus, Context $context): void
    {
        $action = self::DELIVERY_TRANSITIONS[$targetStatus] ?? null;

        if ($action === null) {
            throw new InvalidTransitionException(
                'delivery',
                $targetStatus,
                array_keys(self::DELIVERY_TRANSITIONS)
            );
        }

        $this->stateMachineRegistry->transition(
            new Transition('order_delivery', $orderId, $action, 'stateId'),
            $context
        );
    }
}
