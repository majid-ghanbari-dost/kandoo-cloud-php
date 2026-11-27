<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Operations\CronHeartbeat;
use Kandoo\Cloud\Tests\Support\DbTestCase;

final class CronHeartbeatDatabaseTest extends DbTestCase
{
    public function testMissingFreshStaleFutureAndConcurrentOrder(): void
    {
        $db=$this->db(); $pdo=$db->pdo();
        $pdo->exec('DELETE FROM cloud_cron_heartbeat');
        try {
            $heartbeat=new CronHeartbeat($db);
            $this->assertSame(['completed_at'=>null,'fresh'=>false],$heartbeat->status(1000,120));
            $heartbeat->recordSuccess(900);
            $this->assertSame(['completed_at'=>900,'fresh'=>true],$heartbeat->status(1000,120));
            $this->assertSame(['completed_at'=>900,'fresh'=>false],$heartbeat->status(1021,120));
            $this->assertSame(['completed_at'=>900,'fresh'=>false],$heartbeat->status(899,120));
            $heartbeat->recordSuccess(800);
            $this->assertSame(['completed_at'=>900,'fresh'=>true],$heartbeat->status(1000,120));
            $heartbeat->recordSuccess(1001);
            $this->assertSame(['completed_at'=>1001,'fresh'=>true],$heartbeat->status(1001,120));
        } finally { $pdo->exec('DELETE FROM cloud_cron_heartbeat'); }
    }
}
