<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Support;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Http\Response;
use Kandoo\Cloud\Json\JsonObject;
use Kandoo\Cloud\Json\Writer;
use Kandoo\Cloud\Kernel;
use Kandoo\Cloud\Store\HarnessGateway;
use Kandoo\Cloud\Support\Clock;
use Kandoo\Cloud\Support\FixedClock;
use Kandoo\Cloud\Support\RandomToken;

/**
 * Golden-pair replay harness (PM ORDER 9 P-04): seeds a gateway from a golden case's
 * `given`, replays every step through the Kernel, and asserts status, headers (subset +
 * ranges) and bodies (byte-exact or markers), then the durable `effects`.
 *
 * Gateway-agnostic: the SAME cases run against MemoryGateway (local, no DB) and
 * MariaDbGateway (CI, MariaDB 10.6 service). Divergences are collected and reported
 * explicitly — never silently absorbed.
 *
 * ALL FIXTURE VALUES ARE FAKE SYNTHETIC DATA (vector IDs / TEST-…); see docs/golden/README.md.
 */
final class GoldenHarness
{
    /** Deterministic harness clock: 2026-01-02T03:04:05Z. */
    public const NOW_UNIX = 1767323045;

    /** Fake client address for every replayed request (TEST-NET-3, RFC 5737). */
    public const CLIENT_IP = '203.0.113.7';

    /**
     * @return array{problems: list<string>, responses: list<Response>, gateway: HarnessGateway}
     */
    public static function replay(string $goldenPath, HarnessGateway $gateway): array
    {
        $raw = file_get_contents($goldenPath);
        if ($raw === false) {
            return ['problems' => ["cannot read $goldenPath"], 'responses' => [], 'gateway' => $gateway];
        }
        /** @var array<string, mixed> $case */
        $case = json_decode($raw, true);
        if (!is_array($case)) {
            return ['problems' => ["$goldenPath is not valid JSON"], 'responses' => [], 'gateway' => $gateway];
        }
        $id = (string) ($case['id'] ?? basename($goldenPath));

        $config = Config::validate(array_merge(self::baseConfig(), (array) ($case['config'] ?? [])));
        $gateway->reset();
        $seededEntryCounts = self::seed($case['given'] ?? [], $gateway);
        $clock = new FixedClock(self::NOW_UNIX);
        $kernel = new Kernel($config, $gateway, $clock);

        $problems = [];
        $responses = [];
        $lastBatchEntryCount = null;
        foreach ((array) ($case['steps'] ?? []) as $index => $step) {
            $request = self::buildRequest((array) $step['request'], $gateway);
            if ($request->method() === 'POST') {
                $decoded = json_decode($request->body(), true);
                if (is_array($decoded) && isset($decoded['entries']) && is_array($decoded['entries'])) {
                    $lastBatchEntryCount = count($decoded['entries']);
                }
            }
            $response = $kernel->handle($request);
            $responses[] = $response;
            foreach (self::assertResponse((array) $step['response'], $response) as $problem) {
                $problems[] = "$id step $index: $problem";
            }
        }
        foreach (self::assertEffects((array) ($case['effects'] ?? []), $gateway, $seededEntryCounts, $lastBatchEntryCount) as $problem) {
            $problems[] = "$id effects: $problem";
        }
        return ['problems' => $problems, 'responses' => $responses, 'gateway' => $gateway];
    }

    /** @return array<string, mixed> */
    private static function baseConfig(): array
    {
        return [
            'base_url' => 'https://invoice.TEST-golden.invalid',
            'db' => [
                'host' => '127.0.0.1', 'port' => 3306, 'name' => 'TEST_golden_db',
                'user' => 'TEST_golden_user', 'pass' => 'TEST-GOLDEN-PASS', 'charset' => 'utf8mb4',
            ],
            'replication_key' => null,
            'public_rate_limit_per_minute' => 60,
            'ingest_rate_limit_per_minute' => 120,
            'public_legal_copy_required' => false,
            'env' => 'testing',
        ];
    }

    /**
     * @param array<string, mixed> $given
     * @return array<string, int> seeded entry counts per store
     */
    private static function seed(array $given, HarnessGateway $gateway): array
    {
        $seededEntryCounts = [];
        foreach ((array) ($given['stores'] ?? []) as $store) {
            $gateway->seedStore($store);
            $seededEntryCounts[(string) $store['store_id']] = 0;
        }
        foreach ((array) ($given['entries'] ?? []) as $entry) {
            $storeId = (string) $entry['store_id'];
            $fields = $entry;
            unset($fields['store_id']);
            $entryJson = Writer::encodeCanonical(JsonObject::fromMap($fields));
            $gateway->seedEntry(
                $storeId,
                (int) $entry['operation_seq'],
                (string) $entry['operation_id'],
                (string) $entry['entry_hash'],
                $entryJson,
            );
            $seededEntryCounts[$storeId] = ($seededEntryCounts[$storeId] ?? 0) + 1;
        }
        foreach ((array) ($given['batches'] ?? []) as $batch) {
            $gateway->seedBatch(
                (string) $batch['store_id'],
                (string) $batch['batch_id'],
                (string) $batch['fingerprint'],
            );
        }
        foreach ((array) ($given['public_invoices'] ?? []) as $row) {
            $token = (string) $row['public_token'];
            $gateway->seedProjection([
                'public_token' => $token,
                'public_token_hash' => (string) ($row['public_token_hash'] ?? RandomToken::hash($token)),
                'digital_invoice_id' => (string) ($row['digital_invoice_id'] ?? RandomToken::uuidV4()),
                'invoice_id' => (string) $row['invoice_id'],
                'store_id' => (string) $row['store_id'],
                'store_name' => (string) $row['store_name'],
                'display_number' => (int) $row['display_number'],
                'lifecycle_status' => (string) $row['lifecycle_status'],
                'issued_at' => (string) $row['issued_at_utc'],
                'issued_payload' => Writer::encode($row['issued_payload']),
                'issued_content_sha256' => (string) $row['issued_content_sha256'],
                'payable_rials' => (int) $row['payable_rials'],
                'line_items' => Writer::encode($row['line_items']),
                'customer_phone' => $row['customer_phone'] ?? null,
                'customer_name' => $row['customer_name'] ?? null,
                'payments' => isset($row['payments']) ? Writer::encode($row['payments']) : null,
                'currency_unit' => $row['currency_unit'] ?? null,
                'remaining_balance' => isset($row['remaining_balance']) ? (int) $row['remaining_balance'] : null,
            ]);
        }
        return $seededEntryCounts;
    }

    /** @param array<string, mixed> $stepRequest */
    private static function buildRequest(array $stepRequest, HarnessGateway $gateway): Request
    {
        $path = (string) $stepRequest['path'];

        // Resolve {public_token} from the database (tokens are Cloud-generated, §6.1).
        if (isset($stepRequest['public_token_from'])) {
            $from = (array) $stepRequest['public_token_from'];
            $row = $gateway->findProjectionByStoreInvoice((string) $from['store_id'], (string) $from['invoice_id']);
            $token = $row === null ? 'TEST-UNRESOLVED-TOKEN-00' : (string) $row['public_token'];
            $path = str_replace('{public_token}', $token, $path);
        }

        $query = [];
        $questionMark = strpos($path, '?');
        if ($questionMark !== false) {
            $queryString = substr($path, $questionMark + 1);
            $path = substr($path, 0, $questionMark);
            parse_str($queryString, $parsed);
            foreach ($parsed as $name => $value) {
                $query[(string) $name] = is_array($value) ? (string) end($value) : (string) $value;
            }
        }

        /** @var array<string, string> $headers */
        $headers = (array) ($stepRequest['headers'] ?? []);
        $declared = $stepRequest['content_length'] ?? ($headers['content-length'] ?? null);

        return new Request(
            (string) $stepRequest['method'],
            $path,
            $headers,
            $query,
            (string) ($stepRequest['body_text'] ?? ''),
            $declared === null ? null : (int) $declared,
            self::CLIENT_IP,
        );
    }

    /**
     * @param array<string, mixed> $expected
     * @return list<string>
     */
    private static function assertResponse(array $expected, Response $response): array
    {
        $problems = [];
        if ((int) $expected['status'] !== $response->status()) {
            $problems[] = 'status expected ' . $expected['status'] . ', got ' . $response->status();
        }
        foreach ((array) ($expected['headers'] ?? []) as $name => $value) {
            $actual = $response->header((string) $name);
            if ($actual === null) {
                $problems[] = "missing header $name";
            } elseif ($actual !== (string) $value) {
                $problems[] = "header $name expected '$value', got '$actual'";
            }
        }
        foreach ((array) ($expected['header_ranges'] ?? []) as $name => $range) {
            $actual = $response->header((string) $name);
            if ($actual === null || preg_match('/^-?\d+$/', $actual) !== 1) {
                $problems[] = "header $name missing or non-numeric for range check";
                continue;
            }
            [$low, $high] = $range;
            if ((int) $actual < (int) $low || (int) $actual > (int) $high) {
                $problems[] = "header $name value $actual outside [$low, $high]";
            }
        }
        $bodyMatch = (string) ($expected['body_match'] ?? 'exact');
        if ($bodyMatch === 'exact') {
            $expectedBody = (string) ($expected['body_text'] ?? '');
            if ($response->body() !== $expectedBody) {
                $problems[] = 'body mismatch: expected ' . self::clip($expectedBody) . ', got ' . self::clip($response->body());
            }
        } else {
            foreach ((array) ($expected['body_markers'] ?? []) as $marker) {
                if (!str_contains($response->body(), (string) $marker)) {
                    $problems[] = 'body missing marker: ' . self::clip((string) $marker);
                }
            }
            foreach ((array) ($expected['body_markers_absent'] ?? []) as $marker) {
                if (str_contains($response->body(), (string) $marker)) {
                    $problems[] = 'body must not contain: ' . self::clip((string) $marker);
                }
            }
        }
        return $problems;
    }

    /**
     * @param list<array<string, mixed>> $effects
     * @param array<string, int> $seededEntryCounts
     * @return list<string>
     */
    private static function assertEffects(
        array $effects,
        HarnessGateway $gateway,
        array $seededEntryCounts,
        ?int $lastBatchEntryCount,
    ): array
    {
        $problems = [];
        foreach ($effects as $effect) {
            $storeId = (string) ($effect['store_id'] ?? '');
            $store = $gateway->findStore($storeId);
            if ($store === null) {
                $problems[] = "store $storeId missing after replay";
                continue;
            }
            foreach (['accepted_through_seq', 'entry_count', 'projection_version'] as $key) {
                if (array_key_exists($key, $effect) && (int) $effect[$key] !== (int) $store[$key]) {
                    $problems[] = "$key expected {$effect[$key]}, got {$store[$key]}";
                }
            }
            if (array_key_exists('head_entry_hash', $effect) && $effect['head_entry_hash'] !== $store['head_entry_hash']) {
                $problems[] = 'head_entry_hash expected ' . var_export($effect['head_entry_hash'], true)
                    . ', got ' . var_export($store['head_entry_hash'], true);
            }
            if (array_key_exists('throttled', $effect) && (bool) $effect['throttled'] !== (bool) $store['throttled']) {
                $problems[] = 'throttled flag mismatch';
            }
            if (array_key_exists('operation_id_count', $effect)) {
                // Generator semantics (see docs/golden): the number of operation ids the
                // batch carried — identical for accepted, duplicate and committed-retry
                // outcomes (g-002/g-003/g-006 all assert 2). Fall back to appended rows
                // when no batch body was replayed.
                $actual = $lastBatchEntryCount
                    ?? count($gateway->entriesOf($storeId)) - ($seededEntryCounts[$storeId] ?? 0);
                if ((int) $effect['operation_id_count'] !== $actual) {
                    $problems[] = "operation_id_count expected {$effect['operation_id_count']}, got $actual";
                }
            }
            if (isset($effect['public_invoice'])) {
                $expectedRow = (array) $effect['public_invoice'];
                $invoiceId = (string) ($expectedRow['invoice_id'] ?? '');
                $row = $gateway->findProjectionByStoreInvoice($storeId, $invoiceId);
                if ($row === null) {
                    $problems[] = "projection for invoice $invoiceId missing";
                    continue;
                }
                foreach (['store_id', 'invoice_id', 'store_name', 'lifecycle_status', 'issued_content_sha256', 'customer_phone'] as $key) {
                    if (array_key_exists($key, $expectedRow) && $expectedRow[$key] !== $row[$key]) {
                        $problems[] = "projection $key expected " . var_export($expectedRow[$key], true)
                            . ', got ' . var_export($row[$key], true);
                    }
                }
                foreach (['display_number', 'payable_rials'] as $key) {
                    if (array_key_exists($key, $expectedRow) && (int) $expectedRow[$key] !== (int) $row[$key]) {
                        $problems[] = "projection $key expected {$expectedRow[$key]}, got {$row[$key]}";
                    }
                }
                if (array_key_exists('issued_at_utc', $expectedRow)
                    && self::normalizeStoredTimestamp((string) $row['issued_at']) !== (string) $expectedRow['issued_at_utc']) {
                    $problems[] = "projection issued_at expected {$expectedRow['issued_at_utc']}, got {$row['issued_at']}";
                }
                if (isset($expectedRow['line_items'])) {
                    $decoded = json_decode((string) $row['line_items'], true);
                    if ($decoded !== $expectedRow['line_items']) {
                        $problems[] = 'projection line_items mismatch: got ' . self::clip((string) $row['line_items']);
                    }
                }
                if (isset($expectedRow['public_token_shape'])
                    && preg_match('#' . (string) $expectedRow['public_token_shape'] . '#', (string) $row['public_token']) !== 1) {
                    $problems[] = 'projection public_token does not match required shape';
                }
                // token-hash index integrity (§6.1) — always asserted
                if ((string) $row['public_token_hash'] !== RandomToken::hash((string) $row['public_token'])) {
                    $problems[] = 'projection public_token_hash is not SHA-256(public_token)';
                }
            }
        }
        return $problems;
    }

    /** DATETIME(6) reads back with '.000000'; normalize to the stored-UTC convention (U9). */
    private static function normalizeStoredTimestamp(string $stored): string
    {
        if (str_contains($stored, '.')) {
            [$seconds, $fraction] = explode('.', $stored, 2);
            $fraction = rtrim($fraction, '0');
            return $fraction === '' ? $seconds : $seconds . '.' . $fraction;
        }
        return $stored;
    }

    private static function clip(string $value): string
    {
        return strlen($value) > 240 ? substr($value, 0, 237) . '…' : $value;
    }
}
