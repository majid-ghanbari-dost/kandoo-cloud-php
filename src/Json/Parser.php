<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Json;

/**
 * Strict JSON parser for hash-bearing request bodies (CLOUD-CONTRACT.md §5.5).
 *
 * Mirrors the frozen Cloud's System.Text.Json deserialization boundary:
 *  - rejects invalid UTF-8 (STJ rejects it → 400 "valid operation batch");
 *  - rejects duplicate object keys (not canonical; U7 — stricter fail-closed posture);
 *  - rejects trailing content, control characters in strings, bad escapes;
 *  - plain int64 literals become PHP ints; every other numeric form (float, exponent,
 *    out-of-int64-range) becomes a JsonNumber raw passthrough node, which canonical
 *    hashing rejects exactly like the frozen serializer does;
 *  - recursion depth capped at 64 (STJ JsonReaderOptions.MaxDepth default).
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class Parser
{
    private const MAX_DEPTH = 64;

    private string $s;
    private int $len;
    private int $pos = 0;
    private int $depth = 0;

    private function __construct(string $json)
    {
        $this->s = $json;
        $this->len = strlen($json);
    }

    /**
     * @return mixed JsonObject | list<mixed> | string | int | float-free JsonNumber | bool | null
     * @throws JsonParseException on any malformed input
     */
    public static function parse(string $json): mixed
    {
        if (!mb_check_encoding($json, 'UTF-8')) {
            throw new JsonParseException('Body is not valid UTF-8 JSON.');
        }
        $parser = new self($json);
        $parser->skipWs();
        $value = $parser->parseValue();
        $parser->skipWs();
        if ($parser->pos !== $parser->len) {
            throw new JsonParseException('Trailing content after the JSON value.');
        }
        return $value;
    }

    private function parseValue(): mixed
    {
        if ($this->pos >= $this->len) {
            throw new JsonParseException('Unexpected end of JSON input.');
        }
        if (++$this->depth > self::MAX_DEPTH) {
            throw new JsonParseException('JSON depth exceeds the maximum of 64.');
        }
        try {
            $ch = $this->s[$this->pos];
            return match ($ch) {
                '{'    => $this->parseObject(),
                '['    => $this->parseArray(),
                '"'    => $this->parseString(),
                't'    => $this->expectLiteral('true', true),
                'f'    => $this->expectLiteral('false', false),
                'n'    => $this->expectLiteral('null', null),
                '-', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' => $this->parseNumber(),
                default => throw new JsonParseException("Unexpected character '$ch' in JSON input."),
            };
        } finally {
            $this->depth--;
        }
    }

    private function expectLiteral(string $literal, mixed $value): mixed
    {
        $n = strlen($literal);
        if (substr($this->s, $this->pos, $n) !== $literal) {
            throw new JsonParseException("Invalid literal at offset {$this->pos}.");
        }
        $this->pos += $n;
        return $value;
    }

    private function parseObject(): JsonObject
    {
        $this->pos++; // '{'
        $pairs = [];
        $seen = [];
        $this->skipWs();
        if ($this->pos < $this->len && $this->s[$this->pos] === '}') {
            $this->pos++;
            return new JsonObject($pairs);
        }
        for (;;) {
            $this->skipWs();
            if ($this->pos >= $this->len || $this->s[$this->pos] !== '"') {
                throw new JsonParseException('Expected an object key at offset ' . $this->pos . '.');
            }
            $key = $this->parseString();
            if (isset($seen[$key])) {
                throw new JsonParseException("Duplicate object key '$key' is not canonical JSON (§5.5).");
            }
            $seen[$key] = true;
            $this->skipWs();
            if ($this->pos >= $this->len || $this->s[$this->pos] !== ':') {
                throw new JsonParseException("Expected ':' after object key '$key'.");
            }
            $this->pos++;
            $this->skipWs();
            $value = $this->parseValue();
            $pairs[] = [$key, $value];
            $this->skipWs();
            if ($this->pos >= $this->len) {
                throw new JsonParseException('Unterminated JSON object.');
            }
            $ch = $this->s[$this->pos];
            if ($ch === ',') {
                $this->pos++;
                continue;
            }
            if ($ch === '}') {
                $this->pos++;
                return new JsonObject($pairs);
            }
            throw new JsonParseException("Unexpected character '$ch' inside object at offset {$this->pos}.");
        }
    }

    private function parseArray(): array
    {
        $this->pos++; // '['
        $items = [];
        $this->skipWs();
        if ($this->pos < $this->len && $this->s[$this->pos] === ']') {
            $this->pos++;
            return $items;
        }
        for (;;) {
            $this->skipWs();
            $items[] = $this->parseValue();
            $this->skipWs();
            if ($this->pos >= $this->len) {
                throw new JsonParseException('Unterminated JSON array.');
            }
            $ch = $this->s[$this->pos];
            if ($ch === ',') {
                $this->pos++;
                continue;
            }
            if ($ch === ']') {
                $this->pos++;
                return $items;
            }
            throw new JsonParseException("Unexpected character '$ch' inside array at offset {$this->pos}.");
        }
    }

    private function parseString(): string
    {
        $this->pos++; // opening quote
        $out = '';
        for (;;) {
            if ($this->pos >= $this->len) {
                throw new JsonParseException('Unterminated JSON string.');
            }
            $ch = $this->s[$this->pos];
            $ord = ord($ch);
            if ($ord < 0x20) {
                throw new JsonParseException('Unescaped control character in JSON string.');
            }
            if ($ch === '"') {
                $this->pos++;
                return $out;
            }
            if ($ch !== '\\') {
                $out .= $ch;
                $this->pos++;
                continue;
            }
            $this->pos++;
            if ($this->pos >= $this->len) {
                throw new JsonParseException('Unterminated escape sequence in JSON string.');
            }
            $esc = $this->s[$this->pos];
            switch ($esc) {
                case '"':  $out .= '"';  $this->pos++; break;
                case '\\': $out .= '\\'; $this->pos++; break;
                case '/':  $out .= '/';  $this->pos++; break;
                case 'b':  $out .= "\x08"; $this->pos++; break;
                case 'f':  $out .= "\x0C"; $this->pos++; break;
                case 'n':  $out .= "\x0A"; $this->pos++; break;
                case 'r':  $out .= "\x0D"; $this->pos++; break;
                case 't':  $out .= "\x09"; $this->pos++; break;
                case 'u':
                    $this->pos++;
                    $code = $this->parseHex4();
                    if ($code >= 0xD800 && $code <= 0xDBFF) {
                        // high surrogate: a valid pair must follow
                        if ($this->pos + 1 < $this->len && $this->s[$this->pos] === '\\' && $this->s[$this->pos + 1] === 'u') {
                            $this->pos += 2;
                            $low = $this->parseHex4();
                            if ($low < 0xDC00 || $low > 0xDFFF) {
                                throw new JsonParseException('Invalid Unicode surrogate pair.');
                            }
                            $code = 0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00);
                        } else {
                            throw new JsonParseException('Lone high surrogate in JSON string.');
                        }
                    } elseif ($code >= 0xDC00 && $code <= 0xDFFF) {
                        throw new JsonParseException('Lone low surrogate in JSON string.');
                    }
                    $out .= self::utf8Encode($code);
                    break;
                default:
                    throw new JsonParseException("Invalid escape '\\$esc' in JSON string.");
            }
        }
    }

    private function parseHex4(): int
    {
        if ($this->pos + 4 > $this->len) {
            throw new JsonParseException('Truncated \\u escape in JSON string.');
        }
        $hex = substr($this->s, $this->pos, 4);
        if (preg_match('/^[0-9a-fA-F]{4}$/', $hex) !== 1) {
            throw new JsonParseException("Invalid \\u escape '$hex' in JSON string.");
        }
        $this->pos += 4;
        return (int) hexdec($hex);
    }

    private static function utf8Encode(int $code): string
    {
        if ($code < 0x80) {
            return chr($code);
        }
        if ($code < 0x800) {
            return chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
        }
        if ($code < 0x10000) {
            return chr(0xE0 | ($code >> 12)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
        }
        return chr(0xF0 | ($code >> 18)) . chr(0x80 | (($code >> 12) & 0x3F))
            . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
    }

    private function parseNumber(): mixed
    {
        $start = $this->pos;
        if ($this->s[$this->pos] === '-') {
            $this->pos++;
        }
        $digitsStart = $this->pos;
        while ($this->pos < $this->len && $this->s[$this->pos] >= '0' && $this->s[$this->pos] <= '9') {
            $this->pos++;
        }
        $intDigits = $this->pos - $digitsStart;
        if ($intDigits === 0) {
            throw new JsonParseException("Invalid number literal at offset $start.");
        }
        if ($intDigits > 1 && $this->s[$digitsStart] === '0') {
            throw new JsonParseException("Leading zeros are not valid JSON at offset $start.");
        }
        $isPlainInt = true;
        if ($this->pos < $this->len && $this->s[$this->pos] === '.') {
            $isPlainInt = false;
            $this->pos++;
            $fracStart = $this->pos;
            while ($this->pos < $this->len && $this->s[$this->pos] >= '0' && $this->s[$this->pos] <= '9') {
                $this->pos++;
            }
            if ($this->pos === $fracStart) {
                throw new JsonParseException("Invalid number literal at offset $start.");
            }
        }
        if ($this->pos < $this->len && ($this->s[$this->pos] === 'e' || $this->s[$this->pos] === 'E')) {
            $isPlainInt = false;
            $this->pos++;
            if ($this->pos < $this->len && ($this->s[$this->pos] === '+' || $this->s[$this->pos] === '-')) {
                $this->pos++;
            }
            $expStart = $this->pos;
            while ($this->pos < $this->len && $this->s[$this->pos] >= '0' && $this->s[$this->pos] <= '9') {
                $this->pos++;
            }
            if ($this->pos === $expStart) {
                throw new JsonParseException("Invalid number literal at offset $start.");
            }
        }
        $raw = substr($this->s, $start, $this->pos - $start);
        if ($isPlainInt) {
            // int64 range check without float conversion (mirror STJ TryGetInt64 semantics)
            $negative = $raw[0] === '-';
            $digits = $negative ? substr($raw, 1) : $raw;
            $limit = $negative ? '9223372036854775808' : '9223372036854775807';
            $cmp = strlen($digits) === strlen($limit) ? strcmp($digits, $limit) : (strlen($digits) > strlen($limit) ? 1 : -1);
            if ($cmp <= 0) {
                return (int) $raw;
            }
        }
        return new JsonNumber($raw);
    }

    private function skipWs(): void
    {
        while ($this->pos < $this->len) {
            $ch = $this->s[$this->pos];
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                $this->pos++;
                continue;
            }
            return;
        }
    }
}
