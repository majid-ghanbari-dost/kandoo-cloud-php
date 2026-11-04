<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Json;

/**
 * Thrown when JSON input violates the wire contract (malformed body, wrong types,
 * duplicate keys, invalid UTF-8, depth overflow). Maps to the frozen 400 envelope
 * "The request body must be a valid operation batch." (CLOUD-CONTRACT.md §5.4 step 3).
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class JsonParseException extends \RuntimeException
{
}
