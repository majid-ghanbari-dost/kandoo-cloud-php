<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Service;

use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Http\Response;
use Kandoo\Cloud\Store\StoreGateway;

/** Administration is opt-in and separately authenticated on every page request. */
final class AdminService
{
    public function __construct(private array $config, private StoreGateway $gateway) {}

    public function handle(Request $request): Response
    {
        $hash = $this->config['admin_password_hash'] ?? null;
        if (!is_string($hash) || !$this->gateway->isAdminEnabled()) {
            return Response::emptyBody(404);
        }
        if ($request->method() !== 'GET') {
            return Response::emptyBody(405, ['Allow' => 'GET', 'Cache-Control' => 'no-store']);
        }
        $authorization = $request->header('authorization') ?? '';
        $decoded = preg_match('/^Basic\s+([^\s]+)$/i', $authorization, $m) === 1
            ? base64_decode($m[1], true) : false;
        $parts = is_string($decoded) ? explode(':', $decoded, 2) : [];
        if (count($parts) !== 2 || $parts[0] !== 'admin' || !password_verify($parts[1], $hash)) {
            return Response::emptyBody(401, ['WWW-Authenticate' => 'Basic realm="Administration", charset="UTF-8"', 'Cache-Control' => 'no-store']);
        }
        return Response::html(200, '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>مدیریت فاکتور دیجیتال کندو</title></head><body><h1>مدیریت فاکتور دیجیتال کندو</h1><p>صفحهٔ مدیریت فعال است.</p></body></html>', [
            'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
        ]);
    }
}
