<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Service;

use Kandoo\Cloud\Http\Envelopes;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Http\Response;
use Kandoo\Cloud\Json\BodyInvalidException;
use Kandoo\Cloud\Json\Canonical;
use Kandoo\Cloud\Json\IntegerProfileException;
use Kandoo\Cloud\Json\JsonObject;
use Kandoo\Cloud\Json\JsonParseException;
use Kandoo\Cloud\Json\Parser;
use Kandoo\Cloud\Json\Writer;
use Kandoo\Cloud\Store\StoreGateway;
use Kandoo\Cloud\Support\Clock;
use Kandoo\Cloud\Support\Uuid;

/**
 * Hub→Cloud replication ingest — POST /v1/stores/{store_id}/operation-batches.
 * Implements the EXACT 17-step evaluation order of CLOUD-CONTRACT.md §5.4 with the
 * byte-exact envelopes of §5.6 (pinned by golden pairs G-002…G-016, G-023).
 *
 * One transaction; the store row is locked for the whole validation+commit; no mutation
 * happens before the commit point; any unexpected failure rolls everything back (U11).
 *
 * Privacy: nothing here logs tokens, bodies or URIs (§6.6); error responses never echo
 * credentials. The optional X-Kandoo-Replication-Key (U1) is compared in constant time.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class IngestService
{
    private const MAX_BATCH_BYTES = 1048576;
    private const MAX_BATCH_ENTRIES = 100;
    private const ZERO_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Advertised rate-limit headers on EVERY ingest response (frozen fixed values, §5.2). */
    private const BASE_HEADERS = [
        'RateLimit-Limit' => '120',
        'RateLimit-Remaining' => '119',
        'RateLimit-Reset' => '60',
    ];

    /** @param array<string, mixed> $config */
    public function __construct(
        private array $config,
        private StoreGateway $gateway,
        private Clock $clock,
    ) {
    }

    public function handle(string $routeStoreId, Request $request): Response
    {
        // U1 — optional pre-shared replication key (default null = frozen-identical, no auth).
        $configuredKey = $this->config['replication_key'] ?? null;
        if (is_string($configuredKey) && $configuredKey !== '') {
            $sent = $request->header('x-kandoo-replication-key');
            if (!is_string($sent) || !hash_equals($configuredKey, $sent)) {
                return $this->envelope(Envelopes::unauthenticated());
            }
        }

        // Device credentials are scoped to one store; failures share one envelope.
        if (($this->config['replication_auth_bypass'] ?? false) !== true) {
            $id = Uuid::tryNormalize($request->header('x-kandoo-device-id') ?? '');
            $secret = $request->header('x-kandoo-device-secret');
            $device = $id === null ? null : $this->gateway->findDevice($id);
            $routeId = Uuid::tryNormalize($routeStoreId);
            if ($device === null || !is_string($secret) || $secret === '' || $routeId === null
                || $device['store_id'] !== $routeId
                || !hash_equals($device['secret_sha256'], hash('sha256', $secret))) {
                return $this->envelope(Envelopes::unauthenticated());
            }
            $this->gateway->touchDeviceAuthenticated($id, gmdate('Y-m-d H:i:s', $this->clock->nowUnix()));
        }

        // §5.4 step 1 — route store_id must parse as UUID.
        $storeId = Uuid::tryNormalize($routeStoreId);
        if ($storeId === null) {
            return $this->envelope(Envelopes::storeIdMustBeUuid());
        }

        // Ingest rate limit (U5) — DB-backed fixed 1-minute window per store; 0 disables.
        $limit = (int) ($this->config['ingest_rate_limit_per_minute'] ?? 120);
        if ($limit > 0) {
            $now = $this->clock->nowUnix();
            $windowStart = intdiv($now, 60) * 60;
            if (!$this->gateway->rateHit('ingest', $storeId, $windowStart, $limit)) {
                $retryAfterSeconds = max(1, $windowStart + 60 - $now);
                $envelope = Envelopes::ingestRateLimited($retryAfterSeconds * 1000);
                return $this->envelope($envelope, ['Retry-After' => (string) $retryAfterSeconds]);
            }
        }

        // §5.4 step 2 — DECLARED Content-Length is checked before parsing (frozen behaviour).
        $declared = $request->declaredContentLength();
        if ($declared !== null && $declared > self::MAX_BATCH_BYTES) {
            return $this->envelope(Envelopes::payloadTooLargeBytes());
        }

        // §5.4 step 3 — body must bind to the OperationBatch DTO.
        try {
            $batch = $this->bindBatch($request->body());
        } catch (BodyInvalidException | JsonParseException) {
            return $this->envelope(Envelopes::bodyInvalid());
        }

        // §5.4 steps 4–17 run inside one transaction with the store row locked.
        $this->gateway->beginTransaction();
        try {
            return $this->evaluateLocked($storeId, $batch, $request);
        } catch (ProjectionViolationException) {
            $this->gateway->rollBack();
            return $this->envelope(Envelopes::internalError());
        } catch (\Throwable $e) {
            $this->gateway->rollBack();
            throw $e;
        }
    }

    /**
     * @param array{batch_id: string|null, store_id: string, writer_device_id: string, writer_epoch: int, entries: list<JsonObject>|null} $batch
     */
    private function evaluateLocked(string $storeId, array $batch, Request $request): Response
    {
        // §5.4 step 4 — load + lock the store row.
        $store = $this->gateway->lockStore($storeId);
        if ($store === null) {
            return $this->fail(Envelopes::storeNotFound());
        }
        $accepted = (int) $store['accepted_through_seq'];

        // §5.4 step 5 — writer fencing BEFORE all shape checks (CLOUD-008).
        if ($batch['writer_epoch'] !== (int) $store['active_writer_epoch']
            || $batch['writer_device_id'] !== (string) $store['active_writer_device_id']) {
            return $this->fail(Envelopes::writerFenced($accepted, (int) $store['active_writer_epoch']));
        }

        // §5.4 step 6 — Idempotency-Key required.
        $idempotencyKey = $request->header('idempotency-key');
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return $this->fail(Envelopes::idempotencyKeyRequired());
        }

        // §5.4 step 7 — batch_id present, and key === batch_id (ordinal).
        $batchId = $batch['batch_id'];
        if ($batchId === null || trim($batchId) === '') {
            return $this->fail(Envelopes::batchIdRequired());
        }
        if ($idempotencyKey !== $batchId) {
            return $this->fail(Envelopes::idempotencyConflictKeyMustEqualBatchId());
        }

        // §5.4 step 8 — body store must equal route store.
        if ($batch['store_id'] !== $storeId) {
            return $this->fail(Envelopes::storeMismatch());
        }

        // §5.4 step 9 — backpressure BEFORE completeness (CLOUD-006).
        if ((bool) $store['throttled']) {
            return $this->fail(Envelopes::throttled($accepted), ['Retry-After' => '2']);
        }

        // §5.4 step 10 — complete non-empty batch required from an active writer.
        $entryDtos = $batch['entries'];
        if ($entryDtos === null || $entryDtos === []) {
            return $this->fail(Envelopes::incompleteBatch($accepted));
        }

        // §5.4 step 11 — entry count limit.
        if (count($entryDtos) > self::MAX_BATCH_ENTRIES) {
            return $this->fail(Envelopes::payloadTooManyEntries($accepted));
        }

        // §5.4 step 12 — batch fingerprint over the DTO-round-tripped batch.
        try {
            $fingerprint = Canonical::batchFingerprint(
                $batchId,
                $batch['store_id'],
                $batch['writer_device_id'],
                $batch['writer_epoch'],
                $entryDtos,
            );
        } catch (IntegerProfileException) {
            return $this->fail(Envelopes::batchIntegerProfile());
        }
        $knownFingerprint = $this->gateway->findBatchFingerprint($storeId, $batchId);
        if ($knownFingerprint !== null && $knownFingerprint !== $fingerprint) {
            return $this->fail(Envelopes::idempotencyConflictDifferentContent());
        }

        // §5.4 step 13 — per-entry validation in array order (first failure aborts all).
        $recomputedHashes = [];
        $previousDto = null;
        foreach ($entryDtos as $index => $dto) {
            /** @var JsonObject $dto */
            $seq = (int) $dto->get('operation_seq');

            // 13a — envelope contract
            if ($dto->get('store_id') !== $batch['store_id']
                || $dto->get('writer_device_id') !== $batch['writer_device_id']
                || $dto->get('writer_epoch') !== $batch['writer_epoch']
                || $dto->get('protocol_version') !== '0'
                || !self::nonBlank($dto->get('operation_id'))
                || !self::nonBlank($dto->get('operation_type'))
                || $seq < 1
                || !$dto->get('payload') instanceof JsonObject
                || !$dto->get('outcome') instanceof JsonObject
                || !$dto->get('response_snapshot') instanceof JsonObject) {
                return $this->fail(Envelopes::envelopeViolation());
            }

            // 13b — intra-batch order: strictly ascending and contiguous
            if ($previousDto !== null && $seq !== (int) $previousDto->get('operation_seq') + 1) {
                return $this->fail(Envelopes::sequenceOrderViolation(
                    (int) $previousDto->get('operation_seq') + 1,
                    $seq,
                    $accepted,
                ));
            }

            // 13c — request hash
            try {
                $expectedRequestHash = Canonical::requestHash($dto);
            } catch (IntegerProfileException) {
                return $this->fail(Envelopes::entryIntegerProfile());
            }
            $suppliedRequestHash = $dto->get('request_hash');
            if ($suppliedRequestHash !== $expectedRequestHash) {
                return $this->fail(Envelopes::requestHashMismatch($accepted, $expectedRequestHash, $suppliedRequestHash));
            }

            // 13d — entry hash
            try {
                $entryHash = Canonical::entryHash($dto);
            } catch (IntegerProfileException) {
                return $this->fail(Envelopes::entryIntegerProfile());
            }
            $suppliedEntryHash = $dto->get('entry_hash');
            if ($suppliedEntryHash !== $entryHash) {
                return $this->fail(Envelopes::entryHashMismatch($accepted, $seq, $suppliedEntryHash, $entryHash));
            }

            // 13e — intra-batch chain against the PREVIOUS entry's recomputed hash
            if ($previousDto !== null && $dto->get('previous_entry_hash') !== $recomputedHashes[$index - 1]) {
                return $this->fail(Envelopes::entryHashMismatch(
                    $accepted,
                    $seq,
                    $dto->get('previous_entry_hash'),
                    (string) $recomputedHashes[$index - 1],
                ));
            }

            $recomputedHashes[$index] = $entryHash;
            $previousDto = $dto;
        }

        // §5.4 step 14 — gap check against the stored watermark.
        $firstNewIndex = null;
        foreach ($entryDtos as $index => $dto) {
            if ((int) $dto->get('operation_seq') > $accepted) {
                $firstNewIndex = $index;
                break;
            }
        }
        if ($firstNewIndex !== null && (int) $entryDtos[$firstNewIndex]->get('operation_seq') !== $accepted + 1) {
            return $this->fail(Envelopes::gapDetected(
                $accepted + 1,
                (int) $entryDtos[$firstNewIndex]->get('operation_seq'),
                $accepted,
            ));
        }

        // §5.4 step 15 — per-entry disposition in array order.
        $newRows = [];
        $newIndexes = [];
        $duplicateCount = 0;
        $seenOperationIds = [];
        foreach ($entryDtos as $index => $dto) {
            $seq = (int) $dto->get('operation_seq');
            $operationId = (string) $dto->get('operation_id');

            if (isset($seenOperationIds[$operationId])) {
                return $this->fail(Envelopes::operationIdConflict($accepted, $seq));
            }
            $seenOperationIds[$operationId] = true;

            if ($seq <= $accepted) {
                $storedHash = $this->gateway->findEntryHash($storeId, $seq);
                if ($storedHash === null) {
                    return $this->fail(Envelopes::logConflictUnverifiable($accepted, $seq));
                }
                if ($storedHash !== $recomputedHashes[$index]) {
                    return $this->fail(Envelopes::logConflictDifferentContent($accepted, $seq));
                }
                $duplicateCount++;
                continue;
            }
            if ($this->gateway->findEntrySeqByOperationId($storeId, $operationId) !== null) {
                return $this->fail(Envelopes::operationIdConflict($accepted, $seq));
            }
            $newRows[] = [
                'store_id' => $storeId,
                'operation_seq' => $seq,
                'operation_id' => $operationId,
                'entry_hash' => $recomputedHashes[$index],
                'entry_json' => Writer::encodeCanonical($dto),
            ];
            $newIndexes[] = $index;
        }

        // §5.4 step 16 — head chain (frozen argument order reproduced exactly).
        if ($firstNewIndex !== null) {
            $expectedPrevious = $store['head_entry_hash'] ?? self::ZERO_HASH;
            $firstNewDto = $entryDtos[$firstNewIndex];
            if ($firstNewDto->get('previous_entry_hash') !== $expectedPrevious) {
                return $this->fail(Envelopes::entryHashMismatch(
                    $accepted,
                    (int) $firstNewDto->get('operation_seq'),
                    $firstNewDto->get('entry_hash'),
                    (string) $expectedPrevious,
                ));
            }
        }

        // §5.4 step 17 — commit point: append, project, advance watermarks, record batch.
        $newCount = count($newRows);
        if ($newCount > 0) {
            $this->gateway->insertEntries($newRows);
            foreach ($newIndexes as $index) {
                Projection::apply($entryDtos[$index], $this->gateway, $storeId);
            }
        }

        $lastIndex = count($entryDtos) - 1;
        $acceptedAfter = $newCount > 0 ? (int) $entryDtos[$lastIndex]->get('operation_seq') : $accepted;
        $headAfter = $newCount > 0 ? $recomputedHashes[$lastIndex] : $store['head_entry_hash'];
        $this->gateway->updateStore($storeId, [
            'accepted_through_seq' => $acceptedAfter,
            'entry_count' => (int) $store['entry_count'] + $newCount,
            'head_entry_hash' => $headAfter,
            'projection_version' => (int) $store['projection_version'] + $newCount,
        ]);
        $this->gateway->insertBatch($storeId, $batchId, $fingerprint);
        $this->gateway->commit();

        $ack = [
            'batch_id' => $batchId,
            'status' => $newCount > 0 ? 'ACCEPTED' : 'DUPLICATE',
            'accepted_through_seq' => $acceptedAfter,
            'ingested_count' => $newCount,
            'duplicate_count' => $duplicateCount,
            'head_entry_hash' => $headAfter,
            'next_expected_seq' => $acceptedAfter + 1,
            'cloud_cursor' => $newCount > 0 ? Uuid::toN($storeId) . ':' . $acceptedAfter : null,
        ];
        return Response::json(200, $ack, self::BASE_HEADERS);
    }

    /**
     * §5.4 step 3 — strict DTO binding of the wire body.
     * @return array{batch_id: string|null, store_id: string, writer_device_id: string, writer_epoch: int, entries: list<JsonObject>|null}
     * @throws BodyInvalidException|JsonParseException
     */
    private function bindBatch(string $body): array
    {
        $root = Parser::parse($body);
        if (!$root instanceof JsonObject) {
            throw new BodyInvalidException('Batch root must be a JSON object.');
        }

        $batchIdNode = $root->has('batch_id') ? $root->get('batch_id') : '';
        if ($batchIdNode !== null && !is_string($batchIdNode)) {
            throw new BodyInvalidException('batch_id must be a string.');
        }

        $storeId = $this->bindUuid($root, 'store_id');
        $writerDeviceId = $this->bindUuid($root, 'writer_device_id');

        if (!$root->has('writer_epoch')) {
            $writerEpoch = 0;
        } else {
            $epochNode = $root->get('writer_epoch');
            if (!is_int($epochNode)) {
                throw new BodyInvalidException('writer_epoch must be an integer.');
            }
            $writerEpoch = $epochNode;
        }

        $entriesNode = $root->has('entries') ? $root->get('entries') : null;
        $entryDtos = null;
        if ($entriesNode !== null) {
            if (!is_array($entriesNode) || ($entriesNode !== [] && !array_is_list($entriesNode))) {
                throw new BodyInvalidException('entries must be an array.');
            }
            $entryDtos = [];
            foreach ($entriesNode as $entryNode) {
                if (!$entryNode instanceof JsonObject) {
                    throw new BodyInvalidException('Each entry must be a JSON object.');
                }
                $entryDtos[] = Canonical::entryDto($entryNode);
            }
        }

        return [
            'batch_id' => $batchIdNode,
            'store_id' => $storeId,
            'writer_device_id' => $writerDeviceId,
            'writer_epoch' => $writerEpoch,
            'entries' => $entryDtos,
        ];
    }

    /** Mirrors STJ Guid binding: absent → Guid.Empty; null/invalid → body-invalid. */
    private function bindUuid(JsonObject $root, string $field): string
    {
        if (!$root->has($field)) {
            return Canonical::ZERO_UUID;
        }
        $node = $root->get($field);
        if (!is_string($node)) {
            throw new BodyInvalidException("$field must be a UUID string.");
        }
        $normalized = Uuid::tryNormalize($node);
        if ($normalized === null) {
            throw new BodyInvalidException("$field is not a UUID.");
        }
        return $normalized;
    }

    private static function nonBlank(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /** @param array{status: int, body: array<string, mixed>} $envelope */
    private function envelope(array $envelope, array $extraHeaders = []): Response
    {
        return Response::json($envelope['status'], $envelope['body'], array_merge(self::BASE_HEADERS, $extraHeaders));
    }

    /** Error inside the transaction: roll back first, never leave partial effects. */
    private function fail(array $envelope, array $extraHeaders = []): Response
    {
        $this->gateway->rollBack();
        return $this->envelope($envelope, $extraHeaders);
    }
}
