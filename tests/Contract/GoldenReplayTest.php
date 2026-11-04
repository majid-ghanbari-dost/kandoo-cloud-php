<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Contract;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Database;
use Kandoo\Cloud\Store\MariaDbGateway;
use Kandoo\Cloud\Store\MemoryGateway;
use Kandoo\Cloud\Tests\Support\GoldenHarness;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * CONTRACT REPLAY (PM ORDER 9 P-04): every golden pair in docs/golden is replayed
 * against the PHP Cloud — the SAME 23 cases through the SAME harness on BOTH gateways:
 *
 *  - MemoryGateway: ALWAYS runs (local dev + CI);
 *  - MariaDbGateway: runs when KANDOO_DB_TESTS=1 with reachable MariaDB 10.6 and
 *    applied migrations (CI service); self-skips elsewhere.
 *
 * A divergence FAILS the test with the full problem list — divergences are reported
 * explicitly and never silently changed (the goldens are frozen; only the PHP code or
 * an explicit PM decision may move). See docs/CONTRACT-REPLAY.md.
 *
 * ALL FIXTURE VALUES ARE FAKE SYNTHETIC DATA (vector IDs / TEST-… values).
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class GoldenReplayTest extends TestCase
{
    private static ?Database $database = null;
    private static bool $dbResolved = false;

    /** @return array<string, array{0: string}> */
    public static function goldenFiles(): array
    {
        $dir = dirname(__DIR__, 2) . '/docs/golden';
        $cases = [];
        foreach (scandir($dir) ?: [] as $name) {
            if (is_string($name) && str_ends_with($name, '.json')) {
                $cases[$name] = [$dir . '/' . $name];
            }
        }
        ksort($cases);
        return $cases;
    }

    private function database(): ?Database
    {
        if (!self::$dbResolved) {
            self::$dbResolved = true;
            if (getenv('KANDOO_DB_TESTS') === '1') {
                try {
                    $db = new Database(Config::load()->all());
                    if ($db->isAvailable()) {
                        self::$database = $db;
                    }
                } catch (Throwable) {
                    self::$database = null;
                }
            }
        }
        return self::$database;
    }

    /** @dataProvider goldenFiles */
    public function testGoldenReplayOnMemoryGateway(string $goldenPath): void
    {
        $result = GoldenHarness::replay($goldenPath, new MemoryGateway());
        $this->assertSame(
            [],
            $result['problems'],
            'CONTRACT DIVERGENCE (memory): ' . implode(' | ', $result['problems'])
        );
    }

    /** @dataProvider goldenFiles */
    public function testGoldenReplayOnMariaDb(string $goldenPath): void
    {
        $db = $this->database();
        if ($db === null) {
            $this->markTestSkipped('DB replay disabled (set KANDOO_DB_TESTS=1 with a reachable migrated MariaDB 10.6).');
        }
        $result = GoldenHarness::replay($goldenPath, new MariaDbGateway($db));
        $this->assertSame(
            [],
            $result['problems'],
            'CONTRACT DIVERGENCE (mariadb): ' . implode(' | ', $result['problems'])
        );
    }
}
