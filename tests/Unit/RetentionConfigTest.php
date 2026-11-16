<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\ConfigException;
use PHPUnit\Framework\TestCase;

final class RetentionConfigTest extends TestCase
{
    public function testDefaultsAndOverrides(): void
    {
        $raw=require dirname(__DIR__,2).'/config.example.php'; unset($raw['retention']);
        $this->assertSame(['otp_challenge_seconds'=>86400,'expired_session_seconds'=>2592000,'idempotency_seconds'=>2592000,'undeliverable_sms_seconds'=>7776000],Config::validate($raw)['retention']);
        $raw['retention']=['otp_challenge_seconds'=>123];
        $this->assertSame(123,Config::validate($raw)['retention']['otp_challenge_seconds']);
        $raw['retention']['otp_challenge_seconds']=0;
        $this->expectException(ConfigException::class); Config::validate($raw);
    }
}
