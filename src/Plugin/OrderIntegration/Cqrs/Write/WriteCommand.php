<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs\Write;

/**
 * A durable, retryable unit of work in the write queue. Mutating API calls are
 * recorded as a WriteCommand and dispatched to Shopware by a bounded worker
 * pool, protecting Shopware from raw parallel write load (concept
 * docs/cqrs-write-queue-concept.md / order-api-concept.md §2 Option C).
 */
final class WriteCommand
{
    public const STATUS_QUEUED      = 'queued';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUCCEEDED   = 'succeeded';
    public const STATUS_DEAD        = 'dead';

    /** Supported command types. */
    public const TYPE_ORDER_CREATE = 'order.create';
    public const TYPE_ORDER_PATCH  = 'order.patch';
    public const TYPE_ORDER_STATUS = 'order.status';

    /**
     * @param array<string,mixed>      $payload
     * @param array<string,mixed>|null $result
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $payload,
        public ?string $idempotencyKey = null,
        public string $status = self::STATUS_QUEUED,
        public int $attempts = 0,
        public int $maxAttempts = 5,
        public ?\DateTimeImmutable $availableAt = null,
        public ?string $lastError = null,
        public ?array $result = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {}

    public function isTerminal(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED || $this->status === self::STATUS_DEAD;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'type'           => $this->type,
            'payload'        => $this->payload,
            'idempotencyKey' => $this->idempotencyKey,
            'status'         => $this->status,
            'attempts'       => $this->attempts,
            'maxAttempts'    => $this->maxAttempts,
            'availableAt'    => $this->availableAt?->format(\DateTimeInterface::ATOM),
            'lastError'      => $this->lastError,
            'result'         => $this->result,
            'createdAt'      => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt'      => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
