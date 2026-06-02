<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Http\EtagComparator;

class EtagComparatorTest extends TestCase
{
    private EtagComparator $cmp;

    protected function setUp(): void
    {
        $this->cmp = new EtagComparator();
    }

    public function testNullIsNotSatisfied(): void
    {
        self::assertFalse($this->cmp->ifMatchSatisfied(null, 'W/"abc"'));
    }

    public function testEmptyIsNotSatisfied(): void
    {
        self::assertFalse($this->cmp->ifMatchSatisfied('   ', 'W/"abc"'));
    }

    public function testWildcardAlwaysSatisfied(): void
    {
        self::assertTrue($this->cmp->ifMatchSatisfied('*', 'W/"abc"'));
    }

    public function testExactWeakMatch(): void
    {
        self::assertTrue($this->cmp->ifMatchSatisfied('W/"abc"', 'W/"abc"'));
    }

    public function testStrongHeaderMatchesWeakCurrent(): void
    {
        // Client may echo the tag without the W/ prefix; we compare weakly.
        self::assertTrue($this->cmp->ifMatchSatisfied('"abc"', 'W/"abc"'));
    }

    public function testListWithOneMatchingCandidate(): void
    {
        self::assertTrue($this->cmp->ifMatchSatisfied('W/"x", W/"abc" , "y"', 'W/"abc"'));
    }

    public function testMismatch(): void
    {
        self::assertFalse($this->cmp->ifMatchSatisfied('W/"stale"', 'W/"abc"'));
    }

    public function testExceptionStatusCodes(): void
    {
        $failed = new \Scotty42\OrderIntegration\Exception\PreconditionFailedException();
        self::assertSame(412, $failed->getStatusCode());
        self::assertSame('order.precondition_failed', $failed->getErrorCode());

        $required = new \Scotty42\OrderIntegration\Exception\PreconditionRequiredException();
        self::assertSame(428, $required->getStatusCode());
        self::assertSame('order.precondition_required', $required->getErrorCode());
    }
}
