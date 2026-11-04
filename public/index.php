<?php

declare(strict_types=1);

/**
 * Front controller — فاکتور دیجیتال کندو (Kandoo Digital Invoice) Cloud.
 *
 * Documented host layout (CLOUD-CONTRACT.md §10.4): the host document root points at this
 * public/ directory ONLY. config.php lives one level above (never web-accessible), and
 * src/, docs/, migrations/, tests/, bin/ are denied by .htaccess as defense in depth.
 * Deploy = upload the tree, set the doc root here, create ../config.php from
 * ../config.example.php. No shell, no Composer on the host (decision D2).
 *
 * Privacy: this file logs NOTHING — no request URI, no query string, no body, no token
 * (§6.6). Failures produce plain, detail-free responses (fail closed).
 */

use Kandoo\Cloud\Config;
use Kandoo\Cloud\ConfigException;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Kernel;

ini_set('display_errors', '0');
error_reporting(E_ALL);

$appRoot = dirname(__DIR__);
$bootstrap = $appRoot . '/src/bootstrap.php';
if (!is_file($bootstrap)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Kandoo Cloud is not installed correctly.';
    exit;
}
require $bootstrap;

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$path = rawurldecode($path);

// Real files under /assets/ are served directly (local fonts only — no CDN, §9).
// Apache normally serves them before reaching PHP; this branch keeps non-Apache and
// built-in-server setups byte-safe and prevents path traversal.
if (str_starts_with($path, '/assets/')) {
    $relative = ltrim($path, '/');
    $file = realpath($appRoot . '/public/' . $relative);
    $assetsRoot = realpath($appRoot . '/public/assets');
    if ($file !== false && $assetsRoot !== false && str_starts_with($file, $assetsRoot . DIRECTORY_SEPARATOR) && is_file($file)) {
        $mime = match (strtolower((string) pathinfo($file, PATHINFO_EXTENSION))) {
            'ttf'   => 'font/ttf',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'css'   => 'text/css; charset=utf-8',
            'svg'   => 'image/svg+xml',
            'png'   => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'ico'   => 'image/x-icon',
            'txt'   => 'text/plain; charset=utf-8',
            default => 'application/octet-stream',
        };
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($file));
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }
}

try {
    $config = Config::load();
    $response = (new Kernel($config->all()))->handle(Request::fromGlobals());

    http_response_code($response->status());
    foreach ($response->headers() as $name => $value) {
        header($name . ': ' . $value, true);
    }
    header('X-Content-Type-Options: nosniff', true);
    header('Referrer-Policy: no-referrer', true);
    header('Content-Length: ' . strlen($response->body()), true);
    // HEAD: identical headers (including Content-Length), empty body.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $response->body();
    }
} catch (ConfigException) {
    // Fail closed with a plain error — no details, no paths, no values (P-02 rule).
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Kandoo Cloud is not configured.';
} catch (Throwable) {
    // Generic detail-free envelope; the exception message never reaches the client and is
    // never logged with request context (§6.6).
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo '{"code":"INTERNAL_ERROR","message":"An unexpected server failure occurred.","retry_hint":{"action":"RETRY_AFTER"}}';
}
