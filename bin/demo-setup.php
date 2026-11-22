<?php

declare(strict_types=1);

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Database;
use Kandoo\Cloud\Demo\Lan;
use Kandoo\Cloud\Migrator;
use Kandoo\Cloud\Service\AdminManager;
use Kandoo\Cloud\Store\MariaDbGateway;
use Kandoo\Cloud\Support\RandomToken;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
$dir=getenv('KANDOO_DEMO_DIR') ?: dirname(__DIR__).'/.kandoo-demo';
$configPath=$dir.'/config.php'; $credentialPath=$dir.'/hub-credentials.json';
$command=$argv[1] ?? '';
$stage='validation';
try {
    if ($command === 'setup') {
        $ip=$argv[2] ?? ''; $port=$argv[3] ?? '';
        if (!Lan::privateIp($ip) || preg_match('/^[0-9]{1,5}$/D',$port)!==1 || (int)$port < 1024 || (int)$port > 65535) { throw new RuntimeException('Invalid LAN address or port'); }
        if (!is_dir($dir) || file_exists($configPath) || file_exists($credentialPath)) { throw new RuntimeException('Demo configuration exists or private directory absent; nothing overwritten'); }
        $dbPassword=getenv('KANDOO_DEMO_DB_ROOT_PASSWORD'); $admin=getenv('KANDOO_DEMO_ADMIN_PASSWORD');
        if (!is_string($dbPassword) || !is_string($admin) || strlen($admin)<12 || strlen($admin)>128) { throw new RuntimeException('Admin password must be 12–128 bytes'); }
        $stage='root connection';
        $root=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root',$dbPassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
        $suffix=bin2hex(random_bytes(6)); $dbName='kandoo_demo_'.$suffix; $dbUser='kd_'.$suffix; $dbSecret=bin2hex(random_bytes(24));
        // Never select or modify an existing DB. No automatic destructive rollback on failure.
        $stage='create database';
        $root->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin");
        $stage='create local accounts';
        // On a containerized MariaDB the forwarded loopback connection is seen as a
        // bridge address; grant only that exact private peer, never a wildcard.
        $peer=(string)$root->query("SELECT SUBSTRING_INDEX(USER(),'@',-1)")->fetchColumn();
        $hosts=['localhost', '127.0.0.1'];
        if (Lan::privateIp($peer)) { $hosts[]=$peer; }
        foreach (array_unique($hosts) as $host) {
            $account=$root->quote($dbUser).'@'.$root->quote($host);
            $root->exec('CREATE USER '.$account.' IDENTIFIED BY '.$root->quote($dbSecret));
            $root->exec("GRANT ALL PRIVILEGES ON `$dbName`.* TO ".$account);
        }
        $adminHash=password_hash($admin,PASSWORD_DEFAULT); if (!is_string($adminHash)) { throw new RuntimeException('Password hashing failed'); }
        $raw=['base_url'=>"http://$ip:$port",'env'=>'demo','db'=>['host'=>'127.0.0.1','port'=>3306,'name'=>$dbName,'user'=>$dbUser,'pass'=>$dbSecret,'charset'=>'utf8mb4'], 'admin_password_hash'=>$adminHash,'customer_auth'=>['enabled'=>true, 'code_length'=>6, 'code_ttl_seconds'=>300,
            'max_attempts'=>3, 'codes_per_announcement'=>2, 'issue_window_seconds'=>900, 'max_issues_per_window'=>3,
            'session_ttl_seconds'=>2592000, 'device_ttl_seconds'=>2592000, 'device_numbers_per_day'=>3,
            'issue_ip_limit_per_minute'=>10, 'verify_ip_limit_per_minute'=>20, 'otp_pepper'=>bin2hex(random_bytes(32)),
            'telegram_url'=>null], 'faraz_sms'=>['enabled'=>false]];
        $stage='migrate';
        $config=Config::validate($raw); $db=new Database($config); (new Migrator($db))->migrate();
        $stage='provision';
        $gateway=new MariaDbGateway($db); $store=RandomToken::uuidV4(); $device=RandomToken::uuidV4(); $secret=bin2hex(random_bytes(32));
        $gateway->seedStore(['store_id'=>$store,'active_writer_device_id'=>$device,'active_writer_epoch'=>1,'accepted_through_seq'=>0,'entry_count'=>0,'head_entry_hash'=>null,'projection_version'=>0]);
        $gateway->insertDevice($device,$store,hash('sha256',$secret),gmdate('Y-m-d H:i:s'));
        if (!(new AdminManager($config,$gateway))->transition(true,$admin,gmdate('Y-m-d H:i:s'))) { throw new RuntimeException('Admin activation failed'); }
        // The launcher pre-creates a Windows ACL-protected directory. Secrets stay outside public/.
        $stage='write protected files';
        if (file_put_contents($credentialPath,json_encode(['store_id'=>$store,'device_id'=>$device,'device_secret'=>$secret,'writer_epoch'=>1],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX)===false) { throw new RuntimeException('Cannot write credentials'); }
        if (file_put_contents($configPath,"<?php\nreturn ".var_export($raw,true).";\n",LOCK_EX)===false) { throw new RuntimeException('Cannot write config'); }
        echo "Demo provisioned; credentials stored in protected local directory.\n";
    } elseif ($command === 'check') {
        $config=Config::load($configPath)->all();
        if ($config['env'] !== 'demo' || !is_file($credentialPath)) { throw new RuntimeException('Not a complete demo'); }
        $credentials=json_decode((string)file_get_contents($credentialPath),true,512,JSON_THROW_ON_ERROR);
        $db=new Database($config); $gateway=new MariaDbGateway($db);
        $store=$gateway->findStore($credentials['store_id']); $device=$gateway->findDevice($credentials['device_id']);
        if ((new Migrator($db))->pending() !== [] || $store===null || $device===null || !$gateway->isAdminEnabled()
            || $device['store_id']!==$credentials['store_id'] || !hash_equals($device['secret_sha256'],hash('sha256',$credentials['device_secret']))) { throw new RuntimeException('Demo self-test failed'); }
        echo "SELFTEST PASS: database, migrations, admin, store, device key, real SMS disabled.\n";
        echo 'LAN: '.$config['base_url']."\n";
    } elseif ($command === 'address') {
        $config=Config::load($configPath)->all();
        if ($config['env'] !== 'demo') { throw new RuntimeException('Not demo'); }
        $url=parse_url($config['base_url']); echo $url['host'].':'.($url['port'] ?? 80);
    } else { throw new RuntimeException('Usage: setup <private-ip> <port> | check | address'); }
} catch (Throwable $e) {
    // Never print credentials or database errors; failure may leave an unused DB, never auto-drop.
    $code = $e instanceof PDOException ? (string)($e->errorInfo[1] ?? 'unknown') : 'n/a';
    fwrite(STDERR,"Demo setup/check failed at $stage (DB error $code). Check XAMPP and prerequisites. No existing DB was removed.\n");
    exit(1);
}
