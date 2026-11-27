<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\ConfigException;
use PHPUnit\Framework\TestCase;

final class SmsCostConfigTest extends TestCase
{
    public function testExplicitBoundedIntegerEstimates(): void
    {
        $raw=['base_url'=>'https://TEST.invalid','db'=>['host'=>'TEST','name'=>'TEST','user'=>'TEST','pass'=>'TEST']];
        $this->assertSame(['enabled'=>false],Config::validate($raw)['sms_cost']);
        $raw['sms_cost']=['enabled'=>true,'notification_rials'=>1200,'otp_rials'=>700];
        $this->assertSame($raw['sms_cost'],Config::validate($raw)['sms_cost']);
        foreach ([-1,1000000,'1',1.5,null] as $bad) {
            $invalid=$raw; $invalid['sms_cost']['otp_rials']=$bad;
            try { Config::validate($invalid); $this->fail('Invalid estimate accepted'); }
            catch (ConfigException $e) { $this->assertTrue(true); }
        }
    }
}
