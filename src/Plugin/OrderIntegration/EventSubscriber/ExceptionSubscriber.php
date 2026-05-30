<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\EventSubscriber;

use Scotty42\OrderIntegration\Exception\OrderNotFoundException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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

        // Only handle our plugin routes
        if (!str_starts_with($request->getPathInfo(), '/api/order-integration/')) {
            return;
        }

        $exception = $event->getThrowable();
        $status = 500;
        $code = 'internal_server_error';

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
        }

        if ($exception instanceof OrderNotFoundException) {
            $code = $exception->getErrorCode();
        }

        $event->setResponse(new JsonResponse([
            'type'     => 'about:blank',
            'title'    => Response::$statusTexts[$status] ?? 'Error',
            'status'   => $status,
            'detail'   => $exception->getMessage(),
            'code'     => $code,
        ], $status, ['Content-Type' => 'application/problem+json']));
    }
}
