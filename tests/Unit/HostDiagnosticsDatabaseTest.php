<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Operations\CronHeartbeat;
use Kandoo\Cloud\Operations\HostDiagnostics;
use Kandoo\Cloud\Tests\Support\DbTestCase;

final class HostDiagnosticsDatabaseTest extends DbTestCase
{
    public function testCliRejectsBadKeyWithoutStartingDiagnostics(): void
    {
        $pipes=[];
        $process=proc_open([PHP_BINARY, dirname(__DIR__,2).'/bin/host-diagnostics.php'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null,
            ['KANDOO_HOST_DIAGNOSTICS_KEY_SHA256'=>hash('sha256',str_repeat('a',64))]);
        $this->assertIsResource($process);
        fwrite($pipes[0],str_repeat('b',64)."\n"); fclose($pipes[0]);
        $stdout=stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr=stream_get_contents($pipes[2]); fclose($pipes[2]);
        $this->assertSame(1,proc_close($process));
        $this->assertSame('',$stdout);
        $this->assertSame("Diagnostics unavailable.\n",$stderr);
    }

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
