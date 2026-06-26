<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Cqrs\Write;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Cqrs\Write\WriteCommand;

class WriteCommandTest extends TestCase
{
    public function testIsTerminalTrueForSucceeded(): void
    {
        $cmd = new WriteCommand('id', WriteCommand::TYPE_ORDER_CREATE, [], status: WriteCommand::STATUS_SUCCEEDED);
        self::assertTrue($cmd->isTerminal());
    }

    public function testIsTerminalTrueForDead(): void
    {
        $cmd = new WriteCommand('id', WriteCommand::TYPE_ORDER_CREATE, [], status: WriteCommand::STATUS_DEAD);
        self::assertTrue($cmd->isTerminal());
    }

    public function testIsTerminalFalseForQueued(): void
    {
        $cmd = new WriteCommand('id', WriteCommand::TYPE_ORDER_CREATE, [], status: WriteCommand::STATUS_QUEUED);
        self::assertFalse($cmd->isTerminal());
    }

    public function testIsTerminalFalseForInProgress(): void
    {
        $cmd = new WriteCommand('id', WriteCommand::TYPE_ORDER_CREATE, [], status: WriteCommand::STATUS_IN_PROGRESS);
        self::assertFalse($cmd->isTerminal());
    }

    public function testToArrayContainsAllFields(): void
    {
        $availableAt = new \DateTimeImmutable('2026-01-01T10:00:00+00:00');
        $createdAt   = new \DateTimeImmutable('2026-01-01T09:00:00+00:00');
        $updatedAt   = new \DateTimeImmutable('2026-01-01T09:30:00+00:00');

        $cmd = new WriteCommand(
            id:             'abc123',
            type:           WriteCommand::TYPE_ORDER_PATCH,
            payload:        ['key' => 'val'],
            idempotencyKey: 'idem-key',
            status:         WriteCommand::STATUS_IN_PROGRESS,
            attempts:       2,
            maxAttempts:    10,
            availableAt:    $availableAt,
            lastError:      'some error',
            result:         ['ok' => true],
            createdAt:      $createdAt,
            updatedAt:      $updatedAt,
        );

        $arr = $cmd->toArray();

        self::assertSame('abc123', $arr['id']);
        self::assertSame(WriteCommand::TYPE_ORDER_PATCH, $arr['type']);
        self::assertSame(['key' => 'val'], $arr['payload']);
        self::assertSame('idem-key', $arr['idempotencyKey']);
        self::assertSame(WriteCommand::STATUS_IN_PROGRESS, $arr['status']);
        self::assertSame(2, $arr['attempts']);
        self::assertSame(10, $arr['maxAttempts']);
        self::assertSame($availableAt->format(\DateTimeInterface::ATOM), $arr['availableAt']);
        self::assertSame('some error', $arr['lastError']);
        self::assertSame(['ok' => true], $arr['result']);
        self::assertSame($createdAt->format(\DateTimeInterface::ATOM), $arr['createdAt']);
        self::assertSame($updatedAt->format(\DateTimeInterface::ATOM), $arr['updatedAt']);
    }

    public function testToArrayNullableFieldsAreNull(): void
    {
        $cmd = new WriteCommand('id', WriteCommand::TYPE_ORDER_STATUS, []);

        $arr = $cmd->toArray();

        self::assertNull($arr['idempotencyKey']);
        self::assertNull($arr['availableAt']);
        self::assertNull($arr['lastError']);
        self::assertNull($arr['result']);
        self::assertNull($arr['createdAt']);
        self::assertNull($arr['updatedAt']);
    }
}
