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
        if (($this->config['env'] ?? '') === 'demo') {
            $peer = $request->clientIp();
            if (!\Kandoo\Cloud\Demo\Lan::localPeer($peer)) { return Response::emptyBody(403); }
            // The admin password must not cross even the trusted LAN in cleartext.
            $host = (string) parse_url((string) $this->config['base_url'], PHP_URL_HOST);
            if (($path === '/admin' || $path === '/admin/') && !in_array($peer, ['127.0.0.1', '::1', $host], true)) {
                return Response::emptyBody(403);
            }
        }

        if ($path === '/admin' || $path === '/admin/') {
            $reset = null;
            if (($this->config['customer_auth']['enabled'] ?? false) === true) {
                $reset = function (string $phone): bool {
                    $auth = $this->customerAuth ?? new \Kandoo\Cloud\Customer\CustomerAuth($this->config['customer_auth'], new Database($this->config), $this->clock);
                    return $auth->resetBudget($phone);
                };
            }
            $outbox = null;
            if (($this->config['env'] ?? '') === 'demo') {
                $outbox = function (): array {
                    return (new Database($this->config))->pdo()->query('SELECT phone,kind,body,created_at FROM cloud_demo_sms_outbox ORDER BY created_at DESC,message_id DESC LIMIT 100')->fetchAll();
                };
            }
            $service = new \Kandoo\Cloud\Service\AdminService($this->config, $this->gateway, $reset, $outbox);
            return $service->handle($request);
        }

        if (str_starts_with($path, '/account/') || str_starts_with($path, '/v1/customer/otp/')) {
            $auth = $this->customerAuth ?? new \Kandoo\Cloud\Customer\CustomerAuth(
                $this->config['customer_auth'] ?? ['enabled' => false], new Database($this->config), $this->clock,
                ($this->config['env'] ?? '') === 'demo'
                    ? new \Kandoo\Cloud\Demo\DemoSms($this->config, new Database($this->config), $this->clock, getenv('KANDOO_DEMO_DIR') ?: kandoo_app_root().'/.kandoo-demo')
                    : (($this->config['faraz_sms']['enabled'] ?? false) ? new \Kandoo\Cloud\Sms\FarazAdapter($this->config['faraz_sms']) : null)
            );
            return (new \Kandoo\Cloud\Customer\CustomerPages($this->config['customer_auth'] ?? ['enabled' => false], $auth, ($this->config['public_legal_copy_required'] ?? false) === true, $this->gateway, $this->clock, ($this->config['env'] ?? '') === 'demo'))->handle($request);
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
