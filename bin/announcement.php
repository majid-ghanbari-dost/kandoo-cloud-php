<?php

declare(strict_types=1);

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Database;
use Kandoo\Cloud\Sms\AnnouncementService;
use Kandoo\Cloud\Sms\FarazAdapter;
use Kandoo\Cloud\Support\SystemClock;
use Kandoo\Cloud\Support\Uuid;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
if (!isset($argv[1], $argv[2]) || !in_array($argv[1], ['send', 'show', 'confirm'], true)
    || Uuid::tryNormalize($argv[2]) !== $argv[2] || ($argv[1] === 'send' ? count($argv) !== 5 : count($argv) !== 3)) {
    fwrite(STDERR, "Usage: php bin/announcement.php send <request-id> <store-id> <invoice-id>\n       php bin/announcement.php show|confirm <request-id>\n"); exit(2);
}
try {
    $config = Config::load()->all();
    $db = new Database($config); $clock = new SystemClock();
    if (($config['env'] ?? '') === 'demo') {
        $adapter = new \Kandoo\Cloud\Demo\DemoSms($config,$db,$clock,getenv('KANDOO_DEMO_DIR') ?: kandoo_app_root().'/.kandoo-demo');
    } elseif ($config['faraz_sms']['enabled'] ?? false) { $adapter = new FarazAdapter($config['faraz_sms']); }
    else { throw new RuntimeException('SMS disabled'); }
    $service = new AnnouncementService($db, $adapter, $clock);
    if ($argv[1] === 'send') { $status = $service->send($argv[2], $argv[3], $argv[4]); }
    elseif ($argv[1] === 'confirm') { $status = $service->confirmDelivery($argv[2]); }
    else {
        $q = $db->pdo()->prepare('SELECT state, provider_receipt FROM cloud_sms_delivery_requests WHERE request_id=?');
        $q->execute([$argv[2]]); $row = $q->fetch();
        if ($row === false) { throw new RuntimeException('Unknown request'); }
        echo json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL; exit(0);
    }
    echo $status . PHP_EOL;
    exit(in_array($status, ['ACCEPTED', 'CONFIRMED', 'EXISTS'], true) ? 0 : 1);
} catch (Throwable $e) { fwrite(STDERR, "Announcement operation failed.\n"); exit(1); }
