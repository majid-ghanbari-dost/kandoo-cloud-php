<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Tests\Support\DbTestCase;

final class DemoSetupDatabaseTest extends DbTestCase
{
    public function testInstallerCreatesAndReusesIsolatedDatabaseWithoutOverwriting(): void
    {
        $password=getenv('KANDOO_DEMO_TEST_ROOT_PASSWORD');
        $this->assertTrue(is_string($password), 'CI must supply a synthetic MariaDB root password');
        $directory=sys_get_temp_dir().'/kandoo-demo-test-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory,0700));
        $rootPath=dirname(__DIR__,2); $installer=$rootPath.'/bin/demo-setup.php';
        $environment=array_merge($_ENV, ['PATH'=>(string)getenv('PATH'), 'KANDOO_DEMO_DIR'=>$directory,
            'KANDOO_DEMO_DB_ROOT_PASSWORD'=>$password, 'KANDOO_DEMO_ADMIN_PASSWORD'=>'TEST-admin-password-only']);
        $run=static function (array $args) use ($environment): array {
            $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$environment);
            if (!is_resource($p)) { throw new \RuntimeException('Failed to start demo installer'); }
            fclose($pipes[0]); $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            return [proc_close($p),$output,$error];
        };
        $restoreDb='TEST_RESTORE_'.bin2hex(random_bytes(6));
        $backups=$directory.'/backups'; $backupFile=null;
        try {
            [$status,$output,$error]=$run([PHP_BINARY,$installer,'setup','192.168.1.9','8080']);
            $this->assertSame(0,$status,$output.$error);
            $config=require $directory.'/config.php';
            $this->assertSame('demo',$config['env']);
            $this->assertFalse($config['faraz_sms']['enabled']);
            $before=hash_file('sha256',$directory.'/config.php');
            [$status,$output,$error]=$run([PHP_BINARY,$installer,'check']);
            $this->assertSame(0,$status,$output.$error); $this->assertStringContainsString('SELFTEST PASS',$output);
            [$status]=$run([PHP_BINARY,$installer,'setup','192.168.1.9','8080']);
            $this->assertNotSame(0,$status); $this->assertSame($before,hash_file('sha256',$directory.'/config.php'));
            // XAMPP-like dry-run: same PHP/MariaDB demo setup and isolated CLI backup, on Linux CI (not Windows XAMPP).
            $root=new \PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$password,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
            $demoName=$config['db']['name'];
            $root->exec("INSERT INTO `$demoName`.cloud_cron_heartbeat (id,completed_at) VALUES (1,1234567890) ON DUPLICATE KEY UPDATE completed_at=VALUES(completed_at)");
            $root->exec("CREATE DATABASE `$restoreDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
            $this->assertTrue(mkdir($backups,0700));
            $backupCli=$rootPath.'/bin/backup.php';
            $invoke=static function(array $args,string $configPath) use ($environment): array {
                $env=$environment; $env['KANDOO_CONFIG']=$configPath;
                $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
                if (!is_resource($p)) { throw new \RuntimeException('Failed to start backup CLI'); }
                fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                return [proc_close($p),$out,$err];
            };
            [$status,$output,$error]=$invoke([PHP_BINARY,$backupCli,'create',$backups],$directory.'/config.php');
            $this->assertSame(0,$status,$output.$error); $snapshot=json_decode($output,true,512,JSON_THROW_ON_ERROR); $backupFile=$snapshot['path'];
            $restoreConfig=$config; $restoreConfig['db']['name']=$restoreDb; $restoreConfig['db']['user']='root'; $restoreConfig['db']['pass']=$password;
            $restorePath=$directory.'/restore-config.php'; file_put_contents($restorePath,"<?php return ".var_export($restoreConfig,true).";\n"); chmod($restorePath,0600);
            [$status,$output,$error]=$invoke([PHP_BINARY,$backupCli,'restore-test',$backupFile,$snapshot['sha256']],$restorePath);
            $this->assertSame(0,$status,$output.$error);
            $this->assertSame(1234567890,(int)$root->query("SELECT completed_at FROM `$restoreDb`.cloud_cron_heartbeat WHERE id=1")->fetchColumn());
            $this->assertSame((int)$root->query("SELECT COUNT(*) FROM `$demoName`.schema_migrations")->fetchColumn(),(int)$root->query("SELECT COUNT(*) FROM `$restoreDb`.schema_migrations")->fetchColumn());
        } finally {
            if ($backupFile !== null && is_file($backupFile)) { unlink($backupFile); }
            if (is_dir($backups)) { rmdir($backups); }
            $cleanupRoot=new \PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$password,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
            $cleanupRoot->exec("DROP DATABASE IF EXISTS `$restoreDb`");
            if (is_file($directory.'/config.php')) {
                $config=require $directory.'/config.php'; $name=$config['db']['name']; $user=$config['db']['user'];
                if (preg_match('/^kandoo_demo_[0-9a-f]{12}$/D',$name) && preg_match('/^kd_[0-9a-f]{12}$/D',$user)) {
                    $root=new \PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$password,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
                    $root->exec("DROP DATABASE `$name`");
                    $peer=(string)$root->query("SELECT SUBSTRING_INDEX(USER(),'@',-1)")->fetchColumn();
                    $hosts=['localhost','127.0.0.1'];
                    if (\Kandoo\Cloud\Demo\Lan::privateIp($peer)) { $hosts[]=$peer; }
                    foreach (array_unique($hosts) as $host) { $root->exec('DROP USER '.$root->quote($user).'@'.$root->quote($host)); }
                }
            }
            foreach (glob($directory.'/*') ?: [] as $file) { unlink($file); }
            rmdir($directory);
        }
    }
}
