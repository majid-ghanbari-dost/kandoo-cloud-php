<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Support;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Database;
use PHPUnit\Framework\TestCase;

/**
 * Base class for DB-backed tests (PM ORDER 9: CI provides PHP 8.1 + a MariaDB 10.6
 * service). Self-skips when KANDOO_DB_TESTS != 1, no configuration exists, or the
 * database is unreachable — so the same suite runs green locally without MariaDB.
 *
 * All CI credentials are FAKE values generated at runtime by tests/ci/write-config.php.
 */
abstract class DbTestCase extends TestCase
{
    protected static ?Database $database = null;
    /** @var array<string, mixed> */
    protected static array $config = [];

    public static function setUpBeforeClass(): void
    {
        if (getenv('KANDOO_DB_TESTS') !== '1') {
            static::markTestSkipped('DB tests disabled (set KANDOO_DB_TESTS=1 with a reachable MariaDB 10.6).');
        }
        try {
            static::$config = Config::load()->all();
        } catch (\Throwable $e) {
            static::markTestSkipped('No usable configuration: ' . $e->getMessage());
        }
        $db = new Database(static::$config);
        if (!$db->isAvailable()) {
            static::markTestSkipped('MariaDB is not reachable from this environment.');
        }
        static::$database = $db;
    }

    protected function db(): Database
    {
        if (static::$database === null) {
            static::markTestSkipped('Database unavailable.');
        }
        return static::$database;
    }
}
