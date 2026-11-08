<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Support;

/**
 * Time source abstraction so rate-limit windows and timestamps are deterministic in
 * tests while production uses the wall clock. All instants are UTC.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
interface Clock
{
    /** Current Unix timestamp (UTC seconds). */
    public function nowUnix(): int;
}
