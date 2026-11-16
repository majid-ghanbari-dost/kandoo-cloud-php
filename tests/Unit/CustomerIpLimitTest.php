<?php

declare(strict_types=1);
namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Customer\CustomerAuth;
use Kandoo\Cloud\Customer\CustomerPages;
use Kandoo\Cloud\Database;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Store\MemoryGateway;
use Kandoo\Cloud\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class CustomerIpLimitTest extends TestCase
{
    public function testIssueAndVerifyHaveIndependentPerPeerLimits(): void
    {
        $settings = ['enabled'=>true, 'otp_pepper'=>'TEST-pepper-012345678901234567890123', 'issue_ip_limit_per_minute'=>2, 'verify_ip_limit_per_minute'=>1];
        $gateway = new MemoryGateway(); $clock = new FixedClock(1767323045);
        $auth = new CustomerAuth($settings, new Database([]), $clock);
        $pages = new CustomerPages($settings, $auth, false, $gateway, $clock);
        $make = static fn (string $path, string $ip): Request => new Request('POST', $path, ['content-type'=>'text/plain'], [], '', null, $ip);
        $issue = '/v1/customer/otp/challenges'; $verify = '/v1/customer/otp/challenges/test/verify';
        $this->assertSame(400, $pages->handle($make($issue,'192.0.2.1'))->status());
        $this->assertSame(400, $pages->handle($make($issue,'192.0.2.1'))->status());
        $this->assertSame(429, $pages->handle($make($issue,'192.0.2.1'))->status());
        $this->assertSame(400, $pages->handle($make($issue,'192.0.2.2'))->status());
        $this->assertSame(400, $pages->handle($make($verify,'192.0.2.1'))->status());
        $this->assertSame(429, $pages->handle($make($verify,'192.0.2.1'))->status());
        $clock->set(1767323100);
        $this->assertSame(400, $pages->handle($make($issue,'192.0.2.1'))->status());
    }
}
