<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs\Write;

/**
 * Durable, concurrency-safe write queue. The production implementation
 * (PdoWriteQueue) claims work with SELECT ... FOR UPDATE SKIP LOCKED so several
 * workers can drain in parallel without handing the same command twice — the
 * core guarantee for real-world parallel access.
 */
interface WriteQueueInterface
{
    /**
     * Enqueue a command. If an idempotencyKey is set and already present, the
     * existing command is returned instead of creating a duplicate.
     */
    public function enqueue(WriteCommand $command): WriteCommand;

    /**
     * Atomically claim up to $batchSize due commands (availableAt <= now),
     * marking them in_progress. Implementations MUST NOT hand the same command
     * to two concurrent callers.
     *
     * @return list<WriteCommand>
     */
    public function claim(int $batchSize, \DateTimeImmutable $now): array;

    /**
     * @param array<string,mixed> $result
     */
    public function complete(string $id, array $result, \DateTimeImmutable $now): void;

    /** Return a failed command to the queue for a later retry. */
    public function retryLater(string $id, string $error, \DateTimeImmutable $availableAt, \DateTimeImmutable $now): void;

    /** Move a command to the dead-letter state (exhausted retries). */
    public function deadletter(string $id, string $error, \DateTimeImmutable $now): void;

    public function get(string $id): ?WriteCommand;

    /** Number of commands currently queued (available or waiting on backoff). */
    public function depth(): int;

    /**
     * Delete terminal rows of the given status whose updated_at is older than
     * the cutoff (retention cleanup). Only terminal statuses (succeeded, dead)
     * should ever be passed; queued/in_progress are active work.
     *
     * @return int number of rows removed
     */
    public function purge(string $status, \DateTimeImmutable $olderThan): int;
}
