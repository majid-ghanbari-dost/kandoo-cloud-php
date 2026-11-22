<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Demo;

/** Strict IPv4 private-network matching; never trust forwarded headers or host names. */
final class Lan
{
    public static function privateIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) { return false; }
        $p = array_map('intval', explode('.', $ip));
        return $p[0] === 10 || ($p[0] === 172 && $p[1] >= 16 && $p[1] <= 31)
            || ($p[0] === 192 && $p[1] === 168);
    }

    public static function localPeer(string $ip): bool
    {
        return $ip === '127.0.0.1' || $ip === '::1' || self::privateIp($ip);
    }
}
