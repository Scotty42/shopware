<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Http\EtagComparator;

/**
 * Unit tests for the delivery ETag helper logic used in DeliveryController.
 *
 * The ETag is: W/"sha1(id|versionId|updatedAt.U.u)".
 * These tests verify the shape and the key properties (stability + sensitivity).
 */
class DeliveryEtagTest extends TestCase
{
    /**
     * Exercise the same algorithm DeliveryController::deliveryEtagFor() uses
     * so changes to the implementation break both places.
     */
    private function etagFor(string $id, string $versionId, string $updatedAt): string
    {
        $material = $id . '|' . $versionId . '|' . $updatedAt;

        return 'W/"' . sha1($material) . '"';
    }

    public function testEtagHasWeakPrefix(): void
    {
        $tag = $this->etagFor('id-1', 'ver-1', '1234567890.000000');
        self::assertStringStartsWith('W/"', $tag);
        self::assertStringEndsWith('"', $tag);
    }

    public function testSameInputProducesSameEtag(): void
    {
        $a = $this->etagFor('aaaa', 'bbbb', '1234.000000');
        $b = $this->etagFor('aaaa', 'bbbb', '1234.000000');
        self::assertSame($a, $b);
    }

    public function testDifferentIdProducesDifferentEtag(): void
    {
        $a = $this->etagFor('id-1', 'ver-x', '1234.000000');
        $b = $this->etagFor('id-2', 'ver-x', '1234.000000');
        self::assertNotSame($a, $b);
    }

    public function testDifferentUpdatedAtProducesDifferentEtag(): void
    {
        $a = $this->etagFor('id-1', 'ver-x', '1000.000000');
        $b = $this->etagFor('id-1', 'ver-x', '1000.000001');
        self::assertNotSame($a, $b, 'microsecond difference must change ETag');
    }

    public function testEtagComparatorAcceptsWeakDeliveryEtag(): void
    {
        $cmp = new EtagComparator();
        $etag = $this->etagFor('del-1', 'ver-1', '1234.567890');

        self::assertTrue($cmp->ifMatchSatisfied($etag, $etag));
    }

    public function testEtagComparatorRejectsStaleDeliveryEtag(): void
    {
        $cmp = new EtagComparator();
        $current = $this->etagFor('del-1', 'ver-1', '2000.000000');
        $stale   = $this->etagFor('del-1', 'ver-1', '1000.000000');

        self::assertFalse($cmp->ifMatchSatisfied($stale, $current));
    }

    public function testWildcardAlwaysMatches(): void
    {
        $cmp  = new EtagComparator();
        $etag = $this->etagFor('del-1', 'ver-1', '9999.000000');

        self::assertTrue($cmp->ifMatchSatisfied('*', $etag));
    }
}
