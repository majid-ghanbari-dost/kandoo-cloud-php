<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Json;

/**
 * JSON serializer with two modes (CLOUD-CONTRACT.md §5.5):
 *
 *  - encode()           — preserve insertion order; used for response bodies so the
 *                         frozen byte-exact field order is reproduced, and for storing
 *                         delivered payloads as delivered (U16).
 *  - encodeCanonical()  — object keys sorted by unsigned UTF-8 byte order, used ONLY as
 *                         hash input (entry_hash, request_hash, batch_fingerprint,
 *                         issued_content_sha256).
 *
 * Escaping mirrors JavaScriptEncoder.UnsafeRelaxedJsonEscaping: `"` → \" , `\` → \\ ,
 * \b \f \n \r \t short forms, remaining U+0000–U+001F as \u00XX with UPPERCASE hex,
 * U+007F as \u007F, everything else (including all non-ASCII/Persian text) written raw —
 * never \u-escaped. No insignificant whitespace. Plain PHP ints are written as plain
 * decimal; JsonNumber nodes are raw passthrough in preserve mode and an integer-profile
 * violation in canonical mode; the UNDEFINED marker (a JsonElement that was absent from
 * the wire) is a violation in both modes, exactly like the frozen serializer.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class Writer
{
    public static function encode(mixed $node): string
    {
        return self::write($node, false);
    }

    public static function encodeCanonical(mixed $node): string
    {
        return self::write($node, true);
    }

    private static function write(mixed $node, bool $canonical): string
    {
        if ($node instanceof JsonObject) {
            $pairs = $node->pairs();
            if ($canonical) {
                usort($pairs, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
            }
            $parts = [];
            foreach ($pairs as [$key, $value]) {
                $parts[] = self::writeString($key) . ':' . self::write($value, $canonical);
            }
            return '{' . implode(',', $parts) . '}';
        }
        if ($node === null) {
            return 'null';
        }
        if (is_bool($node)) {
            return $node ? 'true' : 'false';
        }
        if (is_int($node)) {
            return (string) $node;
        }
        if (is_string($node)) {
            return self::writeString($node);
        }
        if (is_array($node)) {
            if ($node === [] || array_is_list($node)) {
                $parts = [];
                foreach ($node as $item) {
                    $parts[] = self::write($item, $canonical);
                }
                return '[' . implode(',', $parts) . ']';
            }
            // associative array → object with string keys
            $pairs = [];
            foreach ($node as $key => $value) {
                $pairs[] = [(string) $key, $value];
            }
            return self::write(new JsonObject($pairs), $canonical);
        }
        if ($node instanceof JsonNumber) {
            if ($canonical) {
                throw new IntegerProfileException('Non-integer number in canonical hash input.');
            }
            return $node->raw();
        }
        if ($node instanceof UndefinedElement) {
            throw new IntegerProfileException('An absent JSON element cannot be serialized.');
        }
        throw new IntegerProfileException('Unsupported node type: ' . get_debug_type($node));
    }

    private static function writeString(string $s): string
    {
        $out = '"';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            $ord = ord($ch);
            switch ($ord) {
                case 0x22: $out .= '\\"'; break;
                case 0x5C: $out .= '\\\\'; break;
                case 0x08: $out .= '\\b'; break;
                case 0x0C: $out .= '\\f'; break;
                case 0x0A: $out .= '\\n'; break;
                case 0x0D: $out .= '\\r'; break;
                case 0x09: $out .= '\\t'; break;
                case 0x7F: $out .= '\\u007F'; break;
                default:
                    if ($ord < 0x20) {
                        $out .= sprintf('\\u%04X', $ord);
                    } else {
                        $out .= $ch;
                    }
            }
        }
        return $out . '"';
    }
}
