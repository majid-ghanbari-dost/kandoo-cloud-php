<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Jalali (Persian / هجری شمسی) calendar conversion — self-contained PHP port of the
 * Borkowski 33-year-break algorithm, faithful to jalaali-js v2 (jalaCalCore /
 * leapFromCycle / g2d / d2g / d2j), verified against the contract anchors:
 * 2024-03-20 → 1403/01/01, 2025-03-21 → 1404/01/01, 2026-01-02 → 1404/10/12.
 *
 * Shared hosts have no intl extension, so the arithmetic is pure PHP (D16).
 * NOTE: jalCal takes the JALAALI year (like jalaali-js v2) and `mod` is the
 * mathematical modulo (non-negative for positive divisors), NOT PHP's `%`.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class Jalali
{
    private const BREAKS = [
        -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181,
        1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178,
    ];

    private const MIN_JALAALI_YEAR = -61;
    private const MAX_JALAALI_YEAR = 3177;

    /** Truncating integer division (JavaScript ~~(a/b)). */
    private static function div(int $a, int $b): int
    {
        return intdiv($a, $b);
    }

    /** Mathematical modulo: a − ~~(a/b)·b — non-negative for positive b. */
    private static function mod(int $a, int $b): int
    {
        return $a - intdiv($a, $b) * $b;
    }

    /**
     * @return array{leap: int, gy: int, march: int} jalCal(jy): leap 0..4 (0 = leap year),
     *         gy = jy + 621, march = Gregorian March day of Farvardin 1.
     */
    public static function jalCal(int $jy): array
    {
        if ($jy < self::MIN_JALAALI_YEAR || $jy > self::MAX_JALAALI_YEAR) {
            throw new InvalidArgumentException("Invalid Jalaali year $jy");
        }
        $breaks = self::BREAKS;
        $count = count($breaks);
        $gy = $jy + 621;
        $leapJ = -14;
        $jp = $breaks[0];
        $jm = 0;
        $jump = 0;
        for ($i = 1; $i < $count; $i++) {
            $jm = $breaks[$i];
            $jump = $jm - $jp;
            if ($jy < $jm) {
                break;
            }
            $leapJ = $leapJ + self::div($jump, 33) * 8 + self::div(self::mod($jump, 33), 4);
            $jp = $jm;
        }
        $n = $jy - $jp;

        // Persian leap years from AD 621 up to (not including) jy.
        $leapJ = $leapJ + self::div($n, 33) * 8 + self::div(self::mod($n, 33) + 3, 4);
        if (self::mod($jump, 33) === 4 && $jump - $n === 4) {
            $leapJ++;
        }

        // Same count for the Gregorian calendar up to gy.
        $leapG = self::div($gy, 4) - self::div((self::div($gy, 100) + 1) * 3, 4) - 150;

        $march = 20 + $leapJ - $leapG;
        return ['leap' => self::leapFromCycle($jump, $n), 'gy' => $gy, 'march' => $march];
    }

    /** Years since the last leap year (0 = current year is leap). */
    private static function leapFromCycle(int $jump, int $n): int
    {
        $adjusted = $n;
        if ($jump - $n < 6) {
            $adjusted = $n - $jump + self::div($jump + 4, 33) * 33;
        }
        $leap = self::mod(self::mod($adjusted + 1, 33) - 1, 4);
        return $leap === -1 ? 4 : $leap;
    }

    /** Gregorian date → Julian day number. */
    public static function g2d(int $gy, int $gm, int $gd): int
    {
        $d = self::div(($gy + self::div($gm - 8, 6) + 100100) * 1461, 4)
            + self::div(153 * self::mod($gm + 9, 12) + 2, 5)
            + $gd - 34840408;
        return $d - self::div(self::div($gy + 100100 + self::div($gm - 8, 6), 100) * 3, 4) + 752;
    }

    /** Julian day number → Gregorian date. @return array{gy: int, gm: int, gd: int} */
    public static function d2g(int $jdn): array
    {
        $j = 4 * $jdn + 139361631;
        $j = $j + self::div(self::div(4 * $jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
        $i = self::div(self::mod($j, 1461), 4) * 5 + 308;
        $gd = self::div(self::mod($i, 153), 5) + 1;
        $gm = self::mod(self::div($i, 153), 12) + 1;
        $gy = self::div($j, 1461) - 100100 + self::div(8 - $gm, 6);
        return ['gy' => $gy, 'gm' => $gm, 'gd' => $gd];
    }

    /** Julian day number → Jalali date. @return array{jy: int, jm: int, jd: int} */
    public static function d2j(int $jdn): array
    {
        $gy = self::d2g($jdn)['gy'];
        $jy = min($gy - 621, self::MAX_JALAALI_YEAR);
        $r = self::jalCal($jy);
        $jdn1f = self::g2d($r['gy'], 3, $r['march']);

        $k = $jdn - $jdn1f;
        if ($k >= 0) {
            if ($k <= 185) {
                return ['jy' => $jy, 'jm' => 1 + self::div($k, 31), 'jd' => self::mod($k, 31) + 1];
            }
            $k -= 186;
        } else {
            $jy--;
            $k += 179;
            if ($r['leap'] === 1) {
                $k++;
            }
        }
        return ['jy' => $jy, 'jm' => 7 + self::div($k, 30), 'jd' => self::mod($k, 30) + 1];
    }

    /** Gregorian date → Jalali date. @return array{jy: int, jm: int, jd: int} */
    public static function fromGregorian(int $gy, int $gm, int $gd): array
    {
        return self::d2j(self::g2d($gy, $gm, $gd));
    }

    /** Jalali date → Gregorian date. @return array{gy: int, gm: int, gd: int} */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $r = self::jalCal($jy);
        return self::d2g(self::g2d($r['gy'], 3, $r['march']) + ($jm - 1) * 31 - self::div($jm, 7) * ($jm - 7) + $jd - 1);
    }

    /** Jalali leap-year check (leap === 0). */
    public static function isLeap(int $jy): bool
    {
        return self::jalCal($jy)['leap'] === 0;
    }

    /** Jalali month length (months 1–6: 31; 7–11: 30; 12: 29 or 30 in leap years). */
    public static function monthLength(int $jy, int $jm): int
    {
        if ($jm <= 6) {
            return 31;
        }
        if ($jm <= 11) {
            return 30;
        }
        return self::isLeap($jy) ? 30 : 29;
    }

    public static function formatDate(int $gy, int $gm, int $gd): string
    {
        $j = self::fromGregorian($gy, $gm, $gd);
        return sprintf('%04d/%02d/%02d', $j['jy'], $j['jm'], $j['jd']);
    }

    /**
     * Stored UTC instant ("YYYY-MM-DD HH:MM:SS", optional ".ffffff") →
     * "YYYY/MM/DD، HH:MM:SS" in Asia/Tehran with Jalali date (U9).
     * Fail-closed: unparseable timestamps throw and surface as INTERNAL_ERROR.
     */
    public static function formatTehranFromStoredUtc(string $storedUtc): string
    {
        [$date, $time] = self::splitStored($storedUtc);
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $date . ' ' . $time,
            new DateTimeZone('UTC')
        );
        if ($parsed === false) {
            throw new InvalidArgumentException('Invalid stored timestamp: ' . $storedUtc);
        }
        $tehran = $parsed->setTimezone(new DateTimeZone('Asia/Tehran'));
        return self::formatDate((int) $tehran->format('Y'), (int) $tehran->format('n'), (int) $tehran->format('j'))
            . '، ' . $tehran->format('H:i:s');
    }

    /** @return array{0: string, 1: string} date and time parts (microseconds dropped) */
    public static function splitStored(string $storedUtc): array
    {
        $parts = preg_split('/[ T]/', trim($storedUtc));
        if (!is_array($parts) || count($parts) < 2) {
            throw new InvalidArgumentException('Invalid stored timestamp: ' . $storedUtc);
        }
        $time = $parts[1];
        if (str_contains($time, '.')) {
            $time = explode('.', $time)[0];
        }
        return [$parts[0], $time];
    }
}
