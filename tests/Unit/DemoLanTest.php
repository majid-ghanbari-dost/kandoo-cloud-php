<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\ConfigException;
use Kandoo\Cloud\Demo\Lan;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Kernel;
use Kandoo\Cloud\Store\MemoryGateway;
use PHPUnit\Framework\TestCase;

final class DemoLanTest extends TestCase
{
    public function testStrictPrivateRanges(): void
    {
        foreach (['10.0.0.1','172.16.0.1','172.31.255.254','192.168.1.2'] as $ip) { $this->assertTrue(Lan::privateIp($ip)); }
        foreach (['127.0.0.1','172.15.255.255','172.32.0.1','8.8.8.8','example.com','::1','192.168.1.999'] as $ip) { $this->assertFalse(Lan::privateIp($ip)); }
    }
    public function testDemoConfigAndPeerGuard(): void
    {
        $raw=require dirname(__DIR__,2).'/config.example.php';
        $raw['env']='demo'; $raw['base_url']='http://192.168.1.5:8080';
        $config=Config::validate($raw); $kernel=new Kernel($config,new MemoryGateway());
        $request=static fn(string $path,string $ip): Request => new Request('GET',$path,[],[],'',null,$ip);
        $this->assertSame(403,$kernel->handle($request('/health','8.8.8.8'))->status());
        $this->assertSame(200,$kernel->handle($request('/health','192.168.1.6'))->status());
        $this->assertSame(403,$kernel->handle($request('/admin','192.168.1.6'))->status());
    }
    public function testPublicDemoOriginRejected(): void
    {
        $raw=require dirname(__DIR__,2).'/config.example.php'; $raw['env']='demo'; $raw['base_url']='http://8.8.8.8';
        $this->expectException(ConfigException::class); Config::validate($raw);
    }
    public function testRealSmsForbiddenInDemo(): void
    {
        $raw=require dirname(__DIR__,2).'/config.example.php'; $raw['env']='demo'; $raw['base_url']='http://10.0.0.1'; $raw['faraz_sms']=['enabled'=>true];
        $this->expectException(ConfigException::class); Config::validate($raw);
    }
}
