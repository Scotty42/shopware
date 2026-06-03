<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\EventSubscriber;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\EventSubscriber\ExceptionSubscriber;
use Scotty42\OrderIntegration\Exception\InvalidTransitionException;
use Scotty42\OrderIntegration\Exception\OrderNotFoundException;
use Scotty42\OrderIntegration\Exception\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class ExceptionSubscriberTest extends TestCase
{
    private function kernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
    }

    private function dispatch(string $path, \Throwable $e): ExceptionEvent
    {
        $event = new ExceptionEvent(
            $this->kernel(),
            Request::create($path, 'PUT'),
            HttpKernelInterface::MAIN_REQUEST,
            $e,
        );
        (new ExceptionSubscriber())->onException($event);

        return $event;
    }

    public function testValidationExceptionBecomesProblemJson422WithErrors(): void
    {
        $event = $this->dispatch(
            '/api/order-integration/v1/orders/x/deliveries/y/status',
            new ValidationException([
                ['pointer' => '/status', 'code' => 'required', 'message' => 'status is required'],
            ]),
        );

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('order.validation_failed', $body['code']);
        self::assertArrayHasKey('errors', $body);
        self::assertSame('/status', $body['errors'][0]['pointer']);
    }

    public function testInvalidTransitionBecomes409(): void
    {
        $event = $this->dispatch(
            '/api/order-integration/v1/orders/x/status',
            new InvalidTransitionException('order', 'completed', ['in_progress']),
        );

        self::assertSame(409, $event->getResponse()->getStatusCode());
        self::assertSame('application/problem+json', $event->getResponse()->headers->get('Content-Type'));
    }

    public function testOrderNotFoundBecomes404(): void
    {
        $event = $this->dispatch('/api/order-integration/v1/orders/x', new OrderNotFoundException('x'));
        self::assertSame(404, $event->getResponse()->getStatusCode());
    }

    public function testIgnoresExceptionsOutsidePluginNamespace(): void
    {
        $event = $this->dispatch('/api/some/other/route', new OrderNotFoundException('x'));
        self::assertNull($event->getResponse());
    }

    public function testIgnoresUnknownExceptionTypesOnPluginRoutes(): void
    {
        $event = $this->dispatch('/api/order-integration/v1/orders/x', new \RuntimeException('boom'));
        self::assertNull($event->getResponse());
    }
}
