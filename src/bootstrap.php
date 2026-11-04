<?php

declare(strict_types=1);

/**
 * Bootstrap for the Kandoo Cloud PHP application (فاکتور دیجیتال کندو / Kandoo Digital Invoice).
 *
 * Dependency-free: registers a tiny PSR-4-style autoloader for the Kandoo\Cloud\ namespace.
 * The deployed tree needs NO shell and NO Composer (decision D2); this file is required by
 * both the web front controller (public/index.php) and the CLI tools (bin/*.php).
 */

if (!defined('KANDOO_CLOUD_BOOTSTRAPPED')) {
    define('KANDOO_CLOUD_BOOTSTRAPPED', 1);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Kandoo\\Cloud\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        // Defence in depth: never resolve class names that try to escape src/.
        if (str_contains($relative, '..')) {
            return;
        }
        $file = __DIR__ . '/' . $relative . '.php';
        if (is_file($file)) {
            require $file;
        }
    });

    /**
     * Application root (the directory that CONTAINS the web root `public/`).
     * Override with the KANDOO_APP_ROOT environment variable if the host layout differs.
     */
    function kandoo_app_root(): string
    {
        $env = getenv('KANDOO_APP_ROOT');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/\\');
        }
        return dirname(__DIR__);
    }
}
