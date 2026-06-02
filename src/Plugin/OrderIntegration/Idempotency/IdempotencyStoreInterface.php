<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Idempotency;

interface IdempotencyStoreInterface
{
    public function get(string $key): ?IdempotencyRecord;

    public function put(IdempotencyRecord $record, int $ttlSeconds): void;
}
