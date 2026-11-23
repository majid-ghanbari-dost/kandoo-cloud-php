<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Operations;

use Kandoo\Cloud\Database;

/** CLI-only host diagnostics; callers must authenticate before invoking run(). */
final class HostDiagnostics
{
    public static function authenticate(string $candidate, string $expectedHash): bool
    {
        // A 256-bit key encoded as lowercase hex, supplied via stdin, never an argument.
        return preg_match('/^[0-9a-f]{64}$/D', $candidate) === 1
            && preg_match('/^[0-9a-f]{64}$/D', $expectedHash) === 1
            && hash_equals($expectedHash, hash('sha256', $candidate));
    }

    /** $tlsProbe and $ipProbe are injectable so tests never contact outside services. */
    public function __construct(private Database $db, private \Closure $tlsProbe, private \Closure $ipProbe) {}

    public function run(int $now, int $heartbeatMaxAge = 600): array
    {
        $result = [
            'php_version' => PHP_VERSION,
            'php_compatible' => PHP_VERSION_ID >= 80100,
            'extensions' => ['pdo_mysql'=>extension_loaded('pdo_mysql'), 'mbstring'=>extension_loaded('mbstring'), 'openssl'=>extension_loaded('openssl')],
            'faraz_tls' => false, 'outbound_ip' => null,
            'database_version' => null, 'mariadb_compatible' => false,
            'persian_utf8mb4_roundtrip' => false, 'cron_heartbeat' => ['completed_at'=>null,'fresh'=>false],
        ];
        try { $result['faraz_tls'] = ($this->tlsProbe)() === true; } catch (\Throwable $e) { /* no sensitive network errors */ }
        try {
            $ip = ($this->ipProbe)();
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) { $result['outbound_ip'] = $ip; }
        } catch (\Throwable $e) { /* failed probe is reported without URL or exception */ }
        try {
            $pdo=$this->db->pdo();
            $version=$pdo->query('SELECT VERSION()')->fetchColumn();
            if (is_string($version)) {
                $result['database_version']=$version;
                $result['mariadb_compatible']=preg_match('/(\d+)\.(\d+)(?:\.\d+)?-MariaDB/i', $version, $m) === 1
                    && ((int)$m[1] > 10 || ((int)$m[1] === 10 && (int)$m[2] >= 6));
            }
            // Read-only round trip; no test rows written to a production database.
            $text="فاکتور دیجیتال کندو — نیم‌فاصله 🐝";
            $q=$pdo->prepare('SELECT CAST(? AS CHAR CHARACTER SET utf8mb4)'); $q->execute([$text]);
            $result['persian_utf8mb4_roundtrip']=$q->fetchColumn() === $text;
            $result['cron_heartbeat']=(new CronHeartbeat($this->db))->status($now,$heartbeatMaxAge);
        } catch (\Throwable $e) { /* diagnostics report failure without credentials */ }
        return $result;
    }

    /** HTTPS HEAD only: no message, provider credential, or redirect following. */
    public static function farazTls(): bool
    {
        $ctx=stream_context_create(['http'=>['method'=>'HEAD','timeout'=>8,'follow_location'=>0,'ignore_errors'=>true],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $response=@file_get_contents('https://api.iranpayamak.com/', false, $ctx);
        return $response !== false && isset($http_response_header[0])
            && preg_match('#^HTTP/\S+ [1-5][0-9]{2}(?:\s|$)#', $http_response_header[0]) === 1;
    }

    /** Fixed TLS echo service: only the host's IP is disclosed; never sends secrets. */
    public static function publicIp(): ?string
    {
        $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>8,'follow_location'=>0,'ignore_errors'=>false],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
        $body=@file_get_contents('https://api.ipify.org/', false, $ctx, 0, 128);
        return is_string($body) ? trim($body) : null;
    }
}
