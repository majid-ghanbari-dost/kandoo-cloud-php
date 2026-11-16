<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Customer;

/** Stateless primitives; the caller must enforce expiry, attempts and one-use atomically. */
final class OtpSecrets
{
    public static function generateCode(int $length): string
    {
        if ($length < 6 || $length > 9) {
            throw new \InvalidArgumentException('Invalid OTP length.');
        }
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= (string) random_int(0, 9);
        }
        return $code;
    }

    public static function codeMac(string $pepper, string $challengeId, string $code): string
    {
        return hash_hmac('sha256', $challengeId . ':' . $code, $pepper);
    }

    public static function verifyCode(string $pepper, string $challengeId, string $code, string $expectedMac): bool
    {
        return hash_equals($expectedMac, self::codeMac($pepper, $challengeId, $code));
    }

    /** Bearer is 32 random bytes encoded as unpadded base64url; only its digest is persisted. */
    public static function sessionToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function sessionHash(string $token): string
    {
        return hash('sha256', $token);
    }
}
