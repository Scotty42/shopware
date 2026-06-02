<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Service;

/**
 * Decides whether a soft-delete (DELETE /orders/{id}) is a no-op.
 *
 * A soft-delete transitions the order to `cancelled`. It must be idempotent
 * ONLY when the order is already cancelled — re-deleting a cancelled order
 * returns 204. For any other current state the transition is attempted and an
 * illegal transition (e.g. from `completed`) must surface as 409, NOT be
 * silently swallowed as success.
 */
final class SoftDeletePolicy
{
    public const CANCELLED = 'cancelled';

    public static function isAlreadyCancelled(?string $currentStatus): bool
    {
        return $currentStatus === self::CANCELLED;
    }
}
