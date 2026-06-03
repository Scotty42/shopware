<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs\Write;

/**
 * Decides when the facade should shed load (HTTP 503 + Retry-After) instead of
 * dragging Shopware down: when the queue is too deep or the downstream error
 * rate is too high (concept §2 / order-api-concept.md §2 "Backpressure").
 *
 * Pure — unit-testable.
 */
final class BackpressurePolicy
{
    public function __construct(
        private readonly int $maxQueueDepth = 10000,
        private readonly float $maxErrorRate = 0.5,
    ) {}

    public function shouldShed(int $queueDepth, float $errorRate): bool
    {
        return $queueDepth >= $this->maxQueueDepth || $errorRate >= $this->maxErrorRate;
    }

    /**
     * Retry-After hint (seconds) scaled by how far over the depth limit we are.
     */
    public function retryAfterSeconds(int $queueDepth): int
    {
        if ($queueDepth < $this->maxQueueDepth) {
            return 1;
        }

        $overflowRatio = $queueDepth / max(1, $this->maxQueueDepth);

        return (int) min(300, ceil($overflowRatio * 5));
    }
}
