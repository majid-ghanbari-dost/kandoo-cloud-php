<?php

declare(strict_types=1);

/**
 * Kandoo Cloud PHP — EXAMPLE configuration (فاکتور دیجیتال کندو / Kandoo Digital Invoice).
 *
 * HOW TO USE (documented layout, CLOUD-CONTRACT.md §10.4):
 *   1. Upload the whole application tree to the host.
 *   2. Point the host's document root at the `public/` directory ONLY.
 *   3. Copy this file to `config.php` ONE LEVEL ABOVE the web root (next to `src/`),
 *      i.e. `<app root>/config.php`, and replace every placeholder with real values.
 *   4. `config.php` is git-ignored and NEVER committed — no credentials live in any repo, ever.
 *
 * EVERY value below is an OBVIOUSLY FAKE placeholder for testing (TEST-… / .invalid domains).
 * If `config.php` is missing or invalid the application FAILS CLOSED with a plain error.
 */

return [
    // ── Base URL (decision D4) ────────────────────────────────────────────────
    // The single source for every absolute link this Cloud generates.
    // Never hardcode a domain anywhere else. FAKE placeholder:
    'base_url' => 'https://invoice.TEST-example.invalid',

    // ── MariaDB 10.6 connection ───────────────────────────────────────────────
    // All values are FAKE placeholders.
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'TEST_kandoo_cloud',
        'user'    => 'TEST_kandoo_user',
        'pass'    => 'TEST-DB-PASSWORD',
        'charset' => 'utf8mb4',
    ],

    // ── Replication auth (CLOUD-CONTRACT.md §4, UNCONFIRMED U1) ───────────────
    // null  = frozen-compatible behaviour: no Authorization-style header required
    //         (the frozen Hub wire sends none).
    // string = every ingest request must carry X-Kandoo-Replication-Key equal to it
    //         (constant-time compare; mismatch → 401 UNAUTHENTICATED).
    // FAKE example value if you enable it: 'TEST-REPLICATION-KEY'.
    'replication_key' => null,
    // Only development/testing may set this to true; never enable in production.
    'replication_auth_bypass' => false,

    // Optional independent password_hash() digest, configured outside the repository.
    // null disables administration, including the activation command.
    'admin_password_hash' => null,

    // Customer auth remains disabled until P-08 configures a real delivery adapter.
    // When enabled, provide all numerical policy values and an independent high-entropy
    // otp_pepper exclusively in config.php outside the repository. Telegram is optional.
    'customer_auth' => ['enabled' => false],

    // Faraz is disabled by default; credentials and pattern identifiers belong in
    // the external config only. Dry mode is permitted only in development/testing.
    'faraz_sms' => ['enabled' => false],

    // Retention after expiration / creation, in seconds (P-15).
    'retention' => ['otp_challenge_seconds'=>86400, 'expired_session_seconds'=>2592000,
        'idempotency_seconds'=>2592000, 'undeliverable_sms_seconds'=>7776000],

    // ── Rate limits (fixed 1-minute windows, DB-backed) ──────────────────────
    // Public invoice reads per client IP (§6.2). Default 60.
    'public_rate_limit_per_minute' => 60,
    // Ingest batches per store (§5.4 note, UNCONFIRMED U5). 0 disables. Default 120.
    'ingest_rate_limit_per_minute' => 120,

    // ── Legal-copy release gate (D14, §6.5, UNCONFIRMED U3) ──────────────────
    // false = serve the public page with the placeholder legal copy (PM ORDER 9).
    // true  = reproduce the frozen production 503 LEGAL_COPY_UNAVAILABLE exactly.
    // No legal or tax claims are made by this product in either mode.
    'public_legal_copy_required' => false,

    // ── Diagnostics ───────────────────────────────────────────────────────────
    // Environment label; 'production' is recommended on a live host. Never enables
    // verbose errors — configuration problems always fail closed with a plain error.
    'env' => 'production',
];
