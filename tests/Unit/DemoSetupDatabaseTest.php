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
        } finally {
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
