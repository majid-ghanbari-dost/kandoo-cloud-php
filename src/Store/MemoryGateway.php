<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Store;

/**
 * In-memory StoreGateway for golden-pair replay without a database (local development;
 * the P-04 contract tests run the SAME goldens against MariaDbGateway in CI). Mirrors the
 * MariaDB semantics this application relies on: row shapes, unique-key first-write-wins,
 * snapshot/rollback transactions, and the atomic check-then-increment rate window.
 *
 * FAKE DATA ONLY — used with synthetic fixtures; never holds production data in this mode.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class MemoryGateway implements HarnessGateway
{
    /** @var array<string, array<string, mixed>> store_id → row */
    private array $stores = [];
    /** @var array<string, list<array{operation_seq: int, operation_id: string, entry_hash: string, entry_json: string}>> */
    private array $entries = [];
    /** @var array<string, array<string, string>> store_id → batch_id → fingerprint */
    private array $batches = [];
    /** @var list<array<string, mixed>> */
    private array $projections = [];
    /** @var array<string, array<string, array{window_start: int, hits: int}>> scope → key → bucket */
    private array $buckets = ['public' => [], 'ingest' => []];

    private int $txDepth = 0;
    /** @var array<string, mixed>|null */
    private ?array $snapshot = null;

    // ── seeding helpers (test harness) ─────────────────────────────────────────

    /** @param array<string, mixed> $row */
    public function seedStore(array $row): void
    {
        $id = (string) $row['store_id'];
        $this->stores[$id] = [
            'store_id' => $id,
            'active_writer_device_id' => (string) $row['active_writer_device_id'],
            'active_writer_epoch' => (int) $row['active_writer_epoch'],
            'accepted_through_seq' => (int) $row['accepted_through_seq'],
            'entry_count' => (int) $row['entry_count'],
            'head_entry_hash' => isset($row['head_entry_hash']) && $row['head_entry_hash'] !== null ? (string) $row['head_entry_hash'] : null,
            'projection_version' => (int) $row['projection_version'],
            'throttled' => (bool) ($row['throttled'] ?? false),
        ];
        $this->entries[$id] ??= [];
    }

    /** Seeds a stored entry from its 16-field wire/DTO array (entry_json canonicalized by the caller). */
    public function seedEntry(string $storeId, int $operationSeq, string $operationId, string $entryHash, string $entryJson): void
    {
        $this->entries[$storeId][] = [
            'operation_seq' => $operationSeq,
            'operation_id' => $operationId,
            'entry_hash' => $entryHash,
            'entry_json' => $entryJson,
        ];
        usort($this->entries[$storeId], static fn (array $a, array $b): int => $a['operation_seq'] <=> $b['operation_seq']);
    }

    public function seedBatch(string $storeId, string $batchId, string $fingerprint): void
    {
        $this->batches[$storeId][$batchId] = $fingerprint;
    }

    public function reset(): void
    {
        $this->stores = [];
        $this->entries = [];
        $this->batches = [];
        $this->projections = [];
        $this->buckets = ['public' => [], 'ingest' => []];
        $this->txDepth = 0;
        $this->snapshot = null;
    }

    public function entriesOf(string $storeId): array
    {
        return $this->entries[$storeId] ?? [];
    }

    public function projectionsOf(string $storeId): array
    {
        return array_values(array_filter(
            $this->projections,
            static fn (array $row): bool => $row['store_id'] === $storeId
        ));
    }

    /** @param array<string, mixed> $row */
    public function seedProjection(array $row): void
    {
        $this->projections[] = self::normalizeProjectionRow($row);
    }

    /** @return list<array<string, mixed>> */
    public function projectionRows(): array
    {
        return $this->projections;
    }

    /** @return array<string, mixed>|null */
    public function storeRow(string $storeId): ?array
    {
        return $this->stores[$storeId] ?? null;
    }

    public function entryCount(string $storeId): int
    {
        return count($this->entries[$storeId] ?? []);
    }

    /** @param array<string, mixed> $row */
    private static function normalizeProjectionRow(array $row): array
    {
        return [
            'public_token' => (string) $row['public_token'],
            'public_token_hash' => (string) $row['public_token_hash'],
            'digital_invoice_id' => (string) $row['digital_invoice_id'],
            'invoice_id' => (string) $row['invoice_id'],
            'store_id' => (string) $row['store_id'],
            'store_name' => (string) $row['store_name'],
            'display_number' => (int) $row['display_number'],
            'lifecycle_status' => (string) $row['lifecycle_status'],
            'issued_at' => (string) $row['issued_at'],
            'issued_payload' => (string) $row['issued_payload'],
            'issued_content_sha256' => (string) $row['issued_content_sha256'],
            'payable_rials' => (int) $row['payable_rials'],
            'line_items' => (string) $row['line_items'],
            'customer_phone' => $row['customer_phone'] ?? null,
            'customer_name' => $row['customer_name'] ?? null,
            'payments' => $row['payments'] ?? null,
            'currency_unit' => $row['currency_unit'] ?? null,
            'remaining_balance' => isset($row['remaining_balance']) ? (int) $row['remaining_balance'] : null,
        ];
    }

    // ── transactions ───────────────────────────────────────────────────────────

    public function beginTransaction(): void
    {
        if ($this->txDepth === 0) {
            $this->snapshot = [
                'stores' => $this->stores,
                'entries' => $this->entries,
                'batches' => $this->batches,
                'projections' => $this->projections,
                'buckets' => $this->buckets,
            ];
        }
        $this->txDepth++;
    }

    public function commit(): void
    {
        $this->txDepth--;
        if ($this->txDepth === 0) {
            $this->snapshot = null;
        }
    }

    public function rollBack(): void
    {
        if ($this->txDepth > 0 && $this->snapshot !== null) {
            $this->stores = $this->snapshot['stores'];
            $this->entries = $this->snapshot['entries'];
            $this->batches = $this->snapshot['batches'];
            $this->projections = $this->snapshot['projections'];
            $this->buckets = $this->snapshot['buckets'];
        }
        $this->txDepth = 0;
        $this->snapshot = null;
    }

    // ── stores ─────────────────────────────────────────────────────────────────

    public function findStore(string $storeId): ?array
    {
        return $this->stores[$storeId] ?? null;
    }

    public function lockStore(string $storeId): ?array
    {
        return $this->findStore($storeId); // single-process: no lock needed
    }

    public function updateStore(string $storeId, array $changes): void
    {
        if (!isset($this->stores[$storeId])) {
            throw new \RuntimeException("updateStore: unknown store $storeId");
        }
        foreach ($changes as $column => $value) {
            $this->stores[$storeId][$column] = $value;
        }
    }

    // ── batches & entries ─────────────────────────────────────────────────────

    public function findBatchFingerprint(string $storeId, string $batchId): ?string
    {
        return $this->batches[$storeId][$batchId] ?? null;
    }

    public function insertBatch(string $storeId, string $batchId, string $fingerprint): void
    {
        $this->batches[$storeId][$batchId] = $fingerprint;
    }

    public function findEntryHash(string $storeId, int $operationSeq): ?string
    {
        foreach ($this->entries[$storeId] ?? [] as $row) {
            if ($row['operation_seq'] === $operationSeq) {
                return $row['entry_hash'];
            }
        }
        return null;
    }

    public function findEntrySeqByOperationId(string $storeId, string $operationId): ?int
    {
        foreach ($this->entries[$storeId] ?? [] as $row) {
            if ($row['operation_id'] === $operationId) {
                return $row['operation_seq'];
            }
        }
        return null;
    }

    public function entryRowsFor(string $storeId): array
    {
        return $this->entries[$storeId] ?? [];
    }

    public function insertEntries(array $rows): void
    {
        foreach ($rows as $row) {
            $storeId = (string) $row['store_id'];
            foreach ($this->entries[$storeId] ?? [] as $existing) {
                if ($existing['operation_seq'] === (int) $row['operation_seq']) {
                    throw new \RuntimeException('insertEntries: duplicate (store_id, operation_seq)');
                }
                if ($existing['operation_id'] === (string) $row['operation_id']) {
                    throw new \RuntimeException('insertEntries: duplicate (store_id, operation_id)');
                }
            }
            $this->entries[$storeId][] = [
                'operation_seq' => (int) $row['operation_seq'],
                'operation_id' => (string) $row['operation_id'],
                'entry_hash' => (string) $row['entry_hash'],
                'entry_json' => (string) $row['entry_json'],
            ];
            usort($this->entries[$storeId], static fn (array $a, array $b): int => $a['operation_seq'] <=> $b['operation_seq']);
        }
    }

    // ── projections ────────────────────────────────────────────────────────────

    public function findProjectionByTokenHash(string $tokenHash): ?array
    {
        foreach ($this->projections as $row) {
            if ($row['public_token_hash'] === $tokenHash) {
                return $row;
            }
        }
        return null;
    }

    public function findProjectionByStoreInvoice(string $storeId, string $invoiceId): ?array
    {
        foreach ($this->projections as $row) {
            if ($row['store_id'] === $storeId && $row['invoice_id'] === $invoiceId) {
                return $row;
            }
        }
        return null;
    }

    public function insertProjectionIgnore(array $row): bool
    {
        $normalized = self::normalizeProjectionRow($row);
        foreach ($this->projections as $existing) {
            if ($existing['store_id'] === $normalized['store_id'] && $existing['invoice_id'] === $normalized['invoice_id']) {
                return false; // first write wins; no token is re-minted
            }
            if ($existing['public_token'] === $normalized['public_token']) {
                throw new \RuntimeException('insertProjectionIgnore: duplicate public_token');
            }
        }
        $this->projections[] = $normalized;
        return true;
    }

    // ── rate limiting ──────────────────────────────────────────────────────────

    public function rateHit(string $scope, string $bucketKey, int $windowStart, int $limit): bool
    {
        $bucket = $this->buckets[$scope][$bucketKey] ?? null;
        if ($bucket !== null && $bucket['window_start'] === $windowStart && $bucket['hits'] >= $limit) {
            return false; // denied: never incremented
        }
        $hits = ($bucket !== null && $bucket['window_start'] === $windowStart) ? $bucket['hits'] + 1 : 1;
        $this->buckets[$scope][$bucketKey] = ['window_start' => $windowStart, 'hits' => $hits];
        return true;
    }

    public function pruneRateBuckets(int $olderThanWindowStart): void
    {
        foreach (['public', 'ingest'] as $scope) {
            foreach ($this->buckets[$scope] as $key => $bucket) {
                if ($bucket['window_start'] < $olderThanWindowStart) {
                    unset($this->buckets[$scope][$key]);
                }
            }
        }
    }
}
