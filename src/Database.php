<?php

declare(strict_types=1);

namespace Kandoo\Cloud;

/**
 * Lazy PDO connection to MariaDB 10.6 (PM ORDER 9 P-02).
 *
 * - Exceptions mode, native prepares (no emulation) so BIGINT/INT come back as PHP ints.
 * - Connection details only ever come from the validated config; nothing is hardcoded.
 * - The Cloud never logs credentials or connection strings.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class Database
{
    private ?\PDO $pdo = null;

    /** @param array<string, mixed> $config validated configuration array */
    public function __construct(private array $config)
    {
    }

    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            /** @var array{host: string, port: int, name: string, user: string, pass: string, charset: string} $db */
            $db = $this->config['db'];
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $db['host'],
                $db['port'],
                $db['name'],
                $db['charset']
            );
            $this->pdo = new \PDO($dsn, $db['user'], $db['pass'], [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES   => false,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        }
        return $this->pdo;
    }

    /** True when a connection can be established (used by tests to self-skip without a DB). */
    public function isAvailable(): bool
    {
        try {
            $this->pdo();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
