<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Operations;

use Kandoo\Cloud\Database;

/** Records completed maintenance only; old concurrent executions cannot move time backwards. */
final class CronHeartbeat
{
    public function __construct(private Database $db) {}

    public function recordSuccess(int $completedAt): void
    {
        if ($completedAt < 0) { throw new \InvalidArgumentException('Invalid completion time'); }
        $q = $this->db->pdo()->prepare('INSERT INTO cloud_cron_heartbeat (id,completed_at) VALUES (1,?) ON DUPLICATE KEY UPDATE completed_at=GREATEST(completed_at,VALUES(completed_at))');
        $q->execute([$completedAt]);
    }

    /** Returns null before the first successful run. Future timestamps are never fresh. */
    public function status(int $now, int $maxAgeSeconds): array
    {
        if ($now < 0 || $maxAgeSeconds < 1) { throw new \InvalidArgumentException('Invalid heartbeat window'); }
        $value = $this->db->pdo()->query('SELECT completed_at FROM cloud_cron_heartbeat WHERE id=1')->fetchColumn();
        if ($value === false) { return ['completed_at'=>null, 'fresh'=>false]; }
        $last = (int)$value;
        return ['completed_at'=>$last, 'fresh'=>$last <= $now && $now - $last <= $maxAgeSeconds];
    }
}
