<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Json;

/**
 * A JSON number that is NOT a canonical signed 64-bit integer: a float/exponent literal,
 * or an integer literal outside the int64 range. The raw source text is kept so passthrough
 * re-serialization can reproduce it exactly (mirrors JsonElement.WriteTo), while canonical
 * hashing rejects it (CLOUD-CONTRACT.md §5.5 integer profile).
 *
 * Plain in-range integer literals are represented as PHP ints, never as JsonNumber.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class JsonNumber
{
    public function __construct(private string $raw)
    {
    }

    public function raw(): string
    {
        return $this->raw;
    }
}
