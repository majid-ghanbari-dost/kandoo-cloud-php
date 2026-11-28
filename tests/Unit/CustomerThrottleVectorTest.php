<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Customer\CustomerAuth;
use Kandoo\Cloud\Customer\OtpSecrets;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Kernel;
use Kandoo\Cloud\Store\MariaDbGateway;
use Kandoo\Cloud\Support\FixedClock;
use Kandoo\Cloud\Tests\Support\DbTestCase;

/**
 * PHP-B / P-34 + BACKLOG item 1 ("throttling and session tests").
 *
 * Replays `docs/vectors/customer-api-throttle-v1-2-proposed.json` against the real customer
 * surface with the read budget ENABLED and small limits, so the 429 path is a pinned contract
 * instead of a paragraph. The same file is what the C# client lane replays, which is why the
 * scenarios carry their own limits and a clock-advance step instead of relying on wall time.
 *
 * Synthetic data only: fake store, fake phone, hand-built session rows, no SMS, no network.
 */
final class CustomerThrottleVectorTest extends DbTestCase
{
    private const STORE = '11111111-1111-4111-8111-111111111111';
    private const INVOICE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const PHONE = '+989120000001';
    private const TOKEN_A = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
    private const TOKEN_B = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';
    private const NOW = 1767323045;

    private const RESET_TABLES = ['cloud_customer_sessions', 'cloud_otp_challenges', 'cloud_sms_announcements',
        'cloud_customer_phones', 'cloud_otp_idempotency_locks', 'customer_read_rate_buckets'];

    /** @return array<string, mixed> */
    private function settings(int $ipLimit, int $sessionLimit): array
    {
        return ['enabled' => true, 'code_length' => 6, 'code_ttl_seconds' => 300, 'max_attempts' => 3,
            'codes_per_announcement' => 2, 'issue_window_seconds' => 900, 'max_issues_per_window' => 3,
            'session_ttl_seconds' => 3600, 'otp_pepper' => 'TEST-only-synthetic-otp-pepper-0123456789',
            'telegram_url' => null,
            'read_ip_limit_per_minute' => $ipLimit, 'read_session_limit_per_minute' => $sessionLimit];
    }

    private function seed(MariaDbGateway $gateway, \PDO $pdo): void
    {
        foreach (self::RESET_TABLES as $table) { $pdo->exec('DELETE FROM '.$table); }
        $gateway->reset();
        $gateway->seedStore(['store_id' => self::STORE, 'active_writer_device_id' => self::STORE, 'active_writer_epoch' => 1,
            'accepted_through_seq' => 0, 'entry_count' => 0, 'head_entry_hash' => null, 'projection_version' => 0]);
        $gateway->seedProjection(['public_token' => 'TESTTHROTTLEVECTORTOKEN1234', 'public_token_hash' => hash('sha256', 'TESTTHROTTLEVECTORTOKEN1234'),
            'digital_invoice_id' => self::INVOICE, 'invoice_id' => self::INVOICE, 'store_id' => self::STORE,
            'store_name' => 'TEST Throttle Store', 'display_number' => 9, 'lifecycle_status' => 'ISSUED',
            'issued_at' => '2026-01-02 03:04:05', 'issued_payload' => '{}', 'issued_content_sha256' => str_repeat('a', 64),
            'payable_rials' => 12000, 'line_items' => '[]', 'customer_phone' => self::PHONE]);
        $insert = $pdo->prepare('INSERT INTO cloud_customer_sessions (token_hash,phone,expires_at) VALUES (?,?,?)');
        $insert->execute([OtpSecrets::sessionHash(self::TOKEN_A), self::PHONE, self::NOW + 3600]);
        $insert->execute([OtpSecrets::sessionHash(self::TOKEN_B), self::PHONE, self::NOW + 3600]);
    }

    /** Objects are required subsets; arrays stay exact, as in the P-31 replay harness. */
    private function assertVectorSubset($expected, $actual, string $label): void
    {
        if (is_array($expected) && $expected !== [] && array_keys($expected) !== range(0, count($expected) - 1)) {
            $this->assertIsArray($actual, $label);
            foreach ($expected as $key => $value) {
                $this->assertArrayHasKey($key, $actual, $label.'.'.$key);
                $this->assertVectorSubset($value, $actual[$key], $label.'.'.$key);
            }
            return;
        }
        $this->assertSame($expected, $actual, $label);
    }

    public function testThrottleVectorsReplayAgainstTheRealCustomerSurface(): void
    {
        $vectors = json_decode((string) file_get_contents(__DIR__.'/../../docs/vectors/customer-api-throttle-v1-2-proposed.json'),
            true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringStartsWith('PROPOSED', (string) $vectors['status'], 'vector file must stay honestly proposed');
        $this->assertNotEmpty($vectors['client_obligations'], 'client obligations must be stated');

        $vars = $vectors['setup'];
        $resolve = static function ($value) use (&$resolve, &$vars) {
            if (is_array($value)) { return array_map($resolve, $value); }
            if (!is_string($value)) { return $value; }
            return preg_replace_callback('/\{\{([a-z_]+)\}\}/', static function (array $match) use (&$vars): string {
                if (!isset($vars[$match[1]]) || !is_string($vars[$match[1]])) {
                    throw new \RuntimeException('Missing vector variable: '.$match[1]);
                }
                return $vars[$match[1]];
            }, $value);
        };

        $db = $this->db(); $pdo = $db->pdo(); $gateway = new MariaDbGateway($db);
        $clock = new FixedClock(self::NOW);
        try {
            $this->seed($gateway, $pdo);
            $scenarios = 0; $steps = 0; $throttled = 0;
            foreach ($vectors['scenarios'] as $scenario) {
                $scenarios++;
                // Each scenario starts from an empty bucket state and the setup clock.
                $pdo->exec('DELETE FROM customer_read_rate_buckets');
                $clock->set(self::NOW);
                $settings = $this->settings((int) $scenario['limits']['read_ip_limit_per_minute'],
                    (int) $scenario['limits']['read_session_limit_per_minute']);
                $config = self::$config;
                $config['customer_auth'] = $settings;
                $kernel = new Kernel($config, $gateway, $clock, new CustomerAuth($settings, $db, $clock));

                foreach ($scenario['steps'] as $step) {
                    $label = $scenario['id'].'/'.$step['id'];
                    if (isset($step['advance_seconds'])) {
                        $clock->set($clock->nowUnix() + (int) $step['advance_seconds']);
                        continue;
                    }
                    $steps++;
                    $request = new Request($step['method'], $resolve($step['path']), $resolve($step['headers']), [], '',
                        null, (string) ($step['peer'] ?? $scenario['peer']));
                    $response = $kernel->handle($request);
                    $this->assertSame((int) $step['expect']['http'], $response->status(), $label);
                    $this->assertSame('no-store', $response->header('Cache-Control'), $label);
                    foreach (($step['expect']['headers'] ?? []) as $header => $value) {
                        $this->assertSame($value, $response->header($header), $label.' header '.$header);
                    }
                    if (isset($step['expect']['body'])) {
                        $actual = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
                        $this->assertVectorSubset($resolve($step['expect']['body']), $actual, $label);
                    }
                    if ((int) $step['expect']['http'] === 429) { $throttled++; }
                }
            }
            // The vectors must actually exercise the throttle path, not just the happy path.
            $this->assertSame(3, $scenarios);
            $this->assertGreaterThanOrEqual(15, $steps);
            $this->assertGreaterThanOrEqual(5, $throttled);
        } finally {
            foreach (self::RESET_TABLES as $table) { $pdo->exec('DELETE FROM '.$table); }
            $gateway->reset();
        }
    }
}
