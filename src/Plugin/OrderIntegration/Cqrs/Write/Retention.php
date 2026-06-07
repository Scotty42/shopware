<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs\Write;

/**
 * Parses a human retention spec ("7d", "24h", "30m", "90s", or a bare number =
 * days) into seconds / a cutoff timestamp. Pure — unit-tested.
 */
final class Retention
{
    public static function parseSeconds(string $spec): int
    {
        $spec = trim($spec);
        if (!preg_match('/^(\d+)\s*([smhd]?)$/i', $spec, $m)) {
            throw new \InvalidArgumentException(
                sprintf('invalid retention "%s" (use e.g. 7d, 24h, 30m, 90s)', $spec)
            );
        }

        $unit = strtolower($m[2] !== '' ? $m[2] : 'd');

        return (int) $m[1] * match ($unit) {
            's' => 1,
            'm' => 60,
            'h' => 3600,
            'd' => 86400,
        };
    }

    public static function cutoff(string $spec, \DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify('-' . self::parseSeconds($spec) . ' seconds');
    }
}
