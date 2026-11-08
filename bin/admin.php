<?php

declare(strict_types=1);

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Database;
use Kandoo\Cloud\Store\MariaDbGateway;
use Kandoo\Cloud\Service\AdminManager;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
if (count($argv) !== 2 || !in_array($argv[1], ['enable', 'disable'], true)) {
    fwrite(STDERR, "Usage: php bin/admin.php enable|disable < password-file\n"); exit(2);
}
try {
    $config = Config::load()->all();
    $password = stream_get_contents(STDIN, 4097);
    $gateway = new MariaDbGateway(new Database($config));
    $manager = new AdminManager($config, $gateway);
    if (!is_string($password) || !$manager->transition($argv[1] === 'enable', $password, gmdate('Y-m-d H:i:s'))) {
        throw new RuntimeException('Authentication failed');
    }
    echo "Admin state updated.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Admin operation failed.\n"); exit(1);
}
