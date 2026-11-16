<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Customer\OtpSecrets;
use PHPUnit\Framework\TestCase;

final class OtpSecretsTest extends TestCase
{
    public function testCodeAndSessionPrimitives(): void
    {
        $code = OtpSecrets::generateCode(6);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/D', $code);
        $pepper = 'TEST-only-synthetic-otp-pepper-0123456789';
        $mac = OtpSecrets::codeMac($pepper, 'TEST-challenge-a', $code);
        $this->assertTrue(OtpSecrets::verifyCode($pepper, 'TEST-challenge-a', $code, $mac));
        $this->assertFalse(OtpSecrets::verifyCode($pepper, 'TEST-challenge-b', $code, $mac));
        $this->assertFalse(OtpSecrets::verifyCode($pepper, 'TEST-challenge-a', $code . '0', $mac));
        $token = OtpSecrets::sessionToken();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $token);
        $this->assertSame(64, strlen(OtpSecrets::sessionHash($token)));
        $this->assertNotSame($token, OtpSecrets::sessionHash($token));
    }
}
