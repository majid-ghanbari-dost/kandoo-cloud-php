<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Json;

/**
 * Thrown when the wire body cannot bind to the frozen DTOs (wrong JSON types, unparsable
 * UUIDs, invalid literals). Maps to 400 VALIDATION_ERROR "The request body must be a
 * valid operation batch." (CLOUD-CONTRACT.md §5.4 step 3). The exception message is for
 * diagnostics only and is NEVER sent to clients or logs.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class BodyInvalidException extends \RuntimeException
{
}
