<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Json;

/**
 * Marker for a JsonElement-typed DTO field that was ABSENT from the wire body.
 * The frozen Cloud deserializes it to a default (ValueKind.Undefined) JsonElement whose
 * serialization throws — reproduced here by Writer for both canonical and preserve modes.
 * Absent is deliberately distinct from JSON null (which is a real Null node).
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class UndefinedElement
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
}
