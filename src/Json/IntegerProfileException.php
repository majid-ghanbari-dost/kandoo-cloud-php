<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Json;

/**
 * Thrown when canonical hash input contains a value outside the P-02 integer JSON profile
 * (float/exponent/out-of-range number, or a JsonElement that was absent from the wire).
 * Mirrors the frozen InvalidOperationException/NotSupportedException path in the canonical
 * serializer. At the HTTP boundary this maps to 400 VALIDATION_ERROR with the batch-level
 * message, because the batch fingerprint is computed before per-entry validation
 * (CLOUD-CONTRACT.md §5.4 steps 12–13).
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class IntegerProfileException extends \RuntimeException
{
}
