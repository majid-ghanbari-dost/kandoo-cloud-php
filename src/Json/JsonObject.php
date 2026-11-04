<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Json;

/**
 * Insertion-ordered JSON object node.
 *
 * Pairs are stored as a list of [key, value] tuples — NOT a PHP associative array — so
 * insertion order survives and key handling mirrors .NET's JsonElement enumeration used
 * by the frozen canonical serializer (CLOUD-CONTRACT.md §5.5). The strict parser rejects
 * duplicate keys before construction.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class JsonObject implements \Countable, \IteratorAggregate
{
    /** @var list<array{0: string, 1: mixed}> */
    private array $pairs;

    /** @param list<array{0: string, 1: mixed}> $pairs */
    public function __construct(array $pairs = [])
    {
        $this->pairs = $pairs;
    }

    /** @param array<string, mixed> $map convenience builder (insertion order preserved) */
    public static function fromMap(array $map): self
    {
        $pairs = [];
        foreach ($map as $key => $value) {
            $pairs[] = [(string) $key, $value];
        }
        return new self($pairs);
    }

    public function has(string $key): bool
    {
        foreach ($this->pairs as [$k]) {
            if ($k === $key) {
                return true;
            }
        }
        return false;
    }

    /** First match wins, mirroring JsonElement.TryGetProperty. */
    public function get(string $key): mixed
    {
        foreach ($this->pairs as [$k, $v]) {
            if ($k === $key) {
                return $v;
            }
        }
        return null;
    }

    /** @return list<array{0: string, 1: mixed}> */
    public function pairs(): array
    {
        return $this->pairs;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(static fn (array $pair): string => $pair[0], $this->pairs);
    }

    public function count(): int
    {
        return count($this->pairs);
    }

    public function getIterator(): \Generator
    {
        foreach ($this->pairs as [$k, $v]) {
            yield $k => $v;
        }
    }
}
