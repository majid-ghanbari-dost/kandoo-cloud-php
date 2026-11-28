<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Http\PeerAddress;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Kernel;
use Kandoo\Cloud\Store\MemoryGateway;
use Kandoo\Cloud\Support\FixedClock;
use PHPUnit\Framework\TestCase;

/**
 * PHP-B / P-34: the recorded finding "behind a reverse proxy every client collapses into one
 * bucket" is fixed by an explicit trust list. Thin pure-unit coverage of the resolver, plus a
 * Kernel-level proof that the public limiter really uses the forwarded address.
 *
 * No database, no network, synthetic values only.
 */
final class PeerAddressTest extends TestCase
{
    private function request(string $peer, ?string $forwarded = null): Request
    {
        $headers = $forwarded === null ? [] : ['x-forwarded-for' => $forwarded];
        return new Request('GET', '/i/TESTTOKEN', $headers, [], '', null, $peer);
    }

    public function testDirectPeerIsUsedWhenNoProxyIsTrusted(): void
    {
        $this->assertSame('203.0.113.9', PeerAddress::effective($this->request('203.0.113.9', '198.51.100.1'), []));
        // An untrusted peer cannot inject an address, even with a plausible header.
        $this->assertSame('203.0.113.9', PeerAddress::effective($this->request('203.0.113.9', '198.51.100.1'), ['10.0.0.1']));
    }

    public function testTrustedProxyForwardsTheRightmostUntrustedHop(): void
    {
        $this->assertSame('198.51.100.7', PeerAddress::effective($this->request('10.0.0.1', '198.51.100.7'), ['10.0.0.1']));
        // Two trusted hops; the left-most value is client-controlled and must be ignored.
        $this->assertSame('198.51.100.7',
            PeerAddress::effective($this->request('10.0.0.2', '1.2.3.4, 198.51.100.7, 10.0.0.1'), ['10.0.0.1', '10.0.0.2']));
    }

    public function testMalformedOrOversizedHeadersFallBackToTheDirectPeer(): void
    {
        foreach (['not-an-ip', '198.51.100.7:443', '[::1]:8080', '198.51.100.7, nope', ''] as $bad) {
            $this->assertSame('10.0.0.1', PeerAddress::effective($this->request('10.0.0.1', $bad), ['10.0.0.1']), $bad);
        }
        $long = implode(', ', array_fill(0, 40, '198.51.100.7'));
        $this->assertSame('10.0.0.1', PeerAddress::effective($this->request('10.0.0.1', $long), ['10.0.0.1']));
        $this->assertSame('10.0.0.1', PeerAddress::effective($this->request('10.0.0.1', str_repeat('1', 600)), ['10.0.0.1']));
        // Every hop trusted: nothing left to attribute, so stay with the direct peer.
        $this->assertSame('10.0.0.1', PeerAddress::effective($this->request('10.0.0.1', '10.0.0.2'), ['10.0.0.1', '10.0.0.2']));
    }

    public function testIpv6IsSupported(): void
    {
        $this->assertSame('2001:db8::7', PeerAddress::effective($this->request('::1', '2001:db8::7'), ['::1']));
    }

    public function testConfigValidatesTheTrustList(): void
    {
        $base = ['base_url' => 'https://invoice.TEST-example.invalid',
            'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'TEST_db', 'user' => 'TEST_u', 'pass' => 'TEST_p']];
        $this->assertSame([], Config::validate($base)['trusted_proxies']);
        $this->assertSame(['10.0.0.1', '::1'], Config::validate($base + ['trusted_proxies' => ['10.0.0.1', '::1']])['trusted_proxies']);
        $this->assertNull(Config::validate($base)['backup_hmac_key']);
        foreach ([['host.example'], [10], ['10.0.0.1', 'nope'], 'not-a-list', array_fill(0, 17, '10.0.0.1')] as $bad) {
            try {
                Config::validate($base + ['trusted_proxies' => $bad]);
                $this->fail('Invalid trust list accepted: ' . json_encode($bad));
            } catch (\Kandoo\Cloud\ConfigException $e) {
                $this->assertStringContainsString('trusted_proxies', $e->getMessage());
            }
        }
        try {
            Config::validate($base + ['backup_hmac_key' => 'too-short']);
            $this->fail('Short backup key accepted');
        } catch (\Kandoo\Cloud\ConfigException $e) {
            $this->assertStringContainsString('backup_hmac_key', $e->getMessage());
        }
    }

    /** The public invoice limiter must key on the forwarded address when the proxy is trusted. */
    public function testPublicRateLimitSeparatesClientsBehindATrustedProxy(): void
    {
        $base = ['base_url' => 'https://invoice.TEST-example.invalid',
            'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'TEST_db', 'user' => 'TEST_u', 'pass' => 'TEST_p'],
            'public_rate_limit_per_minute' => 1];
        $clock = new FixedClock(1767323045);

        // Without the trust list the shared proxy address is one bucket: the second client is limited.
        $direct = new Kernel(Config::validate($base), new MemoryGateway(), $clock);
        $forwarded = static fn (string $ip): Request => new Request('GET', '/i/TESTMISSINGTOKEN1234567890',
            ['x-forwarded-for' => $ip], [], '', null, '10.0.0.1');
        $this->assertSame(404, $direct->handle($forwarded('198.51.100.1'))->status());
        $this->assertSame(429, $direct->handle($forwarded('198.51.100.2'))->status());

        // With the proxy trusted, each forwarded client gets its own budget.
        $proxied = new Kernel(Config::validate($base + ['trusted_proxies' => ['10.0.0.1']]), new MemoryGateway(), $clock);
        $this->assertSame(404, $proxied->handle($forwarded('198.51.100.1'))->status());
        $this->assertSame(404, $proxied->handle($forwarded('198.51.100.2'))->status());
        // …and a repeat from the same forwarded client is still limited.
        $this->assertSame(429, $proxied->handle($forwarded('198.51.100.1'))->status());
    }
}
