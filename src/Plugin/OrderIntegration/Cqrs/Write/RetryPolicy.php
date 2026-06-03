<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs\Write;

/**
 * Exponential backoff with a cap and optional jitter. Pure and deterministic
 * when a fixed jitter function is injected, so it is fully unit-testable; the
 * production binding injects a randomising jitter to avoid thundering-herd
 * retries.
 */
final class RetryPolicy
{
    /** @var callable(int):int */
    private $jitter;

    /**
     * @param callable(int):int|null $jitter maps a computed delay to a possibly
     *        randomised delay. Defaults to identity (no jitter).
     */
    public function __construct(
        private readonly int $baseDelaySeconds = 5,
        private readonly int $maxDelaySeconds = 3600,
        ?callable $jitter = null,
    ) {
        $this->jitter = $jitter ?? static fn (int $delay): int => $delay;
    }

    public function shouldRetry(int $attempts, int $maxAttempts): bool
    {
        return $attempts < $maxAttempts;
    }

    /**
     * Delay before the next attempt. $attempt is the number of attempts already
     * made (1 after the first failure).
     */
    public function nextDelaySeconds(int $attempt): int
    {
        $attempt = max(1, $attempt);
        $raw = $this->baseDelaySeconds * (2 ** ($attempt - 1));
        $capped = (int) min($this->maxDelaySeconds, $raw);

        return max(0, ($this->jitter)($capped));
    }

    public function nextAvailableAt(int $attempt, \DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(sprintf('+%d seconds', $this->nextDelaySeconds($attempt)));
    }
}
