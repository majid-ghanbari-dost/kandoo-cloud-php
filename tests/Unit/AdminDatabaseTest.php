<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Store\MariaDbGateway;
use Kandoo\Cloud\Tests\Support\DbTestCase;

final class AdminDatabaseTest extends DbTestCase
{
    public function testTransitionsArePersistentAndAudited(): void
    {
        $pdo = $this->db()->pdo();
        $pdo->exec('UPDATE cloud_admin_state SET enabled = 0 WHERE singleton_id = 1');
        $pdo->exec('DELETE FROM cloud_admin_events');
        $gateway = new MariaDbGateway($this->db());
        $this->assertFalse($gateway->isAdminEnabled());
        $gateway->setAdminEnabled(true, '2026-01-01 00:00:00');
        $this->assertTrue((new MariaDbGateway($this->db()))->isAdminEnabled());
        $gateway->setAdminEnabled(true, '2026-01-01 00:00:01');
        $gateway->setAdminEnabled(false, '2026-01-01 00:00:02');
        $this->assertFalse($gateway->isAdminEnabled());
        $events = $pdo->query('SELECT event_type FROM cloud_admin_events ORDER BY event_id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['enabled', 'disabled'], $events);
    }
}
