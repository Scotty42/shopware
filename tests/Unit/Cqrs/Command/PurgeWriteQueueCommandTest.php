<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Cqrs\Command;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Cqrs\Command\PurgeWriteQueueCommand;
use Scotty42\OrderIntegration\Cqrs\Write\WriteCommand;
use Scotty42\OrderIntegration\Cqrs\Write\WriteQueueInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class PurgeWriteQueueCommandTest extends TestCase
{
    public function testPurgeIsCalledForBothStatusesAndExitsSuccess(): void
    {
        $calledStatuses = [];

        $queue = $this->createMock(WriteQueueInterface::class);
        $queue->expects(self::exactly(2))
            ->method('purge')
            ->willReturnCallback(function (string $status) use (&$calledStatuses): int {
                $calledStatuses[] = $status;

                return 0;
            });

        $tester = new CommandTester(new PurgeWriteQueueCommand($queue));
        $code   = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertContains(WriteCommand::STATUS_SUCCEEDED, $calledStatuses);
        self::assertContains(WriteCommand::STATUS_DEAD, $calledStatuses);
    }

    public function testOutputMentionsPurgedCounts(): void
    {
        $queue = $this->createMock(WriteQueueInterface::class);
        $queue->method('purge')->willReturnOnConsecutiveCalls(10, 3);

        $tester = new CommandTester(new PurgeWriteQueueCommand($queue));
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('10', $display);
        self::assertStringContainsString('3', $display);
    }

    public function testCustomRetentionOptionsAreAccepted(): void
    {
        $queue = $this->createMock(WriteQueueInterface::class);
        $queue->method('purge')->willReturn(0);

        $tester = new CommandTester(new PurgeWriteQueueCommand($queue));
        $code   = $tester->execute(['--succeeded-after' => '2d', '--dead-after' => '14d']);

        self::assertSame(Command::SUCCESS, $code);
    }
}
