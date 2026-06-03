<?php declare(strict_types=1);

namespace Scotty42\OrderIntegration\Cqrs;

/**
 * Lazily provides the PDO connection to the CQRS read projection / write queue
 * DB. The connection is opened on first use, NOT at construction, so the plugin
 * boots normally when the feature is disabled and no DB is configured.
 *
 * Config (see docs/infrastructure-setup.md and .env.test.dist):
 *   ORDER_INTEGRATION_DB_DSN, ORDER_INTEGRATION_DB_USER, ORDER_INTEGRATION_DB_PASSWORD
 */
final class PdoConnectionProvider
{
    private ?\PDO $pdo = null;

    public function __construct(
        private readonly ?string $dsn,
        private readonly ?string $user,
        private readonly ?string $password,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            self::env('ORDER_INTEGRATION_DB_DSN'),
            self::env('ORDER_INTEGRATION_DB_USER'),
            self::env('ORDER_INTEGRATION_DB_PASSWORD'),
        );
    }

    public function isConfigured(): bool
    {
        return $this->dsn !== null;
    }

    public function pdo(): \PDO
    {
        if ($this->pdo instanceof \PDO) {
            return $this->pdo;
        }

        if ($this->dsn === null) {
            throw new \RuntimeException(
                'ORDER_INTEGRATION_DB_DSN is not configured. The CQRS read projection / '
                . 'write queue requires a database — see docs/infrastructure-setup.md.'
            );
        }

        $this->pdo = new \PDO(
            $this->dsn,
            $this->user ?? '',
            $this->password ?? '',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        return $this->pdo;
    }

    private static function env(string $key): ?string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return ($value === false || $value === null || $value === '') ? null : (string) $value;
    }
}
