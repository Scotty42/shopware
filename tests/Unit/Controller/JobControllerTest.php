<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Controller\JobController;
use Scotty42\OrderIntegration\Cqrs\Write\WriteCommand;
use Scotty42\OrderIntegration\Cqrs\Write\WriteQueueInterface;

class JobControllerTest extends TestCase
{
    private function makeController(?WriteCommand $returnValue): JobController
    {
        $queue = $this->createMock(WriteQueueInterface::class);
        $queue->method('get')->willReturn($returnValue);

        return new JobController($queue);
    }

    private function makeCommand(string $status, ?array $result = null): WriteCommand
    {
        return new WriteCommand(
            id: 'job-abc-123',
            type: WriteCommand::TYPE_ORDER_CREATE,
            payload: [],
            status: $status,
            result: $result,
        );
    }

    public function testGetReturns404WhenJobNotFound(): void
    {
        $controller = $this->makeController(null);

        $response = $controller->get('nonexistent-job');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
    }

    public function testGetNotFoundBodyContainsJobId(): void
    {
        $controller = $this->makeController(null);

        $response = $controller->get('job-xyz');

        $body = json_decode($response->getContent(), true);
        self::assertSame(404, $body['status']);
        self::assertSame('job.not_found', $body['code']);
        self::assertStringContainsString('job-xyz', $body['detail']);
    }

    public function testGetReturns200WhenJobFound(): void
    {
        $command = $this->makeCommand(WriteCommand::STATUS_QUEUED);
        $controller = $this->makeController($command);

        $response = $controller->get('job-abc-123');

        self::assertSame(200, $response->getStatusCode());
    }

    public function testGetFoundBodyContainsCoreFields(): void
    {
        $command = $this->makeCommand(WriteCommand::STATUS_IN_PROGRESS);
        $controller = $this->makeController($command);

        $response = $controller->get('job-abc-123');

        $body = json_decode($response->getContent(), true);
        self::assertSame('job-abc-123', $body['jobId']);
        self::assertSame(WriteCommand::TYPE_ORDER_CREATE, $body['type']);
        self::assertSame(WriteCommand::STATUS_IN_PROGRESS, $body['status']);
        self::assertArrayHasKey('attempts', $body);
        self::assertArrayHasKey('result', $body);
        self::assertArrayHasKey('lastError', $body);
    }

    public function testGetSucceededWithoutOrderIdHasNoLinksKey(): void
    {
        $command = $this->makeCommand(WriteCommand::STATUS_SUCCEEDED, []);
        $controller = $this->makeController($command);

        $response = $controller->get('job-abc-123');

        $body = json_decode($response->getContent(), true);
        self::assertArrayNotHasKey('links', $body);
    }

    public function testGetSucceededWithOrderIdIncludesOrderLink(): void
    {
        $command = $this->makeCommand(WriteCommand::STATUS_SUCCEEDED, ['orderId' => 'order-999']);
        $controller = $this->makeController($command);

        $response = $controller->get('job-abc-123');

        $body = json_decode($response->getContent(), true);
        self::assertArrayHasKey('links', $body);
        self::assertStringContainsString('order-999', $body['links']['order']);
    }

    public function testGetSucceededStatusWithNoResultHasNoLinksKey(): void
    {
        $command = $this->makeCommand(WriteCommand::STATUS_SUCCEEDED, null);
        $controller = $this->makeController($command);

        $response = $controller->get('job-abc-123');

        $body = json_decode($response->getContent(), true);
        self::assertArrayNotHasKey('links', $body);
    }

    public function testGetDeadStatusReturns200WithCorrectStatus(): void
    {
        $command = $this->makeCommand(WriteCommand::STATUS_DEAD);
        $controller = $this->makeController($command);

        $response = $controller->get('job-abc-123');

        $body = json_decode($response->getContent(), true);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(WriteCommand::STATUS_DEAD, $body['status']);
    }
}
