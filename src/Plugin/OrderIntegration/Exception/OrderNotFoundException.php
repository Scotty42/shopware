<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Exception;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderNotFoundException extends HttpException
{
    public function __construct(string $orderId)
    {
        parent::__construct(
            Response::HTTP_NOT_FOUND,
            sprintf('Order "%s" not found.', $orderId),
        );
    }

    public function getErrorCode(): string
    {
        return 'order.not_found';
    }
}
