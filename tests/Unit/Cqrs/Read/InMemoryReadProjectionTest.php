<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Cqrs\Read;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Cqrs\Read\InMemoryReadProjection;

class InMemoryReadProjectionTest extends TestCase
{
    private function snapshot(string $id, string $createdAt, string $status = 'open', ?string $scid = null): array
    {
        return ['id' => $id, 'status' => $status, 'salesChannelId' => $scid, 'createdAt' => $createdAt];
    }

    public function testUpsertGetDelete(): void
    {
        $p = new InMemoryReadProjection();
        $p->upsert($this->snapshot('a', '2026-06-01T00:00:00+00:00'));
        self::assertSame('a', $p->get('a')['id']);

        $p->upsert($this->snapshot('a', '2026-06-01T00:00:00+00:00', 'completed'));
        self::assertSame('completed', $p->get('a')['status'], 'upsert replaces');

        $p->delete('a');
        self::assertNull($p->get('a'));
    }

    public function testListFiltersByStatus(): void
    {
        $p = new InMemoryReadProjection();
        $p->upsert($this->snapshot('a', '2026-06-01T00:00:00+00:00', 'open'));
        $p->upsert($this->snapshot('b', '2026-06-02T00:00:00+00:00', 'cancelled'));

        $res = $p->list(['status' => 'cancelled'], 50, null);
        self::assertCount(1, $res['items']);
        self::assertSame('b', $res['items'][0]['id']);
    }

    public function testListIsCreatedAtDescending(): void
    {
        $p = new InMemoryReadProjection();
        $p->upsert($this->snapshot('old', '2026-06-01T00:00:00+00:00'));
        $p->upsert($this->snapshot('new', '2026-06-05T00:00:00+00:00'));

        $res = $p->list([], 50, null);
        self::assertSame(['new', 'old'], array_column($res['items'], 'id'));
    }

    public function testCursorPagination(): void
    {
        $p = new InMemoryReadProjection();
        $p->upsert($this->snapshot('a', '2026-06-01T00:00:00+00:00'));
        $p->upsert($this->snapshot('b', '2026-06-02T00:00:00+00:00'));
        $p->upsert($this->snapshot('c', '2026-06-03T00:00:00+00:00'));

        $page1 = $p->list([], 2, null);
        self::assertSame(['c', 'b'], array_column($page1['items'], 'id'));
        self::assertNotNull($page1['nextCursor']);

        $page2 = $p->list([], 2, $page1['nextCursor']);
        self::assertSame(['a'], array_column($page2['items'], 'id'));
        self::assertNull($page2['nextCursor']);
    }
}
