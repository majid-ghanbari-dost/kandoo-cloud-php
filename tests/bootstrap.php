<?php

declare(strict_types=1);

/**
 * Test bootstrap — فاکتور دیجیتال کندو (Kandoo Digital Invoice) Cloud.
 *
 * CI creates <app root>/config.php from environment values via tests/ci/write-config.php
 * (FAKE credentials generated at runtime — never committed). Locally, copy
 * config.example.php to config.php or point KANDOO_CONFIG at a test config.
 * DB-backed tests self-skip unless KANDOO_DB_TESTS=1 and the database is reachable.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

// Autoload test-support classes (Kandoo\Cloud\Tests\… → tests/…).
spl_autoload_register(static function (string $class): void {
    $prefix = 'Kandoo\\Cloud\\Tests\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});
