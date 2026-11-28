<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Service\AdminManager;
use Kandoo\Cloud\Service\AdminService;
use Kandoo\Cloud\Store\MemoryGateway;
use PHPUnit\Framework\TestCase;

final class AdminV2Test extends TestCase
{
    private const STORE = '11111111-1111-1111-1111-111111111111';
    private const DEVICE = '33333333-3333-3333-3333-333333333333';

    private function config(string $hash): array
    {
        return Config::validate(['base_url' => 'https://TEST.invalid',
            'db' => ['host' => 'TEST', 'name' => 'TEST', 'user' => 'TEST', 'pass' => 'TEST'],
            'admin_password_hash' => $hash]);
    }

    private function seeded(): MemoryGateway
    {
        $gateway = new MemoryGateway();
        $gateway->seedStore(['store_id' => self::STORE, 'active_writer_device_id' => '22222222-2222-2222-2222-222222222222',
            'active_writer_epoch' => 1, 'accepted_through_seq' => 7, 'entry_count' => 7, 'projection_version' => 1, 'throttled' => false]);
        $gateway->insertDevice(self::DEVICE, self::STORE, hash('sha256', 'TEST-device-secret'), '2026-01-01 00:00:00');
        return $gateway;
    }

    private function enabled(string $password): array
    {
        $config = $this->config(password_hash($password, PASSWORD_DEFAULT));
        $gateway = $this->seeded();
        (new AdminManager($config, $gateway))->transition(true, $password, '2026-01-01 00:00:00');
        return [$config, $gateway];
    }

    private function csrfOf(AdminService $service, string $authorization): string
    {
        $cookie = $service->handle(new Request('GET', '/admin', ['authorization' => $authorization]))->header('Set-Cookie');
        $this->assertIsString($cookie);
        $this->assertSame(1, preg_match('/kandoo_admin_csrf=([^;]+)/', (string) $cookie, $match));
        return $match[1];
    }

    public function testRevocationBlocksDeviceAuthAndIsAudited(): void
    {
        [$config, $gateway] = $this->enabled('TEST-admin-pass');
        $secretHash = hash('sha256', 'TEST-device-secret');
        $this->assertNotNull($gateway->findDeviceByStoreAndSecretHash(self::STORE, $secretHash));

        $service = new AdminService($config, $gateway, static function (string $phone): bool { return true; });
        $authorization = 'Basic ' . base64_encode('admin:TEST-admin-pass');
        $csrf = $this->csrfOf($service, $authorization);
        $headers = ['authorization' => $authorization, 'cookie' => 'kandoo_admin_csrf=' . $csrf, 'origin' => 'https://TEST.invalid'];
        $body = http_build_query(['csrf' => $csrf, 'action' => 'revoke_device', 'device_id' => self::DEVICE, 'reason' => 'TEST-owner request']);
        $this->assertSame(200, $service->handle(new Request('POST', '/admin', $headers, [], $body))->status());
        $this->assertNull($gateway->findDeviceByStoreAndSecretHash(self::STORE, $secretHash));
        $device = $gateway->findDevice(self::DEVICE);
        $this->assertNotNull($device['revoked_at']);
        $this->assertSame('TEST-owner request', $device['revoked_reason']);
        $events = $gateway->adminEvents();
        $last = $events[count($events) - 1];
        $this->assertSame('device_revoked', $last['event_type']);
        $this->assertSame(self::DEVICE, $last['detail']);
        // revoked stays revoked: second revoke, unknown device and malformed ids all fail closed
        $this->assertSame(400, $service->handle(new Request('POST', '/admin', $headers, [], $body))->status());
        $unknown = http_build_query(['csrf' => $csrf, 'action' => 'revoke_device', 'device_id' => '44444444-4444-4444-4444-444444444444']);
        $this->assertSame(400, $service->handle(new Request('POST', '/admin', $headers, [], $unknown))->status());
        $malformed = http_build_query(['csrf' => $csrf, 'action' => 'revoke_device', 'device_id' => 'not-a-uuid']);
        $this->assertSame(400, $service->handle(new Request('POST', '/admin', $headers, [], $malformed))->status());
    }

    public function testRevokeRequiresCsrfAndSameOrigin(): void
    {
        [$config, $gateway] = $this->enabled('TEST-admin-pass');
        $service = new AdminService($config, $gateway);
        $authorization = 'Basic ' . base64_encode('admin:TEST-admin-pass');
        $csrf = $this->csrfOf($service, $authorization);
        $body = http_build_query(['csrf' => $csrf, 'action' => 'revoke_device', 'device_id' => self::DEVICE, 'reason' => '']);
        $this->assertSame(403, $service->handle(new Request('POST', '/admin', ['authorization' => $authorization, 'cookie' => 'kandoo_admin_csrf=' . $csrf, 'origin' => 'https://attacker.invalid'], [], $body))->status());
        $this->assertSame(403, $service->handle(new Request('POST', '/admin', ['authorization' => $authorization, 'cookie' => 'kandoo_admin_csrf=wrong', 'origin' => 'https://TEST.invalid'], [], $body))->status());
        $this->assertNull($gateway->findDevice(self::DEVICE)['revoked_at']);
    }

    public function testV2PageListsStoresDevicesAndSmsSections(): void
    {
        [$config, $gateway] = $this->enabled('TEST-admin-pass');
        $gateway->seedSmsDeliveryStates(['sent', 'sent', 'failed']);
        $gateway->insertSmsCostEntry(['request_id' => null, 'store_id' => self::STORE, 'pattern' => 'notification', 'units' => 2, 'unit_cost' => '1500.000000', 'currency' => 'IRR', 'cost_date' => '2026-01-02', 'created_at' => 1767312000]);
        $gateway->insertSmsCostEntry(['request_id' => null, 'store_id' => self::STORE, 'pattern' => '<script>', 'units' => 1, 'unit_cost' => '950.000000', 'currency' => 'IRR', 'cost_date' => '2026-01-02', 'created_at' => 1767312001]);
        $service = new AdminService($config, $gateway, static function (string $phone): bool { return true; });
        $authorization = 'Basic ' . base64_encode('admin:TEST-admin-pass');
        $response = $service->handle(new Request('GET', '/admin', ['authorization' => $authorization]));
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('فاکتور دیجیتال کندو', $response->body());
        $this->assertStringContainsString('فروشگاه‌ها (1)', $response->body());
        $this->assertStringContainsString('دستگاه‌ها (1)', $response->body());
        $this->assertStringContainsString('بازنشانی بودجهٔ کد', $response->body());
        $this->assertStringContainsString(self::STORE, $response->body());
        $this->assertStringContainsString(self::DEVICE, $response->body());
        $this->assertStringContainsString('ابطال دستگاه', $response->body());
        $this->assertStringContainsString('آمار پیامک‌ها', $response->body());
        $this->assertStringContainsString('sent: 2', $response->body());
        $this->assertStringContainsString('failed: 1', $response->body());
        $this->assertStringContainsString('گزارش هزینهٔ پیامک', $response->body());
        $this->assertStringContainsString('2026-01-02', $response->body());
        $this->assertStringContainsString('&lt;script&gt;', $response->body());
        $this->assertStringNotContainsString('<script>', $response->body());
        // 2×1500 + 1×950 = 3950 for the day
        $this->assertStringContainsString('3950.000000', $response->body());
        $this->assertSame('no-store', $response->header('Cache-Control'));
        $this->assertStringContainsString('name="viewport"', $response->body());
        $this->assertStringContainsString('border-radius:16px', $response->body());
        $this->assertStringContainsString('@media(max-width:600px)', $response->body());
        $this->assertStringContainsString("style-src 'nonce-", (string)$response->header('Content-Security-Policy'));
        $this->assertStringNotContainsString("script-src 'unsafe-inline'", (string)$response->header('Content-Security-Policy'));

    }
    public function testEmptyStatesAndDemoOutboxAreEscaped(): void
    {
        $config = $this->config(password_hash('TEST-admin-pass', PASSWORD_DEFAULT));
        $gateway = new MemoryGateway();
        (new AdminManager($config, $gateway))->transition(true, 'TEST-admin-pass', '2026-01-01 00:00:00');
        $service = new AdminService($config, $gateway, null, static fn (): array => [['phone'=>'<b>synthetic</b>', 'body'=>'<script>synthetic</script>']]);
        $page = $service->handle(new Request('GET', '/admin', ['authorization'=>'Basic '.base64_encode('admin:TEST-admin-pass')]));
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('هنوز فروشگاهی ثبت نشده است.', $page->body());
        $this->assertStringContainsString('هنوز دستگاهی ثبت نشده است.', $page->body());
        $this->assertStringContainsString('&lt;script&gt;synthetic&lt;/script&gt;', $page->body());
        $this->assertStringNotContainsString('<script>synthetic</script>', $page->body());
    }

}
