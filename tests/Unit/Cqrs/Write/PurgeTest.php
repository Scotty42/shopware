<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Cqrs\Write;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Cqrs\Write\InMemoryWriteQueue;
use Scotty42\OrderIntegration\Cqrs\Write\Retention;
use Scotty42\OrderIntegration\Cqrs\Write\WriteCommand;

class PurgeTest extends TestCase
{
    /**
     * @return array<string,array{string,int}>
     */
    public static function specs(): array
    {
        return [
            'days'        => ['7d', 7 * 86400],
            'hours'       => ['24h', 86400],
            'minutes'     => ['30m', 1800],
            'seconds'     => ['90s', 90],
            'bare=days'   => ['5', 5 * 86400],
            'whitespace'  => [' 1d ', 86400],
        ];
    }

    #[DataProvider('specs')]
    public function testParseSeconds(string $spec, int $expected): void
    {
        self::assertSame($expected, Retention::parseSeconds($spec));
    }

    public function testParseRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Retention::parseSeconds('7 weeks');
    }

    public function testCutoffSubtracts(): void
    {
        $now = new \DateTimeImmutable('2026-06-10T00:00:00+00:00');
        self::assertSame('2026-06-03T00:00:00+00:00', Retention::cutoff('7d', $now)->format(\DateTimeInterface::ATOM));
    }

    public function testPurgeRemovesOldTerminalRowsOnly(): void
    {
        $q = new InMemoryWriteQueue();
        $old = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');

        foreach (['a', 'b', 'c', 'd'] as $id) {
            $q->enqueue(new WriteCommand($id, WriteCommand::TYPE_ORDER_CREATE, ['x' => 1]));
        }
        $q->claim(3, $old);                 // a, b, c -> in_progress; d stays queued
        $q->complete('a', [], $old);        // succeeded, updatedAt = old
        $q->complete('b', [], $old);        // succeeded, updatedAt = old
        $q->deadletter('c', 'boom', $old);  // dead, updatedAt = old

        $cutoff = new \DateTimeImmutable('2026-02-01T00:00:00+00:00');
        self::assertSame(2, $q->purge(WriteCommand::STATUS_SUCCEEDED, $cutoff));
        self::assertSame(1, $q->purge(WriteCommand::STATUS_DEAD, $cutoff));

        self::assertNull($q->get('a'));
        self::assertNull($q->get('c'));
        self::assertNotNull($q->get('d'), 'queued rows are never purged');
        self::assertSame(1, $q->depth());
    }

    public function testPurgeRespectsCutoff(): void
    {
        $q = new InMemoryWriteQueue();
        $recent = new \DateTimeImmutable('2026-06-10T12:00:00+00:00');
        $q->enqueue(new WriteCommand('a', WriteCommand::TYPE_ORDER_CREATE, ['x' => 1]));
        $q->claim(1, $recent);
        $q->complete('a', [], $recent);     // succeeded just now

        // cutoff in the past -> nothing old enough
        self::assertSame(0, $q->purge(WriteCommand::STATUS_SUCCEEDED, new \DateTimeImmutable('2026-06-01T00:00:00+00:00')));
        self::assertNotNull($q->get('a'));
    }
}
