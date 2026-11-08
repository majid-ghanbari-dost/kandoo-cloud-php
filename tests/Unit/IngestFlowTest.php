<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Json\Canonical;
use Kandoo\Cloud\Json\Parser;
use Kandoo\Cloud\Kernel;
use Kandoo\Cloud\Store\MemoryGateway;
use Kandoo\Cloud\Support\FixedClock;
use Kandoo\Cloud\Tests\Support\GoldenHarness;
use PHPUnit\Framework\TestCase;

/**
 * Replication ingest endpoint behaviour (CLOUD-CONTRACT.md §5, U1/U2/U4/U15).
 * Byte-exact frozen envelopes plus paths no golden covers (fingerprint conflict).
 * All fixtures are FAKE synthetic values.
 */
final class IngestFlowTest extends TestCase
{
    private const STORE = '11111111-1111-4111-8111-111111111111';
    private const WRITER = '22222222-2222-4222-8222-222222222222';
    private const DEVICE = '33333333-3333-4333-8333-333333333333';
    private const PATH = '/v1/stores/11111111-1111-4111-8111-111111111111/operation-batches';
    private const NOW = 1767323045;
    private const ZERO_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private const BODY_INVALID =
        '{"code":"VALIDATION_ERROR","message":"The request body must be a valid operation batch.","retry_hint":{"action":"FIX_REQUEST"}}';
    private const UNAUTHENTICATED =
        '{"code":"UNAUTHENTICATED","message":"A valid replication credential is required.","retry_hint":{"action":"REAUTHENTICATE"}}';
    private const STORE_NOT_FOUND =
        '{"code":"STORE_NOT_FOUND","message":"The store is not available in this scope.","retry_hint":{"action":"NONE"}}';
    private const IDEM_CONFLICT_DIFFERENT =
        '{"code":"IDEMPOTENCY_CONFLICT","message":"The batch key was already used for different content.","retry_hint":{"action":"REVIEW"}}';

    private MemoryGateway $gateway;

    /** @param array<string, mixed> $overrides */
    private function kernel(array $overrides = []): Kernel
    {
        $config = Config::validate(array_merge([
            'base_url' => 'https://hub.TEST-php.invalid',
            'db' => [
                'host' => '127.0.0.1', 'port' => 3306, 'name' => 'TEST_db',
                'user' => 'TEST_user', 'pass' => 'TEST-PASS', 'charset' => 'utf8mb4',
            ],
            'replication_key' => null,
            'public_rate_limit_per_minute' => 60,
            'ingest_rate_limit_per_minute' => 120,
            'public_legal_copy_required' => false,
            'env' => 'testing',
        ], $overrides));
        return new Kernel($config, $this->gateway, new FixedClock(self::NOW));
    }

    protected function setUp(): void
    {
        $this->gateway = new MemoryGateway();
        $this->gateway->seedStore([
            'store_id' => self::STORE,
            'active_writer_device_id' => self::WRITER,
            'active_writer_epoch' => 1,
            'accepted_through_seq' => 0,
            'entry_count' => 0,
            'head_entry_hash' => null,
            'projection_version' => 0,
            'throttled' => false,
        ]);
    }

    /** Idempotency-Key must equal batch_id (frozen rule), so the key is the batch id. */
    private function batchRequest(string $batchId, string $body, array $extraHeaders = []): Request
    {
        return new Request('POST', self::PATH, array_merge([
            'content-type' => 'application/json',
            'idempotency-key' => $batchId,
        ], $extraHeaders), [], $body);
    }

    /** @param list<array<string, mixed>> $entries */
    private function batchBody(string $batchId, array $entries): string
    {
        return (string) json_encode([
            'batch_id' => $batchId,
            'store_id' => self::STORE,
            'writer_device_id' => self::WRITER,
            'writer_epoch' => 1,
            'entries' => $entries,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A syntactically valid 16-field wire entry whose request_hash and entry_hash are
     * recomputed through the SAME parse+canonicalization path the service applies
     * (Parser → Canonical::entryDto → requestHash/entryHash).
     * @return array<string, mixed>
     */
    private function wireEntry(int $seq, string $previousEntryHash, ?string $operationId = null): array
    {
        $entry = [
            'protocol_version' => '0',
            'store_id' => self::STORE,
            'writer_device_id' => self::WRITER,
            'writer_epoch' => 1,
            'operation_seq' => $seq,
            'previous_entry_hash' => $previousEntryHash,
            'entry_hash' => '',
            'operation_id' => $operationId ?? 'TEST-op-' . $seq,
            'device_id' => self::DEVICE,
            'operation_type' => 'invoice.draft_create',
            'base_version' => null,
            'request_hash' => '',
            'payload' => ['store_name' => 'TEST Store'],
            'outcome' => ['status' => 'SUCCEEDED'],
            'response_snapshot' => ['status' => 200],
            'hub_recorded_at' => '2026-01-02 03:04:05',
        ];
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $dto = Canonical::entryDto(Parser::parse((string) json_encode($entry, $flags)));
        $entry['request_hash'] = Canonical::requestHash($dto);
        $dto = Canonical::entryDto(Parser::parse((string) json_encode($entry, $flags)));
        $entry['entry_hash'] = Canonical::entryHash($dto);
        return $entry;
    }

    public function testGoldenAcceptReplayIsByteExact(): void
    {
        $result = GoldenHarness::replay(dirname(__DIR__, 2) . '/docs/golden/g-002-ingest-accept.json', new MemoryGateway());
        $this->assertSame([], $result['problems']);
    }

    public function testMalformedJsonEnvelopeIsExact(): void
    {
        $response = $this->kernel()->handle($this->batchRequest('TEST-batch-1', '{oops'));
        $this->assertSame(400, $response->status());
        $this->assertSame(self::BODY_INVALID, $response->body());
        // every ingest response carries the rate-limit headers (§5.6)
        $this->assertSame('120', $response->header('RateLimit-Limit'));
        $this->assertSame('119', $response->header('RateLimit-Remaining'));
        $this->assertSame('60', $response->header('RateLimit-Reset'));
    }

    public function testReplicationKeyIsCheckedBeforeEverythingElse(): void
    {
        $kernel = $this->kernel(['replication_key' => 'TEST-REPLICATION-KEY-0123456789abcdef']);

        // missing key
        $response = $kernel->handle($this->batchRequest('TEST-batch-1', '{}'));
        $this->assertSame(401, $response->status());
        $this->assertSame(self::UNAUTHENTICATED, $response->body());

        // wrong key — checked BEFORE store-id parsing (this path store id is not a UUID)
        $wrongPath = new Request(
            'POST',
            '/v1/stores/not-a-uuid/operation-batches',
            ['x-kandoo-replication-key' => 'TEST-WRONG-KEY', 'content-type' => 'application/json'],
            [],
            '{}'
        );
        $response = $kernel->handle($wrongPath);
        $this->assertSame(401, $response->status());
        $this->assertSame(self::UNAUTHENTICATED, $response->body());

        // correct key proceeds past authentication (fails later on the envelope, not on auth)
        $ok = $this->batchRequest('TEST-batch-1', '{}', ['x-kandoo-replication-key' => 'TEST-REPLICATION-KEY-0123456789abcdef']);
        $response = $kernel->handle($ok);
        $this->assertNotSame(401, $response->status());

        // the configured key itself is never echoed in any response
        $this->assertStringNotContainsString('TEST-REPLICATION-KEY-0123456789abcdef', $response->body());
    }

    public function testIngestRateLimitIsPerStorePerMinute(): void
    {
        $kernel = $this->kernel(['ingest_rate_limit_per_minute' => 1]);
        $first = $kernel->handle($this->batchRequest('TEST-batch-1', '{oops'));
        $this->assertSame(400, $first->status()); // counted, then rejected on body
        // advertised headers stay the frozen fixed values even with a custom limit (§5.2)
        $this->assertSame('120', $first->header('RateLimit-Limit'));
        $this->assertSame('119', $first->header('RateLimit-Remaining'));
        $this->assertSame('60', $first->header('RateLimit-Reset'));

        $second = $kernel->handle($this->batchRequest('TEST-batch-2', '{oops'));
        $this->assertSame(429, $second->status());
        $this->assertStringContainsString('"code":"RATE_LIMITED"', $second->body());
        // FixedClock 03:04:05 → window ends 03:05:00 → 55 s remain (U5, deterministic)
        $this->assertStringContainsString('"retry_after_ms":55000', $second->body());
        $this->assertSame('55', $second->header('Retry-After'));

        // a different store is unaffected (per-store limiting). The body must survive
        // bindBatch (§5.4 steps 2–3 run before the store load), so use a well-formed
        // batch bound to the unknown store id.
        $otherStore = '44444444-4444-4444-8444-444444444444';
        $otherBody = (string) json_encode([
            'batch_id' => 'TEST-batch-3',
            'store_id' => $otherStore,
            'writer_device_id' => self::WRITER,
            'writer_epoch' => 1,
            'entries' => [$this->wireEntry(1, self::ZERO_HASH)],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $other = $kernel->handle(new Request(
            'POST',
            '/v1/stores/' . $otherStore . '/operation-batches',
            ['content-type' => 'application/json', 'idempotency-key' => 'TEST-batch-3'],
            [],
            $otherBody
        ));
        $this->assertSame(404, $other->status());
        $this->assertSame(self::STORE_NOT_FOUND, $other->body());
    }

    public function testFingerprintConflictIs409(): void
    {
        // same batch_id stored earlier with a DIFFERENT fingerprint (no golden covers this)
        $this->gateway->seedBatch(self::STORE, 'TEST-batch-conflict', str_repeat('0', 64));
        $response = $this->kernel()->handle($this->batchRequest(
            'TEST-batch-conflict',
            $this->batchBody('TEST-batch-conflict', [$this->wireEntry(1, self::ZERO_HASH)])
        ));
        $this->assertSame(409, $response->status());
        $this->assertSame(self::IDEM_CONFLICT_DIFFERENT, $response->body());
        $this->assertSame(0, $this->gateway->findStore(self::STORE)['entry_count']);
    }

    public function testRejectedBatchesLeaveTheStoreUntouched(): void
    {
        $kernel = $this->kernel();
        // wrong previous hash at head → 400 ENTRY_HASH_MISMATCH, nothing stored
        $response = $kernel->handle($this->batchRequest(
            'TEST-batch-1',
            $this->batchBody('TEST-batch-1', [$this->wireEntry(1, str_repeat('9', 64))])
        ));
        $this->assertSame(400, $response->status());
        $this->assertStringContainsString('"code":"ENTRY_HASH_MISMATCH"', $response->body());
        $this->assertStringContainsString('"action":"REVIEW"', $response->body());
        $this->assertStringContainsString('"received_seq":1', $response->body());
        $this->assertStringContainsString('"recomputed_entry_hash":"' . self::ZERO_HASH . '"', $response->body());
        $this->assertStringContainsString('"quarantine":"REVIEW"', $response->body());

        $store = $this->gateway->findStore(self::STORE);
        $this->assertSame(0, $store['entry_count']);
        $this->assertSame(0, $store['accepted_through_seq']);
        $this->assertNull($store['head_entry_hash']);
        $this->assertSame(0, $store['projection_version']);
        $this->assertSame([], $this->gateway->entriesOf(self::STORE));
    }
}
