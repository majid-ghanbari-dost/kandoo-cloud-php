<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Http;

/**
 * Effective client address for rate limiting (PHP-B / P-34).
 *
 * `docs/SECURITY-REVIEW-P34.md` recorded a real gap: every limiter keyed on the DIRECT peer
 * collapses all customers into one bucket when the Cloud runs behind a reverse proxy. This
 * helper resolves the address from `X-Forwarded-For` **only** when the direct peer is an
 * explicitly configured trusted proxy, so the default behaviour is unchanged and a spoofed
 * header from an untrusted peer is ignored.
 *
 * Resolution is rightmost-untrusted (RFC 7239 / the usual proxy convention): a client can
 * prepend anything it likes, but the right-most entry was appended by the proxy we trust, so
 * only entries to the right of the last untrusted hop are considered. Anything malformed,
 * oversized or absent fails SAFE to the direct peer — never to an attacker-supplied value.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice). No address is ever logged here.
 */
final class PeerAddress
{
    private const MAX_HEADER_BYTES = 512;
    private const MAX_HOPS = 16;

    /**
     * @param list<string> $trustedProxies validated proxy addresses (empty = feature off)
     */
    public static function effective(Request $request, array $trustedProxies): string
    {
        $direct = $request->clientIp();
        if ($trustedProxies === [] || !in_array($direct, $trustedProxies, true)) {
            return $direct;
        }
        $header = trim((string) ($request->header('x-forwarded-for') ?? ''));
        if ($header === '' || strlen($header) > self::MAX_HEADER_BYTES) {
            return $direct;
        }
        $parts = array_map('trim', explode(',', $header));
        if (count($parts) > self::MAX_HOPS) {
            return $direct;
        }
        for ($index = count($parts) - 1; $index >= 0; $index--) {
            $candidate = $parts[$index];
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                return $direct;
            }
            if (in_array($candidate, $trustedProxies, true)) {
                continue;
            }
            return $candidate;
        }
        return $direct;
    }
}
