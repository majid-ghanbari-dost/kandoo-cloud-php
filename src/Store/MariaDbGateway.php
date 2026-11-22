<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Store;

use Kandoo\Cloud\Database;

/**
 * Production StoreGateway — PDO / MariaDB 10.6 / InnoDB (CLOUD-CONTRACT.md §10.1).
 *
 * - The store row is locked with SELECT … FOR UPDATE for the whole ingest transaction.
 * - Rate windows use an atomic UPDATE-then-INSERT-IGNORE pair: denied requests never
 *   increment, allowed requests increment exactly once, concurrent requests serialize
 *   on the row lock.
 * - First-write-wins projections use INSERT IGNORE on the UNIQUE (store_id, invoice_id).
 * - Native prepares: BIGINT/INT columns return PHP ints; the row casters below still
 *   normalize types defensively so both gateway implementations agree byte-for-byte.
 *
 * Privacy: no logging anywhere; prepared statements only (no string-interpolated SQL).
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class MariaDbGateway implements HarnessGateway
{
    public function __construct(private Database $db)
    {
    }

    private function pdo(): \PDO
    {
        return $this->db->pdo();
    }

    public function insertDevice(string $deviceId, string $storeId, string $secretHash, string $registeredAt): void
    {
        $stmt = $this->pdo()->prepare('INSERT INTO cloud_replication_devices (device_id, store_id, secret_sha256, registered_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$deviceId, $storeId, $secretHash, $registeredAt]);
    }

    public function findDevice(string $deviceId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT device_id, store_id, secret_sha256, registered_at, last_authenticated_at, verified FROM cloud_replication_devices WHERE device_id = ?');
        $stmt->execute([$deviceId]);
        $row = $stmt->fetch();
        if ($row === false) { return null; }
        $row['verified'] = (bool) $row['verified'];
        return $row;
    }

    public function findDeviceByStoreAndSecretHash(string $storeId, string $secretHash): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT device_id, store_id, secret_sha256, registered_at, last_authenticated_at, verified FROM cloud_replication_devices WHERE store_id = ? AND secret_sha256 = ? LIMIT 2');
        $stmt->execute([$storeId, $secretHash]);
        $rows = $stmt->fetchAll();
        if (count($rows) !== 1) { return null; }
        $rows[0]['verified'] = (bool) $rows[0]['verified'];
        return $rows[0];
    }

    public function touchDeviceAuthenticated(string $deviceId, string $at): void
    {
        $stmt = $this->pdo()->prepare('UPDATE cloud_replication_devices SET verified = 1, last_authenticated_at = ? WHERE device_id = ?');
        $stmt->execute([$at, $deviceId]);
    }

    public function isAdminEnabled(): bool
    {
        $value = $this->pdo()->query('SELECT enabled FROM cloud_admin_state WHERE singleton_id = 1')->fetchColumn();
        return $value !== false && (int) $value === 1;
    }

    public function setAdminEnabled(bool $enabled, string $at): void
    {
        // State and audit entry must commit or roll back together.
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) { throw new \LogicException('Admin transition needs its own transaction.'); }
        $pdo->beginTransaction();
        try {
            $current = $pdo->query('SELECT enabled FROM cloud_admin_state WHERE singleton_id = 1 FOR UPDATE')->fetchColumn();
            if ($current === false) { throw new \RuntimeException('Admin state missing.'); }
            if ((bool) $current !== $enabled) {
                $stmt = $pdo->prepare('UPDATE cloud_admin_state SET enabled = ? WHERE singleton_id = 1');
                $stmt->execute([$enabled ? 1 : 0]);
                $stmt = $pdo->prepare('INSERT INTO cloud_admin_events (event_type, occurred_at) VALUES (?, ?)');
                $stmt->execute([$enabled ? 'enabled' : 'disabled', $at]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ── transactions ───────────────────────────────────────────────────────────

    public function beginTransaction(): void
    {
        if (!$this->pdo()->inTransaction()) {
            $this->pdo()->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo()->inTransaction()) {
            $this->pdo()->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo()->inTransaction()) {
            $this->pdo()->rollBack();
        }
    }

    // ── stores ─────────────────────────────────────────────────────────────────

    public function findStore(string $storeId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT store_id, active_writer_device_id, active_writer_epoch, accepted_through_seq,
                    entry_count, head_entry_hash, projection_version, throttled
             FROM cloud_stores WHERE store_id = ?'
        );
        $stmt->execute([$storeId]);
        $row = $stmt->fetch();
        return $row === false ? null : self::castStoreRow($row);
    }

    public function lockStore(string $storeId): ?array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT store_id, active_writer_device_id, active_writer_epoch, accepted_through_seq,
                    entry_count, head_entry_hash, projection_version, throttled
             FROM cloud_stores WHERE store_id = ? FOR UPDATE'
        );
        $stmt->execute([$storeId]);
        $row = $stmt->fetch();
        return $row === false ? null : self::castStoreRow($row);
    }

    /** @param array<string, mixed> $row */
    private static function castStoreRow(array $row): array
    {
        return [
            'store_id' => (string) $row['store_id'],
            'active_writer_device_id' => (string) $row['active_writer_device_id'],
            'active_writer_epoch' => (int) $row['active_writer_epoch'],
            'accepted_through_seq' => (int) $row['accepted_through_seq'],
            'entry_count' => (int) $row['entry_count'],
            'head_entry_hash' => $row['head_entry_hash'] === null ? null : (string) $row['head_entry_hash'],
            'projection_version' => (int) $row['projection_version'],
            'throttled' => (bool) $row['throttled'],
        ];
    }

    public function updateStore(string $storeId, array $changes): void
    {
        $allowed = ['accepted_through_seq', 'entry_count', 'head_entry_hash', 'projection_version', 'throttled'];
        $sets = [];
        $values = [];
        foreach ($changes as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                throw new \InvalidArgumentException("updateStore: column $column is not mutable");
            }
            $sets[] = "$column = ?";
            $values[] = $value;
        }
        if ($sets === []) {
            return;
        }
        $values[] = $storeId;
        $stmt = $this->pdo()->prepare('UPDATE cloud_stores SET ' . implode(', ', $sets) . ' WHERE store_id = ?');
        $stmt->execute($values);
    }

    // ── batches & entries ─────────────────────────────────────────────────────

    public function findBatchFingerprint(string $storeId, string $batchId): ?string
    {
        $stmt = $this->pdo()->prepare('SELECT fingerprint FROM cloud_batches WHERE store_id = ? AND batch_id = ?');
        $stmt->execute([$storeId, $batchId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function insertBatch(string $storeId, string $batchId, string $fingerprint): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO cloud_batches (store_id, batch_id, fingerprint) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE fingerprint = VALUES(fingerprint)'
        );
        $stmt->execute([$storeId, $batchId, $fingerprint]);
    }

    public function findEntryHash(string $storeId, int $operationSeq): ?string
    {
        $stmt = $this->pdo()->prepare('SELECT entry_hash FROM cloud_entries WHERE store_id = ? AND operation_seq = ?');
        $stmt->execute([$storeId, $operationSeq]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function findEntrySeqByOperationId(string $storeId, string $operationId): ?int
    {
        $stmt = $this->pdo()->prepare('SELECT operation_seq FROM cloud_entries WHERE store_id = ? AND operation_id = ?');
        $stmt->execute([$storeId, $operationId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function entryRowsFor(string $storeId): array
    {
        $stmt = $this->pdo()->prepare('SELECT operation_seq, entry_json FROM cloud_entries WHERE store_id = ? ORDER BY operation_seq ASC');
        $stmt->execute([$storeId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = ['operation_seq' => (int) $row['operation_seq'], 'entry_json' => (string) $row['entry_json']];
        }
        return $rows;
    }

    public function insertEntries(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $stmt = $this->pdo()->prepare(
            'INSERT INTO cloud_entries (store_id, operation_seq, operation_id, entry_hash, entry_json)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($rows as $row) {
            $stmt->execute([
                (string) $row['store_id'], (int) $row['operation_seq'], (string) $row['operation_id'],
                (string) $row['entry_hash'], (string) $row['entry_json'],
            ]);
        }
    }

    // ── projections ────────────────────────────────────────────────────────────

    public function findProjectionByTokenHash(string $tokenHash): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM public_invoices WHERE public_token_hash = ?');
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
        return $row === false ? null : self::castProjectionRow($row);
    }

    public function findProjectionByStoreInvoice(string $storeId, string $invoiceId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM public_invoices WHERE store_id = ? AND invoice_id = ?');
        $stmt->execute([$storeId, $invoiceId]);
        $row = $stmt->fetch();
        return $row === false ? null : self::castProjectionRow($row);
    }

    /** @param array<string, mixed> $row */
    private static function castProjectionRow(array $row): array
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
            'customer_phone' => $row['customer_phone'] === null ? null : (string) $row['customer_phone'],
            'customer_name' => ($row['customer_name'] ?? null) === null ? null : (string) $row['customer_name'],
            'payments' => ($row['payments'] ?? null) === null ? null : (string) $row['payments'],
            'currency_unit' => ($row['currency_unit'] ?? null) === null ? null : (string) $row['currency_unit'],
            'remaining_balance' => ($row['remaining_balance'] ?? null) === null ? null : (int) $row['remaining_balance'],
        ];
    }

    public function insertProjectionIgnore(array $row): bool
    {
        $stmt = $this->pdo()->prepare(
            'INSERT IGNORE INTO public_invoices
                (public_token, public_token_hash, digital_invoice_id, invoice_id, store_id, store_name,
                 display_number, lifecycle_status, issued_at, issued_payload, issued_content_sha256,
                 payable_rials, line_items, customer_phone,
                 customer_name, payments, currency_unit, remaining_balance)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $row['public_token'], $row['public_token_hash'], $row['digital_invoice_id'], $row['invoice_id'],
            $row['store_id'], $row['store_name'], $row['display_number'], $row['lifecycle_status'],
            $row['issued_at'], $row['issued_payload'], $row['issued_content_sha256'], $row['payable_rials'],
            $row['line_items'], $row['customer_phone'],
            $row['customer_name'] ?? null, $row['payments'] ?? null, $row['currency_unit'] ?? null,
            $row['remaining_balance'] ?? null,
        ]);
        return $stmt->rowCount() > 0;
    }

    // ── rate limiting ──────────────────────────────────────────────────────────

    private static function tableFor(string $scope): string
    {
        return match ($scope) {
            'public' => 'public_rate_buckets',
            'ingest' => 'ingest_rate_buckets',
            'customer_issue' => 'customer_issue_rate_buckets',
            'customer_verify' => 'customer_verify_rate_buckets',
            default => throw new \InvalidArgumentException('Unknown rate-limit scope.'),
        };
    }

    public function rateHit(string $scope, string $bucketKey, int $windowStart, int $limit): bool
    {
        $table = self::tableFor($scope);
        // 1) atomic claim inside the current window, or window swap with reset to 1
        $update = $this->pdo()->prepare(
            "UPDATE $table
             SET hits = IF(window_start = ?, hits + 1, 1), window_start = ?
             WHERE bucket_key = ? AND ((window_start = ? AND hits < ?) OR window_start < ?)"
        );
        $update->execute([$windowStart, $windowStart, $bucketKey, $windowStart, $limit, $windowStart]);
        if ($update->rowCount() > 0) {
            return true;
        }
        // 2) first request of a window when no row exists yet
        $insert = $this->pdo()->prepare("INSERT IGNORE INTO $table (bucket_key, window_start, hits) VALUES (?, ?, 1)");
        $insert->execute([$bucketKey, $windowStart]);
        if ($insert->rowCount() > 0) {
            return true;
        }
        // 3) the row appeared concurrently between (1) and (2): retry the atomic claim once.
        $update->execute([$windowStart, $windowStart, $bucketKey, $windowStart, $limit, $windowStart]);
        return $update->rowCount() > 0;
        // false here = row exists, current window, hits >= limit → denied (never incremented)
    }

    public function pruneRateBuckets(int $olderThanWindowStart): void
    {
        foreach (['public_rate_buckets', 'ingest_rate_buckets', 'customer_issue_rate_buckets', 'customer_verify_rate_buckets'] as $table) {
            $stmt = $this->pdo()->prepare("DELETE FROM $table WHERE window_start < ?");
            $stmt->execute([$olderThanWindowStart]);
        }
    }

    // ── test-harness surface (HarnessGateway; FAKE fixtures only) ─────────────

    public function reset(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['public_invoices', 'cloud_entries', 'cloud_batches', 'cloud_stores', 'public_rate_buckets', 'ingest_rate_buckets'] as $table) {
            $pdo->exec("TRUNCATE TABLE $table");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function seedStore(array $row): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO cloud_stores
                (store_id, active_writer_device_id, active_writer_epoch, accepted_through_seq,
                 entry_count, head_entry_hash, projection_version, throttled)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                active_writer_device_id = VALUES(active_writer_device_id),
                active_writer_epoch = VALUES(active_writer_epoch),
                accepted_through_seq = VALUES(accepted_through_seq),
                entry_count = VALUES(entry_count),
                head_entry_hash = VALUES(head_entry_hash),
                projection_version = VALUES(projection_version),
                throttled = VALUES(throttled)'
        );
        $stmt->execute([
            (string) $row['store_id'],
            (string) $row['active_writer_device_id'],
            (int) $row['active_writer_epoch'],
            (int) $row['accepted_through_seq'],
            (int) $row['entry_count'],
            $row['head_entry_hash'] ?? null,
            (int) $row['projection_version'],
            !empty($row['throttled']) ? 1 : 0,
        ]);
    }

    public function seedEntry(string $storeId, int $operationSeq, string $operationId, string $entryHash, string $entryJson): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO cloud_entries (store_id, operation_seq, operation_id, entry_hash, entry_json)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE entry_hash = VALUES(entry_hash), entry_json = VALUES(entry_json)'
        );
        $stmt->execute([$storeId, $operationSeq, $operationId, $entryHash, $entryJson]);
    }

    public function seedBatch(string $storeId, string $batchId, string $fingerprint): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO cloud_batches (store_id, batch_id, fingerprint) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE fingerprint = VALUES(fingerprint)'
        );
        $stmt->execute([$storeId, $batchId, $fingerprint]);
    }

    public function seedProjection(array $row): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO public_invoices
                (public_token, public_token_hash, digital_invoice_id, invoice_id, store_id, store_name,
                 display_number, lifecycle_status, issued_at, issued_payload, issued_content_sha256,
                 payable_rials, line_items, customer_phone,
                 customer_name, payments, currency_unit, remaining_balance)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                store_name = VALUES(store_name),
                display_number = VALUES(display_number),
                lifecycle_status = VALUES(lifecycle_status),
                issued_at = VALUES(issued_at),
                issued_payload = VALUES(issued_payload),
                issued_content_sha256 = VALUES(issued_content_sha256),
                payable_rials = VALUES(payable_rials),
                line_items = VALUES(line_items),
                customer_phone = VALUES(customer_phone),
                customer_name = VALUES(customer_name),
                payments = VALUES(payments),
                currency_unit = VALUES(currency_unit),
                remaining_balance = VALUES(remaining_balance)'
        );
        $stmt->execute([
            (string) $row['public_token'],
            (string) $row['public_token_hash'],
            (string) $row['digital_invoice_id'],
            (string) $row['invoice_id'],
            (string) $row['store_id'],
            (string) $row['store_name'],
            (int) $row['display_number'],
            (string) $row['lifecycle_status'],
            (string) $row['issued_at'],
            (string) $row['issued_payload'],
            (string) $row['issued_content_sha256'],
            (int) $row['payable_rials'],
            (string) $row['line_items'],
            $row['customer_phone'] ?? null,
            $row['customer_name'] ?? null,
            $row['payments'] ?? null,
            $row['currency_unit'] ?? null,
            $row['remaining_balance'] ?? null,
        ]);
    }

    public function entriesOf(string $storeId): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT operation_seq, operation_id, entry_hash, entry_json
             FROM cloud_entries WHERE store_id = ? ORDER BY operation_seq ASC'
        );
        $stmt->execute([$storeId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'operation_seq' => (int) $row['operation_seq'],
                'operation_id' => (string) $row['operation_id'],
                'entry_hash' => (string) $row['entry_hash'],
                'entry_json' => (string) $row['entry_json'],
            ];
        }
        return $rows;
    }

    public function projectionsOf(string $storeId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM public_invoices WHERE store_id = ? ORDER BY invoice_id ASC');
        $stmt->execute([$storeId]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = self::castProjectionRow($row);
        }
        return $rows;
    }
}
