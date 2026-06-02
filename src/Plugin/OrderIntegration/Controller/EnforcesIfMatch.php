<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Controller;

use Scotty42\OrderIntegration\Exception\PreconditionFailedException;
use Scotty42\OrderIntegration\Exception\PreconditionRequiredException;
use Scotty42\OrderIntegration\Http\EtagComparator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Enforces If-Match optimistic concurrency on mutating actions
 * (docs/order-api-concept.md §5.5). The consuming controller must expose an
 * EtagComparator via getEtagComparator().
 */
trait EnforcesIfMatch
{
    abstract protected function getEtagComparator(): EtagComparator;

    private function assertIfMatch(Request $request, string $currentEtag): void
    {
        $ifMatch = $request->headers->get('If-Match');

        if ($ifMatch === null || trim($ifMatch) === '') {
            throw new PreconditionRequiredException();
        }

        if (!$this->getEtagComparator()->ifMatchSatisfied($ifMatch, $currentEtag)) {
            throw new PreconditionFailedException();
        }
    }
}
