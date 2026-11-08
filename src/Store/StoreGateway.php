<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Store;

/**
 * Persistence boundary for the Cloud store (CLOUD-CONTRACT.md §10).
 *
 * Two implementations:
 *  - MariaDbGateway — production (PDO/MariaDB 10.6, InnoDB, row locks, atomic upserts).
 *  - MemoryGateway  — in-process arrays for golden-pair replay without a database
 *                     (local pre-validation; CI replays the same goldens on MariaDB).
 *
 * Row shapes are canonical PHP types in BOTH implementations: ints for BIGINT/INT
 * columns, strings for CHAR/VARCHAR/LONGTEXT, bool for TINYINT(1) flags, null for NULLs.
 * All UUID/hash values stored and returned normalized lowercase.
 *
 * Privacy: gateways never log; the raw public token is written only to its own column
 * (U10) and read only by trusted server-side code paths.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
interface StoreGateway
{
    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    /**
     * Store row or null. @return array<string, mixed>|null
     * {store_id, active_writer_device_id, active_writer_epoch:int, accepted_through_seq:int,
     *  entry_count:int, head_entry_hash:?string, projection_version:int, throttled:bool}
     */
    public function findStore(string $storeId): ?array;

    /** Store row locked for the whole validation+commit transaction (SELECT … FOR UPDATE). */
    public function lockStore(string $storeId): ?array;

    /** @param array<string, mixed> $changes column => value (mutable watermark fields only) */
    public function updateStore(string $storeId, array $changes): void;

    public function findBatchFingerprint(string $storeId, string $batchId): ?string;

    public function insertBatch(string $storeId, string $batchId, string $fingerprint): void;

    public function findEntryHash(string $storeId, int $operationSeq): ?string;

    /** Operation-identity index (§5.4 step 15): seq of the stored entry, or null. */
    public function findEntrySeqByOperationId(string $storeId, string $operationId): ?int;

    /**
     * All stored entries of a store, ascending by operation_seq (projection draft scan, §7.2).
     * @return list<array{operation_seq: int, entry_json: string}>
     */
    public function entryRowsFor(string $storeId): array;

    /** @param list<array{store_id: string, operation_seq: int, operation_id: string, entry_hash: string, entry_json: string}> $rows */
    public function insertEntries(array $rows): void;

    /** Projection row by UNIQUE token-hash index, or null. */
    public function findProjectionByTokenHash(string $tokenHash): ?array;

    /** Projection row by UNIQUE (store_id, invoice_id), or null. */
    public function findProjectionByStoreInvoice(string $storeId, string $invoiceId): ?array;

    /**
     * First-write-wins projection insert (§7.2): ignores a duplicate (store_id, invoice_id)
     * and never re-mints a token. @return bool true when a row was inserted
     */
    public function insertProjectionIgnore(array $row): bool;

    // ── Rate limiting (fixed 1-minute windows; denied requests never increment) ──

    /**
     * Atomically account one request in the given window.
     * @param string $scope 'public' (per client address) or 'ingest' (per store)
     * @return bool true when the request is allowed (counter incremented)
     */
    public function rateHit(string $scope, string $bucketKey, int $windowStart, int $limit): bool;

    /** Cron pruning: drop bucket rows whose window ended before the given timestamp. */
    public function pruneRateBuckets(int $olderThanWindowStart): void;
}
