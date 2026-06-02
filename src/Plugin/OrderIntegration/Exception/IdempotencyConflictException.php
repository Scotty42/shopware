<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Exception;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when an Idempotency-Key is replayed with a different request body.
 * Surfaces as 409 Conflict (RFC 9457) via ExceptionSubscriber.
 */
class IdempotencyConflictException extends HttpException
{
    public function __construct(string $key)
    {
        parent::__construct(
            Response::HTTP_CONFLICT,
            sprintf('Idempotency-Key "%s" was already used with a different request body.', $key),
        );
    }

    public function getErrorCode(): string
    {
        return 'order.idempotency_key_reused';
    }
}
