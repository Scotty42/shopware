<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs\Write;

/**
 * Production write queue backed by a relational DB (PostgreSQL; MySQL 8 works
 * with the same SKIP LOCKED syntax).
 *
 * The claim() query is the heart of safe parallel operation:
 *
 *   UPDATE ... WHERE id IN (
 *     SELECT id ... WHERE status='queued' AND due
 *     ORDER BY created_at LIMIT :batch
 *     FOR UPDATE SKIP LOCKED
 *   ) RETURNING *;
 *
 * SKIP LOCKED lets N worker processes claim disjoint batches concurrently
 * without blocking each other and without ever handing the same command twice.
 *
 * Schema: see docs/infrastructure-setup.md (table order_write_queue).
 */
final class PdoWriteQueue implements WriteQueueInterface
{
    public function __construct(private readonly \Scotty42\OrderIntegration\Cqrs\PdoConnectionProvider $connection)
    {
    }

    public function enqueue(WriteCommand $command): WriteCommand
    {
        $now = new \DateTimeImmutable();
        $command->createdAt ??= $now;
        $command->updatedAt = $now;

        $sql = 'INSERT INTO order_write_queue
                    (id, type, payload, idempotency_key, status, attempts, max_attempts, available_at, created_at, updated_at)
                VALUES
                    (:id, :type, :payload, :idem, :status, :attempts, :max_attempts, :available_at, :created_at, :updated_at)
                ON CONFLICT (idempotency_key) DO NOTHING';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'id'           => $command->id,
            'type'         => $command->type,
            'payload'      => json_encode($command->payload),
            'idem'         => $command->idempotencyKey,
            'status'       => WriteCommand::STATUS_QUEUED,
            'attempts'     => $command->attempts,
            'max_attempts' => $command->maxAttempts,
            'available_at' => $command->availableAt?->format('c'),
            'created_at'   => $command->createdAt->format('c'),
            'updated_at'   => $command->updatedAt->format('c'),
        ]);

        if ($stmt->rowCount() === 0 && $command->idempotencyKey !== null) {
            // Conflict: an equal idempotency key already exists — return it.
            $existing = $this->findByIdempotencyKey($command->idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }
        }

        return $command;
    }

    public function claim(int $batchSize, \DateTimeImmutable $now): array
    {
        $sql = 'UPDATE order_write_queue q
                   SET status = :in_progress, attempts = attempts + 1, updated_at = :now
                 WHERE q.id IN (
                     SELECT id FROM order_write_queue
                      WHERE status = :queued
                        AND (available_at IS NULL OR available_at <= :now)
                      ORDER BY created_at ASC
                      LIMIT :batch
                      FOR UPDATE SKIP LOCKED
                 )
             RETURNING *';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->bindValue('in_progress', WriteCommand::STATUS_IN_PROGRESS);
        $stmt->bindValue('queued', WriteCommand::STATUS_QUEUED);
        $stmt->bindValue('now', $now->format('c'));
        $stmt->bindValue('batch', $batchSize, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map([$this, 'mapRow'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function complete(string $id, array $result, \DateTimeImmutable $now): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE order_write_queue
                SET status = :s, result = :result, last_error = NULL, updated_at = :now
              WHERE id = :id'
        );
        $stmt->execute([
            's'      => WriteCommand::STATUS_SUCCEEDED,
            'result' => json_encode($result),
            'now'    => $now->format('c'),
            'id'     => $id,
        ]);
    }

    public function retryLater(string $id, string $error, \DateTimeImmutable $availableAt, \DateTimeImmutable $now): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE order_write_queue
                SET status = :s, last_error = :err, available_at = :avail, updated_at = :now
              WHERE id = :id'
        );
        $stmt->execute([
            's'     => WriteCommand::STATUS_QUEUED,
            'err'   => $error,
            'avail' => $availableAt->format('c'),
            'now'   => $now->format('c'),
            'id'    => $id,
        ]);
    }

    public function deadletter(string $id, string $error, \DateTimeImmutable $now): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE order_write_queue SET status = :s, last_error = :err, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([
            's'   => WriteCommand::STATUS_DEAD,
            'err' => $error,
            'now' => $now->format('c'),
            'id'  => $id,
        ]);
    }

    public function get(string $id): ?WriteCommand
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM order_write_queue WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? $this->mapRow($row) : null;
    }

    public function depth(): int
    {
        $stmt = $this->connection->pdo()->prepare('SELECT COUNT(*) FROM order_write_queue WHERE status = :s');
        $stmt->execute(['s' => WriteCommand::STATUS_QUEUED]);

        return (int) $stmt->fetchColumn();
    }

    private function findByIdempotencyKey(string $key): ?WriteCommand
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM order_write_queue WHERE idempotency_key = :k');
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? $this->mapRow($row) : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function mapRow(array $row): WriteCommand
    {
        return new WriteCommand(
            id: (string) $row['id'],
            type: (string) $row['type'],
            payload: $this->decode($row['payload']) ?? [],
            idempotencyKey: $row['idempotency_key'] !== null ? (string) $row['idempotency_key'] : null,
            status: (string) $row['status'],
            attempts: (int) $row['attempts'],
            maxAttempts: (int) $row['max_attempts'],
            availableAt: $this->date($row['available_at']),
            lastError: $row['last_error'] !== null ? (string) $row['last_error'] : null,
            result: $this->decode($row['result']),
            createdAt: $this->date($row['created_at']),
            updatedAt: $this->date($row['updated_at']),
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decode(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return new \DateTimeImmutable($value);
    }
}
