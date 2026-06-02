<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\EventSubscriber;

use Scotty42\OrderIntegration\Exception\IdempotencyConflictException;
use Scotty42\OrderIntegration\Exception\InvalidTransitionException;
use Scotty42\OrderIntegration\Exception\MissingIdempotencyKeyException;
use Scotty42\OrderIntegration\Exception\OrderNotFoundException;
use Scotty42\OrderIntegration\Exception\PreconditionFailedException;
use Scotty42\OrderIntegration\Exception\PreconditionRequiredException;
use Scotty42\OrderIntegration\Exception\ValidationException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class ExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onException', 200],
        ];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/order-integration/')) {
            return;
        }

        $exception = $event->getThrowable();

        $handled = $exception instanceof OrderNotFoundException
            || $exception instanceof ValidationException
            || $exception instanceof InvalidTransitionException
            || $exception instanceof IdempotencyConflictException
            || $exception instanceof MissingIdempotencyKeyException
            || $exception instanceof PreconditionFailedException
            || $exception instanceof PreconditionRequiredException;

        if (!$handled) {
            return;
        }

        $status = $exception->getStatusCode();

        $body = [
            'type'   => 'about:blank',
            'title'  => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $exception->getMessage(),
            'code'   => $exception->getErrorCode(),
        ];

        if ($exception instanceof ValidationException) {
            $body['errors'] = $exception->getValidationErrors();
        }

        $event->setResponse(new JsonResponse(
            $body,
            $status,
            ['Content-Type' => 'application/problem+json']
        ));
    }
}
