<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs\Read;

/**
 * Denormalized read store for orders (CQRS read side). Serves GET reads without
 * touching Shopware's DAL, decoupling read load from the shop (concept §2,
 * Phase 2). Kept in sync from Shopware business events by OrderProjectionWriter.
 */
interface ReadProjectionInterface
{
    /**
     * Insert or replace an order snapshot. The snapshot is the mapped Order
     * payload plus routing keys (id, status, salesChannelId, createdAt).
     *
     * @param array<string,mixed> $snapshot
     */
    public function upsert(array $snapshot): void;

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $id): ?array;

    /**
     * @param array{status?:string,salesChannelId?:string} $filters
     *
     * @return array{items:list<array<string,mixed>>,nextCursor:?string}
     */
    public function list(array $filters, int $limit, ?string $cursor): array;

    public function delete(string $id): void;
}
