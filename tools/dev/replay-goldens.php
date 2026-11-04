<?php

declare(strict_types=1);

/**
 * LOCAL DEV golden replay — runs every docs/golden/*.json against the Kernel with the
 * in-memory gateway (no database needed). CI replays the same goldens through PHPUnit
 * (tests/Contract, P-04) against BOTH the memory gateway and the MariaDB 10.6 service.
 * Divergences are reported explicitly — never silently changed (PM ORDER 9).
 *
 * Usage: php tools/dev/replay-goldens.php [filter-substring]
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */

use Kandoo\Cloud\Store\MemoryGateway;
use Kandoo\Cloud\Tests\Support\GoldenHarness;

require dirname(__DIR__, 2) . '/tests/bootstrap.php';

$filter = $argv[1] ?? '';
$dir = dirname(__DIR__, 2) . '/docs/golden';
$files = [];
foreach (scandir($dir) ?: [] as $name) {
    if (str_ends_with($name, '.json') && ($filter === '' || str_contains($name, $filter))) {
        $files[] = $dir . '/' . $name;
    }
}
sort($files);

$failed = 0;
foreach ($files as $file) {
    $result = GoldenHarness::replay($file, new MemoryGateway());
    $name = basename($file);
    if ($result['problems'] === []) {
        echo "PASS $name\n";
        continue;
    }
    $failed++;
    echo "FAIL $name\n";
    foreach ($result['problems'] as $problem) {
        echo "   - $problem\n";
    }
}
echo "\n" . count($files) . " golden(s), $failed divergence(s)\n";
exit($failed === 0 ? 0 : 1);
