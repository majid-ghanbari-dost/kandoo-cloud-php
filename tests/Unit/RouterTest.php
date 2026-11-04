<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * Kernel skeleton routing tests (PM ORDER 9 P-02). P-03 adds the replication and public
 * invoice routes; deferred endpoints must keep the generic empty 404 (U12).
 */
final class RouterTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(): array
    {
        return Config::validate([
            'base_url' => 'https://invoice.TEST-example.invalid',
            'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'TEST_db', 'user' => 'TEST_u', 'pass' => 'TEST_p'],
        ]);
    }

    private function kernel(): Kernel
    {
        return new Kernel($this->config());
    }

    public function testHealthReturnsExactOkBody(): void
    {
        $response = $this->kernel()->handle(new Request('GET', '/health'));
        $this->assertSame(200, $response->status());
        $this->assertSame('{"status":"ok"}', $response->body());
        $this->assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
    }

    public function testHealthRejectsOtherMethods(): void
    {
        $response = $this->kernel()->handle(new Request('POST', '/health', [], [], '{}'));
        $this->assertSame(405, $response->status());
        $this->assertSame('', $response->body());
        $this->assertSame('GET', $response->header('Allow'));
    }

    public function testUnknownPathIsGenericEmpty404(): void
    {
        foreach (['/nope', '/v1/customers/123', '/api/otp/send', '/i/'] as $path) {
            $response = $this->kernel()->handle(new Request('GET', $path));
            $this->assertSame(404, $response->status(), "path $path");
            $this->assertSame('', $response->body(), "path $path");
        }
    }
}
