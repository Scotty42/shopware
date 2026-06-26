<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Integration\Cqrs;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Cqrs\PdoConnectionProvider;
use Scotty42\OrderIntegration\Cqrs\Read\PdoReadProjection;

class PdoReadProjectionTest extends TestCase
{
    private static ?PdoConnectionProvider $connectionProvider = null;
    private PdoReadProjection $projection;

    public static function setUpBeforeClass(): void
    {
        $dsn = $_SERVER['ORDER_INTEGRATION_DB_DSN'] ?? $_ENV['ORDER_INTEGRATION_DB_DSN'] ?? getenv('ORDER_INTEGRATION_DB_DSN');
        if ($dsn === false || $dsn === null || $dsn === '') {
            return;
        }
        $user     = $_SERVER['ORDER_INTEGRATION_DB_USER'] ?? $_ENV['ORDER_INTEGRATION_DB_USER'] ?? getenv('ORDER_INTEGRATION_DB_USER') ?: null;
        $password = $_SERVER['ORDER_INTEGRATION_DB_PASSWORD'] ?? $_ENV['ORDER_INTEGRATION_DB_PASSWORD'] ?? getenv('ORDER_INTEGRATION_DB_PASSWORD') ?: null;
        self::$connectionProvider = new PdoConnectionProvider((string) $dsn, $user ?: null, $password ?: null);
    }

    protected function setUp(): void
    {
        $dsn = $_SERVER['ORDER_INTEGRATION_DB_DSN'] ?? $_ENV['ORDER_INTEGRATION_DB_DSN'] ?? getenv('ORDER_INTEGRATION_DB_DSN');
        if ($dsn === false || $dsn === null || $dsn === '') {
            $this->markTestSkipped('ORDER_INTEGRATION_DB_DSN is not set — skipping PDO integration tests.');
        }

        self::$connectionProvider->pdo()->exec('TRUNCATE TABLE order_read_projection');
        $this->projection = new PdoReadProjection(self::$connectionProvider);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function makeSnapshot(string $id, array $overrides = []): array
    {
        return array_merge([
            'id'             => $id,
            'status'         => 'open',
            'salesChannelId' => 'sc-default',
            'createdAt'      => '2024-01-01T00:00:00+00:00',
            'updatedAt'      => '2024-01-01T00:00:00+00:00',
            'orderNumber'    => $id,
        ], $overrides);
    }

    public function testUpsertAndGet(): void
    {
        $snapshot = $this->makeSnapshot('ord-1');
        $this->projection->upsert($snapshot);

        $fetched = $this->projection->get('ord-1');

        self::assertNotNull($fetched);
        self::assertSame('ord-1', $fetched['id']);
        self::assertSame('open', $fetched['status']);
        self::assertSame('sc-default', $fetched['salesChannelId']);
    }

    public function testUpsertIsIdempotent(): void
    {
        $this->projection->upsert($this->makeSnapshot('ord-2', ['status' => 'open']));
        $this->projection->upsert($this->makeSnapshot('ord-2', ['status' => 'cancelled']));

        $fetched = $this->projection->get('ord-2');

        self::assertSame('cancelled', $fetched['status']);
    }

    public function testDelete(): void
    {
        $this->projection->upsert($this->makeSnapshot('ord-3'));
        $this->projection->delete('ord-3');

        self::assertNull($this->projection->get('ord-3'));
    }

    public function testListNoFilters(): void
    {
        $this->projection->upsert($this->makeSnapshot('ord-4a'));
        $this->projection->upsert($this->makeSnapshot('ord-4b'));
        $this->projection->upsert($this->makeSnapshot('ord-4c'));

        $result = $this->projection->list([], 10, null);

        self::assertCount(3, $result['items']);
        self::assertNull($result['nextCursor']);

        $ids = array_column($result['items'], 'id');
        self::assertContains('ord-4a', $ids);
        self::assertContains('ord-4b', $ids);
        self::assertContains('ord-4c', $ids);
    }

    public function testListCursorPagination(): void
    {
        // Insert 5 records with distinct createdAt values (1 day apart) so
        // cursor-based ordering (created_at DESC, id DESC) is deterministic.
        for ($i = 1; $i <= 5; $i++) {
            $ts = sprintf('2024-01-%02dT00:00:00+00:00', $i);
            $this->projection->upsert($this->makeSnapshot("ord-5-{$i}", ['createdAt' => $ts, 'updatedAt' => $ts]));
        }

        $page1 = $this->projection->list([], 2, null);

        self::assertCount(2, $page1['items']);
        self::assertNotNull($page1['nextCursor']);

        $page2 = $this->projection->list([], 2, $page1['nextCursor']);

        self::assertCount(2, $page2['items']);

        // The two pages must not overlap.
        $ids1 = array_column($page1['items'], 'id');
        $ids2 = array_column($page2['items'], 'id');
        self::assertEmpty(array_intersect($ids1, $ids2));
    }

    public function testListFilterByStatus(): void
    {
        $this->projection->upsert($this->makeSnapshot('ord-6a', ['status' => 'open']));
        $this->projection->upsert($this->makeSnapshot('ord-6b', ['status' => 'open']));
        $this->projection->upsert($this->makeSnapshot('ord-6c', ['status' => 'open']));
        $this->projection->upsert($this->makeSnapshot('ord-6d', ['status' => 'cancelled']));
        $this->projection->upsert($this->makeSnapshot('ord-6e', ['status' => 'cancelled']));

        $result = $this->projection->list(['status' => 'open'], 10, null);

        self::assertCount(3, $result['items']);
    }

    public function testListFilterBySalesChannelId(): void
    {
        $this->projection->upsert($this->makeSnapshot('ord-7a', ['salesChannelId' => 'sc1']));
        $this->projection->upsert($this->makeSnapshot('ord-7b', ['salesChannelId' => 'sc1']));
        $this->projection->upsert($this->makeSnapshot('ord-7c', ['salesChannelId' => 'sc2']));
        $this->projection->upsert($this->makeSnapshot('ord-7d', ['salesChannelId' => 'sc2']));

        $sc1 = $this->projection->list(['salesChannelId' => 'sc1'], 10, null);
        $sc2 = $this->projection->list(['salesChannelId' => 'sc2'], 10, null);

        self::assertCount(2, $sc1['items']);
        self::assertCount(2, $sc2['items']);
    }

    public function testListHasMoreFlag(): void
    {
        $this->projection->upsert($this->makeSnapshot('ord-8a'));
        $this->projection->upsert($this->makeSnapshot('ord-8b'));
        $this->projection->upsert($this->makeSnapshot('ord-8c'));

        // limit=2 — there is a third record, so nextCursor must be non-null.
        $partial = $this->projection->list([], 2, null);
        self::assertNotNull($partial['nextCursor']);

        // limit=3 — fetches exactly all records, so nextCursor must be null.
        $full = $this->projection->list([], 3, null);
        self::assertNull($full['nextCursor']);
    }
}
