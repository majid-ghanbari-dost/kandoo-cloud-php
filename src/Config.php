<?php

declare(strict_types=1);

namespace Kandoo\Cloud;

/**
 * Configuration loading — FAIL CLOSED (PM ORDER 9 P-02).
 *
 * The real config.php lives OUTSIDE the web root (one level above public/) and is never
 * committed. If it is missing or invalid the application refuses to run with a plain
 * error message that leaks nothing (no paths, no values, no stack details on the web).
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice). No credentials are ever stored
 * in this repository; config.example.php carries obviously fake TEST-… placeholders.
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values validated configuration */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function defaultPath(): string
    {
        $env = getenv('KANDOO_CONFIG');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        return kandoo_app_root() . '/config.php';
    }

    /**
     * @throws ConfigException when the file is missing, unreadable or invalid.
     *         The exception message is developer-facing (CLI only); the web front
     *         controller converts it to a plain, detail-free error.
     */
    public static function load(?string $path = null): self
    {
        $path ??= self::defaultPath();
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigException(
                'Configuration file not found. Copy config.example.php to config.php '
                . '(one level above the web root) and fill in real values.'
            );
        }
        /** @var mixed $raw */
        $raw = require $path;
        if (!is_array($raw)) {
            throw new ConfigException('Configuration file must return an array.');
        }
        return new self(self::validate($raw));
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function validate(array $raw): array
    {
        $config = [];

        $baseUrl = $raw['base_url'] ?? null;
        if (!is_string($baseUrl) || $baseUrl === ''
            || !(str_starts_with($baseUrl, 'https://') || str_starts_with($baseUrl, 'http://'))) {
            throw new ConfigException('base_url must be a non-empty http(s) URL (decision D4: the single source of absolute links).');
        }
        $config['base_url'] = rtrim($baseUrl, '/');

        $db = $raw['db'] ?? null;
        if (!is_array($db)) {
            throw new ConfigException('db must be an array with host, port, name, user, pass, charset.');
        }
        foreach (['host', 'name', 'user'] as $key) {
            if (!isset($db[$key]) || !is_string($db[$key]) || $db[$key] === '') {
                throw new ConfigException("db.$key must be a non-empty string.");
            }
        }
        if (!array_key_exists('pass', $db) || !is_string($db['pass'])) {
            throw new ConfigException('db.pass must be a string.');
        }
        $port = $db['port'] ?? 3306;
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new ConfigException('db.port must be an integer between 1 and 65535.');
        }
        $charset = $db['charset'] ?? 'utf8mb4';
        if (!is_string($charset) || $charset === '') {
            throw new ConfigException('db.charset must be a non-empty string.');
        }
        $config['db'] = [
            'host' => $db['host'], 'port' => $port, 'name' => $db['name'],
            'user' => $db['user'], 'pass' => $db['pass'], 'charset' => $charset,
        ];

        $key = $raw['replication_key'] ?? null;
        if ($key !== null && (!is_string($key) || $key === '')) {
            throw new ConfigException('replication_key must be null (frozen-compatible) or a non-empty string.');
        }
        $config['replication_key'] = $key;

        // Password hash is generated outside the repository with password_hash().
        $adminHash = $raw['admin_password_hash'] ?? null;
        if ($adminHash !== null && (!is_string($adminHash)
            || (password_get_info($adminHash)['algoName'] ?? 'unknown') === 'unknown')) {
            throw new ConfigException('admin_password_hash must be null or a password_hash digest.');
        }
        if ($adminHash !== null && is_string($key) && password_verify($key, $adminHash)) {
            throw new ConfigException('The administrative password must differ from the replication key.');
        }
        $config['admin_password_hash'] = $adminHash;

        foreach (['public_rate_limit_per_minute' => 60, 'ingest_rate_limit_per_minute' => 120] as $name => $default) {
            $value = $raw[$name] ?? $default;
            if (!is_int($value) || $value < 0) {
                throw new ConfigException("$name must be a non-negative integer (0 disables).");
            }
            $config[$name] = $value;
        }

        $gate = $raw['public_legal_copy_required'] ?? false;
        if (!is_bool($gate)) {
            throw new ConfigException('public_legal_copy_required must be a boolean (D14 gate, §6.5).');
        }
        $config['public_legal_copy_required'] = $gate;

        $env = $raw['env'] ?? 'production';
        if (!is_string($env) || $env === '') {
            throw new ConfigException('env must be a non-empty string.');
        }
        $config['env'] = $env;
        $bypass = $raw['replication_auth_bypass'] ?? false;
        if (!is_bool($bypass) || ($bypass && !in_array($env, ['development', 'testing'], true))) {
            throw new ConfigException('replication_auth_bypass is allowed only in development or testing.');
        }
        $config['replication_auth_bypass'] = $bypass;

        return $config;
    }

    /** @return array<string, mixed> the full validated configuration */
    public function all(): array
    {
        return $this->values;
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function baseUrl(): string
    {
        /** @var string */
        return $this->values['base_url'];
    }
}
