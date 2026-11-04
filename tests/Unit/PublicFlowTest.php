<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Kernel;
use Kandoo\Cloud\Store\MemoryGateway;
use Kandoo\Cloud\Support\FixedClock;
use Kandoo\Cloud\Support\RandomToken;
use PHPUnit\Framework\TestCase;

/**
 * Public read endpoint behaviour (CLOUD-CONTRACT.md §6, U6/U7/U8/U13, D10).
 * Envelope bodies are the frozen byte-exact strings (same source as docs/golden).
 * All fixtures are FAKE synthetic values.
 */
final class PublicFlowTest extends TestCase
{
    private const STORE = '11111111-1111-4111-8111-111111111111';
    private const TOKEN = 'TESTPUBLICTOKENAAAAAAAAAA';
    private const NOT_FOUND_BODY =
        '{"code":"PUBLIC_INVOICE_NOT_FOUND","message":"The requested invoice is not available.","retry_hint":{"action":"NONE"}}';
    private const RATE_LIMITED_BODY =
        '{"code":"PUBLIC_RATE_LIMITED","message":"Too many public invoice requests.","retry_hint":{"action":"RETRY_AFTER"}}';
    private const LEGAL_GATE_BODY =
        '{"code":"LEGAL_COPY_UNAVAILABLE","message":"Public invoice wording is awaiting lawyer approval.","retry_hint":{"action":"ENABLE_LEGAL_COPY"}}';
    private const NOW = 1767323045; // 2026-01-02T03:04:05Z

    private MemoryGateway $gateway;

    /** @param array<string, mixed> $overrides */
    private function kernel(array $overrides = []): Kernel
    {
        $config = Config::validate(array_merge([
            'base_url' => 'https://invoice.TEST-php.invalid',
            'db' => [
                'host' => '127.0.0.1', 'port' => 3306, 'name' => 'TEST_db',
                'user' => 'TEST_user', 'pass' => 'TEST-PASS', 'charset' => 'utf8mb4',
            ],
            'replication_key' => null,
            'public_rate_limit_per_minute' => 60,
            'ingest_rate_limit_per_minute' => 120,
            'public_legal_copy_required' => false,
            'env' => 'testing',
        ], $overrides));
        return new Kernel($config, $this->gateway, new FixedClock(self::NOW));
    }

    /** @param array<string, mixed> $overrides */
    private function seedProjection(array $overrides = []): void
    {
        $token = (string) ($overrides['public_token'] ?? self::TOKEN);
        $this->gateway->seedProjection(array_merge([
            'public_token' => $token,
            'public_token_hash' => RandomToken::hash($token),
            'digital_invoice_id' => RandomToken::uuidV4(),
            'invoice_id' => 'TEST-inv-1',
            'store_id' => self::STORE,
            'store_name' => 'TEST Store',
            'display_number' => 7,
            'lifecycle_status' => 'ISSUED',
            'issued_at' => '2026-01-02 03:04:05',
            'issued_payload' => '{"store_name":"TEST Store"}',
            'issued_content_sha256' => str_repeat('a', 64),
            'payable_rials' => 25000,
            'line_items' => '[{"product_name":"TEST Product","quantity_millis":2000,"unit_price_rials":12500,"line_total_rials":25000}]',
            'customer_phone' => '+989120000001',
            'customer_name' => null,
            'payments' => null,
            'currency_unit' => null,
            'remaining_balance' => null,
        ], $overrides));
    }

    protected function setUp(): void
    {
        $this->gateway = new MemoryGateway();
    }

    public function testByteIdentical404ForMissingInvalidAndMalformedTokens(): void
    {
        $kernel = $this->kernel(); // no projection seeded at all
        $bodies = [];
        $statuses = [];
        foreach ([
            self::TOKEN,                 // valid shape, unknown token
            'a!b@c',                      // malformed characters
            'SHORT',                      // too short
            str_repeat('A', 200),         // too long
        ] as $token) {
            $response = $kernel->handle(new Request('GET', '/i/' . $token));
            $statuses[] = $response->status();
            $bodies[] = $response->body();
            $this->assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
            $this->assertSame('no-store', $response->header('Cache-Control'));
            $this->assertSame('Accept', $response->header('Vary'));
        }
        $this->assertSame([404, 404, 404, 404], $statuses);
        // Byte-identical: one shared envelope for every not-found class (U6), so the
        // Content-Length derived from the body is identical too.
        $this->assertSame(array_fill(0, 4, self::NOT_FOUND_BODY), $bodies);
        $this->assertCount(1, array_unique($bodies));
    }

    public function testUnknownTokenWithSeededOtherInvoiceIsSame404(): void
    {
        $this->seedProjection();
        $response = $this->kernel()->handle(new Request('GET', '/i/BBBBBBBBBBBBBBBBBBBBBB'));
        $this->assertSame(404, $response->status());
        $this->assertSame(self::NOT_FOUND_BODY, $response->body());
    }

    public function testStoredHashMismatchNeverServesTheInvoice(): void
    {
        $this->seedProjection(['public_token_hash' => str_repeat('f', 64)]);
        $response = $this->kernel()->handle(new Request('GET', '/i/' . self::TOKEN));
        $this->assertSame(404, $response->status());
        $this->assertSame(self::NOT_FOUND_BODY, $response->body());
    }

    public function testUnknownLifecycleStatusIsSame404(): void
    {
        $this->seedProjection(['lifecycle_status' => 'ARCHIVED']);
        $response = $this->kernel()->handle(new Request('GET', '/i/' . self::TOKEN));
        $this->assertSame(404, $response->status());
        $this->assertSame(self::NOT_FOUND_BODY, $response->body());
    }

    public function testRevokedAndSupersededAreServed(): void
    {
        foreach (['REVOKED', 'SUPERSEDED'] as $status) {
            $this->gateway->reset();
            $this->seedProjection(['lifecycle_status' => $status]);
            $response = $this->kernel()->handle(new Request('GET', '/i/' . self::TOKEN, [], ['format' => 'json']));
            $this->assertSame(200, $response->status(), $status);
            $this->assertStringContainsString('"lifecycle_status":"' . $status . '"', $response->body());
        }
    }

    public function testFormatNegotiation(): void
    {
        $this->seedProjection();
        $kernel = $this->kernel();

        $json = $kernel->handle(new Request('GET', '/i/' . self::TOKEN, [], ['format' => 'json']));
        $this->assertSame('application/json; charset=utf-8', $json->header('Content-Type'));
        $this->assertSame('{', $json->body()[0] ?? '');

        $acceptJson = $kernel->handle(new Request('GET', '/i/' . self::TOKEN, ['accept' => 'application/json']));
        $this->assertSame('application/json; charset=utf-8', $acceptJson->header('Content-Type'));

        $any = $kernel->handle(new Request('GET', '/i/' . self::TOKEN, ['accept' => '*/*']));
        $this->assertStringContainsString('<!doctype html>', $any->body());
        $this->assertSame('text/html; charset=utf-8', $any->header('Content-Type'));

        $htmlDefault = $kernel->handle(new Request('GET', '/i/' . self::TOKEN, ['accept' => 'text/html,*/*;q=0.8']));
        $this->assertStringContainsString('فاکتور دیجیتال کندو', $htmlDefault->body());
    }

    public function testLegalCopyGateReturns503WithoutEchoingTheToken(): void
    {
        $this->seedProjection();
        $kernel = $this->kernel(['public_legal_copy_required' => true]);
        $response = $kernel->handle(new Request('GET', '/i/' . self::TOKEN));
        $this->assertSame(503, $response->status());
        $this->assertSame(self::LEGAL_GATE_BODY, $response->body());
        $this->assertStringNotContainsString(self::TOKEN, $response->body());
        $this->assertSame('no-store', $response->header('Cache-Control'));
        $this->assertSame('Accept', $response->header('Vary'));
    }

    public function testPublicRateLimitPerClientIp(): void
    {
        $this->seedProjection();
        $kernel = $this->kernel(['public_rate_limit_per_minute' => 2]);
        $first = $kernel->handle(new Request('GET', '/i/' . self::TOKEN, [], ['format' => 'json'], '', null, '198.51.100.9'));
        $this->assertSame(200, $first->status());
        $second = $kernel->handle(new Request('GET', '/i/BBBBBBBBBBBBBBBBBBBBBB', [], [], '', null, '198.51.100.9'));
        $this->assertSame(404, $second->status());
        $third = $kernel->handle(new Request('GET', '/i/' . self::TOKEN, [], ['format' => 'json'], '', null, '198.51.100.9'));
        $this->assertSame(429, $third->status());
        $this->assertSame(self::RATE_LIMITED_BODY, $third->body());
        $retryAfter = (int) (string) $third->header('Retry-After');
        $this->assertTrue($retryAfter >= 1 && $retryAfter <= 60, "retry-after $retryAfter in [1,60]");
        $this->assertSame('no-store', $third->header('Cache-Control'));
        $this->assertSame('Accept', $third->header('Vary'));

        // a different client is unaffected (per-client limiting)
        $other = $kernel->handle(new Request('GET', '/i/' . self::TOKEN, [], ['format' => 'json'], '', null, '198.51.100.10'));
        $this->assertSame(200, $other->status());
    }

    public function testTokenHashIndexIsSha256OfRawToken(): void
    {
        $this->assertSame(hash('sha256', self::TOKEN), RandomToken::hash(self::TOKEN));
        $this->assertSame(22, strlen(RandomToken::generate()));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', RandomToken::generate());
        $this->assertNotSame(RandomToken::generate(), RandomToken::generate());
    }
}
