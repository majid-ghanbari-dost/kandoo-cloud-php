<?php

declare(strict_types=1);

namespace Kandoo\Cloud;

use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Http\Response;
use Kandoo\Cloud\Service\IngestService;
use Kandoo\Cloud\Service\PublicInvoiceService;
use Kandoo\Cloud\Store\MariaDbGateway;
use Kandoo\Cloud\Store\StoreGateway;
use Kandoo\Cloud\Support\Clock;
use Kandoo\Cloud\Support\SystemClock;

/**
 * Application kernel — pure request → response logic.
 *
 * Routes (frozen surface, CLOUD-CONTRACT.md §2):
 *   GET  /health                                  → {"status":"ok"}           (§2.1)
 *   POST /v1/stores/{store_id}/operation-batches  → replication ingest         (§5)
 *   GET  /i/{token}                               → public invoice read        (§6)
 * Known path + wrong method → 405 (Allow). Everything else → 404 with an empty body
 * (framework-equivalent). Deferred customer/OTP endpoints are NOT mounted (U12): they
 * share the same generic 404 as any unknown path.
 *
 * Privacy: the kernel logs nothing — no tokens, no URIs, no bodies (§6.6).
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class Kernel
{
    private StoreGateway $gateway;
    private Clock $clock;

    /** @param array<string, mixed> $config validated configuration */
    public function __construct(
        private array $config,
        ?StoreGateway $gateway = null,
        ?Clock $clock = null,
    ) {
        $this->gateway = $gateway ?? new MariaDbGateway(new Database($config));
        $this->clock = $clock ?? new SystemClock();
    }

    public function handle(Request $request): Response
    {
        $path = $request->path();
        $method = $request->method();

        if ($path === '/admin' || $path === '/admin/') {
            $service = new \Kandoo\Cloud\Service\AdminService($this->config, $this->gateway);
            return $service->handle($request);
        }

        if ($path === '/health') {
            if ($method !== 'GET') {
                return Response::emptyBody(405, ['Allow' => 'GET']);
            }
            return Response::json(200, ['status' => 'ok']);
        }

        if (preg_match('#^/v1/stores/([^/]+)/operation-batches$#', $path, $matches) === 1) {
            if ($method !== 'POST') {
                return Response::emptyBody(405, ['Allow' => 'POST']);
            }
            $service = new IngestService($this->config, $this->gateway, $this->clock);
            return $service->handle($matches[1], $request);
        }

        if (preg_match('#^/i/([^/]*)$#', $path, $matches) === 1) {
            if ($method !== 'GET' && $method !== 'HEAD') {
                return Response::emptyBody(405, ['Allow' => 'GET']);
            }
            $service = new PublicInvoiceService($this->config, $this->gateway, $this->clock);
            return $service->handle($matches[1], $request);
        }

        return Response::emptyBody(404);
    }
}
