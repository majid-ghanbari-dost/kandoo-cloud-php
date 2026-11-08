<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\ConfigException;
use PHPUnit\Framework\TestCase;

final class FarazConfigTest extends TestCase
{
    public function testExplicitSettingsAndProductionDryModeRejected(): void
    {
        $raw=['base_url'=>'https://TEST.invalid','db'=>['host'=>'TEST','name'=>'TEST','user'=>'TEST','pass'=>'TEST'],
            'env'=>'testing','faraz_sms'=>['enabled'=>true,'dry_run'=>true,'api_key'=>'TEST-FAKE', 'line_number'=>'TEST-LINE','announcement_pattern'=>'TEST-ANNOUNCEMENT','otp_pattern'=>'TEST-OTP']];
        $this->assertTrue(Config::validate($raw)['faraz_sms']['dry_run']);
        $raw['env']='production';
        try { Config::validate($raw); $this->fail('Dry mode must not be active in production.'); }
        catch (ConfigException $e) { $this->assertTrue(true); }
        $raw['env']='testing'; unset($raw['faraz_sms']['api_key']);
        try { Config::validate($raw); $this->fail('Missing API key must fail.'); }
        catch (ConfigException $e) { $this->assertTrue(true); }
    }
}
