<?php

declare(strict_types=1);

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Database;
use Kandoo\Cloud\Store\MariaDbGateway;
use Kandoo\Cloud\Support\Uuid;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

// Secret is read from stdin, not command-line arguments or output. Provision over a secure channel.
if (count($argv) < 3 || !in_array($argv[1], ['register', 'show'], true)
    || ($id = Uuid::tryNormalize($argv[2])) === null) {
    fwrite(STDERR, "Usage: php bin/device.php register <device-id> <store-id> < secret-file\n       php bin/device.php show <device-id>\n"); exit(2);
}
try {
    $gateway = new MariaDbGateway(new Database(Config::load()->all()));
    if ($argv[1] === 'register') {
        $store = isset($argv[3]) ? Uuid::tryNormalize($argv[3]) : null;
        if ($store === null || count($argv) !== 4) { throw new RuntimeException('Invalid arguments'); }
        $secret = stream_get_contents(STDIN);
        if (!is_string($secret) || strlen($secret) < 32 || strlen($secret) > 4096) { throw new RuntimeException('Secret must be 32 to 4096 bytes'); }
        $gateway->insertDevice($id, $store, hash('sha256', $secret), gmdate('Y-m-d H:i:s'));
        echo "Device registered.\n";
    } else {
        if (count($argv) !== 3) { throw new RuntimeException('Invalid arguments'); }
        $device = $gateway->findDevice($id);
        if ($device === null) { throw new RuntimeException('Device not found'); }
        unset($device['secret_sha256']);
        echo json_encode($device, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Device operation failed. Check arguments, configuration and database.\n"); exit(1);
}
