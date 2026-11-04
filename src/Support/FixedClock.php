<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Support;

/**
 * Deterministic clock for tests and golden replay.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class FixedClock implements Clock
{
    public function __construct(private int $nowUnix)
    {
    }

    public function nowUnix(): int
    {
        return $this->nowUnix;
    }

    public function set(int $nowUnix): void
    {
        $this->nowUnix = $nowUnix;
    }
}
