<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Exception;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when a mutating request is missing a valid Idempotency-Key header.
 * Required on POST/PUT/PATCH/DELETE per docs/order-api-openapi.yaml.
 */
class MissingIdempotencyKeyException extends HttpException
{
    public function __construct(string $detail = 'Idempotency-Key header is required for mutating requests.')
    {
        parent::__construct(Response::HTTP_BAD_REQUEST, $detail);
    }

    public function getErrorCode(): string
    {
        return 'order.idempotency_key_required';
    }
}
