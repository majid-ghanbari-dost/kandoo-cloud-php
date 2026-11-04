<?php

declare(strict_types=1);

namespace Kandoo\Cloud;

/**
 * URL building — base_url from configuration is the SINGLE SOURCE for every absolute
 * link (decision D4). No domain is ever hardcoded anywhere in this application.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class Urls
{
    private string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function base(): string
    {
        return $this->baseUrl;
    }

    /** Absolute link to a public invoice page: {base_url}/i/{token} (§6). */
    public function publicInvoiceUrl(string $token): string
    {
        return $this->baseUrl . '/i/' . rawurlencode($token);
    }

    /** Absolute link to the health endpoint (§2.1). */
    public function healthUrl(): string
    {
        return $this->baseUrl . '/health';
    }

    /**
     * Root-relative asset path (e.g. local fonts). Root-relative by design so the public
     * page contains no absolute URL at all (§9: no http:// or https:// anywhere).
     */
    public function assetPath(string $path): string
    {
        return '/assets/' . ltrim($path, '/');
    }
}
