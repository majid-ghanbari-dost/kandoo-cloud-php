<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Json\Canonical;
use Kandoo\Cloud\Json\JsonObject;
use Kandoo\Cloud\Json\Parser;
use PHPUnit\Framework\TestCase;

/**
 * Canonical hashing tests tied to the OFFICIAL fixture constants (CLOUD-CONTRACT.md §5.5):
 * the same values the frozen .NET repo publishes as synthetic test vectors. The inputs are
 * read from the golden request bodies (docs/golden/) so the wire bytes, the DTO
 * normalization and the PHP hasher are all verified together.
 *
 * FAKE FIXTURE DATA ONLY — vector IDs from docs/sync-protocol-test-vectors.json (test_only).
 */
final class CanonicalHashTest extends TestCase
{
    private const ENTRY_41_HASH = 'ca15b6e0b3d101f57f9855c6e43871e4d5db6875d87a19b0b8b0ee8baa9975a8';
    private const ENTRY_42_HASH = 'fa0daab5586810444f1ecfb1ebf8f3f7e061a42630d77806a4e8d9aa3a98e502';
    private const PRODUCT_REQUEST_HASH = '8b6fcb64f008a9d921bf44fe7a9346eb6a8898fa569904942be8a9f36b3eb8a6';
    private const CORRUPTED_RECOMPUTED = '387ca0f3d13d21a4b4373898e1c0c58dd44f6d7adca1ec01556027e5ae0a97b8';

    private function goldenDir(): string
    {
        return dirname(__DIR__, 2) . '/docs/golden';
    }

    private function firstBatch(string $file): JsonObject
    {
        $raw = file_get_contents($this->goldenDir() . '/' . $file);
        $this->assertNotFalse($raw);
        /** @var array{steps: list<array{request: array{body_text: string}}>} $case */
        $case = json_decode($raw, true);
        $node = Parser::parse($case['steps'][0]['request']['body_text']);
        $this->assertInstanceOf(JsonObject::class, $node);
        return $node;
    }

    public function testOfficialEntryAndRequestHashesReproduce(): void
    {
        $batch = $this->firstBatch('g-002-ingest-accept.json');
        /** @var list<JsonObject> $entries */
        $entries = $batch->get('entries');
        $this->assertCount(2, $entries);

        $dto41 = Canonical::entryDto($entries[0]);
        $dto42 = Canonical::entryDto($entries[1]);
        $this->assertSame(self::ENTRY_41_HASH, Canonical::entryHash($dto41));
        $this->assertSame(self::ENTRY_42_HASH, Canonical::entryHash($dto42));
        $this->assertSame(self::PRODUCT_REQUEST_HASH, Canonical::requestHash($dto41));
        // entry 42's request hash equals its wire value too
        $this->assertSame($dto42->get('request_hash'), Canonical::requestHash($dto42));
    }

    public function testIntentionalCorruptionRecomputesToDocumentedHash(): void
    {
        $batch = $this->firstBatch('g-008-ingest-entry-hash-mismatch.json');
        /** @var list<JsonObject> $entries */
        $entries = $batch->get('entries');
        $dto = Canonical::entryDto($entries[0]);
        // The wire entry_hash is the UNCORRUPTED fixture constant; recomputation differs.
        $this->assertSame(self::ENTRY_41_HASH, $dto->get('entry_hash'));
        $this->assertSame(self::CORRUPTED_RECOMPUTED, Canonical::entryHash($dto));
        // The golden response documents exactly this recomputed value.
        $raw = file_get_contents($this->goldenDir() . '/g-008-ingest-entry-hash-mismatch.json');
        $this->assertNotFalse($raw);
        $this->assertStringContainsString(self::CORRUPTED_RECOMPUTED, $raw);
    }

    public function testBatchFingerprintIsStableAcrossReplayedGoldens(): void
    {
        // The same batch content must fingerprint identically everywhere it is replayed —
        // duplicate/idempotency detection depends on it (§5.4 step 12). The pinned value
        // was produced by the reference canonicalizer that reproduced every official
        // fixture hash (see CLOUD-CONTRACT.md §5.5 verification record).
        $expected = 'e6dab1e3c11f17f2aa5447a7e3852132eb4b6ad36642d10c2404f371031559f0';
        foreach ([
            'g-002-ingest-accept.json',
            'g-003-ingest-duplicate.json',
            'g-006-ingest-committed-retry.json',
            'g-013-ingest-idempotency-conflict.json',
        ] as $file) {
            $batch = $this->firstBatch($file);
            /** @var list<JsonObject> $entries */
            $entries = $batch->get('entries');
            $dtos = array_map([Canonical::class, 'entryDto'], $entries);
            $computed = Canonical::batchFingerprint(
                (string) $batch->get('batch_id'),
                (string) $batch->get('store_id'),
                (string) $batch->get('writer_device_id'),
                (int) $batch->get('writer_epoch'),
                $dtos,
            );
            $this->assertSame($expected, $computed, "fingerprint for $file");
        }
    }

    public function testDtoNormalizationDropsUnknownFieldsAndDefaults(): void
    {
        $wire = Parser::parse(
            '{"operation_seq":5,"store_id":"11111111-1111-4111-8111-111111111111",'
            . '"UNKNOWN_EXTRA":"x","payload":{"k":1}}'
        );
        $this->assertInstanceOf(JsonObject::class, $wire);
        $dto = Canonical::entryDto($wire);
        $this->assertSame(Canonical::ENTRY_FIELDS, $dto->keys()); // exactly the 16 fields
        $this->assertSame('', $dto->get('operation_id'));         // absent string → ""
        $this->assertSame(0, $dto->get('writer_epoch'));          // absent long → 0
        $this->assertSame('00000000-0000-0000-0000-000000000000', $dto->get('device_id'));
        $this->assertNull($dto->get('base_version'));             // absent long? → null
    }

    public function testIssuedContentHashMatchesGoldenProjectionRow(): void
    {
        // G-017's stored issued_payload must hash to the stored issued_content_sha256 (§7.1).
        // Walks the golden with the application's own strict parser (order-preserving).
        $raw = file_get_contents($this->goldenDir() . '/g-017-public-json-view.json');
        $this->assertNotFalse($raw);
        $case = Parser::parse($raw);
        $this->assertInstanceOf(JsonObject::class, $case);
        /** @var JsonObject $given */
        $given = $case->get('given');
        /** @var list<JsonObject> $rows */
        $rows = $given->get('public_invoices');
        $row = $rows[0];
        $this->assertSame(
            $row->get('issued_content_sha256'),
            Canonical::contentSha256($row->get('issued_payload')),
        );
    }
}
