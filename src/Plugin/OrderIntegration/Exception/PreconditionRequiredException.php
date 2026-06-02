<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Exception;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when a mutating request omits the required If-Match header.
 * Surfaces as 428 Precondition Required (RFC 6585).
 */
class PreconditionRequiredException extends HttpException
{
    public function __construct(string $detail = 'If-Match header is required for this operation.')
    {
        parent::__construct(Response::HTTP_PRECONDITION_REQUIRED, $detail);
    }

    public function getErrorCode(): string
    {
        return 'order.precondition_required';
    }
}
