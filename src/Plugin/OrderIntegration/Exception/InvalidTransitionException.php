<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Exception;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InvalidTransitionException extends HttpException
{
    public function __construct(
        string $machine,
        string $targetStatus,
        array $validStatuses,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            Response::HTTP_CONFLICT,
            sprintf(
                'Cannot transition %s to "%s". Valid targets: %s.',
                $machine,
                $targetStatus,
                implode(', ', $validStatuses)
            ),
            $previous,
        );
    }

    public function getErrorCode(): string
    {
        return 'order.invalid_state_transition';
    }
}
