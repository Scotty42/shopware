<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Exception;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ValidationException extends HttpException
{
    public function __construct(private readonly array $validationErrors)
    {
        parent::__construct(Response::HTTP_UNPROCESSABLE_ENTITY, 'Validation failed.');
    }

    public function getErrorCode(): string
    {
        return 'order.validation_failed';
    }

    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }
}
