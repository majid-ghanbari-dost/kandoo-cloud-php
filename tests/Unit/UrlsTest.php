<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Urls;
use PHPUnit\Framework\TestCase;

/**
 * Base-URL tests (PM ORDER 9 P-02): configuration is the single source of every absolute
 * link (decision D4) — changing base_url changes every generated link, and no domain is
 * hardcoded anywhere. All hostnames here are FAKE .invalid placeholders.
 */
final class UrlsTest extends TestCase
{
    public function testPublicInvoiceUrlComesFromConfiguredBaseUrl(): void
    {
        $a = new Urls('https://first.TEST-example.invalid');
        $b = new Urls('https://second.TEST-example.invalid/base');
        $this->assertSame('https://first.TEST-example.invalid/i/TOKEN-1', $a->publicInvoiceUrl('TOKEN-1'));
        $this->assertSame('https://second.TEST-example.invalid/base/i/TOKEN-1', $b->publicInvoiceUrl('TOKEN-1'));
    }

    public function testTrailingSlashIsNormalized(): void
    {
        $urls = new Urls('https://invoice.TEST-example.invalid///');
        $this->assertSame('https://invoice.TEST-example.invalid/health', $urls->healthUrl());
        $this->assertSame('https://invoice.TEST-example.invalid', $urls->base());
    }

    public function testTokenIsUrlEncodedIntoLinks(): void
    {
        $urls = new Urls('https://invoice.TEST-example.invalid');
        // Token alphabet is [A-Za-z0-9_-] (§6.1); rawurlencode keeps it intact while
        // proving nothing else could leak through.
        $this->assertSame(
            'https://invoice.TEST-example.invalid/i/AbCdEf012345_-XyZ',
            $urls->publicInvoiceUrl('AbCdEf012345_-XyZ')
        );
    }

    public function testAssetPathsAreRootRelativeWithoutDomain(): void
    {
        $urls = new Urls('https://invoice.TEST-example.invalid');
        $this->assertSame('/assets/fonts/Vazirmatn-Regular.ttf', $urls->assetPath('fonts/Vazirmatn-Regular.ttf'));
    }
}
