<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Tests\Unit\Cqrs;

use PHPUnit\Framework\TestCase;
use Scotty42\OrderIntegration\Cqrs\PdoConnectionProvider;

class PdoConnectionProviderTest extends TestCase
{
    public function testIsConfiguredReturnsFalseWithNullDsn(): void
    {
        $provider = new PdoConnectionProvider(null, null, null);

        self::assertFalse($provider->isConfigured());
    }

    public function testPdoThrowsRuntimeExceptionWithNullDsn(): void
    {
        $provider = new PdoConnectionProvider(null, null, null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ORDER_INTEGRATION_DB_DSN/');
        $provider->pdo();
    }

    public function testIsConfiguredReturnsTrueWithDsn(): void
    {
        $provider = new PdoConnectionProvider('sqlite::memory:', null, null);

        self::assertTrue($provider->isConfigured());
    }

    public function testPdoReturnsPdoInstanceForSqliteMemory(): void
    {
        $provider = new PdoConnectionProvider('sqlite::memory:', null, null);

        self::assertInstanceOf(\PDO::class, $provider->pdo());
    }

    public function testPdoReturnsSameInstanceOnSecondCall(): void
    {
        $provider = new PdoConnectionProvider('sqlite::memory:', null, null);

        $first  = $provider->pdo();
        $second = $provider->pdo();

        self::assertSame($first, $second, 'lazy init must return the same PDO on repeated calls');
    }

    public function testFromEnvReadsEnvironmentVariables(): void
    {
        // Set env vars, then clean up regardless of test outcome.
        $_SERVER['ORDER_INTEGRATION_DB_DSN']      = 'sqlite::memory:';
        $_SERVER['ORDER_INTEGRATION_DB_USER']     = 'user';
        $_SERVER['ORDER_INTEGRATION_DB_PASSWORD'] = 'pass';

        try {
            $provider = PdoConnectionProvider::fromEnv();
            self::assertTrue($provider->isConfigured());
            self::assertInstanceOf(\PDO::class, $provider->pdo());
        } finally {
            unset(
                $_SERVER['ORDER_INTEGRATION_DB_DSN'],
                $_SERVER['ORDER_INTEGRATION_DB_USER'],
                $_SERVER['ORDER_INTEGRATION_DB_PASSWORD'],
            );
        }
    }

    public function testFromEnvWithMissingDsnIsNotConfigured(): void
    {
        unset($_SERVER['ORDER_INTEGRATION_DB_DSN'], $_ENV['ORDER_INTEGRATION_DB_DSN']);

        $provider = PdoConnectionProvider::fromEnv();

        self::assertFalse($provider->isConfigured());
    }
}
