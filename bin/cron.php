<?php

declare(strict_types=1);

/**
 * Minute cron — prunes stale rate-limit windows (PM ORDER 9 P-02: shared host uses our
 * own cron; D2). Schedule on the host (every minute, or every 5 minutes — pruning is
 * idempotent):
 *
 *   * * * * * php /path/to/app/bin/cron.php >> /path/to/private/cron.log 2>&1
 *
 * Keeps rows for the current and previous 1-minute window only. Never touches contract
 * data (cloud_entries / public_invoices / cloud_stores).
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */

use Kandoo\Cloud\Config;
use Kandoo\Cloud\ConfigException;
use Kandoo\Cloud\Database;
use Kandoo\Cloud\Store\MariaDbGateway;
use Kandoo\Cloud\Retention\Cleanup;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $config = Config::load();
    $db = new Database($config->all());
    $gateway = new MariaDbGateway($db);
    $now = time();
    $currentWindow = intdiv($now, 60) * 60;
    $gateway->pruneRateBuckets($currentWindow - 60); // keep current + previous window
    $counts = (new Cleanup($db, $config->get('retention'), $config->get('customer_auth')['issue_window_seconds'] ?? 0))->run($now);
    fwrite(STDOUT, gmdate('c', $now) . ' retention '.json_encode($counts, JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(0);
} catch (ConfigException $e) {
    fwrite(STDERR, 'CONFIG ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'CRON FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
