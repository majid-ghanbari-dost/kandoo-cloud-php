<?php

declare(strict_types=1);

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Database;
use Kandoo\Cloud\Operations\HostDiagnostics;

// This is deliberately CLI-only. The operator must secure the host account and stdin.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
$hash=getenv('KANDOO_HOST_DIAGNOSTICS_KEY_SHA256');
$key=fgets(STDIN, 67);
if (!is_string($hash) || !is_string($key) || !HostDiagnostics::authenticate(rtrim($key, "\r\n"), $hash)) {
    fwrite(STDERR, "Diagnostics unavailable.\n"); exit(1);
}
unset($key, $hash);
try {
    $db=new Database(Config::load()->all());
    $checks=(new HostDiagnostics($db, [HostDiagnostics::class,'farazTls'](...), [HostDiagnostics::class,'publicIp'](...)))->run(time());
    echo json_encode($checks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
    $ok=$checks['php_compatible'] && !in_array(false,$checks['extensions'],true) && $checks['faraz_tls']
        && $checks['outbound_ip'] !== null && $checks['mariadb_compatible'] && $checks['persian_utf8mb4_roundtrip'] && $checks['cron_heartbeat']['fresh'];
    exit($ok ? 0 : 1);
} catch (Throwable $e) { fwrite(STDERR,"Diagnostics failed.\n"); exit(1); }
