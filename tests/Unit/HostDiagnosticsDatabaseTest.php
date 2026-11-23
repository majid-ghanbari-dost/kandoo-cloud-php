<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Operations\CronHeartbeat;
use Kandoo\Cloud\Operations\HostDiagnostics;
use Kandoo\Cloud\Tests\Support\DbTestCase;

final class HostDiagnosticsDatabaseTest extends DbTestCase
{
    public function testSecretAndSyntheticReadOnlyDiagnostics(): void
    {
        $key=str_repeat('a',64); $hash=hash('sha256',$key);
        $this->assertTrue(HostDiagnostics::authenticate($key,$hash));
        $this->assertFalse(HostDiagnostics::authenticate(str_repeat('b',64),$hash));
        $this->assertFalse(HostDiagnostics::authenticate('short',$hash));
        $this->assertFalse(HostDiagnostics::authenticate($key,'not-a-hash'));
        $db=$this->db(); $pdo=$db->pdo(); $pdo->exec('DELETE FROM cloud_cron_heartbeat');
        try {
            (new CronHeartbeat($db))->recordSuccess(1000);
            $service=new HostDiagnostics($db,static fn(): bool => true,static fn(): string => '8.8.8.8');
            $out=$service->run(1050,120);
            $this->assertTrue($out['php_compatible']); $this->assertTrue($out['mariadb_compatible']);
            $this->assertTrue($out['persian_utf8mb4_roundtrip']); $this->assertTrue($out['cron_heartbeat']['fresh']);
            $this->assertTrue($out['faraz_tls']); $this->assertSame('8.8.8.8',$out['outbound_ip']);
            $bad=new HostDiagnostics($db,static fn(): bool => false,static fn(): string => '127.0.0.1');
            $out=$bad->run(1200,120); $this->assertFalse($out['faraz_tls']);
            $this->assertNull($out['outbound_ip']); $this->assertFalse($out['cron_heartbeat']['fresh']);
        } finally { $pdo->exec('DELETE FROM cloud_cron_heartbeat'); }
    }
}
