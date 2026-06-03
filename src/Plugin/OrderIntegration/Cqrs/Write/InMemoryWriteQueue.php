<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs\Write;

/**
 * Process-local queue for unit tests and single-process use. The claim()
 * semantics (FIFO, due-only, single-hand) mirror the SKIP LOCKED behaviour of
 * PdoWriteQueue so the worker logic can be exercised without a database.
 */
final class InMemoryWriteQueue implements WriteQueueInterface
{
    /** @var array<string,WriteCommand> */
    private array $commands = [];

    /** @var array<string,string> idempotencyKey => command id */
    private array $byIdempotencyKey = [];

    public function enqueue(WriteCommand $command): WriteCommand
    {
        if ($command->idempotencyKey !== null && isset($this->byIdempotencyKey[$command->idempotencyKey])) {
            return $this->commands[$this->byIdempotencyKey[$command->idempotencyKey]];
        }

        $command->status = WriteCommand::STATUS_QUEUED;
        $command->createdAt ??= new \DateTimeImmutable();
        $command->updatedAt = $command->createdAt;

        $this->commands[$command->id] = $command;
        if ($command->idempotencyKey !== null) {
            $this->byIdempotencyKey[$command->idempotencyKey] = $command->id;
        }

        return $command;
    }

    public function claim(int $batchSize, \DateTimeImmutable $now): array
    {
        $claimed = [];
        foreach ($this->commands as $command) {
            if (count($claimed) >= $batchSize) {
                break;
            }
            if ($command->status !== WriteCommand::STATUS_QUEUED) {
                continue;
            }
            if ($command->availableAt !== null && $command->availableAt > $now) {
                continue;
            }

            $command->status = WriteCommand::STATUS_IN_PROGRESS;
            $command->attempts++;
            $command->updatedAt = $now;
            $claimed[] = $command;
        }

        return $claimed;
    }

    public function complete(string $id, array $result, \DateTimeImmutable $now): void
    {
        $c = $this->require($id);
        $c->status = WriteCommand::STATUS_SUCCEEDED;
        $c->result = $result;
        $c->lastError = null;
        $c->updatedAt = $now;
    }

    public function retryLater(string $id, string $error, \DateTimeImmutable $availableAt, \DateTimeImmutable $now): void
    {
        $c = $this->require($id);
        $c->status = WriteCommand::STATUS_QUEUED;
        $c->lastError = $error;
        $c->availableAt = $availableAt;
        $c->updatedAt = $now;
    }

    public function deadletter(string $id, string $error, \DateTimeImmutable $now): void
    {
        $c = $this->require($id);
        $c->status = WriteCommand::STATUS_DEAD;
        $c->lastError = $error;
        $c->updatedAt = $now;
    }

    public function get(string $id): ?WriteCommand
    {
        return $this->commands[$id] ?? null;
    }

    public function depth(): int
    {
        $n = 0;
        foreach ($this->commands as $c) {
            if ($c->status === WriteCommand::STATUS_QUEUED) {
                $n++;
            }
        }

        return $n;
    }

    private function require(string $id): WriteCommand
    {
        if (!isset($this->commands[$id])) {
            throw new \RuntimeException(sprintf('WriteCommand "%s" not found.', $id));
        }

        return $this->commands[$id];
    }
}
