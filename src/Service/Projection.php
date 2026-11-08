<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Service;

use Kandoo\Cloud\Json\Canonical;
use Kandoo\Cloud\Json\JsonObject;
use Kandoo\Cloud\Json\Parser;
use Kandoo\Cloud\Json\Writer;
use Kandoo\Cloud\Store\StoreGateway;
use Kandoo\Cloud\Support\RandomToken;
use Kandoo\Cloud\Support\Uuid;

/**
 * Public projection derivation (CLOUD-CONTRACT.md §7) — applied per newly appended entry,
 * in seq order, INSIDE the ingest transaction.
 *
 * Trigger (silent skip, legacy/low-detail records never invent data):
 *   operation_type == invoice.issue AND response_snapshot.status == SUCCEEDED AND
 *   response_snapshot.response.invoice is an object AND invoice.status == ISSUED.
 * Everything after the trigger is STRICT: any violation fails the whole transaction
 * (§7.3, frozen internal codes carried in ProjectionViolationException; the client sees
 * only the INTERNAL_ERROR envelope, U11).
 *
 * The Cloud stores ONLY the compact projection — never enriched, never computed beyond
 * the recorded line totals (§7.2 payable = Σ line_total_rials with overflow check), and
 * the issued_payload/issued_content_sha256 pair is stored exactly as delivered (U16).
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */
final class Projection
{
    public static function apply(JsonObject $entryDto, StoreGateway $gateway, string $storeId): void
    {
        // ── trigger checks (silent skip) ────────────────────────────────────────
        if ($entryDto->get('operation_type') !== 'invoice.issue') {
            return;
        }
        $snapshot = $entryDto->get('response_snapshot');
        if (!$snapshot instanceof JsonObject || $snapshot->get('status') !== 'SUCCEEDED') {
            return;
        }
        $snapshotResponse = $snapshot->get('response');
        if (!$snapshotResponse instanceof JsonObject) {
            return;
        }
        $invoice = $snapshotResponse->get('invoice');
        if (!$invoice instanceof JsonObject) {
            return;
        }
        if ($invoice->get('status') !== 'ISSUED') {
            return;
        }

        // ── strict rules (§7.1) ─────────────────────────────────────────────────
        $invoiceId = self::normalizeUuidField($invoice->get('invoice_id'), 'PUBLIC_INVOICE_ID_INVALID');
        $payload = $entryDto->get('payload'); // envelope check guaranteed a JsonObject
        $payloadInvoiceId = self::normalizeUuidField(
            $payload instanceof JsonObject ? $payload->get('invoice_id') : null,
            'PUBLIC_INVOICE_ID_INVALID'
        );
        if ($invoiceId !== $payloadInvoiceId) {
            throw new ProjectionViolationException('PUBLIC_INVOICE_ID_INVALID');
        }

        $displayNumber = $invoice->get('display_number');
        if (!is_int($displayNumber) || $displayNumber <= 0) {
            throw new ProjectionViolationException('PUBLIC_INVOICE_NUMBER_INVALID');
        }

        $issuedPayload = $invoice->get('issued_payload');
        $contentSha = $invoice->get('issued_content_sha256');
        $issuedAtRaw = $invoice->get('issued_at');
        $items = $invoice->get('items');
        if (!$issuedPayload instanceof JsonObject || !is_string($contentSha) || !is_string($issuedAtRaw) || !is_array($items)) {
            throw new ProjectionViolationException('PUBLIC_INVOICE_PROJECTION_INCOMPLETE');
        }
        // Verification only — the Cloud never rewrites the payload (D5, U16).
        if (Canonical::contentSha256($issuedPayload) !== $contentSha) {
            throw new ProjectionViolationException('PUBLIC_INVOICE_HASH_MISMATCH');
        }
        $issuedAtUtc = self::parseIssuedAtToStoredUtc($issuedAtRaw);

        if ($items === [] || !array_is_list($items)) {
            throw new ProjectionViolationException('PUBLIC_INVOICE_LINES_MISSING');
        }
        $lineItems = [];
        $payable = 0;
        foreach ($items as $item) {
            if (!$item instanceof JsonObject) {
                throw new ProjectionViolationException('PUBLIC_INVOICE_LINE_INVALID');
            }
            $description = $item->get('description');
            if (!is_string($description) || trim($description) === '') {
                throw new ProjectionViolationException('PUBLIC_INVOICE_TEXT_INVALID');
            }
            $quantity = $item->get('quantity_millis');
            $unitPrice = $item->get('unit_price_rials');
            $lineTotal = $item->get('line_total_rials');
            if (!is_int($quantity) || $quantity <= 0
                || !is_int($unitPrice) || $unitPrice < 0
                || !is_int($lineTotal) || $lineTotal < 0) {
                throw new ProjectionViolationException('PUBLIC_INVOICE_LINE_INVALID');
            }
            if ($payable > PHP_INT_MAX - $lineTotal) {
                throw new ProjectionViolationException('PUBLIC_INVOICE_PROJECTION_INVALID'); // checked 64-bit addition
            }
            $payable += $lineTotal;
            $lineItems[] = [
                'product_name' => $description,
                'quantity_millis' => $quantity,
                'unit_price_rials' => $unitPrice,
                'line_total_rials' => $lineTotal,
            ];
        }

        // ── store_name / customer_phone from the earliest matching draft (§7.2) ─
        $storeName = 'Store ' . $storeId; // fallback "Store {store_id:D}"
        $customerPhone = null;
        foreach ($gateway->entryRowsFor($storeId) as $entryRow) {
            $draft = self::parseStoredEntry($entryRow['entry_json']);
            if ($draft === null || $draft->get('operation_type') !== 'invoice.draft_create') {
                continue;
            }
            $outcome = $draft->get('outcome');
            if (!$outcome instanceof JsonObject || $outcome->get('status') !== 'SUCCEEDED') {
                continue;
            }
            $outcomeResponse = $outcome->get('response');
            $draftInvoice = $outcomeResponse instanceof JsonObject ? $outcomeResponse->get('invoice') : null;
            if (!$draftInvoice instanceof JsonObject) {
                continue;
            }
            $draftInvoiceId = Uuid::tryNormalize((string) ($draftInvoice->get('invoice_id') ?? ''));
            if ($draftInvoiceId !== $invoiceId) {
                continue;
            }
            $draftPayload = $draft->get('payload');
            if ($draftPayload instanceof JsonObject) {
                $draftStoreName = $draftPayload->get('store_name');
                if (is_string($draftStoreName) && trim($draftStoreName) !== '') {
                    $storeName = trim($draftStoreName);
                }
                $draftPhone = $draftPayload->get('customer_phone');
                if (is_string($draftPhone) && trim($draftPhone) !== '') {
                    $normalizedPhone = self::normalizePhone($draftPhone);
                    if ($normalizedPhone === null) {
                        throw new ProjectionViolationException('PUBLIC_INVOICE_TEXT_INVALID');
                    }
                    $customerPhone = $normalizedPhone;
                }
            }
            break; // earliest matching successful draft wins
        }

        // ── proposed v1.1 passthrough (§11, U14): recorded as delivered, never computed ─
        $v11 = self::v11Passthrough($invoice);

        // ── build and insert the projection row (first write wins; no token re-mint) ─
        $token = RandomToken::generate();
        $row = array_merge([
            'public_token' => $token,
            'public_token_hash' => RandomToken::hash($token),
            'digital_invoice_id' => RandomToken::uuidV4(),
            'invoice_id' => $invoiceId,
            'store_id' => $storeId,
            'store_name' => $storeName,
            'display_number' => $displayNumber,
            'lifecycle_status' => 'ISSUED',
            'issued_at' => $issuedAtUtc,
            'issued_payload' => Writer::encode($issuedPayload),   // as delivered (U16)
            'issued_content_sha256' => $contentSha,
            'payable_rials' => $payable,
            'line_items' => Writer::encode($lineItems),
            'customer_phone' => $customerPhone,
        ], $v11);
        $gateway->insertProjectionIgnore($row);
    }

    private static function parseStoredEntry(string $entryJson): ?JsonObject
    {
        try {
            $node = Parser::parse($entryJson);
        } catch (\Throwable) {
            return null;
        }
        return $node instanceof JsonObject ? $node : null;
    }

    private static function normalizeUuidField(mixed $value, string $violationCode): string
    {
        $normalized = is_string($value) ? Uuid::tryNormalize($value) : null;
        if ($normalized === null || $normalized === Canonical::ZERO_UUID) {
            throw new ProjectionViolationException($violationCode);
        }
        return $normalized;
    }

    /**
     * Wire issued_at (ISO-8601 UTC, 'Z' or '+00:00') → stored UTC 'Y-m-d H:i:s[.u]'.
     * Fractional seconds are kept only when non-zero, trailing zeros trimmed (U9).
     */
    public static function parseIssuedAtToStoredUtc(string $issuedAt): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}[Tt ]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/', $issuedAt) !== 1) {
            throw new ProjectionViolationException('PUBLIC_INVOICE_PROJECTION_INCOMPLETE');
        }
        try {
            $dt = new \DateTimeImmutable($issuedAt);
        } catch (\Throwable) {
            throw new ProjectionViolationException('PUBLIC_INVOICE_PROJECTION_INCOMPLETE');
        }
        $utc = $dt->setTimezone(new \DateTimeZone('UTC'));
        $micro = $utc->format('u');
        $base = $utc->format('Y-m-d H:i:s');
        if ($micro === '000000') {
            return $base;
        }
        return $base . '.' . rtrim($micro, '0');
    }

    /** International '+' phone syntax required; formatting characters stripped (§7.2). */
    private static function normalizePhone(string $phone): ?string
    {
        $cleaned = str_replace([' ', '-', '(', ')', '.'], '', trim($phone));
        if (preg_match('/^\+\d{5,15}$/', $cleaned) !== 1) {
            return null;
        }
        return $cleaned;
    }

    /**
     * Proposed v1.1 (U14): pass through ONLY what the Hub recorded — customer_name,
     * payments[], currency_unit (rial|toman), remaining_balance (RECORDED, never computed).
     * Absent or null → column stays NULL. A recorded value of the wrong shape fails closed
     * (the ingest transaction rolls back) — the Cloud never guesses and never enriches.
     * @return array<string, mixed>
     */
    private static function v11Passthrough(JsonObject $invoice): array
    {
        $out = ['customer_name' => null, 'payments' => null, 'currency_unit' => null, 'remaining_balance' => null];

        if ($invoice->has('customer_name')) {
            $name = $invoice->get('customer_name');
            if ($name !== null) {
                $length = is_string($name) ? preg_match_all('/./us', $name) : false;
                if (!is_string($name) || $length === false || $length < 1 || $length > 255) {
                    throw new ProjectionViolationException('PUBLIC_INVOICE_TEXT_INVALID');
                }
                $out['customer_name'] = $name;
            }
        }

        if ($invoice->has('payments')) {
            $payments = $invoice->get('payments');
            if ($payments !== null) {
                if (!is_array($payments) || ($payments !== [] && !array_is_list($payments))) {
                    throw new ProjectionViolationException('PUBLIC_INVOICE_PROJECTION_INVALID');
                }
                foreach ($payments as $payment) {
                    if (!$payment instanceof JsonObject || count($payment) !== 2 || !$payment->has('type') || !$payment->has('amount')) {
                        throw new ProjectionViolationException('PUBLIC_INVOICE_PROJECTION_INVALID');
                    }
                    $type = $payment->get('type');
                    $amount = $payment->get('amount');
                    if (!in_array($type, ['card-reader', 'card-to-card', 'cash', 'credit'], true)
                        || !is_int($amount) || $amount < 0) {
                        throw new ProjectionViolationException('PUBLIC_INVOICE_PROJECTION_INVALID');
                    }
                }
                $out['payments'] = Writer::encode($payments); // as delivered (order preserved)
            }
        }

        if ($invoice->has('currency_unit')) {
            $unit = $invoice->get('currency_unit');
            if ($unit !== null) {
                if (!in_array($unit, ['rial', 'toman'], true)) {
                    throw new ProjectionViolationException('PUBLIC_INVOICE_PROJECTION_INVALID');
                }
                $out['currency_unit'] = $unit; // recorded per-store unit, never assumed
            }
        }

        if ($invoice->has('remaining_balance')) {
            $balance = $invoice->get('remaining_balance');
            if ($balance !== null) {
                if (!is_int($balance) || $balance < 0) {
                    throw new ProjectionViolationException('PUBLIC_INVOICE_PROJECTION_INVALID');
                }
                $out['remaining_balance'] = $balance; // RECORDED value — never computed
            }
        }

        return $out;
    }
}
