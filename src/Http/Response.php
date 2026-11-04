<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Http;

use Kandoo\Cloud\Json\Writer;

/**
 * Immutable HTTP response value object with byte-exact body control.
 *
 * JSON bodies are serialized by the application's own writer (src/Json/Writer.php) so the
 * exact field order and escaping of the frozen contract are reproduced byte-for-byte —
 * never left to a generic encoder.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class Response
{
    /**
     * @param array<string, string> $headers ordered response headers (output casing)
     */
    public function __construct(
        private int $status,
        private array $headers = [],
        private string $body = '',
    ) {
    }

    /**
     * @param mixed $data ordered PHP structure (arrays preserve insertion order)
     */
    public static function json(int $status, mixed $data, array $extraHeaders = []): self
    {
        $headers = array_merge(['Content-Type' => 'application/json; charset=utf-8'], $extraHeaders);
        return new self($status, $headers, Writer::encode($data));
    }

    public static function html(int $status, string $html, array $extraHeaders = []): self
    {
        $headers = array_merge(['Content-Type' => 'text/html; charset=utf-8'], $extraHeaders);
        return new self($status, $headers, $html);
    }

    public static function emptyBody(int $status, array $headers = []): self
    {
        return new self($status, $headers, '');
    }

    public function withHeader(string $name, string $value): self
    {
        $headers = $this->headers;
        $headers[$name] = $value;
        return new self($this->status, $headers, $this->body);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === strtolower($name)) {
                return $value;
            }
        }
        return null;
    }

    public function body(): string
    {
        return $this->body;
    }
}
