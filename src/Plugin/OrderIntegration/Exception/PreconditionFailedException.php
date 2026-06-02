<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Exception;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when an If-Match header does not match the resource's current ETag
 * (lost-update protection). Surfaces as 412 Precondition Failed.
 */
class PreconditionFailedException extends HttpException
{
    public function __construct(string $detail = 'If-Match does not match the current resource state.')
    {
        parent::__construct(Response::HTTP_PRECONDITION_FAILED, $detail);
    }

    public function getErrorCode(): string
    {
        return 'order.precondition_failed';
    }
}
