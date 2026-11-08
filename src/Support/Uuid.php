<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Support;

/**
 * UUID parsing/normalization mirroring System.Guid.TryParse + "D" re-serialization.
 *
 * Accepted wire forms (same leniency as the frozen Cloud, UNCONFIRMED U13), checked
 * STRUCTURALLY (no trimming, exact hyphen positions, case-insensitive hex):
 *   D: 8-4-4-4-12 (36 chars)   N: 32 hex digits   B: {D}   P: (D)
 * Normalized form: lowercase "D". Nothing else parses — no version/variant checks,
 * exactly like Guid.TryParse.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class Uuid
{
    /** @return string|null the lowercase D form, or null when not a UUID */
    public static function tryNormalize(string $value): ?string
    {
        $len = strlen($value);
        $hex = null;

        if ($len === 36) {
            // D form: hyphens at 8, 13, 18, 23
            if ($value[8] === '-' && $value[13] === '-' && $value[18] === '-' && $value[23] === '-') {
                $hex = substr($value, 0, 8) . substr($value, 9, 4) . substr($value, 14, 4)
                    . substr($value, 19, 4) . substr($value, 24, 12);
            }
        } elseif ($len === 32) {
            // N form
            $hex = $value;
        } elseif ($len === 38) {
            // B form {…} or P form (…) with D-structure inside
            $open = $value[0];
            $close = $value[37];
            if (($open === '{' && $close === '}') || ($open === '(' && $close === ')')) {
                if ($value[9] === '-' && $value[14] === '-' && $value[19] === '-' && $value[24] === '-') {
                    $hex = substr($value, 1, 8) . substr($value, 10, 4) . substr($value, 15, 4)
                        . substr($value, 20, 4) . substr($value, 25, 12);
                }
            }
        }

        if ($hex === null || preg_match('/^[0-9a-fA-F]{32}$/', $hex) !== 1) {
            return null;
        }
        return strtolower(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
            substr($hex, 16, 4), substr($hex, 20, 12)
        ));
    }

    /** "N" form (32 lowercase hex digits, no hyphens) — used by cloud_cursor (§5.2). */
    public static function toN(string $normalizedD): string
    {
        return str_replace('-', '', $normalizedD);
    }

    public static function isValid(string $value): bool
    {
        return self::tryNormalize($value) !== null;
    }
}
