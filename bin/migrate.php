<?php

declare(strict_types=1);

/**
 * Migration CLI — applies every pending migrations/*.sql in order, tracked by
 * schema_migrations (PM ORDER 9 P-02). Safe to re-run (idempotent).
 *
 * Usage:  php bin/migrate.php            (uses <app root>/config.php or $KANDOO_CONFIG)
 *
 * On a shared host without shell access, the same statements can be pasted into the
 * host's phpMyAdmin/SQL console in file order; the schema_migrations bookkeeping rows
 * are ordinary INSERTs.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */

use Kandoo\Cloud\Config;
use Kandoo\Cloud\ConfigException;
use Kandoo\Cloud\Database;
use Kandoo\Cloud\Migrator;

if (PHP_SAPI !== 'cli') {
    // Never web-accessible (bin/ is denied by .htaccess; this is belt and braces).
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $config = Config::load();
} catch (ConfigException $e) {
    fwrite(STDERR, 'CONFIG ERROR: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Create config.php from config.example.php (one level above the web root).' . PHP_EOL);
    exit(1);
}

try {
    $migrator = new Migrator(new Database($config->all()));
    $applied = $migrator->migrate();
    if ($applied === []) {
        echo "No pending migrations." . PHP_EOL;
        exit(0);
    }
    foreach ($applied as $name) {
        echo "applied: $name" . PHP_EOL;
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'MIGRATION FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
