<?php

declare(strict_types=1);

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Database;
use Kandoo\Cloud\Migrator;
use Kandoo\Cloud\Operations\CronHeartbeat;

/**
 * Read-only host preflight for the shared host runbook (P-30, PHP-B).
 *
 * Answers one question before a release: "does this host look like the deployment the runbook
 * assumes?" It WRITES NOTHING — no rows, no files, no migrations — so it is safe to run against
 * the real database, and it never prints a secret value (only lengths, counts and verdicts).
 *
 *   php bin/preflight.php            human lines, exit 1 if any FAIL
 *   php bin/preflight.php --strict   WARN also fails (go-live gate)
 *   php bin/preflight.php --json     machine-readable result for the operator's log
 *
 * Passing preflight is NOT deploy approval: HTTPS, proxy configuration, web-server access logs,
 * backup transfer and the pen test stay with the operator (docs/SECURITY-REVIEW-P34.md).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';

$strict = in_array('--strict', $argv, true);
$json = in_array('--json', $argv, true);

/** @var list<array{level:string,name:string,detail:string}> $results */
$results = [];
$record = static function (string $level, string $name, string $detail) use (&$results): void {
    $results[] = ['level' => $level, 'name' => $name, 'detail' => $detail];
};
$pass = static fn (string $name, string $detail = 'ok') => $record('PASS', $name, $detail);
$warn = static fn (string $name, string $detail) => $record('WARN', $name, $detail);
$fail = static fn (string $name, string $detail) => $record('FAIL', $name, $detail);

// 1) Runtime and extensions.
$missing = array_values(array_filter(['pdo_mysql', 'mbstring', 'openssl', 'json'],
    static fn (string $extension): bool => !extension_loaded($extension)));
if (PHP_VERSION_ID < 80100) {
    $fail('php', 'PHP 8.1+ required, found '.PHP_VERSION);
} elseif ($missing !== []) {
    $fail('php', 'missing extension(s): '.implode(', ', $missing));
} else {
    $pass('php', PHP_VERSION.' with pdo_mysql, mbstring, openssl, json');
}

// 2) Configuration file: present, valid, outside the web root, not world-readable.
$configPath = Config::defaultPath();
$root = kandoo_app_root();
$config = null;
try {
    $config = Config::load($configPath)->all();
    $pass('config', 'loaded (values are never printed)');
} catch (Throwable $e) {
    $fail('config', 'not loadable: '.$e->getMessage());
}
if (is_file($configPath)) {
    // Independent of whether the file is valid: a config inside the web root is served by the
    // web server whatever it contains.
    $real = realpath($configPath);
    $publicRootReal = realpath($root.'/public');
    if ($real === false) {
        $fail('config_location', 'config path cannot be resolved');
    } elseif ($publicRootReal !== false && str_starts_with($real, $publicRootReal.DIRECTORY_SEPARATOR)) {
        $fail('config_location', 'config.php is INSIDE the web root');
    } else {
        $pass('config_location', 'outside the web root');
    }
    $mode = @fileperms($configPath);
    $env_ = (string) ($config['env'] ?? '');
    if (is_int($mode)) {
        $permissions = $mode & 0777;
        if (($permissions & 0002) !== 0) {
            $fail('config_permissions', 'world-writable ('.decoct($permissions).'), use 0600 or 0640');
        } elseif (($permissions & 0004) !== 0) {
            $detail = 'world-readable ('.decoct($permissions).'), use 0600 or 0640';
            $env_ === 'production' ? $fail('config_permissions', $detail) : $warn('config_permissions', $detail);
        } else {
            $pass('config_permissions', decoct($permissions));
        }
    } else {
        $warn('config_permissions', 'file mode not readable by this account');
    }
}
if ($config !== null) {

    // 3) Deployment-facing configuration policy (no values printed).
    $env = (string) ($config['env'] ?? '');
    if (in_array($env, ['production', 'demo'], true)) {
        $pass('env', $env);
    } else {
        $warn('env', $env.' (expected production on the real host)');
    }
    $baseUrl = (string) ($config['base_url'] ?? '');
    if (str_starts_with($baseUrl, 'https://')) {
        $pass('base_url', 'https');
    } elseif ($env === 'demo') {
        $warn('base_url', 'not https (allowed only for the LAN demo kit)');
    } else {
        $fail('base_url', 'production base_url must be https');
    }
    if (($config['customer_auth']['enabled'] ?? false) === true) {
        $readIp = (int) ($config['customer_auth']['read_ip_limit_per_minute'] ?? 120);
        $readSession = (int) ($config['customer_auth']['read_session_limit_per_minute'] ?? 60);
        if ($readIp < 1 || $readSession < 1) {
            $warn('customer_read_budget', 'disabled (limit 0) — a stolen Bearer is not rate limited');
        } else {
            $pass('customer_read_budget', $readIp.'/min per client, '.$readSession.'/min per session');
        }
    } else {
        $warn('customer_read_budget', 'customer_auth disabled, nothing to bound');
    }
    $pepper = (string) ($config['customer_auth']['otp_pepper'] ?? '');
    if (strlen($pepper) >= 32) {
        $pass('otp_pepper', 'configured ('.strlen($pepper).' bytes)');
    } elseif ($pepper === '') {
        $fail('otp_pepper', 'empty — customer OTP cannot run');
    } else {
        $fail('otp_pepper', 'shorter than 32 bytes');
    }
    if (($config['replication_key'] ?? null) === null && ($config['replication_auth_bypass'] ?? false) === false) {
        $warn('replication_key', 'not set (device-authenticated ingest only)');
    } else {
        $pass('replication_key', 'device authentication configured');
    }
    if (($config['admin_password_hash'] ?? null) === null) {
        $warn('admin_password_hash', 'not set — the admin panel stays off');
    } else {
        $pass('admin_password_hash', 'configured');
    }
    if (($config['backup_hmac_key'] ?? null) === null) {
        $warn('backup_hmac_key', 'not set — archives are checksummed but not authenticated');
    } else {
        $pass('backup_hmac_key', 'configured (signatures enforced on restore-test)');
    }
    $proxies = (array) ($config['trusted_proxies'] ?? []);
    if ($proxies === []) {
        $warn('trusted_proxies', 'empty — rate limits key on the direct peer (set it if a reverse proxy is in front)');
    } else {
        $pass('trusted_proxies', count($proxies).' proxy address(es) trusted');
    }
}

// 4) Web root contents: only public entry points, no config, no dumps, no backups.
$publicRoot = $root.'/public';
$allowed = ['index.php' => true, 'assets' => true, '.htaccess' => true, 'robots.txt' => true, 'favicon.ico' => true];
if (!is_dir($publicRoot)) {
    $fail('web_root', 'public/ is missing');
} else {
    $unexpected = [];
    foreach (scandir($publicRoot) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        if (!isset($allowed[$entry])) { $unexpected[] = $entry; }
    }
    if ($unexpected === []) {
        $pass('web_root', 'only public entry points');
    } else {
        $fail('web_root', 'unexpected entr(y/ies) in public/: '.implode(', ', $unexpected));
    }
}
$leaks = [];
foreach (['jsonl', 'sql', 'bak', 'gz', 'zip', 'env', 'log'] as $extension) {
    $found = glob($publicRoot.'/*.'.$extension) ?: [];
    foreach ($found as $path) { $leaks[] = basename($path); }
}
if ($leaks === []) {
    $pass('no_dumps_in_web_root', 'no backup/dump/log files under public/');
} else {
    $fail('no_dumps_in_web_root', 'data files under public/: '.implode(', ', $leaks));
}

// 5) Database: connectivity, charset, migrations, runtime tables, cron heartbeat (read-only).
if ($config !== null) {
    try {
        $db = new Database($config);
        $pdo = $db->pdo();
        $charset = (string) $pdo->query('SELECT @@character_set_database')->fetchColumn();
        if ($charset === 'utf8mb4') {
            $pass('database', 'connected, utf8mb4');
        } else {
            $fail('database', 'charset is '.($charset !== '' ? $charset : 'unknown').', utf8mb4 required');
        }
        $pending = (new Migrator($db))->pending();
        if ($pending === []) {
            $pass('migrations', 'none pending');
        } else {
            $fail('migrations', count($pending).' pending — run bin/migrate.php before serving');
        }
        $required = ['cloud_stores', 'cloud_sms_announcements', 'cloud_customer_sessions', 'cloud_otp_challenges',
            'customer_read_rate_buckets', 'admin_rate_buckets', 'cloud_cron_heartbeat'];
        $present = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')
            ->fetchAll(PDO::FETCH_COLUMN);
        $absent = array_values(array_diff($required, array_map('strval', $present)));
        if ($absent === []) {
            $pass('tables', count($required).' runtime tables present');
        } else {
            $fail('tables', 'missing: '.implode(', ', $absent));
        }
        $heartbeat = (new CronHeartbeat($db))->status(time(), 93600);
        if (($heartbeat['completed_at'] ?? null) === null) {
            $warn('cron_heartbeat', 'no successful maintenance run yet (expected on a fresh install)');
        } elseif ($heartbeat['fresh'] === true) {
            $pass('cron_heartbeat', 'fresh (within 26h)');
        } else {
            $fail('cron_heartbeat', 'stale — check the cron entry before serving');
        }
    } catch (Throwable $e) {
        $fail('database', 'not reachable with the configured credentials ('.get_class($e).')');
    }
}

$failCount = count(array_filter($results, static fn (array $row): bool => $row['level'] === 'FAIL'));
$warnCount = count(array_filter($results, static fn (array $row): bool => $row['level'] === 'WARN'));
$exitCode = $failCount > 0 || ($strict && $warnCount > 0) ? 1 : 0;

if ($json) {
    echo json_encode(['status' => $exitCode === 0 ? 'PASS' : 'FAIL', 'strict' => $strict,
        'fail' => $failCount, 'warn' => $warnCount, 'checks' => $results], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
} else {
    foreach ($results as $row) {
        echo str_pad($row['level'], 5).' '.$row['name'].': '.$row['detail'], PHP_EOL;
    }
    echo PHP_EOL, $exitCode === 0 ? 'PREFLIGHT PASS' : 'PREFLIGHT FAIL',
        ' ('.$failCount.' fail, '.$warnCount.' warn', $strict ? ', strict' : '', ')', PHP_EOL;
    echo 'Passing preflight is not deploy approval: HTTPS, proxy setup, access logs and the pen test stay with the operator.', PHP_EOL;
}
exit($exitCode);
