<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Migrator;
use Kandoo\Cloud\Tests\Support\DbTestCase;

/**
 * DB-backed migration tests (run in CI against the MariaDB 10.6 service; self-skip
 * elsewhere). The CI step `php bin/migrate.php` has already applied everything, so this
 * asserts the tracked end state and idempotency.
 */
final class MigratorTest extends DbTestCase
{
    public function testMigrationsAreTrackedAndIdempotent(): void
    {
        $migrator = new Migrator($this->db());
        $applied = $migrator->applied();
        $this->assertContains('0001_core.sql', $applied);
        $this->assertContains('0002_rate_limits.sql', $applied);
        $this->assertContains('0003_replication_devices.sql', $applied);
        $this->assertContains('0004_admin.sql', $applied);
        $this->assertContains('0005_customer_auth.sql', $applied);
        $this->assertContains('0006_sms_requests.sql', $applied);
        $this->assertSame([], $migrator->pending());
        $this->assertSame([], $migrator->migrate()); // re-run is a no-op
    }

    public function testContractTablesExist(): void
    {
        $rows = $this->db()->pdo()->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        $tables = array_map('strval', $rows ?: []);
        foreach ([
            'schema_migrations', 'cloud_stores', 'cloud_entries', 'cloud_batches',
            'public_invoices', 'public_rate_buckets', 'ingest_rate_buckets', 'cloud_replication_devices', 'cloud_admin_state', 'cloud_admin_events', 'cloud_customer_phones', 'cloud_sms_announcements', 'cloud_otp_challenges', 'cloud_customer_sessions', 'cloud_otp_idempotency_locks', 'cloud_sms_delivery_requests',
        ] as $table) {
            $this->assertContains($table, $tables);
        }
    }

    public function testTokenHashIndexIsUnique(): void
    {
        $rows = $this->db()->pdo()->query("SHOW INDEX FROM public_invoices")->fetchAll();
        $unique = [];
        foreach ($rows ?: [] as $row) {
            if ((int) $row['Non_unique'] === 0) {
                $unique[(string) $row['Key_name']] = true;
            }
        }
        // §6.1: token-hash lookups go through a UNIQUE index, never a plaintext scan.
        $this->assertArrayHasKey('ux_public_invoices_token_hash', $unique);
        $this->assertArrayHasKey('ux_public_invoices_store_invoice', $unique);
    }
}
