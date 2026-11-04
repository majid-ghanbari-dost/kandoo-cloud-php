<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Store;

/**
 * Test-harness surface on top of StoreGateway: deterministic seeding, inspection and
 * reset used by the golden-pair replay harness (tests/Support/GoldenHarness.php).
 * Production code depends only on StoreGateway; the P-04 contract tests replay the same
 * goldens against MemoryGateway (no DB) and MariaDbGateway (CI, MariaDB 10.6 service).
 *
 * All seeded values are FAKE synthetic fixtures.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
interface HarnessGateway extends StoreGateway
{
    /** Empties every table/state so each golden case starts from a pristine database. */
    public function reset(): void;

    /** @param array<string, mixed> $row full cloud_stores row */
    public function seedStore(array $row): void;

    public function seedEntry(string $storeId, int $operationSeq, string $operationId, string $entryHash, string $entryJson): void;

    public function seedBatch(string $storeId, string $batchId, string $fingerprint): void;

    /** @param array<string, mixed> $row full public_invoices row (canonical PHP types) */
    public function seedProjection(array $row): void;

    /** @return list<array{operation_seq: int, operation_id: string, entry_hash: string, entry_json: string}> ascending */
    public function entriesOf(string $storeId): array;

    /** @return list<array<string, mixed>> */
    public function projectionsOf(string $storeId): array;
}
