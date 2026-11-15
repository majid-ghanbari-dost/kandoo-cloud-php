<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\ConfigException;
use PHPUnit\Framework\TestCase;

final class CustomerAuthConfigTest extends TestCase
{
    private function raw(): array
    {
        return ['base_url' => 'https://TEST.invalid',
            'db' => ['host' => 'TEST', 'name' => 'TEST', 'user' => 'TEST', 'pass' => 'TEST'],
            'customer_auth' => ['enabled' => true, 'code_length' => 6,
                'code_ttl_seconds' => 300, 'max_attempts' => 5, 'codes_per_announcement' => 2,
                'issue_window_seconds' => 900, 'max_issues_per_window' => 3,
                'session_ttl_seconds' => 2592000, 'device_numbers_per_day'=>3, 'device_ttl_seconds'=>2592000, 'issue_ip_limit_per_minute'=>10, 'verify_ip_limit_per_minute'=>20, 'otp_pepper' => 'TEST-only-synthetic-otp-pepper-0123456789', 'telegram_url' => null]];
    }

    public function testConfigurationIsExplicitAndDisabledByDefault(): void
    {
        $raw = $this->raw();
        unset($raw['customer_auth']);
        $this->assertFalse(Config::validate($raw)['customer_auth']['enabled']);
        $raw = $this->raw();
        $this->assertSame($raw['customer_auth'], Config::validate($raw)['customer_auth']);
        foreach (['code_length', 'code_ttl_seconds', 'max_attempts', 'codes_per_announcement',
            'issue_window_seconds', 'max_issues_per_window', 'session_ttl_seconds'] as $name) {
            $bad = $raw;
            unset($bad['customer_auth'][$name]);
            try { Config::validate($bad); $this->fail('Missing policy should fail: ' . $name); }
            catch (ConfigException $e) { $this->assertTrue(true); }
        }
        $raw['customer_auth']['codes_per_announcement'] = 3;
        try { Config::validate($raw); $this->fail('Announcement cap must not exceed two.'); }
        catch (ConfigException $e) { $this->assertTrue(true); }
    }
}
