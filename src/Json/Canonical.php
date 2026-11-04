<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Json;

use Kandoo\Cloud\Support\Uuid;

/**
 * Canonical JSON hashing — the exact profile of CLOUD-CONTRACT.md §5.5, verified against
 * every official fixture hash (entry_41/entry_42/product_request/CLOUD-007 corruption).
 *
 * The frozen Cloud hashes the DESERIALIZED DTO RE-SERIALIZATION, not raw wire bytes:
 * unknown extra fields drop out, absent strings become "", absent longs become 0, absent
 * UUIDs become 00000000-0000-0000-0000-000000000000, UUIDs re-serialize as lowercase D,
 * integers as plain decimal. This class performs the identical normalization.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class Canonical
{
    public const ZERO_UUID = '00000000-0000-0000-0000-000000000000';

    /** @var list<string> the 16 known LogEntry DTO fields, in declaration order */
    public const ENTRY_FIELDS = [
        'protocol_version', 'store_id', 'writer_device_id', 'writer_epoch', 'operation_seq',
        'previous_entry_hash', 'entry_hash', 'operation_id', 'device_id', 'operation_type',
        'base_version', 'request_hash', 'payload', 'outcome', 'response_snapshot', 'hub_recorded_at',
    ];

    private const STRING_FIELDS = [
        'protocol_version', 'previous_entry_hash', 'entry_hash', 'operation_id',
        'operation_type', 'request_hash', 'hub_recorded_at',
    ];
    private const UUID_FIELDS = ['store_id', 'writer_device_id', 'device_id'];
    private const LONG_FIELDS = ['writer_epoch', 'operation_seq'];
    private const ELEMENT_FIELDS = ['payload', 'outcome', 'response_snapshot'];

    /** Thrown for wire bodies that cannot bind to the DTOs (types/unparsable UUIDs). */
    public static function bodyInvalid(string $why): BodyInvalidException
    {
        return new BodyInvalidException($why);
    }

    /**
     * Normalizes one wire entry object into the 16-field DTO node.
     * @throws BodyInvalidException when a present field has the wrong JSON type
     * @throws JsonParseException (caller treats as body-invalid)
     */
    public static function entryDto(JsonObject $entry): JsonObject
    {
        $pairs = [];
        foreach (self::ENTRY_FIELDS as $field) {
            $present = $entry->has($field);
            $value = $present ? $entry->get($field) : null;

            if (in_array($field, self::STRING_FIELDS, true)) {
                // absent → "" (DTO initializer); explicit null → null (STJ assigns null); wrong type → 400
                $normalized = !$present ? '' : (is_string($value) || $value === null ? $value : throw self::bodyInvalid("entry.$field must be a string"));
            } elseif (in_array($field, self::UUID_FIELDS, true)) {
                if (!$present) {
                    $normalized = self::ZERO_UUID;
                } elseif ($value === null || !is_string($value)) {
                    throw self::bodyInvalid("entry.$field must be a UUID string");
                } else {
                    $normalized = Uuid::tryNormalize($value) ?? throw self::bodyInvalid("entry.$field is not a UUID");
                }
            } elseif (in_array($field, self::LONG_FIELDS, true)) {
                // absent → 0; explicit null or non-integer → 400 (JsonNumber counts as non-integer)
                $normalized = !$present ? 0 : (is_int($value) ? $value : throw self::bodyInvalid("entry.$field must be an integer"));
            } elseif ($field === 'base_version') {
                // long? — absent → null; null → null; int → int; anything else → 400
                $normalized = !$present || $value === null ? null : (is_int($value) ? $value : throw self::bodyInvalid('entry.base_version must be an integer or null'));
            } else {
                // JsonElement fields: absent → UndefinedElement marker; otherwise the raw node
                $normalized = $present ? $value : UndefinedElement::instance();
            }
            $pairs[] = [$field, $normalized];
        }
        return new JsonObject($pairs);
    }

    /**
     * entry_hash = SHA256(canonical_json(entry_dto minus root key entry_hash)).
     * @throws IntegerProfileException when the DTO contains a non-integer number or an
     *         absent (Undefined) element — at the HTTP boundary the fingerprint step hits
     *         this first, producing the batch-level integer-profile message (§5.4 step 12).
     */
    public static function entryHash(JsonObject $entryDto): string
    {
        $pairs = [];
        foreach ($entryDto->pairs() as [$key, $value]) {
            if ($key !== 'entry_hash') {
                $pairs[] = [$key, $value];
            }
        }
        return self::sha256Hex(Writer::encodeCanonical(new JsonObject($pairs)));
    }

    /**
     * request_hash = SHA256(canonical_json({store_id, device_id, operation_id,
     * operation_type, base_version, payload})) over DTO-normalized values.
     * @throws IntegerProfileException
     */
    public static function requestHash(JsonObject $entryDto): string
    {
        $input = JsonObject::fromMap([
            'store_id'       => $entryDto->get('store_id'),
            'device_id'      => $entryDto->get('device_id'),
            'operation_id'   => $entryDto->get('operation_id'),
            'operation_type' => $entryDto->get('operation_type'),
            'base_version'   => $entryDto->get('base_version'),
            'payload'        => $entryDto->get('payload'),
        ]);
        return self::sha256Hex(Writer::encodeCanonical($input));
    }

    /**
     * batch_fingerprint = SHA256(canonical_json(OperationBatch DTO)) — exactly the five
     * known batch fields; entries = list of 16-field DTO nodes, or null when absent.
     * @param list<JsonObject>|null $entryDtos
     * @throws IntegerProfileException
     */
    public static function batchFingerprint(string $batchId, string $storeId, string $writerDeviceId, int $writerEpoch, ?array $entryDtos): string
    {
        $input = JsonObject::fromMap([
            'batch_id'         => $batchId,
            'store_id'         => $storeId,
            'writer_device_id' => $writerDeviceId,
            'writer_epoch'     => $writerEpoch,
            'entries'          => $entryDtos,
        ]);
        return self::sha256Hex(Writer::encodeCanonical($input));
    }

    /** issued_content_sha256 = SHA256(canonical_json(issued_payload)) — verification only (§7.1). */
    public static function contentSha256(mixed $node): string
    {
        return self::sha256Hex(Writer::encodeCanonical($node));
    }

    public static function sha256Hex(string $bytes): string
    {
        return hash('sha256', $bytes);
    }
}
