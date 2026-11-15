<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Migrator;
use PHPUnit\Framework\TestCase;

/**
 * Pure (no-DB) tests for the migration statement splitter.
 */
final class MigratorSqlTest extends TestCase
{
    public function testSplitsStatementsAndStripsComments(): void
    {
        $sql = "-- leading comment\nCREATE TABLE a (\n id INT NOT NULL -- inline\n);\n\n-- another\nINSERT INTO a (id) VALUES (1);\n";
        $statements = Migrator::splitStatements($sql);
        $this->assertCount(2, $statements);
        $this->assertStringContainsString('CREATE TABLE a', $statements[0]);
        $this->assertStringContainsString('INSERT INTO a', $statements[1]);
        $this->assertStringNotContainsString('-- leading comment', $statements[0]);
    }

    public function testSemicolonInsideStringLiteralDoesNotSplit(): void
    {
        $sql = "INSERT INTO t (v) VALUES ('a;b'); SELECT 1;";
        $statements = Migrator::splitStatements($sql);
        $this->assertCount(2, $statements);
        $this->assertSame("INSERT INTO t (v) VALUES ('a;b')", $statements[0]);
        $this->assertSame('SELECT 1', $statements[1]);
    }

    public function testShippedMigrationsParseToNonEmptyStatements(): void
    {
        $dir = dirname(__DIR__, 2) . '/migrations';
        $files = array_values(array_filter(scandir($dir) ?: [], static fn (string $n): bool => (bool) preg_match('/^\d{4}_.+\.sql$/', $n)));
        sort($files);
        $this->assertSame(['0001_core.sql', '0002_rate_limits.sql', '0003_replication_devices.sql', '0004_admin.sql', '0005_customer_auth.sql', '0006_sms_requests.sql', '0007_customer_controls.sql'], $files);
        foreach ($files as $file) {
            $sql = file_get_contents($dir . '/' . $file);
            $this->assertNotFalse($sql);
            $statements = Migrator::splitStatements($sql);
            $this->assertNotEmpty($statements, $file);
            foreach ($statements as $statement) {
                $this->assertStringNotContainsString(';', $statement, "$file: statement terminator leaked");
            }
        }
        // sanity: the core migration defines every contract table (§10.1)
        $core = file_get_contents($dir . '/0001_core.sql');
        $this->assertNotFalse($core);
        foreach (['cloud_stores', 'cloud_entries', 'cloud_batches', 'public_invoices'] as $table) {
            $this->assertStringContainsString("CREATE TABLE $table", $core);
        }
    }
}
