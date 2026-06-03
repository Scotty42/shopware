<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Controller;

use Scotty42\OrderIntegration\Cqrs\Write\WriteCommand;
use Scotty42\OrderIntegration\Cqrs\Write\WriteQueueInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Job status for asynchronously-queued writes. A 202 response from a mutating
 * endpoint carries a Location pointing here; the client polls until the job is
 * terminal.
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class JobController extends AbstractController
{
    public function __construct(private readonly WriteQueueInterface $queue)
    {
    }

    #[Route(
        path: '/api/order-integration/v1/jobs/{jobId}',
        name: 'api.order-integration.jobs.get',
        methods: ['GET']
    )]
    public function get(string $jobId): JsonResponse
    {
        $command = $this->queue->get($jobId);

        if ($command === null) {
            return new JsonResponse([
                'type'   => 'about:blank',
                'title'  => 'Not Found',
                'status' => 404,
                'detail' => sprintf('Job "%s" not found.', $jobId),
                'code'   => 'job.not_found',
            ], Response::HTTP_NOT_FOUND, ['Content-Type' => 'application/problem+json']);
        }

        $body = [
            'jobId'     => $command->id,
            'type'      => $command->type,
            'status'    => $command->status,
            'attempts'  => $command->attempts,
            'result'    => $command->result,
            'lastError' => $command->lastError,
        ];

        // For a succeeded order.create, point at the created order.
        if ($command->status === WriteCommand::STATUS_SUCCEEDED && isset($command->result['orderId'])) {
            $body['links'] = ['order' => sprintf('/api/order-integration/v1/orders/%s', $command->result['orderId'])];
        }

        return new JsonResponse($body, Response::HTTP_OK);
    }
}
