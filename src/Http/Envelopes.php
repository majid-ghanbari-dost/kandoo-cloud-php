<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Http;

/**
 * Exact frozen error/ack envelopes (CLOUD-CONTRACT.md §5.6, §6, §8 — byte-exact field
 * order fixed by the golden pairs). Builders return ordered arrays for the Writer;
 * never use associative shortcuts that could reorder fields.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class Envelopes
{
    public static function storeIdMustBeUuid(): array
    {
        return self::error(400, 'VALIDATION_ERROR', 'store_id must be a UUID.', ['action' => 'FIX_REQUEST']);
    }

    public static function bodyInvalid(): array
    {
        return self::error(400, 'VALIDATION_ERROR', 'The request body must be a valid operation batch.', ['action' => 'FIX_REQUEST']);
    }

    public static function batchIdRequired(): array
    {
        return self::error(400, 'VALIDATION_ERROR', 'batch_id is required.', ['action' => 'FIX_REQUEST']);
    }

    public static function storeMismatch(): array
    {
        return self::error(400, 'VALIDATION_ERROR', 'The batch store does not match the route store.', ['action' => 'FIX_REQUEST']);
    }

    public static function envelopeViolation(): array
    {
        return self::error(400, 'VALIDATION_ERROR', 'An entry is outside the store, writer, or P-02 envelope contract.', ['action' => 'FIX_REQUEST']);
    }

    public static function invoiceExtensionInvalid(): array
    {
        return self::error(400, 'VALIDATION_ERROR', 'The invoice version marker or v1.1 fields are invalid.', ['action' => 'FIX_REQUEST']);
    }

    public static function batchIntegerProfile(): array
    {
        return self::error(400, 'VALIDATION_ERROR', 'The batch contains a value outside the integer JSON profile.', ['action' => 'FIX_REQUEST']);
    }

    public static function entryIntegerProfile(): array
    {
        return self::error(400, 'VALIDATION_ERROR', 'The entry contains a value outside the P-02 integer JSON profile.', ['action' => 'FIX_REQUEST']);
    }

    public static function idempotencyKeyRequired(): array
    {
        return self::error(400, 'IDEMPOTENCY_KEY_REQUIRED', 'Idempotency-Key is required.', ['action' => 'FIX_REQUEST']);
    }

    public static function requestHashMismatch(int $acceptedThroughSeq, string $expected, mixed $supplied): array
    {
        return self::error(400, 'REQUEST_HASH_MISMATCH', 'The request hash does not match the canonical P-02 request.', [
            'action' => 'REVIEW',
            'accepted_through_seq' => $acceptedThroughSeq,
            'details' => ['expected_request_hash' => $expected, 'supplied_request_hash' => $supplied],
        ]);
    }

    public static function incompleteBatch(int $acceptedThroughSeq): array
    {
        return self::error(400, 'INCOMPLETE_BATCH', 'An active writer must supply a complete non-empty batch.', [
            'action' => 'RETRY_SAME',
            'accepted_through_seq' => $acceptedThroughSeq,
        ]);
    }

    public static function sequenceOrderViolation(int $expectedSeq, int $receivedSeq, int $acceptedThroughSeq): array
    {
        return self::error(400, 'SEQUENCE_ORDER_VIOLATION', 'Entries must be strictly ascending and contiguous inside a batch.', [
            'action' => 'RESUME_FROM_SEQUENCE',
            'expected_seq' => $expectedSeq,
            'received_seq' => $receivedSeq,
            'accepted_through_seq' => $acceptedThroughSeq,
        ]);
    }

    public static function entryHashMismatch(int $acceptedThroughSeq, int $receivedSeq, mixed $supplied, string $recomputed): array
    {
        return self::error(400, 'ENTRY_HASH_MISMATCH', 'The entry hash chain failed verification; the entry is retained for review, not applied.', [
            'action' => 'REVIEW',
            'accepted_through_seq' => $acceptedThroughSeq,
            'received_seq' => $receivedSeq,
            'details' => [
                'supplied_entry_hash' => $supplied,
                'recomputed_entry_hash' => $recomputed,
                'quarantine' => 'REVIEW',
            ],
        ]);
    }

    public static function unauthenticated(): array
    {
        return self::error(401, 'UNAUTHENTICATED', 'A valid replication credential is required.', ['action' => 'REAUTHENTICATE']);
    }

    public static function storeNotFound(): array
    {
        return self::error(404, 'STORE_NOT_FOUND', 'The store is not available in this scope.', ['action' => 'NONE']);
    }

    public static function idempotencyConflictKeyMustEqualBatchId(): array
    {
        return self::error(409, 'IDEMPOTENCY_CONFLICT', 'Idempotency-Key must equal batch_id.', ['action' => 'REVIEW']);
    }

    public static function idempotencyConflictDifferentContent(): array
    {
        return self::error(409, 'IDEMPOTENCY_CONFLICT', 'The batch key was already used for different content.', ['action' => 'REVIEW']);
    }

    public static function writerFenced(int $acceptedThroughSeq, int $currentVersion): array
    {
        return self::error(409, 'WRITER_FENCED', 'The writer is not the active Hub epoch; stop new writes and review unsynced entries.', [
            'action' => 'STOP_WRITER',
            'accepted_through_seq' => $acceptedThroughSeq,
            'current_version' => $currentVersion,
        ]);
    }

    public static function gapDetected(int $expectedSeq, int $receivedSeq, int $acceptedThroughSeq): array
    {
        return self::error(409, 'GAP_DETECTED', 'Upload the earliest missing operation sequence before retrying the batch.', [
            'action' => 'RESUME_FROM_SEQUENCE',
            'expected_seq' => $expectedSeq,
            'received_seq' => $receivedSeq,
            'accepted_through_seq' => $acceptedThroughSeq,
        ]);
    }

    public static function logConflictUnverifiable(int $acceptedThroughSeq, int $receivedSeq): array
    {
        return self::error(409, 'LOG_CONFLICT', 'The historical entry cannot be hash-verified; no suffix was applied.', [
            'action' => 'REVIEW',
            'accepted_through_seq' => $acceptedThroughSeq,
            'received_seq' => $receivedSeq,
        ]);
    }

    public static function logConflictDifferentContent(int $acceptedThroughSeq, int $receivedSeq): array
    {
        return self::error(409, 'LOG_CONFLICT', 'An existing operation sequence has different content; Cloud will not overwrite it.', [
            'action' => 'REVIEW',
            'accepted_through_seq' => $acceptedThroughSeq,
            'received_seq' => $receivedSeq,
        ]);
    }

    public static function operationIdConflict(int $acceptedThroughSeq, int $receivedSeq): array
    {
        return self::error(409, 'OPERATION_ID_CONFLICT', 'The operation identity is already present with a different log position or content.', [
            'action' => 'REVIEW',
            'accepted_through_seq' => $acceptedThroughSeq,
            'received_seq' => $receivedSeq,
        ]);
    }

    public static function payloadTooLargeBytes(): array
    {
        return self::error(413, 'PAYLOAD_TOO_LARGE', 'The request exceeds the batch byte limit.', [
            'action' => 'FIX_REQUEST',
            'max_batch_entries' => 100,
            'max_batch_bytes' => 1048576,
        ]);
    }

    public static function payloadTooManyEntries(int $acceptedThroughSeq): array
    {
        return self::error(413, 'PAYLOAD_TOO_LARGE', 'The batch exceeds the entry limit; split only at a valid batch boundary.', [
            'action' => 'FIX_REQUEST',
            'max_batch_entries' => 100,
            'max_batch_bytes' => 1048576,
            'accepted_through_seq' => $acceptedThroughSeq,
        ]);
    }

    public static function throttled(int $acceptedThroughSeq): array
    {
        return self::error(429, 'THROTTLED', 'Cloud ingest is throttled; retain the outbox and retry the unchanged batch later.', [
            'action' => 'RETRY_AFTER',
            'retry_after_ms' => 2000,
            'max_batch_entries' => 2,
            'max_batch_bytes' => 65536,
            'accepted_through_seq' => $acceptedThroughSeq,
        ]);
    }

    public static function ingestRateLimited(int $retryAfterMs): array
    {
        return self::error(429, 'RATE_LIMITED', 'Too many ingest requests for this store.', [
            'action' => 'RETRY_AFTER',
            'retry_after_ms' => $retryAfterMs,
        ]);
    }

    public static function internalError(): array
    {
        return self::error(500, 'INTERNAL_ERROR', 'An unexpected server failure occurred.', ['action' => 'RETRY_AFTER']);
    }

    public static function publicInvoiceNotFound(): array
    {
        return self::error(404, 'PUBLIC_INVOICE_NOT_FOUND', 'The requested invoice is not available.', ['action' => 'NONE']);
    }

    public static function publicRateLimited(): array
    {
        return self::error(429, 'PUBLIC_RATE_LIMITED', 'Too many public invoice requests.', ['action' => 'RETRY_AFTER']);
    }

    public static function legalCopyUnavailable(): array
    {
        return self::error(503, 'LEGAL_COPY_UNAVAILABLE', 'Public invoice wording is awaiting lawyer approval.', ['action' => 'ENABLE_LEGAL_COPY']);
    }

    /**
     * @param array<string, mixed> $hint ordered retry_hint members
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function error(int $status, string $code, string $message, array $hint): array
    {
        return ['status' => $status, 'body' => ['code' => $code, 'message' => $message, 'retry_hint' => $hint]];
    }
}
