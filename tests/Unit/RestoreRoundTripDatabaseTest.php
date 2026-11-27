<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Customer\CustomerAuth;
use Kandoo\Cloud\Customer\OtpSecrets;
use Kandoo\Cloud\Database;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Kernel;
use Kandoo\Cloud\Migrator;
use Kandoo\Cloud\Store\MariaDbGateway;
use Kandoo\Cloud\Support\FixedClock;
use Kandoo\Cloud\Tests\Support\DbTestCase;

/**
 * PHP-B: business-level proof for the tested backup/restore item.
 *
 * P-30 already proves the archive format, checksum and guard rails. This test proves the
 * thing the operator actually cares about: after `backup.php create` and an isolated
 * `backup.php restore-test` into a separate empty database, the restored Cloud still
 * authenticates the SAME customer session, serves the same invoices and keeps the OTP
 * announcement budget. Everything is synthetic (fake store, fake phone, TEST_ databases).
 */
final class RestoreRoundTripDatabaseTest extends DbTestCase
{
    private const STORE = '11111111-1111-4111-8111-111111111111';
    private const INVOICE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const PHONE = '+989120000001';
    private const TOKEN = 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';
    private const ANNOUNCEMENT = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const NOW = 1767323045;

    public function testRestoredDatabaseStillServesTheSameCustomerSession(): void
    {
        $password = getenv('KANDOO_DEMO_TEST_ROOT_PASSWORD');
        $this->assertIsString($password, 'Synthetic CI MariaDB root password required');
        $suffix = bin2hex(random_bytes(6));
        $source = 'TEST_SOURCE_'.$suffix;
        $target = 'TEST_RESTORE_'.$suffix;
        $dir = sys_get_temp_dir().'/kandoo-restore-rt-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($dir, 0700));
        $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC];
        $root = new \PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', $password, $options);
        $file = null;
        $sourceConfig = $dir.'/source-config.php';
        $restoreConfig = $dir.'/restore-config.php';
        try {
            $root->exec("CREATE DATABASE `$source` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
            $root->exec("CREATE DATABASE `$target` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
            $cfg = self::$config;
            $cfg['db'] = ['host' => '127.0.0.1', 'port' => 3306, 'name' => $source, 'user' => 'root',
                'pass' => $password, 'charset' => 'utf8mb4'];
            $sourceDb = new Database($cfg);
            $src = $sourceDb->pdo();
            $this->assertNotEmpty((new Migrator($sourceDb))->migrate());

            // Synthetic Cloud state: one store, one issued invoice, one announcement, one session.
            $gateway = new MariaDbGateway($sourceDb);
            $gateway->seedStore(['store_id' => self::STORE, 'active_writer_device_id' => self::STORE,
                'active_writer_epoch' => 1, 'accepted_through_seq' => 0, 'entry_count' => 0,
                'head_entry_hash' => null, 'projection_version' => 0]);
            $gateway->seedProjection(['public_token' => 'TESTRESTORETOKEN123456789012', 'public_token_hash' => hash('sha256', 'TESTRESTORETOKEN123456789012'),
                'digital_invoice_id' => self::INVOICE, 'invoice_id' => self::INVOICE, 'store_id' => self::STORE,
                'store_name' => 'TEST Restore Store', 'display_number' => 11, 'lifecycle_status' => 'ISSUED',
                'issued_at' => '2026-01-02 03:04:05', 'issued_payload' => '{}', 'issued_content_sha256' => str_repeat('c', 64),
                'payable_rials' => 44000, 'line_items' => '[]', 'customer_phone' => self::PHONE]);
            $q = $src->prepare('INSERT INTO cloud_sms_announcements (announcement_id,store_id,invoice_id,phone,delivered_at,code_issues) VALUES (?,?,?,?,?,?)');
            $q->execute([self::ANNOUNCEMENT, self::STORE, self::INVOICE, self::PHONE, self::NOW, 1]);
            $q = $src->prepare('INSERT INTO cloud_customer_sessions (token_hash,phone,expires_at) VALUES (?,?,?)');
            $q->execute([OtpSecrets::sessionHash(self::TOKEN), self::PHONE, self::NOW + 3600]);
            file_put_contents($sourceConfig, "<?php return ".var_export($cfg, true).";\n"); chmod($sourceConfig, 0600);

            $cfg['db']['name'] = $target;
            $targetDb = new Database($cfg);
            $dst = $targetDb->pdo();
            file_put_contents($restoreConfig, "<?php return ".var_export($cfg, true).";\n"); chmod($restoreConfig, 0600);

            $run = static function (array $args, string $config): array {
                $env = array_merge($_ENV, ['PATH' => (string) getenv('PATH'), 'KANDOO_CONFIG' => $config]);
                $proc = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
                if (!is_resource($proc)) { throw new \RuntimeException('Failed to start backup CLI'); }
                fclose($pipes[0]);
                $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                return [proc_close($proc), $out, $err];
            };
            $cli = dirname(__DIR__, 2).'/bin/backup.php';
            [$status, $output, $error] = $run([PHP_BINARY, $cli, 'create', $dir], $sourceConfig);
            $this->assertSame(0, $status, $output.$error);
            $backup = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $file = $backup['path'];
            $this->assertSame(hash_file('sha256', $file), $backup['sha256']);

            [$status, $output, $error] = $run([PHP_BINARY, $cli, 'restore-test', $file, $backup['sha256']], $restoreConfig);
            $this->assertSame(0, $status, $output.$error);
            $this->assertStringContainsString('ISOLATED RESTORE COMPLETE', $output);

            // Schema and row counts survive unchanged, including the new read-bucket table.
            $this->assertSame([], (new Migrator($targetDb))->pending());
            $this->assertSame(
                (int) $src->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn(),
                (int) $dst->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn()
            );
            foreach (['cloud_stores', 'public_invoices', 'cloud_customer_sessions', 'cloud_sms_announcements', 'customer_read_rate_buckets'] as $table) {
                $this->assertSame(
                    (int) $src->query("SELECT COUNT(*) FROM $table")->fetchColumn(),
                    (int) $dst->query("SELECT COUNT(*) FROM $table")->fetchColumn(),
                    $table
                );
            }
            $this->assertSame(1, (int) $dst->query('SELECT code_issues FROM cloud_sms_announcements')->fetchColumn());

            // Business proof: the restored Cloud answers the pre-backup Bearer exactly as before.
            $restoredDb = new Database($cfg);
            $settings = ['enabled' => true, 'code_length' => 6, 'code_ttl_seconds' => 300, 'max_attempts' => 3,
                'codes_per_announcement' => 2, 'issue_window_seconds' => 900, 'max_issues_per_window' => 3,
                'session_ttl_seconds' => 3600, 'otp_pepper' => 'TEST-only-synthetic-otp-pepper-0123456789',
                'telegram_url' => null, 'read_ip_limit_per_minute' => 0, 'read_session_limit_per_minute' => 0];
            $clock = new FixedClock(self::NOW);
            $config = self::$config;
            $config['customer_auth'] = $settings;
            $kernel = new Kernel($config, new MariaDbGateway($restoredDb), $clock,
                new CustomerAuth($settings, $restoredDb, $clock));
            $headers = ['authorization' => 'Bearer '.self::TOKEN];
            $list = $kernel->handle(new Request('GET', '/v1/customer/invoices', $headers, [], '', null, '192.0.2.10'));
            $this->assertSame(200, $list->status());
            $this->assertStringContainsString('TEST Restore Store', $list->body());
            $this->assertStringContainsString(self::INVOICE, $list->body());
            $detail = $kernel->handle(new Request('GET', '/v1/customer/invoices/'.self::STORE.'/'.self::INVOICE, $headers, [], '', null, '192.0.2.10'));
            $this->assertSame(200, $detail->status());
            $this->assertSame(44000, json_decode($detail->body(), true, 512, JSON_THROW_ON_ERROR)['invoice']['payable_rials']);
            $resume = $kernel->handle(new Request('GET', '/v1/customer/session', $headers, [], '', null, '192.0.2.10'));
            $this->assertSame(200, $resume->status());
            $this->assertSame(self::NOW + 3600, json_decode($resume->body(), true, 512, JSON_THROW_ON_ERROR)['expires_at']);
        } finally {
            if ($file !== null && is_file($file)) { unlink($file); }
            foreach ([$sourceConfig, $restoreConfig] as $config) { if (is_file($config)) { unlink($config); } }
            $root->exec("DROP DATABASE IF EXISTS `$source`");
            $root->exec("DROP DATABASE IF EXISTS `$target`");
            if (is_dir($dir)) { rmdir($dir); }
        }
    }
}
