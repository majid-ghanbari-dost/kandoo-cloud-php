<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Support\Jalali;
use PHPUnit\Framework\TestCase;

/**
 * Jalali conversion tests — anchors recorded in CLOUD-CONTRACT.md §9 (self-contained
 * break-based algorithm; no intl extension on shared hosts).
 */
final class JalaliTest extends TestCase
{
    public function testVerifiedAnchors(): void
    {
        $this->assertSame([ 'jy' => 1403, 'jm' => 1, 'jd' => 1 ], Jalali::fromGregorian(2024, 3, 20));
        $this->assertSame(['jy' => 1404, 'jm' => 1, 'jd' => 1], Jalali::fromGregorian(2025, 3, 21));
        $this->assertSame(['jy' => 1404, 'jm' => 10, 'jd' => 12], Jalali::fromGregorian(2026, 1, 2));
    }

    public function testGoldenFixtureInstant(): void
    {
        // G-017/G-018: stored 2026-01-02 03:04:05 UTC → Tehran 06:34:05 → 1404/10/12.
        $this->assertSame('1404/10/12، 06:34:05', Jalali::formatTehranFromStoredUtc('2026-01-02 03:04:05'));
    }

    public function testNowruzBoundariesAndLeapBehaviour(): void
    {
        // day before Nowruz 1403 belongs to 1402/12/29 (1402 was NOT a leap year)
        $this->assertSame('1402/12/29', Jalali::formatDate(2024, 3, 19));
        // Mehr/Mordad lengths: 1403-06-31 exists (months 1–6 are 31 days)
        $this->assertSame(['jy' => 1403, 'jm' => 6, 'jd' => 31], Jalali::fromGregorian(2024, 9, 21));
        $this->assertSame(['jy' => 1403, 'jm' => 7, 'jd' => 1], Jalali::fromGregorian(2024, 9, 22));
    }

    public function testMicrosecondStoredTimestamps(): void
    {
        $this->assertSame('1404/10/12، 06:34:05', Jalali::formatTehranFromStoredUtc('2026-01-02 03:04:05.123456'));
    }
}
