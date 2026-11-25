<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Operations\LogicalBackup;
use Kandoo\Cloud\Database;
use Kandoo\Cloud\Migrator;
use Kandoo\Cloud\Tests\Support\DbTestCase;

final class LogicalBackupDatabaseTest extends DbTestCase
{
    public function testSyntheticBackupAndIsolatedRestoreGuards(): void
    {
        $password=getenv('KANDOO_DEMO_TEST_ROOT_PASSWORD');
        $this->assertIsString($password,'Synthetic CI MariaDB root password required');
        $suffix=bin2hex(random_bytes(6)); $source='TEST_SOURCE_'.$suffix; $target='TEST_RESTORE_'.$suffix;
        $dir=sys_get_temp_dir().'/kandoo-backup-test-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($dir,0700));
        $options=[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_EMULATE_PREPARES=>false,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC];
        $root=new \PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$password,$options);
        $file=null; $sourceConfig=$dir.'/source-config.php'; $targetConfig=$dir.'/restore-config.php';
        try {
            $root->exec("CREATE DATABASE `$source` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
            $root->exec("CREATE DATABASE `$target` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
            $cfg=self::$config; $cfg['db']=['host'=>'127.0.0.1','port'=>3306,'name'=>$source,'user'=>'root','pass'=>$password,'charset'=>'utf8mb4'];
            $sourceDb=new Database($cfg); $src=$sourceDb->pdo();
            $this->assertNotEmpty((new Migrator($sourceDb))->migrate());
            file_put_contents($sourceConfig,"<?php return ".var_export($cfg,true).";\n"); chmod($sourceConfig,0600);
            $cfg['db']['name']=$target; $targetDb=new Database($cfg); $dst=$targetDb->pdo();
            file_put_contents($targetConfig,"<?php return ".var_export($cfg,true).";\n"); chmod($targetConfig,0600);
            $run=static function (array $args, string $config): array {
                $env=array_merge($_ENV,['PATH'=>(string)getenv('PATH'),'KANDOO_CONFIG'=>$config]);
                $proc=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
                if (!is_resource($proc)) { throw new \RuntimeException('Failed to start backup CLI'); }
                fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
                return [proc_close($proc),$out,$err];
            };
            $cli=dirname(__DIR__,2).'/bin/backup.php';
            $src->exec('CREATE TABLE parent (id BIGINT NOT NULL PRIMARY KEY, label VARCHAR(100) NOT NULL, data BLOB NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $src->exec('CREATE TABLE child (id INT NOT NULL PRIMARY KEY, parent_id BIGINT NOT NULL, CONSTRAINT fk_test_parent FOREIGN KEY (parent_id) REFERENCES parent(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            $insert=$src->prepare('INSERT INTO parent VALUES (?,?,?)'); $insert->execute(['9223372036854775807','فاکتور نیم‌فاصله 🐝',"\x00\xffTEST"]);
            $src->exec('INSERT INTO child VALUES (1,9223372036854775807)');
            [$status,$output,$error]=$run([PHP_BINARY,$cli,'create',$dir],$sourceConfig);
            $this->assertSame(0,$status,$output.$error);
            $backup=json_decode($output,true,512,JSON_THROW_ON_ERROR); $file=$backup['path'];
            $this->assertFileExists($file); $this->assertSame(hash_file('sha256',$file),$backup['sha256']);
            $this->assertSame(0,fileperms($file)&0077);
            try { LogicalBackup::restoreTest($dst,$file,str_repeat('0',64)); $this->fail('Wrong checksum accepted'); } catch (\RuntimeException $e) { $this->assertSame('Backup integrity mismatch',$e->getMessage()); }
            $this->assertSame(0,(int)$dst->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn());
            [$status,$output,$error]=$run([PHP_BINARY,$cli,'restore-test',$file,$backup['sha256']],$targetConfig);
            $this->assertSame(0,$status,$output.$error); $this->assertStringContainsString('ISOLATED RESTORE COMPLETE',$output);
            $this->assertSame([], (new Migrator($targetDb))->pending());
            $this->assertSame((int)$src->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn(),(int)$dst->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn());
            $row=$dst->query('SELECT id,label,data FROM parent')->fetch();
            $this->assertSame('9223372036854775807',(string)$row['id']);
            $this->assertSame('فاکتور نیم‌فاصله 🐝',$row['label']); $this->assertSame("\x00\xffTEST",$row['data']);
            $this->assertSame(1,(int)$dst->query('SELECT COUNT(*) FROM child')->fetchColumn());
            try { LogicalBackup::restoreTest($dst,$file,$backup['sha256']); $this->fail('Nonempty target accepted'); } catch (\RuntimeException $e) { $this->assertSame('Restore target must be empty',$e->getMessage()); }
            try { LogicalBackup::restoreTest($src,$file,$backup['sha256']); $this->fail('Wrong schema accepted'); } catch (\RuntimeException $e) { $this->assertSame('Restore requires an isolated TEST_RESTORE database',$e->getMessage()); }
            try { LogicalBackup::create($src,dirname(__DIR__,2)); $this->fail('Application directory accepted'); } catch (\RuntimeException $e) { $this->assertStringContainsString('outside the application',$e->getMessage()); }
        } finally {
            if ($file !== null && is_file($file)) { unlink($file); }
            foreach ([$sourceConfig,$targetConfig] as $config) { if (is_file($config)) { unlink($config); } }
            $root->exec("DROP DATABASE IF EXISTS `$source`"); $root->exec("DROP DATABASE IF EXISTS `$target`");
            rmdir($dir);
        }
    }
}
