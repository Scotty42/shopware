<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Controller\EnforcesIfMatch;
use Scotty42\OrderIntegration\Exception\PreconditionFailedException;
use Scotty42\OrderIntegration\Exception\PreconditionRequiredException;
use Scotty42\OrderIntegration\Http\EtagComparator;
use Symfony\Component\HttpFoundation\Request;

class EnforcesIfMatchTest extends TestCase
{
    private function makeSubject(): object
    {
        return new class {
            use EnforcesIfMatch;

            protected function getEtagComparator(): EtagComparator
            {
                return new EtagComparator();
            }

            public function check(Request $request, string $currentEtag): void
            {
                $this->assertIfMatch($request, $currentEtag);
            }
        };
    }

    public function testMissingIfMatchHeaderThrowsPreconditionRequired(): void
    {
        $subject = $this->makeSubject();
        $request = Request::create('/orders/1', 'PATCH');

        $this->expectException(PreconditionRequiredException::class);
        $subject->check($request, '"etag-abc"');
    }

    public function testEmptyIfMatchHeaderThrowsPreconditionRequired(): void
    {
        $subject = $this->makeSubject();
        $request = Request::create('/orders/1', 'PATCH');
        $request->headers->set('If-Match', '   ');

        $this->expectException(PreconditionRequiredException::class);
        $subject->check($request, '"etag-abc"');
    }

    public function testWrongEtagThrowsPreconditionFailed(): void
    {
        $subject = $this->makeSubject();
        $request = Request::create('/orders/1', 'PATCH');
        $request->headers->set('If-Match', '"stale-etag"');

        $this->expectException(PreconditionFailedException::class);
        $subject->check($request, '"current-etag"');
    }

    public function testMatchingEtagDoesNotThrow(): void
    {
        $subject = $this->makeSubject();
        $request = Request::create('/orders/1', 'PATCH');
        $request->headers->set('If-Match', '"etag-abc"');

        // Should not throw — no assertion needed beyond absence of exception
        $subject->check($request, '"etag-abc"');
        $this->addToAssertionCount(1);
    }
}
