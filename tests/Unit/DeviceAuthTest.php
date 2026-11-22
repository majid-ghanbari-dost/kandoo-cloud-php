<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\ConfigException;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Kernel;
use Kandoo\Cloud\Store\MemoryGateway;
use Kandoo\Cloud\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class DeviceAuthTest extends TestCase
{
    private const STORE = '11111111-1111-4111-8111-111111111111';
    private const DEVICE = '33333333-3333-4333-8333-333333333333';
    private const PATH = '/v1/stores/11111111-1111-4111-8111-111111111111/operation-batches';
    private const SECRET = 'TEST-synthetic-replication-secret-12345';

    private function config(array $overrides = []): array
    {
        return Config::validate(array_merge([
            'base_url' => 'https://TEST.invalid',
            'db' => ['host' => 'TEST', 'name' => 'TEST', 'user' => 'TEST', 'pass' => 'TEST'],
            'env' => 'testing',
        ], $overrides));
    }

    public function testRegistrationAuthenticationAndRejections(): void
    {
        $gateway = new MemoryGateway();
        $gateway->seedStore(['store_id' => self::STORE, 'active_writer_device_id' => self::DEVICE,
            'active_writer_epoch' => 1, 'accepted_through_seq' => 0, 'entry_count' => 0,
            'head_entry_hash' => null, 'projection_version' => 0]);
        $gateway->insertDevice(self::DEVICE, self::STORE, hash('sha256', self::SECRET), '2026-01-01 00:00:00');
        $device = $gateway->findDevice(self::DEVICE);
        $this->assertSame(hash('sha256', self::SECRET), $device['secret_sha256']);
        $this->assertFalse($device['verified']);
        $kernel = new Kernel($this->config(), $gateway, new FixedClock(1767323045));
        $rejected = [];
        foreach ([[], ['x-kandoo-device-id' => self::DEVICE, 'x-kandoo-device-secret' => 'TEST-wrong'],
            ['x-kandoo-device-id' => '44444444-4444-4444-8444-444444444444', 'x-kandoo-device-secret' => self::SECRET]] as $headers) {
            $response = $kernel->handle(new Request('POST', self::PATH, $headers, [], '{'));
            $this->assertSame(401, $response->status());
            $rejected[] = $response->body();
        }
        $this->assertSame($rejected[0], $rejected[1]);
        $this->assertSame($rejected[0], $rejected[2]);
        $this->assertSame('{"code":"UNAUTHENTICATED","message":"A valid replication credential is required.","retry_hint":{"action":"REAUTHENTICATE"}}', $rejected[0]);
        $response = $kernel->handle(new Request('POST', self::PATH,
            ['x-kandoo-device-id' => self::DEVICE, 'x-kandoo-device-secret' => self::SECRET], [], '{'));
        $this->assertSame(400, $response->status());
        $device = $gateway->findDevice(self::DEVICE);
        $this->assertTrue($device['verified']);
        $this->assertNotNull($device['last_authenticated_at']);
        $this->assertStringContainsString("hash_equals(\$device['secret_sha256'], hash('sha256', \$secret))", file_get_contents(dirname(__DIR__, 2) . '/src/Service/IngestService.php'));
    }

    public function testBearerAndLegacyOnMemoryGateway(): void
    {
        $gateway = new MemoryGateway();
        $gateway->seedStore(['store_id' => self::STORE, 'active_writer_device_id' => self::DEVICE,
            'active_writer_epoch' => 1, 'accepted_through_seq' => 0, 'entry_count' => 0,
            'head_entry_hash' => null, 'projection_version' => 0]);
        $gateway->insertDevice(self::DEVICE, self::STORE, hash('sha256', self::SECRET), '2026-01-01 00:00:00');
        $kernel = new Kernel($this->config(), $gateway, new FixedClock(1767323045));
        foreach ([['authorization' => 'Bearer '.self::SECRET],
            ['x-kandoo-device-id' => self::DEVICE, 'x-kandoo-device-secret' => self::SECRET]] as $headers) {
            $this->assertSame(400, $kernel->handle(new Request('POST', self::PATH, $headers, [], '{'))->status());
        }
        foreach ([[], ['authorization'=>'Bearer wrong'], ['authorization'=>'Basic '.self::SECRET],
            ['authorization'=>'Bearer wrong', 'x-kandoo-device-id'=>self::DEVICE, 'x-kandoo-device-secret'=>self::SECRET]] as $headers) {
            $this->assertSame(401, $kernel->handle(new Request('POST', self::PATH, $headers, [], '{'))->status());
        }
        $gateway->insertDevice('44444444-4444-4444-8444-444444444444', self::STORE, hash('sha256', self::SECRET), '2026-01-01 00:00:00');
        $this->assertSame(401, $kernel->handle(new Request('POST', self::PATH, ['authorization'=>'Bearer '.self::SECRET], [], '{'))->status());
    }

    public function testBypassRestrictedAndExplicit(): void
    {
        $gateway = new MemoryGateway();
        $kernel = new Kernel($this->config(['replication_auth_bypass' => true]), $gateway, new FixedClock(1767323045));
        $this->assertSame(400, $kernel->handle(new Request('POST', self::PATH, [], [], '{'))->status());
        try {
            $this->config(['env' => 'production', 'replication_auth_bypass' => true]);
            $this->assertTrue(false, 'production bypass must fail');
        } catch (ConfigException $e) {
            $this->assertTrue(true);
        }
    }
}
