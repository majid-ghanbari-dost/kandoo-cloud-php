<?php

declare(strict_types=1);

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Customer\CustomerAuth;
use Kandoo\Cloud\Customer\OtpTransport;
use Kandoo\Cloud\Database;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Kernel;
use Kandoo\Cloud\Migrator;
use Kandoo\Cloud\Service\AdminManager;
use Kandoo\Cloud\Sms\FarazAdapter;
use Kandoo\Cloud\Store\MariaDbGateway;
use Kandoo\Cloud\Store\MemoryGateway;
use Kandoo\Cloud\Support\RandomToken;
use Kandoo\Cloud\Support\SystemClock;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
if (count($argv) !== 2 || $argv[1] !== '--sample') { fwrite(STDERR, "Usage: php bin/selftest.php --sample (isolated TEST_ database only)\n"); exit(2); }

$check = static function (bool $ok, string $name): void {
    if (!$ok) { throw new RuntimeException($name); }
    echo "PASS $name\n";
};
$pdo = null; $store = null; $phone = null; $idem = null;
try {
    $config=Config::load()->all();
    // Self-test writes temporary synthetic rows, so it must never target production data.
    if ($config['env'] !== 'testing' || !str_starts_with($config['db']['name'], 'TEST_') || getenv('KANDOO_BLOCK_REAL_SMS') !== '1') {
        throw new RuntimeException('sample isolation guard');
    }
    $check(PHP_VERSION_ID >= 80100 && extension_loaded('pdo_mysql') && function_exists('random_bytes'), 'environment');
    $check(true, 'config');
    $db=new Database($config); $pdo=$db->pdo();
    $check((int)$pdo->query('SELECT 1')->fetchColumn() === 1 && (new Migrator($db))->pending() === [], 'database and migrations');
    $settings=['dry_run'=>true,'api_key'=>'TEST-FAKE','line_number'=>'TEST-LINE','announcement_pattern'=>'TEST-NOTICE','otp_pattern'=>'TEST-OTP'];
    $sms=new FarazAdapter($settings);
    $check($sms->sendAnnouncement('+989120000001') && $sms->sendCode('+989120000001','123456') && count($sms->dryRequests())===2, 'SMS dry patterns');
    $clock=new SystemClock(); $memory=new MemoryGateway();
    $store=RandomToken::uuidV4(); $device=RandomToken::uuidV4(); $invoice=RandomToken::uuidV4(); $announcement=RandomToken::uuidV4();
    $memory->seedStore(['store_id'=>$store,'active_writer_device_id'=>$device,'active_writer_epoch'=>1,'accepted_through_seq'=>0,'entry_count'=>0,'head_entry_hash'=>null,'projection_version'=>0]);
    $deviceSecret='TEST-synthetic-device-secret-for-selftest';
    $memory->insertDevice($device,$store,hash('sha256',$deviceSecret),gmdate('Y-m-d H:i:s'));
    $authConfig=$config; $authConfig['replication_auth_bypass']=false; $authConfig['replication_key']=null;
    $kernel=new Kernel($authConfig,$memory,$clock);
    $path='/v1/stores/'.$store.'/operation-batches';
    $no=$kernel->handle(new Request('POST',$path));
    $yes=$kernel->handle(new Request('POST',$path,['x-kandoo-device-id'=>$device,'x-kandoo-device-secret'=>$deviceSecret],[],'{'));
    $check($no->status()===401 && $yes->status()===400 && $memory->findDevice($device)['verified']===true, 'replication auth');
    $adminPassword='TEST-selftest-admin-only';
    $manager=new AdminManager(['admin_password_hash'=>password_hash($adminPassword,PASSWORD_DEFAULT)],$memory);
    $check(!$memory->isAdminEnabled() && !$manager->transition(true,'TEST-wrong',gmdate('Y-m-d H:i:s')) && $manager->transition(true,$adminPassword,gmdate('Y-m-d H:i:s')) && $manager->transition(false,$adminPassword,gmdate('Y-m-d H:i:s')), 'admin auth');
    $gateway=new MariaDbGateway($db);
    $gateway->seedStore(['store_id'=>$store,'active_writer_device_id'=>$device,'active_writer_epoch'=>1,'accepted_through_seq'=>0,'entry_count'=>0,'head_entry_hash'=>null,'projection_version'=>0]);
    $phone='+989'.str_pad((string)random_int(0,999999999),9,'0',STR_PAD_LEFT);
    $token='TEST'.RandomToken::generate();
    $gateway->seedProjection(['public_token'=>$token,'public_token_hash'=>hash('sha256',$token),'digital_invoice_id'=>$invoice,'invoice_id'=>$invoice,'store_id'=>$store,'store_name'=>'TEST Store','display_number'=>1,'lifecycle_status'=>'ISSUED','issued_at'=>'2026-01-02 03:04:05','issued_payload'=>'{}','issued_content_sha256'=>str_repeat('a',64),'payable_rials'=>1,'line_items'=>'[]','customer_phone'=>$phone]);
    $q=$pdo->prepare('INSERT INTO cloud_sms_announcements (announcement_id,store_id,invoice_id,phone,delivered_at) VALUES (?,?,?,?,?)'); $q->execute([$announcement,$store,$invoice,$phone,$clock->nowUnix()]);
    $fake=new class implements OtpTransport { public string $code=''; public function sendCode(string $phone,string $code): bool { $this->code=$code; return true; } };
    $customer=['enabled'=>true,'code_length'=>6,'code_ttl_seconds'=>300,'max_attempts'=>5,'codes_per_announcement'=>2,'issue_window_seconds'=>900,'max_issues_per_window'=>3,'session_ttl_seconds'=>3600,'otp_pepper'=>'TEST-selftest-otp-pepper-not-a-real-secret','telegram_url'=>null];
    $idem='TEST-selftest-'.RandomToken::uuidV4();
    $auth=new CustomerAuth($customer,$db,$clock,$fake); $sampleDevice=\Kandoo\Cloud\Customer\OtpSecrets::sessionToken(); $issued=$auth->issue($phone,$idem,$sampleDevice);
    $verified=($issued['status']??'')==='OTP_SENT' ? $auth->verify($issued['challenge_id'],$fake->code,$sampleDevice) : ['status'=>'INVALID'];
    $session=$verified['token']??'';
    $check(($verified['status']??'')==='OK' && $auth->phoneForToken($session)===$phone && count($auth->invoices($phone))>=1 && $auth->invoice($phone,$store,$invoice)!==null, 'customer login and invoice');
    echo "SELFTEST PASS (isolated sample; no live SMS)\n";
} catch (Throwable $e) {
    fwrite(STDERR, "SELFTEST FAILED: " . (isset($check) ? 'check or setup failed' : 'setup failed') . "\n");
    $failed=true;
} finally {
    if ($pdo instanceof PDO && $store !== null) {
        try {
            if ($phone !== null) { $q=$pdo->prepare('DELETE FROM cloud_customer_sessions WHERE phone=?'); $q->execute([$phone]); }
            $q=$pdo->prepare('DELETE FROM cloud_stores WHERE store_id=?'); $q->execute([$store]);
            if ($phone !== null) { $q=$pdo->prepare('DELETE FROM cloud_customer_phones WHERE phone=?'); $q->execute([$phone]); }
            if (isset($sampleDevice)) { $q=$pdo->prepare('DELETE FROM cloud_customer_devices WHERE device_hash=?'); $q->execute([hash_hmac('sha256',$sampleDevice,'TEST-selftest-otp-pepper-not-a-real-secret')]); }
            if ($idem !== null) { $q=$pdo->prepare('DELETE FROM cloud_otp_idempotency_locks WHERE idempotency_hash=?'); $q->execute([hash_hmac('sha256',$idem,'TEST-selftest-otp-pepper-not-a-real-secret')]); }
        } catch (Throwable $e) { fwrite(STDERR, "SELFTEST CLEANUP FAILED\n"); $failed=true; }
    }
}
exit(!empty($failed) ? 1 : 0);
