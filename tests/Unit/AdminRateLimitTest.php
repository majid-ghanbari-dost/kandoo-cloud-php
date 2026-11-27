<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\ConfigException;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Kernel;
use Kandoo\Cloud\Service\AdminManager;
use Kandoo\Cloud\Store\MemoryGateway;
use Kandoo\Cloud\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class AdminRateLimitTest extends TestCase
{
    public function testPeerLimitUsesOnlyDirectPeerAndResetsByWindow(): void
    {
        $pw='TEST-admin-secret';
        $raw=['base_url'=>'https://TEST.invalid','db'=>['host'=>'TEST','name'=>'TEST','user'=>'TEST','pass'=>'TEST'],
            'admin_password_hash'=>password_hash($pw,PASSWORD_DEFAULT),'admin_rate_limit_per_minute'=>2];
        $config=Config::validate($raw); $g=new MemoryGateway(); $clock=new FixedClock(1200);
        (new AdminManager($config,$g))->transition(true,$pw,'2026-01-01 00:00:00');
        $k=new Kernel($config,$g,$clock);
        $bad=['authorization'=>'Basic '.base64_encode('admin:TEST-bad'),'x-forwarded-for'=>'198.51.100.1'];
        $send=static fn(array $headers,string $ip) => $k->handle(new Request('GET','/admin',$headers,[],'',null,$ip));
        $this->assertSame(401,$send($bad,'192.0.2.1')->status());
        $bad['x-forwarded-for']='198.51.100.2'; $this->assertSame(401,$send($bad,'192.0.2.1')->status());
        $limited=$send(['authorization'=>'Basic '.base64_encode('admin:'.$pw)],'192.0.2.1');
        $this->assertSame(429,$limited->status()); $this->assertSame('60',$limited->header('Retry-After'));
        $this->assertSame(401,$send($bad,'192.0.2.2')->status());
        $clock->set(1260); $this->assertSame(200,$send(['authorization'=>'Basic '.base64_encode('admin:'.$pw)],'192.0.2.1')->status());
        $raw['admin_rate_limit_per_minute']=0;
        try { Config::validate($raw); $this->fail('Unlimited admin rate accepted'); } catch (ConfigException $e) { $this->assertTrue(true); }
    }
}
