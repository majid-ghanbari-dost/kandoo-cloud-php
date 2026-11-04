<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Http;

/**
 * Immutable HTTP request value object.
 *
 * Privacy rule (§6.6, PM ORDER 9 P-03): the application NEVER logs request URIs, query
 * strings, bodies or tokens; this object exists only for the duration of one request and
 * its contents never reach any log sink.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class Request
{
    /**
     * @param array<string, string> $headers  lower-cased header names → values
     * @param array<string, string> $query    query parameters (last value wins)
     */
    public function __construct(
        private string $method,
        private string $path,
        private array $headers = [],
        private array $query = [],
        private string $body = '',
        private ?int $declaredContentLength = null,
        private string $clientIp = '',
    ) {
        $this->method = strtoupper($method);
    }

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';
        if ($path === '') {
            $path = '/';
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $server => $header) {
            if (isset($_SERVER[$server]) && is_scalar($_SERVER[$server])) {
                $headers[$header] = (string) $_SERVER[$server];
            }
        }

        $query = [];
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $parsed);
            foreach ($parsed as $name => $value) {
                if (is_string($name)) {
                    $query[$name] = is_array($value) ? (string) end($value) : (string) $value;
                }
            }
        }

        $body = file_get_contents('php://input');
        $declared = isset($headers['content-length']) && preg_match('/^\d+$/', $headers['content-length']) === 1
            ? (int) $headers['content-length']
            : null;

        return new self(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $headers,
            $query,
            is_string($body) ? $body : '',
            $declared,
            is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '',
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, string> */
    public function query(): array
    {
        return $this->query;
    }

    public function queryParam(string $name): ?string
    {
        return $this->query[$name] ?? null;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function bodyLength(): int
    {
        return strlen($this->body);
    }

    /**
     * The Content-Length the client DECLARED (§5.4 step 2 checks the declared header
     * before parsing, exactly like the frozen Cloud). Null when absent.
     */
    public function declaredContentLength(): ?int
    {
        return $this->declaredContentLength;
    }

    public function clientIp(): string
    {
        return $this->clientIp;
    }
}
