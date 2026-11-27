<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Customer\CustomerAuth;
use Kandoo\Cloud\Customer\OtpSecrets;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Http\Response;
use Kandoo\Cloud\Kernel;
use Kandoo\Cloud\Store\MariaDbGateway;
use Kandoo\Cloud\Support\FixedClock;
use Kandoo\Cloud\Tests\Support\DbTestCase;

/**
 * PHP-B / P-34: the customer API and pages carry their OWN read budget, independent of the
 * OTP issue/verify budgets (that gap was recorded in docs/SECURITY-REVIEW-P34.md).
 *
 * Everything here is synthetic: fake store, fake phone, hand-built session rows.
 * No real data, no SMS, no network. FAKE fixtures only.
 */
final class CustomerReadLimitDatabaseTest extends DbTestCase
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

    /** Seeds one synthetic store, one invoice and two live customer sessions. */
    private function seed(MariaDbGateway $gateway, \PDO $pdo): void
    {
        foreach (self::RESET_TABLES as $table) { $pdo->exec('DELETE FROM '.$table); }
        $gateway->reset();
        $gateway->seedStore(['store_id' => self::STORE, 'active_writer_device_id' => self::STORE, 'active_writer_epoch' => 1,
            'accepted_through_seq' => 0, 'entry_count' => 0, 'head_entry_hash' => null, 'projection_version' => 0]);
        $gateway->seedProjection(['public_token' => 'TESTREADLIMITTOKEN1234567890', 'public_token_hash' => hash('sha256', 'TESTREADLIMITTOKEN1234567890'),
            'digital_invoice_id' => self::INVOICE, 'invoice_id' => self::INVOICE, 'store_id' => self::STORE,
            'store_name' => 'TEST Read Limit Store', 'display_number' => 9, 'lifecycle_status' => 'ISSUED',
            'issued_at' => '2026-01-02 03:04:05', 'issued_payload' => '{}', 'issued_content_sha256' => str_repeat('a', 64),
            'payable_rials' => 12000, 'line_items' => '[]', 'customer_phone' => self::PHONE]);
        $insert = $pdo->prepare('INSERT INTO cloud_customer_sessions (token_hash,phone,expires_at) VALUES (?,?,?)');
        $insert->execute([OtpSecrets::sessionHash(self::TOKEN_A), self::PHONE, self::NOW + 3600]);
        $insert->execute([OtpSecrets::sessionHash(self::TOKEN_B), self::PHONE, self::NOW + 3600]);
    }

    private function kernel(array $settings, MariaDbGateway $gateway, FixedClock $clock): Kernel
    {
        $config = self::$config;
        $config['customer_auth'] = $settings;
        return new Kernel($config, $gateway, $clock, new CustomerAuth($settings, $this->db(), $clock));
    }

    private function read(Kernel $kernel, string $path, string $token, string $peer = '192.0.2.10'): Response
    {
        return $kernel->handle(new Request('GET', $path, ['authorization' => 'Bearer '.$token], [], '', null, $peer));
    }

    public function testPerSessionReadBudgetBoundsStolenBearerWithoutAffectingOtherSessions(): void
    {
        $db = $this->db(); $pdo = $db->pdo(); $gateway = new MariaDbGateway($db);
        try {
            $this->seed($gateway, $pdo);
            $clock = new FixedClock(self::NOW);
            $kernel = $this->kernel($this->settings(0, 2), $gateway, $clock);
            $detail = '/v1/customer/invoices/'.self::STORE.'/'.self::INVOICE;

            // Two reads are inside the per-session budget of 2, even from different peers.
            $this->assertSame(200, $this->read($kernel, '/v1/customer/invoices', self::TOKEN_A, '192.0.2.10')->status());
            $this->assertSame(200, $this->read($kernel, $detail, self::TOKEN_A, '192.0.2.11')->status());

            // The third read with the same session is throttled, with no invoice payload.
            $blocked = $this->read($kernel, '/v1/customer/invoices', self::TOKEN_A, '192.0.2.10');
            $this->assertSame(429, $blocked->status());
            $this->assertSame('{"status":"RATE_LIMITED"}', $blocked->body());
            $this->assertSame('60', $blocked->header('Retry-After'));
            $this->assertSame('no-store', $blocked->header('Cache-Control'));

            // A second session on the same peer keeps its own budget.
            $this->assertSame(200, $this->read($kernel, '/v1/customer/invoices', self::TOKEN_B, '192.0.2.10')->status());

            // Unknown or malformed credentials stay 401 (never 429): the auth check is not a bucket probe.
            $this->assertSame(401, $this->read($kernel, '/v1/customer/invoices', str_repeat('Z', 43), '192.0.2.10')->status());
            $this->assertSame(401, $kernel->handle(new Request('GET', '/v1/customer/invoices', [], [], '', null, '192.0.2.10'))->status());

            // Denied requests are not counted and the window rolls over.
            $clock->set(self::NOW + 60);
            $this->assertSame(200, $this->read($kernel, '/v1/customer/invoices', self::TOKEN_A, '192.0.2.10')->status());

            // Only keyed digests are stored: no raw address and no raw session token.
            $stored = $pdo->query('SELECT bucket_key FROM customer_read_rate_buckets')->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertNotEmpty($stored);
            foreach ($stored as $key) {
                $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $key);
                $this->assertStringNotContainsString('192.0.2.', (string) $key);
                $this->assertStringNotContainsString(self::TOKEN_A, (string) $key);
            }
        } finally {
            foreach (self::RESET_TABLES as $table) { $pdo->exec('DELETE FROM '.$table); }
            $gateway->reset();
        }
    }

    public function testPerPeerReadBudgetProtectsBothNativeApiAndBrowserPages(): void
    {
        $db = $this->db(); $pdo = $db->pdo(); $gateway = new MariaDbGateway($db);
        try {
            $this->seed($gateway, $pdo);
            $clock = new FixedClock(self::NOW);
            $kernel = $this->kernel($this->settings(2, 0), $gateway, $clock);

            $this->assertSame(200, $this->read($kernel, '/v1/customer/invoices', self::TOKEN_A, '198.51.100.7')->status());
            $this->assertSame(200, $this->read($kernel, '/v1/customer/invoices', self::TOKEN_B, '198.51.100.7')->status());

            $blocked = $this->read($kernel, '/v1/customer/invoices', self::TOKEN_A, '198.51.100.7');
            $this->assertSame(429, $blocked->status());
            $this->assertSame('{"status":"RATE_LIMITED"}', $blocked->body());

            // Another peer is unaffected, even with the same session token.
            $this->assertSame(200, $this->read($kernel, '/v1/customer/invoices', self::TOKEN_A, '198.51.100.8')->status());

            // The browser page shares the same view of the budget and refuses without invoice HTML.
            $page = $kernel->handle(new Request('GET', '/account/invoices',
                ['cookie' => 'kandoo_session='.self::TOKEN_B], [], '', null, '198.51.100.7'));
            $this->assertSame(429, $page->status());
            $this->assertSame('60', $page->header('Retry-After'));
            $this->assertStringNotContainsString('TEST Read Limit Store', $page->body());

            $clean = $kernel->handle(new Request('GET', '/account/invoices',
                ['cookie' => 'kandoo_session='.self::TOKEN_B], [], '', null, '198.51.100.8'));
            $this->assertSame(200, $clean->status());
            $this->assertStringContainsString('TEST Read Limit Store', $clean->body());
        } finally {
            foreach (self::RESET_TABLES as $table) { $pdo->exec('DELETE FROM '.$table); }
            $gateway->reset();
        }
    }

    public function testSessionResumeValidatesAStoredBearerWithoutReadingInvoices(): void
    {
        $db = $this->db(); $pdo = $db->pdo(); $gateway = new MariaDbGateway($db);
        try {
            $this->seed($gateway, $pdo);
            $clock = new FixedClock(self::NOW);
            $kernel = $this->kernel($this->settings(0, 0), $gateway, $clock);

            $valid = $this->read($kernel, '/v1/customer/session', self::TOKEN_A);
            $this->assertSame(200, $valid->status());
            $this->assertSame('no-store', $valid->header('Cache-Control'));
            $decoded = json_decode($valid->body(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('VALID', $decoded['status']);
            $this->assertSame(self::NOW + 3600, $decoded['expires_at']);
            $this->assertStringNotContainsString(self::PHONE, $valid->body());
            $this->assertStringNotContainsString(self::TOKEN_A, $valid->body());

            $this->assertSame(401, $kernel->handle(new Request('GET', '/v1/customer/session', [], [], '', null, '192.0.2.10'))->status());
            $this->assertSame(401, $kernel->handle(new Request('GET', '/v1/customer/session',
                ['authorization' => 'Bearer short'], [], '', null, '192.0.2.10'))->status());

            // Expiry is enforced against the clock, not the stored row.
            $clock->set(self::NOW + 3600);
            $this->assertSame(401, $this->read($kernel, '/v1/customer/session', self::TOKEN_A)->status());

            // An explicit revoke makes the stored Bearer unusable for resume and for data.
            $clock->set(self::NOW);
            $revoke = $kernel->handle(new Request('DELETE', '/v1/customer/session',
                ['authorization' => 'Bearer '.self::TOKEN_A], [], '', null, '192.0.2.10'));
            $this->assertSame(200, $revoke->status());
            $this->assertSame(401, $this->read($kernel, '/v1/customer/session', self::TOKEN_A)->status());
            $this->assertSame(401, $this->read($kernel, '/v1/customer/invoices', self::TOKEN_A)->status());
            $this->assertSame(200, $this->read($kernel, '/v1/customer/session', self::TOKEN_B)->status());
        } finally {
            foreach (self::RESET_TABLES as $table) { $pdo->exec('DELETE FROM '.$table); }
            $gateway->reset();
        }
    }
}
