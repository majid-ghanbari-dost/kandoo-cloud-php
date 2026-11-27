<?php

declare(strict_types=1);
use Kandoo\Cloud\Config;
use Kandoo\Cloud\Database;
require dirname(__DIR__,2).'/src/bootstrap.php';
$config=Config::load()->all();
if ($config['env'] !== 'testing' || !str_starts_with($config['db']['name'],'TEST_') || !in_array($config['db']['host'],['127.0.0.1','localhost'],true)) { throw new RuntimeException('Not isolated local test DB'); }
$db=new Database($config);
$q=$db->pdo()->prepare('SELECT public_token, customer_name, currency_unit, remaining_balance FROM public_invoices WHERE store_id=? AND invoice_id=?');
$q->execute(['11111111-1111-4111-8111-111111111111','77777777-7777-4777-8777-777777777777']);
$row=$q->fetch();
if (!$row || $row['customer_name'] !== 'TEST customer' || $row['currency_unit'] !== 'toman' || (int)$row['remaining_balance'] !== 17) { throw new RuntimeException('Missing v1.1 projection'); }
$url='http://127.0.0.1:18083/i/'.rawurlencode($row['public_token']);
$html=file_get_contents($url); $json=file_get_contents($url.'?format=json'); $view=json_decode($json,true,512,JSON_THROW_ON_ERROR);
if (!str_contains($html,'TEST customer') || ($view['remaining_balance'] ?? null)!==17) { throw new RuntimeException('Public page mismatch'); }
// Real HTTP proof from the synthetic demo outbox, not a live SMS delivery.
if (getenv('KANDOO_BLOCK_REAL_SMS') !== '1') { throw new RuntimeException('SMS isolation required'); }
$demo=$config; $demo['env']='demo'; $demo['base_url']='http://127.0.0.1:18083'; $demo['faraz_sms']=['enabled'=>false];
$dir=sys_get_temp_dir().'/p36-outbox-'.bin2hex(random_bytes(8));
if (!mkdir($dir,0700)) { throw new RuntimeException('Cannot create synthetic outbox'); }
try {
    $clock=new \Kandoo\Cloud\Support\SystemClock();
    $sms=new \Kandoo\Cloud\Demo\DemoSms($demo,$db,$clock,$dir);
    $service=new \Kandoo\Cloud\Sms\AnnouncementService($db,$sms,$clock);
    $status=$service->send('dddddddd-dddd-4ddd-8ddd-dddddddddddd','11111111-1111-4111-8111-111111111111','77777777-7777-4777-8777-777777777777');
    if ($status !== 'ACCEPTED') { throw new RuntimeException('Synthetic announcement failed'); }
    $line=json_decode(trim((string)file_get_contents($dir.'/sms.log')),true,512,JSON_THROW_ON_ERROR);
    if (!str_ends_with((string)$line['body'],$url)) { throw new RuntimeException('Outbox URL mismatch'); }
    $fromOutbox=file_get_contents($url);
    if (!is_string($fromOutbox) || !str_contains($fromOutbox,'TEST customer') || !str_contains($fromOutbox,'class="brand-header"')) { throw new RuntimeException('Outbox public HTTP page mismatch'); }
    echo "P-36 synthetic demo outbox -> public invoice HTTP PASS; no live SMS.\n";
} finally { if (is_file($dir.'/sms.log')) { unlink($dir.'/sms.log'); } rmdir($dir); }
$login=file_get_contents('http://127.0.0.1:18083/account/login');
if (!str_contains($login,'ورود مشتری')) { throw new RuntimeException('Customer login page mismatch'); }
echo "P-23 public HTML/JSON and customer login page PASS; recorded v1.1 fields preserved.\n";
