<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Http;

use Kandoo\Cloud\Json\JsonObject;
use Kandoo\Cloud\Json\Parser;
use Kandoo\Cloud\Service\PublicInvoiceService;
use Kandoo\Cloud\Support\Jalali;

/**
 * Public invoice page renderer (CLOUD-CONTRACT.md §9) — فاکتور دیجیتال کندو.
 *
 * Invariants (pinned by golden G-018 markers): single light page; Persian RTL; header
 * line "فاکتور دیجیتال کندو"; design tokens as CSS custom properties (honey #F2A900,
 * ink #2B1D0E, cream #FFF8E7, wax #F5E9C8, green #256B43, review #9A3B0A); local
 * Vazirmatn @font-face from /assets/fonts/ (no CDN, no external request); Jalali issue
 * date in Asia/Tehran; thousand-grouped integers; all dynamic text HTML-escaped; the
 * token never appears; NO "http://" or "https://" anywhere in the document; print rules.
 *
 * Truthfulness (v1.0): when customer_name / payments are not recorded in the issued
 * data, a data-gap section says so explicitly instead of inventing values. When the
 * proposed v1.1 fields ARE recorded (U14), the real recorded values replace the gap
 * copy — remaining_balance is displayed as recorded, never computed; the currency unit
 * word follows the recorded currency_unit, never an assumption.
 *
 * No legal or tax claims: the legal block is the D14 placeholder copy verbatim.
 */
final class Html
{
    /** Customer-only adaptation; preserve the frozen public renderer. */
    public static function renderCustomerInvoicePage(array $row): string
    {
        $page = self::renderInvoicePage($row);
        $page = str_replace('--radius: 14px;', '--radius: 16px;', $page);
        return str_replace('</style>', '@media(max-width:600px){.sheet{margin:12px;padding:18px}.facts{grid-template-columns:auto 1fr}}' . '</style>', $page);
    }

    /** @param array<string, mixed> $row projection row */
    public static function renderInvoicePage(array $row): string
    {
        $storeName = self::e((string) $row['store_name']);
        $displayNumber = self::n0((int) $row['display_number']);
        $lifecycle = (string) $row['lifecycle_status'];
        $invoiceId = self::e((string) $row['invoice_id']);
        $issuedAtIso = self::e(PublicInvoiceService::formatIssuedAtIso((string) $row['issued_at']));
        $tehran = Jalali::formatTehranFromStoredUtc((string) $row['issued_at']);
        $payable = self::n0((int) $row['payable_rials']);

        $lineItems = [];
        $decoded = Parser::parse((string) $row['line_items']);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if ($item instanceof JsonObject) {
                    $lineItems[] = $item;
                }
            }
        }

        $rows = '';
        foreach ($lineItems as $item) {
            $rows .= '<tr>'
                . '<td>' . self::e((string) $item->get('product_name')) . '</td>'
                . '<td class="num"><bdi>' . self::n0((int) $item->get('quantity_millis')) . '</bdi></td>'
                . '<td class="num"><bdi>' . self::n0((int) $item->get('unit_price_rials')) . '</bdi></td>'
                . '<td class="num"><bdi>' . self::n0((int) $item->get('line_total_rials')) . '</bdi></td>'
                . '</tr>';
        }

        // v1.1 recorded values (U14) — rendered only when present; gap copy for the rest.
        $customerName = $row['customer_name'] ?? null;
        $paymentsRaw = $row['payments'] ?? null;
        $currencyUnit = $row['currency_unit'] ?? null;
        $remainingBalance = $row['remaining_balance'] ?? null;
        $unitWord = $currencyUnit === 'toman' ? 'تومان' : 'ریال';

        $customerFact = '';
        $gapParagraphs = '';
        if (is_string($customerName) && $customerName !== '') {
            $customerFact = '<dt>نام مشتری</dt><dd>' . self::e($customerName) . '</dd>';
        } else {
            $gapParagraphs .= '<p>در داده صدور این فاکتور، فیلد جداگانه‌ای برای <code dir="ltr">customer_name</code> (نام مشتری) ثبت نشده است.</p>';
        }

        $paymentsSection = '';
        if (is_string($paymentsRaw) && $paymentsRaw !== '') {
            $paymentRows = '';
            $decodedPayments = Parser::parse($paymentsRaw);
            if (is_array($decodedPayments)) {
                foreach ($decodedPayments as $payment) {
                    if (!$payment instanceof JsonObject) {
                        continue;
                    }
                    $paymentRows .= '<tr>'
                        . '<td>' . self::e(self::paymentLabel((string) $payment->get('type'))) . '</td>'
                        . '<td class="num"><bdi>' . self::n0((int) $payment->get('amount')) . '</bdi> ' . $unitWord . '</td>'
                        . '</tr>';
                }
            }
            $paymentsSection = '<section class="payments"><h2>پرداخت‌ها</h2>'
                . '<table><thead><tr><th>روش</th><th class="num">مبلغ</th></tr></thead>'
                . '<tbody>' . $paymentRows . '</tbody></table>';
            $paymentsSection .= '</section>';
        } else {
            $gapParagraphs .= '<p>روش پرداخت (<code dir="ltr">payment_method</code>) به‌صورت فیلد جداگانه در داده صدور این فاکتور موجود نیست.</p>';
        }
        if ($remainingBalance !== null) {
            $balanceClass = (int) $remainingBalance > 0 ? 'balance due' : 'balance settled';
            $paymentsSection .= '<section class="payments"><p class="' . $balanceClass . '">مانده باقی‌مانده (ثبت‌شده): <bdi>'
                . self::n0((int) $remainingBalance) . '</bdi> ' . $unitWord . '</p></section>';
        }

        $gapSection = $gapParagraphs === '' ? '' :
            '<section class="gap"><h2>محدودیت‌های داده صدور</h2>' . $gapParagraphs
            . '<p>این صفحه فقط همان داده صدور را نشان می‌دهد؛ هیچ مقدار تازه‌ای محاسبه یا جایگزین نمی‌شود.</p></section>';

        $statusLabel = self::e(self::lifecycleLabel($lifecycle));
        $statusClass = $lifecycle === 'ISSUED' ? 'status issued' : 'status review';

        return <<<HTML
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>فاکتور دیجیتال کندو — شماره {$displayNumber}</title>
<style>
@font-face { font-family: "Vazirmatn"; src: url("/assets/fonts/Vazirmatn-Regular.ttf") format("truetype"); font-weight: 400; font-style: normal; font-display: swap; }
@font-face { font-family: "Vazirmatn"; src: url("/assets/fonts/Vazirmatn-Bold.ttf") format("truetype"); font-weight: 700; font-style: normal; font-display: swap; }
:root {
  --honey: #F2A900;
  --ink: #2B1D0E;
  --cream: #FFF8E7;
  --wax: #F5E9C8;
  --green: #256B43;
  --review: #9A3B0A;
  --paper: #FFFFFF;
  --muted: #6B5A42;
  --line: #E8D9B5;
  --space-1: 4px;
  --space-2: 8px;
  --space-3: 16px;
  --space-4: 24px;
  --space-5: 32px;
  --radius: 14px;
  color-scheme: light;
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--wax); color: var(--ink); font-family: "Vazirmatn", Tahoma, Arial, sans-serif; line-height: 1.9; }
.sheet { max-width: 46rem; margin: 20px auto; background: var(--paper); border: 0; border-radius: 16px; padding: 20px 18px; box-shadow: 0 1px 0 rgba(43,29,14,.08); }
.brand-header { display:flex; align-items:center; gap:12px; }
.brand-header svg { flex:none; }
.brand-header .store { font-size:20px; line-height:1.4; }
.tear { position:relative; border:0; border-top:2px dashed #D9C9A0; margin:18px -18px; }
.tear::before, .tear::after { content:""; position:absolute; top:-11px; width:20px; height:20px; border-radius:50%; background:var(--wax); }
.tear::before { right:-10px; } .tear::after { left:-10px; }
.print-hint { text-align:center; color:var(--muted); font-size:.8rem; }
.eyebrow { margin: 0; color: var(--green); font-weight: 700; letter-spacing: .02em; }
h1 { font-size: 1.35rem; margin: var(--space-1) 0 var(--space-2); }
h2 { font-size: 1rem; margin: 0 0 var(--space-2); }
.store { margin: 0; font-size: 1.05rem; font-weight: 700; }
.facts { display: grid; grid-template-columns: auto 1fr; gap: 8px 12px; padding:0; margin:14px 0; }
.facts dt { color: var(--muted); font-size: .85rem; white-space: nowrap; }
.facts dd { margin: 0; font-weight: 700; text-align:left; overflow-wrap:anywhere; }
.tz { color: var(--muted); font-size: .75rem; font-weight: 400; }
.status { display:inline-block; padding:2px 12px; border-radius:999px; color:#fff; font-size:.85rem; }
.status.issued { background:var(--green); }
.status.review { background:var(--review); }
.gap, .payments { border: 1px dashed var(--line); background: var(--cream); border-radius: var(--radius); padding: var(--space-3); margin: var(--space-4) 0; font-size: .9rem; }
.gap h2, .payments h2 { color: var(--review); }
.gap p { margin: var(--space-1) 0; color: var(--muted); }
.gap code { background: var(--paper); border: 1px solid var(--line); border-radius: 6px; padding: 0 var(--space-1); font-size: .8rem; }
.payments table { width: 100%; border-collapse: collapse; }
.payments th, .payments td { border: 1px solid var(--line); padding: var(--space-2); text-align: right; }
.payments th { background: var(--wax); }
.balance { font-weight: 700; margin: var(--space-2) 0 0; }
.balance.due { color: var(--review); }
.balance.settled { color: var(--green); }
table.lines { width: 100%; border-collapse: collapse; margin: var(--space-4) 0; }
table.lines th { background: var(--wax); border: 1px solid var(--line); padding: var(--space-2); font-size: .9rem; }
table.lines td { border: 1px solid var(--line); padding: var(--space-2); }
table.lines .num { text-align: left; }
.payable { font-size: 1.1rem; font-weight: 700; color: var(--ink); background: var(--honey); border: 0; border-radius: 12px; padding: 14px 16px; margin: var(--space-3) 0; }
.legal { margin-top: var(--space-4); border-top: 1px solid var(--line); padding-top: var(--space-3); font-size: .8rem; color: var(--review); }
.legal p { margin: var(--space-1) 0; }
.legal .en { color: var(--muted); }
@media screen and (max-width:600px) {
  .sheet { margin:20px 16px; }
  .facts { grid-template-columns:auto 1fr; }
  /* Receipt-like line groups, preserving recorded thousandths, unit prices and rial totals. */
  table.lines, table.lines tbody { display:block; width:100%; }
  table.lines thead { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0,0,0,0); }
  table.lines tr { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:0 12px; padding:10px 0; border-bottom:1px dotted #C9B88E; }
  table.lines td { display:block; border:0; padding:0; }
  table.lines td:first-child { grid-column:1; font-weight:700; overflow-wrap:anywhere; }
  table.lines td:nth-child(2), table.lines td:nth-child(3) { grid-column:1; text-align:right; color:var(--muted); font-size:.8rem; }
  table.lines td:nth-child(2)::before { content:"تعداد (هزارم واحد): "; }
  table.lines td:nth-child(3)::before { content:"قیمت واحد (ریال): "; }
  table.lines td:nth-child(4) { grid-column:2; grid-row:1 / 4; align-self:center; font-weight:700; }
  table.lines td:nth-child(4)::before { content:"جمع (ریال): "; font-size:.75rem; color:var(--muted); }
}
@media print {
  body { background: var(--paper); }
  .sheet { margin: 0; border: none; border-top: 4px solid var(--honey); border-radius: 0; }
  .print-hint { display:none; }
  .status { color:var(--ink); background:none; padding:0; }
  .tear::before, .tear::after { display:none; }
  @page { margin: 12mm; }
}
</style>
</head>
<body>
<main class="sheet">
<header class="brand-header">
<svg width="56" height="56" viewBox="0 0 72 72" role="img" aria-label="کندو"><polygon points="36,4 63,20 63,52 36,68 9,52 9,20" fill="#F2A900"/><path d="M24 38l8 8 16-18" fill="none" stroke="#2B1D0E" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/></svg>
<div><p class="store">{$storeName}</p><p class="eyebrow">فاکتور دیجیتال کندو</p><h1>فاکتور شماره <bdi>{$displayNumber}</bdi></h1></div>
</header>
<hr class="tear">
<dl class="facts">
<dt>شماره فاکتور</dt><dd><bdi>{$displayNumber}</bdi></dd>
<dt>وضعیت</dt><dd><span class="{$statusClass}">{$statusLabel}</span> <bdi>({$lifecycle})</bdi></dd>
<dt>تاریخ و زمان صدور</dt><dd><bdi>{$tehran}</bdi> <span class="tz" dir="ltr">Asia/Tehran</span> <time datetime="{$issuedAtIso}"></time></dd>
<dt>شناسه فاکتور</dt><dd dir="ltr"><bdi>{$invoiceId}</bdi></dd>
{$customerFact}
</dl>
<hr class="tear">
<table class="lines">
<thead><tr><th>کالا</th><th class="num">تعداد (هزارم واحد)</th><th class="num">قیمت واحد (ریال)</th><th class="num">جمع (ریال)</th></tr></thead>
<tbody>{$rows}</tbody>
</table>
<p class="payable">مبلغ قابل پرداخت: <bdi>{$payable}</bdi> ریال</p>
{$paymentsSection}
{$gapSection}
<p class="print-hint">برای نگهداری فاکتور، از چاپ مرورگر استفاده کنید.</p>
<footer class="legal">
<p>D14 placeholder — needs lawyer approval</p>
<p class="en">وضعیت متن حقوقی: needs lawyer approval</p>
</footer>
</main>
</body>
</html>
HTML;
    }

    /** Mirrors WebUtility.HtmlEncode (& < > " '). */
    public static function e(string $value): string
    {
        return str_replace(
            ['&', '<', '>', '"', "'"],
            ['&amp;', '&lt;', '&gt;', '&quot;', '&#39;'],
            $value
        );
    }

    /** Invariant thousand grouping ("N0"): 1000 → 1,000. */
    public static function n0(int $value): string
    {
        return number_format($value, 0, '.', ',');
    }

    private static function lifecycleLabel(string $status): string
    {
        return match ($status) {
            'ISSUED' => 'صادر شده',
            'REVOKED' => 'باطل شده',
            'SUPERSEDED' => 'جایگزین شده',
            default => $status,
        };
    }

    private static function paymentLabel(string $type): string
    {
        return match ($type) {
            'card-reader' => 'کارتخوان',
            'card-to-card' => 'کارت به کارت',
            'cash' => 'نقد',
            'credit' => 'اعتباری',
            default => $type,
        };
    }
}
