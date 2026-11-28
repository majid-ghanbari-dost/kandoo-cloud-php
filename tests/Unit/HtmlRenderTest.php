<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Kernel;
use Kandoo\Cloud\Store\MemoryGateway;
use Kandoo\Cloud\Support\FixedClock;
use Kandoo\Cloud\Support\RandomToken;
use Kandoo\Cloud\Tests\Support\GoldenHarness;
use PHPUnit\Framework\TestCase;

/**
 * Persian RTL public page (CLOUD-CONTRACT.md §9, D10/D14, U14). The golden g-018 marker
 * set is pinned via the replay harness; this test adds the behaviours no golden covers:
 * v1.1 recorded-value replacement, escaping, and the no-external-reference rule.
 * All fixtures are FAKE synthetic values.
 */
final class HtmlRenderTest extends TestCase
{
    private const STORE = '11111111-1111-4111-8111-111111111111';
    private const TOKEN = 'TESTPUBLICTOKENAAAAAAAAAA';

    private MemoryGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new MemoryGateway();
    }

    private function kernel(): Kernel
    {
        $config = Config::validate([
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
        ]);
        return new Kernel($config, $this->gateway, new FixedClock(GoldenHarness::NOW_UNIX));
    }

    /** @param array<string, mixed> $overrides */
    private function seedProjection(array $overrides = []): void
    {
        $token = (string) ($overrides['public_token'] ?? self::TOKEN);
        $this->gateway->seedProjection(array_merge([
            'public_token' => $token,
            'public_token_hash' => RandomToken::hash($token),
            'digital_invoice_id' => RandomToken::uuidV4(),
            'invoice_id' => 'TEST-inv-html-1',
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

    private function renderHtml(): string
    {
        $response = $this->kernel()->handle(new Request('GET', '/i/' . self::TOKEN, ['accept' => 'text/html']));
        $this->assertSame(200, $response->status());
        $this->assertSame('text/html; charset=utf-8', $response->header('Content-Type'));
        return $response->body();
    }

    public function testGoldenHtmlViewReplayPinsEveryMarker(): void
    {
        $result = GoldenHarness::replay(dirname(__DIR__, 2) . '/docs/golden/g-018-public-html-view.json', new MemoryGateway());
        $this->assertSame([], $result['problems']);
    }

    public function testPageStructureAndDesignTokens(): void
    {
        $this->seedProjection();
        $html = $this->renderHtml();
        $this->assertStringContainsString('<!doctype html>', $html);
        $this->assertStringContainsString('lang="fa"', $html);
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('<meta charset="utf-8">', $html);
        $this->assertStringContainsString('فاکتور دیجیتال کندو', $html);
        foreach ([
            '--honey: #F2A900', '--ink: #2B1D0E', '--cream: #FFF8E7',
            '--wax: #F5E9C8', '--green: #256B43', '--review: #9A3B0A',
        ] as $token) {
            $this->assertStringContainsString($token, $html);
        }
        $this->assertStringContainsString('@font-face', $html);
        $this->assertStringContainsString('/assets/fonts/Vazirmatn-Regular.ttf', $html);
        $this->assertStringContainsString('/assets/fonts/Vazirmatn-Bold.ttf', $html);
        $this->assertStringContainsString('@media print', $html);
        // single light page: no external references anywhere (D10)
        $this->assertStringNotContainsString('http://', $html);
        $this->assertStringNotContainsString('https://', $html);
        // Jalali + Tehran wall time of the stored instant (U9)
        $this->assertStringContainsString('1404/10/12', $html);
        $this->assertStringContainsString('06:34:05', $html);
        $this->assertStringContainsString('Asia/Tehran', $html);
    }

    public function testPublicOutboxPageUsesMobileReceiptLayoutWithoutChangingRecordedValues(): void
    {
        $this->seedProjection();
        $html = $this->renderHtml();
        $this->assertStringContainsString('class="brand-header"', $html);
        $this->assertStringContainsString('class="tear"', $html);
        $this->assertStringContainsString('background: var(--honey)', $html);
        $this->assertStringContainsString('@media screen and (max-width:600px)', $html);
        $this->assertStringContainsString('تعداد (هزارم واحد): ', $html);
        $this->assertStringContainsString('جمع (ریال): ', $html);
        $this->assertStringContainsString('border-bottom:1px dotted #C9B88E', $html);
        $this->assertStringContainsString('25,000</bdi> ریال', $html);
        $this->assertStringNotContainsString(self::TOKEN, $html);
    }

    public function testRawTokenNeverAppearsAndGapCopyIsExplicit(): void
    {
        $this->seedProjection();
        $html = $this->renderHtml();
        $this->assertStringNotContainsString(self::TOKEN, $html);
        $this->assertStringNotContainsString('payload-only', $html);
        // v1.0 gap copy names the missing fields explicitly instead of inventing values
        $this->assertStringContainsString('customer_name', $html);
        $this->assertStringContainsString('payment_method', $html);
        $this->assertStringContainsString('needs lawyer approval', $html);
    }

    public function testRecordedV11ValuesReplaceTheGapCopy(): void
    {
        $this->seedProjection([
            'customer_name' => 'مشتری نمونه',
            'payments' => '[{"type":"cash","amount":20000},{"type":"card-reader","amount":5000}]',
            'currency_unit' => 'toman',
            'remaining_balance' => 0,
        ]);
        $html = $this->renderHtml();
        $this->assertStringContainsString('مشتری نمونه', $html);
        $this->assertStringContainsString('نقد', $html);
        $this->assertStringContainsString('کارتخوان', $html);
        $this->assertStringContainsString('20,000', $html);
        $this->assertStringContainsString('5,000', $html);
        $this->assertStringContainsString('تومان', $html);
        // gap literals are gone once the values are recorded (U14)
        $this->assertStringNotContainsString('<code dir="ltr">customer_name</code>', $html);
        $this->assertStringNotContainsString('<code dir="ltr">payment_method</code>', $html);
    }

    public function testDynamicTextIsEscaped(): void
    {
        $this->seedProjection([
            'store_name' => "TEST <script>alert('x')</script>",
            'line_items' => '[{"product_name":"TEST <b>کالا</b>","quantity_millis":1000,"unit_price_rials":800,"line_total_rials":800}]',
        ]);
        $html = $this->renderHtml();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&#39;', $html);
        $this->assertStringContainsString('TEST &lt;b&gt;کالا&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>کالا</b>', $html);
    }
}
