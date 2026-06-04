<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Service\SoftDeletePolicy;

class SoftDeletePolicyTest extends TestCase
{
    public function testAlreadyCancelledIsIdempotentNoOp(): void
    {
        self::assertTrue(SoftDeletePolicy::isAlreadyCancelled('cancelled'));
    }

    /**
     * Any non-cancelled state must NOT be treated as a no-op — the controller
     * then attempts the transition, which surfaces 409 when illegal. This is
     * the regression guard for the bug where every state returned a fake 204.
     *
     * @return array<string,array{?string}>
     */
    public static function nonCancelledStates(): array
    {
        return [
            'open'        => ['open'],
            'in_progress' => ['in_progress'],
            'completed'   => ['completed'],
            'null'        => [null],
        ];
    }

    #[DataProvider('nonCancelledStates')]
    public function testNonCancelledStatesAreNotNoOp(?string $status): void
    {
        self::assertFalse(SoftDeletePolicy::isAlreadyCancelled($status));
    }
}
