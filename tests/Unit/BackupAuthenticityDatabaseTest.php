<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Database;
use Kandoo\Cloud\Migrator;
use Kandoo\Cloud\Operations\LogicalBackup;
use Kandoo\Cloud\Tests\Support\DbTestCase;

/**
 * PHP-B / P-34: the recorded finding "SHA-256 does not prove authenticity — anyone who can
 * replace the file can recompute the digest" is closed by an optional HMAC key.
 *
 * Proves: `bin/backup.php create` signs the archive when `backup_hmac_key` is configured, and
 * `restore-test` refuses a missing, wrong or unsupported signature BEFORE touching the target
 * database; the signed path still restores. Synthetic TEST_ databases only.
 */
final class BackupAuthenticityDatabaseTest extends DbTestCase
{
    private const KEY = 'TEST-only-synthetic-backup-hmac-key-0123456789';

    public function testSignedArchiveIsRequiredBeforeAnyRestoreWork(): void
    {
        $password = getenv('KANDOO_DEMO_TEST_ROOT_PASSWORD');
        $this->assertIsString($password, 'Synthetic CI MariaDB root password required');
        $suffix = bin2hex(random_bytes(6));
        $source = 'TEST_SOURCE_'.$suffix;
        $target = 'TEST_RESTORE_'.$suffix;
        $dir = sys_get_temp_dir().'/kandoo-hmac-test-'.bin2hex(random_bytes(8));
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
            $cfg['backup_hmac_key'] = self::KEY;
            $sourceDb = new Database($cfg);
            $src = $sourceDb->pdo();
            $this->assertNotEmpty((new Migrator($sourceDb))->migrate());
            $src->exec("INSERT INTO cloud_stores (store_id, active_writer_device_id, active_writer_epoch, accepted_through_seq, entry_count, head_entry_hash, projection_version, throttled) VALUES ('11111111-1111-4111-8111-111111111111','11111111-1111-4111-8111-111111111111',1,0,0,NULL,0,0)");
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
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) ($backup['hmac_sha256'] ?? ''));
            $this->assertSame(hash_hmac_file('sha256', $file, self::KEY), $backup['hmac_sha256']);

            $tablesIn = static fn (\PDO $pdo): int => (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn();
            $this->assertSame(0, $tablesIn($dst));

            // 1) No signature while a key is configured: refused before any DDL.
            try { LogicalBackup::restoreTest($dst, $file, $backup['sha256'], self::KEY); $this->fail('Unsigned restore accepted'); }
            catch (\RuntimeException $e) { $this->assertSame('Backup authenticity required', $e->getMessage()); }
            // 2) Wrong signature: refused.
            try { LogicalBackup::restoreTest($dst, $file, $backup['sha256'], self::KEY, str_repeat('0', 64)); $this->fail('Wrong signature accepted'); }
            catch (\RuntimeException $e) { $this->assertSame('Backup authenticity mismatch', $e->getMessage()); }
            // 3) A signature cannot be checked without a key: the check never silently degrades.
            try { LogicalBackup::restoreTest($dst, $file, $backup['sha256'], null, $backup['hmac_sha256']); $this->fail('Signature accepted without a key'); }
            catch (\RuntimeException $e) { $this->assertSame('Authenticity key not configured', $e->getMessage()); }
            $this->assertSame(0, $tablesIn($dst), 'refused restores must not touch the target');

            // 4) The correctly signed archive restores.
            [$status, $output, $error] = $run([PHP_BINARY, $cli, 'restore-test', $file, $backup['sha256'], $backup['hmac_sha256']], $restoreConfig);
            $this->assertSame(0, $status, $output.$error);
            $this->assertStringContainsString('ISOLATED RESTORE COMPLETE', $output);
            $this->assertSame([], (new Migrator($targetDb))->pending());
            $this->assertSame($tablesIn($src), $tablesIn($dst));
            $this->assertSame(1, (int) $dst->query('SELECT COUNT(*) FROM cloud_stores')->fetchColumn());
        } finally {
            if ($file !== null && is_file($file)) { unlink($file); }
            foreach ([$sourceConfig, $restoreConfig] as $config) { if (is_file($config)) { unlink($config); } }
            $root->exec("DROP DATABASE IF EXISTS `$source`");
            $root->exec("DROP DATABASE IF EXISTS `$target`");
            if (is_dir($dir)) { rmdir($dir); }
        }
    }
}
