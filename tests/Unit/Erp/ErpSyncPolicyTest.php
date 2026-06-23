<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Erp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Erp\ErpSyncPolicy;

class ErpSyncPolicyTest extends TestCase
{
    private ErpSyncPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ErpSyncPolicy();
    }

    /**
     * @return array<string,array{?array<string,mixed>,bool}>
     */
    public static function syncStates(): array
    {
        return [
            'null customFields'  => [null, false],
            'empty customFields' => [[], false],
            'key present null'   => [['erpSyncedAt' => null], false],
            'key empty string'   => [['erpSyncedAt' => ''], false],
            'key set'            => [['erpSyncedAt' => '2026-06-01T10:00:00+00:00'], true],
            'other keys only'    => [['foo' => 'bar'], false],
        ];
    }

    #[DataProvider('syncStates')]
    public function testIsSynced(?array $customFields, bool $expected): void
    {
        self::assertSame($expected, $this->policy->isSynced($customFields));
    }

    public function testAcknowledgementPatchShape(): void
    {
        $now = new \DateTimeImmutable('2026-06-03T08:30:00+00:00');
        $patch = $this->policy->acknowledgementPatch(str_repeat('a', 32), $now);

        self::assertSame(str_repeat('a', 32), $patch['id']);
        self::assertSame('2026-06-03T08:30:00+00:00', $patch['customFields']['erpSyncedAt']);
        self::assertArrayNotHasKey('erpOrderId', $patch['customFields']);
    }

    public function testAcknowledgementPatchWithErpOrderId(): void
    {
        $now = new \DateTimeImmutable('2026-06-03T08:30:00+00:00');
        $patch = $this->policy->acknowledgementPatch(str_repeat('a', 32), $now, 'SO-12345');

        self::assertSame('2026-06-03T08:30:00+00:00', $patch['customFields']['erpSyncedAt']);
        self::assertSame('SO-12345', $patch['customFields']['erpOrderId']);
    }

    public function testPlanPartitionsRequestedIds(): void
    {
        $a = str_repeat('a', 32); // unsynced -> acknowledged
        $b = str_repeat('b', 32); // already synced
        $c = str_repeat('c', 32); // unsynced -> acknowledged
        $missing = str_repeat('d', 32); // not found

        $existing = [
            $a => null,
            $b => ['erpSyncedAt' => '2026-05-01T00:00:00+00:00'],
            $c => ['foo' => 'bar'],
        ];

        $now = new \DateTimeImmutable('2026-06-03T09:00:00+00:00');
        $plan = $this->policy->planAcknowledgement($existing, [$a, $b, $c, $missing], $now);

        self::assertSame([$a, $c], $plan['acknowledged']);
        self::assertSame([$b], $plan['alreadySynced']);
        self::assertSame([$missing], $plan['notFound']);
        self::assertCount(2, $plan['patches']);
        self::assertSame($a, $plan['patches'][0]['id']);
        self::assertSame('2026-06-03T09:00:00+00:00', $plan['patches'][0]['customFields']['erpSyncedAt']);
        self::assertArrayNotHasKey('erpOrderId', $plan['patches'][0]['customFields']);
    }

    public function testPlanStoresErpOrderIdInPatch(): void
    {
        $a = str_repeat('a', 32);
        $b = str_repeat('b', 32);
        $existing = [$a => null, $b => null];
        $now = new \DateTimeImmutable('2026-06-03T09:00:00+00:00');

        $plan = $this->policy->planAcknowledgement(
            $existing,
            [$a, $b],
            $now,
            [$a => 'SO-12345'],  // only $a has an ERP order id
        );

        self::assertSame('SO-12345', $plan['patches'][0]['customFields']['erpOrderId']);
        self::assertArrayNotHasKey('erpOrderId', $plan['patches'][1]['customFields']);
    }

    public function testPlanDoesNotUpdateErpOrderIdWhenAlreadySynced(): void
    {
        $a = str_repeat('a', 32);
        $existing = [$a => ['erpSyncedAt' => '2026-05-01T00:00:00+00:00']];
        $now = new \DateTimeImmutable('2026-06-03T09:00:00+00:00');

        $plan = $this->policy->planAcknowledgement($existing, [$a], $now, [$a => 'SO-99999']);

        self::assertSame([], $plan['patches']);
        self::assertSame([$a], $plan['alreadySynced']);
    }

    public function testPlanDeduplicatesRequestedIds(): void
    {
        $a = str_repeat('a', 32);
        $now = new \DateTimeImmutable('2026-06-03T09:00:00+00:00');

        $plan = $this->policy->planAcknowledgement([$a => null], [$a, $a, $a], $now);

        self::assertSame([$a], $plan['acknowledged']);
        self::assertCount(1, $plan['patches']);
    }

    public function testPlanPreservesAlreadySyncedTimestamp(): void
    {
        // already-synced ids produce no patch, so the original timestamp stays.
        $b = str_repeat('b', 32);
        $plan = $this->policy->planAcknowledgement(
            [$b => ['erpSyncedAt' => '2026-05-01T00:00:00+00:00']],
            [$b],
            new \DateTimeImmutable('2026-06-03T09:00:00+00:00'),
        );

        self::assertSame([], $plan['patches']);
        self::assertSame([$b], $plan['alreadySynced']);
    }
}
