<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\ConfigException;
use PHPUnit\Framework\TestCase;

/**
 * Configuration tests — fail-closed behaviour (PM ORDER 9 P-02).
 * All values here are FAKE placeholders; no real credential appears anywhere.
 */
final class ConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private function validRaw(): array
    {
        return [
            'base_url' => 'https://invoice.TEST-example.invalid/',
            'db' => [
                'host' => '127.0.0.1', 'port' => 3306, 'name' => 'TEST_db',
                'user' => 'TEST_user', 'pass' => 'TEST-pass', 'charset' => 'utf8mb4',
            ],
            'replication_key' => null,
            'public_rate_limit_per_minute' => 60,
            'ingest_rate_limit_per_minute' => 120,
            'public_legal_copy_required' => false,
            'env' => 'testing',
        ];
    }

    public function testValidateNormalizesBaseUrlAndKeepsValues(): void
    {
        $config = Config::validate($this->validRaw());
        $this->assertSame('https://invoice.TEST-example.invalid', $config['base_url']);
        $this->assertSame(60, $config['public_rate_limit_per_minute']);
        $this->assertSame(120, $config['ingest_rate_limit_per_minute']);
        $this->assertNull($config['replication_key']);
        $this->assertFalse($config['public_legal_copy_required']);
    }

    public function testValidateAppliesDefaults(): void
    {
        $raw = $this->validRaw();
        unset($raw['public_rate_limit_per_minute'], $raw['ingest_rate_limit_per_minute'], $raw['env'], $raw['public_legal_copy_required']);
        unset($raw['db']['port'], $raw['db']['charset']);
        $config = Config::validate($raw);
        $this->assertSame(60, $config['public_rate_limit_per_minute']);
        $this->assertSame(120, $config['ingest_rate_limit_per_minute']);
        $this->assertSame(3306, $config['db']['port']);
        $this->assertSame('utf8mb4', $config['db']['charset']);
        $this->assertSame('production', $config['env']);
    }

    public function testMissingFileFailsClosed(): void
    {
        try {
            Config::load('/nonexistent/TEST-config-path.php');
            $this->fail('Expected ConfigException for a missing config file.');
        } catch (ConfigException $e) {
            $this->assertStringContainsString('Configuration file not found', $e->getMessage());
        }
    }

    public function testNonArrayConfigFailsClosed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kandoo-cfg-');
        $this->assertNotFalse($path);
        file_put_contents($path, '<?php return "not-an-array";');
        try {
            Config::load($path);
            $this->fail('Expected ConfigException for a non-array config.');
        } catch (ConfigException $e) {
            $this->assertStringContainsString('must return an array', $e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function testInvalidValuesFailClosed(): void
    {
        $cases = [
            'missing base_url' => (function () { $r = $this->validRaw(); unset($r['base_url']); return $r; })(),
            'ftp base_url'     => (function () { $r = $this->validRaw(); $r['base_url'] = 'ftp://x.test'; return $r; })(),
            'empty base_url'   => (function () { $r = $this->validRaw(); $r['base_url'] = ''; return $r; })(),
            'missing db'       => (function () { $r = $this->validRaw(); unset($r['db']); return $r; })(),
            'bad db port'      => (function () { $r = $this->validRaw(); $r['db']['port'] = 'TEST'; return $r; })(),
            'bad rate limit'   => (function () { $r = $this->validRaw(); $r['public_rate_limit_per_minute'] = -1; return $r; })(),
            'empty replication key' => (function () { $r = $this->validRaw(); $r['replication_key'] = ''; return $r; })(),
            'bad gate flag'    => (function () { $r = $this->validRaw(); $r['public_legal_copy_required'] = 'yes'; return $r; })(),
        ];
        foreach ($cases as $label => $raw) {
            try {
                Config::validate($raw);
                $this->fail("Expected ConfigException for: $label");
            } catch (ConfigException) {
                $this->assertTrue(true, $label);
            }
        }
    }
}
