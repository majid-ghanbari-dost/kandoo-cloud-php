<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\ConfigException;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Kernel;
use Kandoo\Cloud\Store\MemoryGateway;
use Kandoo\Cloud\Service\AdminManager;
use PHPUnit\Framework\TestCase;

final class AdminTest extends TestCase
{
    private function config(?string $hash = null): array
    {
        return Config::validate(['base_url' => 'https://TEST.invalid',
            'db' => ['host' => 'TEST', 'name' => 'TEST', 'user' => 'TEST', 'pass' => 'TEST'],
            'admin_password_hash' => $hash]);
    }

    public function testDefaultOffAndAuthenticatedPageAndAudit(): void
    {
        $password = 'TEST-administrative-passphrase';
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $gateway = new MemoryGateway();
        $kernel = new Kernel($this->config($hash), $gateway);
        $notFound = $kernel->handle(new Request('GET', '/missing'));
        $disabled = $kernel->handle(new Request('GET', '/admin'));
        $this->assertSame($notFound->status(), $disabled->status());
        $this->assertSame($notFound->headers(), $disabled->headers());
        $this->assertSame($notFound->body(), $disabled->body());
        $manager = new AdminManager($this->config($hash), $gateway);
        $this->assertFalse($manager->transition(true, 'TEST-wrong', '2026-01-01 00:00:00'));
        $this->assertFalse($gateway->isAdminEnabled());
        $this->assertSame([], $gateway->adminEvents());
        $this->assertTrue($manager->transition(true, $password, '2026-01-01 00:00:00'));
        $this->assertSame(401, $kernel->handle(new Request('GET', '/admin'))->status());
        $wrong = 'Basic ' . base64_encode('admin:TEST-wrong');
        $this->assertSame(401, $kernel->handle(new Request('GET', '/admin', ['authorization' => $wrong]))->status());
        $correct = 'Basic ' . base64_encode('admin:' . $password);
        $response = $kernel->handle(new Request('GET', '/admin', ['authorization' => $correct]));
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('فاکتور دیجیتال کندو', $response->body());
        $this->assertSame('no-store', $response->header('Cache-Control'));
        $this->assertTrue($manager->transition(false, $password, '2026-01-01 00:00:01'));
        $this->assertSame(404, $kernel->handle(new Request('GET', '/admin', ['authorization' => $correct]))->status());
        $this->assertSame(['enabled', 'disabled'], array_column($gateway->adminEvents(), 'event_type'));
        $this->assertSame(404, (new Kernel($this->config(), $gateway))->handle(new Request('GET', '/admin'))->status());
    }

    public function testPasswordMustBeIndependent(): void
    {
        $password = 'TEST-same-key';
        try {
            Config::validate(['base_url' => 'https://TEST.invalid', 'db' => ['host' => 'TEST', 'name' => 'TEST', 'user' => 'TEST', 'pass' => 'TEST'],
                'replication_key' => $password, 'admin_password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
            $this->fail('Expected separate passwords.');
        } catch (ConfigException $e) { $this->assertTrue(true); }
    }
}
