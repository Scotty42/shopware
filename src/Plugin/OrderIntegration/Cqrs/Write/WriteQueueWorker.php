<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs\Write;

/**
 * Drains the write queue: claims a bounded batch, dispatches each command to
 * the handler, and on failure either schedules a backoff retry or dead-letters
 * once attempts are exhausted. The bounded batch + a single worker process (or
 * a few, thanks to SKIP LOCKED) caps the concurrent write pressure on Shopware.
 */
final class WriteQueueWorker
{
    public function __construct(
        private readonly WriteQueueInterface $queue,
        private readonly CommandHandlerInterface $handler,
        private readonly RetryPolicy $retryPolicy,
        private readonly int $batchSize = 20,
    ) {}

    /**
     * Process a single batch.
     *
     * @return array{claimed:int,succeeded:int,retried:int,dead:int}
     */
    public function drainOnce(\DateTimeImmutable $now): array
    {
        $claimed = $this->queue->claim($this->batchSize, $now);

        $succeeded = 0;
        $retried = 0;
        $dead = 0;

        foreach ($claimed as $command) {
            try {
                $result = $this->handler->handle($command);
                $this->queue->complete($command->id, $result, $now);
                $succeeded++;
            } catch (\Throwable $e) {
                if ($this->retryPolicy->shouldRetry($command->attempts, $command->maxAttempts)) {
                    $availableAt = $this->retryPolicy->nextAvailableAt($command->attempts, $now);
                    $this->queue->retryLater($command->id, $e->getMessage(), $availableAt, $now);
                    $retried++;
                } else {
                    $this->queue->deadletter($command->id, $e->getMessage(), $now);
                    $dead++;
                }
            }
        }

        return ['claimed' => count($claimed), 'succeeded' => $succeeded, 'retried' => $retried, 'dead' => $dead];
    }
}
