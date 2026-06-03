<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs\Write;

/**
 * Applies a claimed WriteCommand to Shopware. Throws on failure (the worker
 * then schedules a retry or dead-letters). Returns a result array stored on the
 * command (e.g. the new orderId) and returned via the job-status endpoint.
 */
interface CommandHandlerInterface
{
    /**
     * @return array<string,mixed>
     */
    public function handle(WriteCommand $command): array;
}
