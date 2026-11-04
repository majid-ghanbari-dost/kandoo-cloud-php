<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Support;

/**
 * Production clock (wall time, UTC seconds).
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class SystemClock implements Clock
{
    public function nowUnix(): int
    {
        return time();
    }
}
