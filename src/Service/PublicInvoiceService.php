<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Service;

use Kandoo\Cloud\Http\Envelopes;
use Kandoo\Cloud\Http\Html;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Http\Response;
use Kandoo\Cloud\Json\Parser;
use Kandoo\Cloud\Store\StoreGateway;
use Kandoo\Cloud\Support\Clock;
use Kandoo\Cloud\Support\RandomToken;

/**
 * Public invoice read — GET /i/{token} (CLOUD-CONTRACT.md §6).
 *
 * Order (frozen): rate limit BEFORE lookup (§6.2) → token shape → UNIQUE hash-index
 * lookup (§6.1, never a plaintext scan) → constant-time re-verification of the stored
 * token → lifecycle visibility → legal-copy gate (§6.5) → content negotiation (§6.4).
 *
 * Identical-404 rule (§6.3): malformed, unknown, unauthorized and invisible tokens all
 * collapse into ONE byte-identical response; the token appears nowhere in body or
 * headers; nothing is logged (§6.6).
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class PublicInvoiceService
{
    private const BASE_HEADERS = [
        'Cache-Control' => 'no-store',
        'Vary' => 'Accept',
    ];

    /** @param array<string, mixed> $config */
    public function __construct(
        private array $config,
        private StoreGateway $gateway,
        private Clock $clock,
    ) {
    }

    public function handle(string $token, Request $request): Response
    {
        // §6.2 — fixed 1-minute window per client address, BEFORE token lookup.
        $limit = (int) ($this->config['public_rate_limit_per_minute'] ?? 60);
        if ($limit > 0) {
            $now = $this->clock->nowUnix();
            $windowStart = intdiv($now, 60) * 60;
            $peer = \Kandoo\Cloud\Http\PeerAddress::effective($request, (array) ($this->config['trusted_proxies'] ?? []));
            $clientKey = $peer !== '' ? $peer : 'unknown';
            if (!$this->gateway->rateHit('public', $clientKey, $windowStart, $limit)) {
                $retryAfter = max(1, $windowStart + 60 - $now);
                $envelope = Envelopes::publicRateLimited();
                return Response::json(
                    $envelope['status'],
                    $envelope['body'],
                    array_merge(self::BASE_HEADERS, ['Retry-After' => (string) $retryAfter]),
                );
            }
        }

        $row = $this->findVisibleInvoice($token);
        if ($row === null) {
            return $this->notFound();
        }

        // §6.5 — legal-copy release gate (D14) applies AFTER the invoice was found.
        if (($this->config['public_legal_copy_required'] ?? false) === true) {
            $envelope = Envelopes::legalCopyUnavailable();
            return Response::json($envelope['status'], $envelope['body'], self::BASE_HEADERS);
        }

        // §6.4 — content negotiation.
        if ($this->wantsJson($request)) {
            return Response::json(200, $this->viewArray($row), self::BASE_HEADERS);
        }
        return Response::html(200, Html::renderInvoicePage($row), self::BASE_HEADERS);
    }

    /** @return array<string, mixed>|null */
    private function findVisibleInvoice(string $token): ?array
    {
        // Shape first: anything malformed collapses into the identical 404 (§6.1/§6.3).
        if (!RandomToken::hasValidShape($token)) {
            return null;
        }
        $tokenHash = RandomToken::hash($token);
        $row = $this->gateway->findProjectionByTokenHash($tokenHash);
        if ($row === null) {
            return null;
        }
        // Constant-time re-verification: hash the STORED raw token and compare digests
        // with a fixed-time primitive (§6.1). Any failure behaves exactly like "unknown".
        if (!hash_equals(hash('sha256', (string) $row['public_token']), $tokenHash)) {
            return null;
        }
        if (!in_array($row['lifecycle_status'], ['ISSUED', 'REVOKED', 'SUPERSEDED'], true)) {
            return null;
        }
        return $row;
    }

    private function wantsJson(Request $request): bool
    {
        $format = $request->queryParam('format');
        if ($format !== null && strcasecmp($format, 'json') === 0) {
            return true;
        }
        $accept = strtolower($request->header('accept') ?? '');
        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }

    private function notFound(): Response
    {
        $envelope = Envelopes::publicInvoiceNotFound();
        return Response::json($envelope['status'], $envelope['body'], self::BASE_HEADERS);
    }

    /**
     * §8 PublicInvoiceView — exact frozen field order (golden G-017), plus proposed v1.1
     * passthrough fields ONLY when the Hub recorded them (§11, U14). The issued payload is
     * re-emitted exactly as stored (order and numeric literals preserved, U16).
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function viewArray(array $row): array
    {
        $issuedAt = self::formatIssuedAtIso((string) $row['issued_at']);
        $view = [
            'product_name' => 'Kandoo digital invoice',
            'store_name' => $row['store_name'],
            'display_number' => $row['display_number'],
            'lifecycle_status' => $row['lifecycle_status'],
            'issued_at' => $issuedAt,
            'digital_invoice' => [
                'invoice_id' => $row['invoice_id'],
                'store_id' => $row['store_id'],
                'issued_payload' => Parser::parse((string) $row['issued_payload']),
                'issued_content_sha256' => $row['issued_content_sha256'],
                'issued_at' => $issuedAt,
            ],
            'legal_copy' => [
                'resource_id' => 'D14-PLACEHOLDER',
                'version' => '0',
                'content' => 'D14 placeholder — needs lawyer approval',
                'signature' => 'NOT_SIGNED_NEEDS_LAWYER_APPROVAL',
            ],
            'legal_copy_status' => 'needs lawyer approval',
        ];

        // v1.1 (U14) — additive, optional, recorded-only; v1.0 output stays byte-identical.
        if (($row['customer_name'] ?? null) !== null) {
            $view['customer_name'] = $row['customer_name'];
        }
        if (($row['payments'] ?? null) !== null) {
            $view['payments'] = Parser::parse((string) $row['payments']);
        }
        if (($row['currency_unit'] ?? null) !== null) {
            $view['currency_unit'] = $row['currency_unit'];
        }
        if (($row['remaining_balance'] ?? null) !== null) {
            $view['remaining_balance'] = $row['remaining_balance'];
        }
        return $view;
    }

    /** Stored UTC 'Y-m-d H:i:s[.u]' → wire 'YYYY-MM-DDTHH:MM:SS[.frac]+00:00' (U9). */
    public static function formatIssuedAtIso(string $storedUtc): string
    {
        $parts = explode('.', $storedUtc, 2);
        $base = str_replace(' ', 'T', $parts[0]);
        $fraction = '';
        if (isset($parts[1]) && $parts[1] !== '' && rtrim($parts[1], '0') !== '') {
            $fraction = '.' . rtrim($parts[1], '0');
        }
        return $base . $fraction . '+00:00';
    }
}
