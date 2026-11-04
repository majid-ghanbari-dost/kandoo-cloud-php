<?php

declare(strict_types=1);

namespace Kandoo\Cloud;

use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Http\Response;

/**
 * Application kernel — pure request → response logic (PM ORDER 9 P-02 skeleton).
 *
 * Routes (frozen surface, CLOUD-CONTRACT.md §2):
 *   GET  /health                                  → {"status":"ok"}            (§2.1)
 *   POST /v1/stores/{store_id}/operation-batches  → replication ingest          (§5, wired in P-03)
 *   GET  /i/{token}                               → public invoice read         (§6, wired in P-03)
 * Everything else → 404 with an empty body (framework-equivalent); known path with a
 * wrong method → 405. Deferred customer/OTP endpoints are NOT mounted (U12): they share
 * the same generic 404 as any unknown path.
 *
 * Privacy: nothing in this class logs tokens, URIs or bodies (§6.6).
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class Kernel
{
    /** @param array<string, mixed> $config validated configuration */
    public function __construct(private array $config)
    {
    }

    public function handle(Request $request): Response
    {
        $path = $request->path();

        if ($path === '/health') {
            if ($request->method() !== 'GET') {
                return Response::emptyBody(405, ['Allow' => 'GET']);
            }
            return Response::json(200, ['status' => 'ok']);
        }

        // P-03 mounts: POST /v1/stores/{store_id}/operation-batches and GET /i/{token}.

        return Response::emptyBody(404);
    }
}
