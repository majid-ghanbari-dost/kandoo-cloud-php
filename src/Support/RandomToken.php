<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Support;

/**
 * Public capability tokens (CLOUD-CONTRACT.md §6.1).
 *
 *  - 16 cryptographically random bytes → base64url without padding = exactly 22 chars,
 *    alphabet [A-Za-z0-9_-] (128 bits of entropy, unguessable).
 *  - public_token_hash = lowercase hex SHA-256 of the UTF-8 token; lookups go through the
 *    hash UNIQUE index, never a plaintext scan.
 *  - The raw token is stored ONLY so trusted server-side delivery orchestration can build
 *    the link later (U10); it is never logged, never returned by an endpoint, never echoed
 *    in an error.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class RandomToken
{
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Shape validation (§6.1): length 22–128, alphabet [A-Za-z0-9_-] only. */
    public static function hasValidShape(string $token): bool
    {
        $len = strlen($token);
        if ($len < 22 || $len > 128) {
            return false;
        }
        return preg_match('/^[A-Za-z0-9_-]+$/', $token) === 1;
    }

    /** Server-side v4 UUID (digital_invoice_id — projection identity, §7.2). */
    public static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
            substr($hex, 16, 4), substr($hex, 20, 12)
        );
    }
}
