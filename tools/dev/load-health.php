<?php

declare(strict_types=1);

/** Local-only sequential in-process health probe; never sends HTTP or accesses production. */
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Kernel;
use Kandoo\Cloud\Store\MemoryGateway;

$kernel = new Kernel([], new MemoryGateway());
$request = new Request('GET', '/health');
$count = 10000;
$failures = 0;
$start = microtime(true);
for ($i = 0; $i < $count; $i++) {
    $response = $kernel->handle($request);
    if ($response->status() !== 200 || $response->body() !== '{"status":"ok"}') { $failures++; }
}
$elapsed = microtime(true) - $start;
echo json_encode(['requests' => $count, 'failures' => $failures, 'elapsed_seconds' => $elapsed,
    'requests_per_second' => $elapsed > 0 ? $count / $elapsed : null,
    'execution' => 'sequential in-process memory; no network or database'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($failures !== 0 || $elapsed <= 0) { exit(1); }
