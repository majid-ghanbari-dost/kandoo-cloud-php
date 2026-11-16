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
        private ?\Kandoo\Cloud\Customer\CustomerAuth $customerAuth = null,
    ) {
        $this->gateway = $gateway ?? new MariaDbGateway(new Database($config));
        $this->clock = $clock ?? new SystemClock();
    }

    public function handle(Request $request): Response
    {
        $path = $request->path();
        $method = $request->method();

        if ($path === '/admin' || $path === '/admin/') {
            $reset = null;
            if (($this->config['customer_auth']['enabled'] ?? false) === true) {
                $reset = function (string $phone): bool {
                    $auth = $this->customerAuth ?? new \Kandoo\Cloud\Customer\CustomerAuth($this->config['customer_auth'], new Database($this->config), $this->clock);
                    return $auth->resetBudget($phone);
                };
            }
            $service = new \Kandoo\Cloud\Service\AdminService($this->config, $this->gateway, $reset);
            return $service->handle($request);
        }

        if (str_starts_with($path, '/account/') || str_starts_with($path, '/v1/customer/otp/')) {
            $auth = $this->customerAuth ?? new \Kandoo\Cloud\Customer\CustomerAuth(
                $this->config['customer_auth'] ?? ['enabled' => false], new Database($this->config), $this->clock,
                ($this->config['faraz_sms']['enabled'] ?? false) ? new \Kandoo\Cloud\Sms\FarazAdapter($this->config['faraz_sms']) : null
            );
            return (new \Kandoo\Cloud\Customer\CustomerPages($this->config['customer_auth'] ?? ['enabled' => false], $auth, ($this->config['public_legal_copy_required'] ?? false) === true, $this->gateway, $this->clock))->handle($request);
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
