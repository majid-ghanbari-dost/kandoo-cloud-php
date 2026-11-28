<?php

declare(strict_types=1);

// Synthetic isolated CI visual fixture; never used as a production route.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($path) && str_starts_with($path, '/assets/')) { return false; }
if ($path !== '/admin') { http_response_code(404); exit; }
require dirname(__DIR__, 2).'/src/bootstrap.php';
$gateway = new \Kandoo\Cloud\Store\MemoryGateway();
$gateway->setAdminEnabled(true, '2026-01-01 00:00:00');
$empty = ($_GET['state'] ?? '') === 'empty';
if (!$empty) {
    $store='11111111-1111-4111-8111-111111111111';
    $device='22222222-2222-4222-8222-222222222222';
    $gateway->seedStore(['store_id'=>$store,'active_writer_device_id'=>$device,'active_writer_epoch'=>1,'accepted_through_seq'=>7,'entry_count'=>7,'head_entry_hash'=>null,'projection_version'=>0,'throttled'=>false]);
    $gateway->insertDevice($device,$store,hash('sha256','TEST-visual-only'), '2026-01-01 00:00:00');
    $gateway->seedSmsDeliveryStates(['sent','sent','failed']);
}
$config=['base_url'=>'http://127.0.0.1:18084','env'=>'demo', 'admin_password_hash'=>password_hash('TEST-visual-admin', PASSWORD_DEFAULT), 'admin_rate_limit_per_minute'=>100];
$outbox = $empty ? [] : [['phone'=>'+989120000001','body'=>'پیام ساختگی دمو']];
$service = new \Kandoo\Cloud\Service\AdminService($config,$gateway,static fn (string $phone): bool => true,static fn (): array => $outbox);
$response = $service->handle(\Kandoo\Cloud\Http\Request::fromGlobals());
http_response_code($response->status());
foreach ($response->headers() as $name=>$value) { header($name.': '.$value, true); }
echo $response->body();
