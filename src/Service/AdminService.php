<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Service;

use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Http\Response;
use Kandoo\Cloud\Store\StoreGateway;

/**
 * Administration is opt-in and separately authenticated on every page request.
 *
 * v2 (ORDER 16 P-32): store overview, replication devices with revocation, OTP budget
 * reset, SMS delivery statistics and the SMS cost log. Every POST action keeps the v1
 * protection: Basic authentication, same-origin check, CSRF token, size cap; the page is
 * served only while the admin state is enabled (default off).
 */
final class AdminService
{
    public function __construct(private array $config, private StoreGateway $gateway, private ?\Closure $resetBudget = null, private ?\Closure $demoOutbox = null, private ?\Kandoo\Cloud\Support\Clock $clock = null) {}

    public function handle(Request $request): Response
    {
        $hash = $this->config['admin_password_hash'] ?? null;
        if (!is_string($hash) || !$this->gateway->isAdminEnabled()) {
            return Response::emptyBody(404);
        }
        if (!in_array($request->method(), ['GET', 'POST'], true)) {
            return Response::emptyBody(405, ['Allow' => 'GET, POST', 'Cache-Control' => 'no-store']);
        }
        // Count all enabled-admin requests before password verification, not proxy-supplied headers.
        $now = $this->clock !== null ? $this->clock->nowUnix() : time();
        $peer = $request->clientIp() !== '' ? $request->clientIp() : 'unknown';
        $key = hash('sha256', "admin-peer\0".$peer);
        if (!$this->gateway->rateHit('admin', $key, intdiv($now,60)*60, (int)($this->config['admin_rate_limit_per_minute'] ?? 10))) {
            return Response::emptyBody(429, ['Cache-Control'=>'no-store', 'Retry-After'=>(string)max(1,60-($now%60))]);
        }
        $authorization = $request->header('authorization') ?? '';
        $decoded = preg_match('/^Basic\s+([^\s]+)$/i', $authorization, $m) === 1
            ? base64_decode($m[1], true) : false;
        $parts = is_string($decoded) ? explode(':', $decoded, 2) : [];
        if (count($parts) !== 2 || $parts[0] !== 'admin' || !password_verify($parts[1], $hash)) {
            return Response::emptyBody(401, ['WWW-Authenticate' => 'Basic realm="Administration", charset="UTF-8"', 'Cache-Control' => 'no-store']);
        }
        return $request->method() === 'POST' ? $this->handlePost($request) : $this->page();
    }

    /** Shared POST gate (size cap, same-origin, CSRF token), then the v1/v2 actions. */
    private function handlePost(Request $request): Response
    {
        if (strlen($request->body()) > 4096) { return Response::emptyBody(404, ['Cache-Control' => 'no-store']); }
        $origin = $request->header('origin') ?? '';
        $base = parse_url((string)($this->config['base_url'] ?? ''));
        $expected = is_array($base) && isset($base['scheme'], $base['host']) ? $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '') : '';
        parse_str($request->body(), $form);
        $cookie = '';
        foreach (explode(';', $request->header('cookie') ?? '') as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2 && $pair[0] === 'kandoo_admin_csrf') { $cookie = $pair[1]; }
        }
        $csrf = $form['csrf'] ?? null;
        if ($expected === '' || $origin !== $expected || !is_string($csrf) || strlen($csrf) !== 43 || !hash_equals($cookie, $csrf)) {
            return Response::emptyBody(403, ['Cache-Control' => 'no-store']);
        }
        $action = isset($form['action']) && is_string($form['action']) ? $form['action'] : 'reset_budget';
        if ($action === 'revoke_device') {
            $deviceId = $form['device_id'] ?? null;
            $reason = $form['reason'] ?? '';
            if (!is_string($deviceId) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $deviceId) !== 1
                || !is_string($reason) || strlen($reason) > 255) {
                return Response::emptyBody(400, ['Cache-Control' => 'no-store']);
            }
            if (!$this->gateway->revokeDevice(strtolower($deviceId), trim($reason), gmdate('Y-m-d H:i:s.u'))) {
                return Response::emptyBody(400, ['Cache-Control' => 'no-store']);
            }
            return Response::html(200, '<!doctype html><html lang="fa" dir="rtl"><body><p>دستگاه باطل شد.</p></body></html>', ['Cache-Control' => 'no-store']);
        }
        if ($action !== 'reset_budget' || $this->resetBudget === null) {
            return Response::emptyBody(404, ['Cache-Control' => 'no-store']);
        }
        $phone = $form['phone'] ?? null;
        if (!is_string($phone)) { return Response::emptyBody(400, ['Cache-Control' => 'no-store']); }
        if (!($this->resetBudget)($phone)) { return Response::emptyBody(400, ['Cache-Control' => 'no-store']); }
        return Response::html(200, '<!doctype html><html lang="fa" dir="rtl"><body><p>درخواست ثبت شد.</p></body></html>', ['Cache-Control' => 'no-store']);
    }

    /** Full v2 overview page. */
    private function page(): Response
    {
        $csrf = \Kandoo\Cloud\Customer\OtpSecrets::sessionToken();
        $styleNonce = base64_encode(random_bytes(16));
        $headers = [
            'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'nonce-{$styleNonce}'; font-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
            'Set-Cookie' => 'kandoo_admin_csrf=' . $csrf . '; Path=/admin; ' . (($this->config['env'] ?? '') === 'demo' ? '' : 'Secure; ') . 'HttpOnly; SameSite=Strict',
        ];
        $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $hidden = static function (string $action, string $token): string {
            return '<input type="hidden" name="csrf" value="' . htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"><input type="hidden" name="action" value="' . htmlspecialchars($action, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        };
        $body = '<header class="page-heading"><h1>مدیریت فاکتور دیجیتال کندو</h1><p>صفحهٔ مدیریت فعال است.</p></header>';

        if ($this->resetBudget !== null) {
            $body .= '<section><h2>بازنشانی بودجهٔ کد یک‌بارمصرف</h2>'
                . '<form method="post" action="/admin">' . $hidden('reset_budget', $csrf)
                . '<label>شماره موبایل <input name="phone" required></label><button>بازنشانی بودجهٔ کد</button></form></section>';
        }

        $stores = $this->gateway->listStores();
        $body .= '<section><h2>فروشگاه‌ها (' . $e(count($stores)) . ')</h2>'
            . '<table><thead><tr><th>شناسه</th><th>تعداد ردیف‌های ثبت‌شده</th><th>آخرین توالی پذیرفته</th><th>محدودشده</th></tr></thead><tbody>';
        foreach ($stores as $store) {
            $body .= '<tr><td><code>' . $e($store['store_id']) . '</code></td><td>' . $e($store['entry_count']) . '</td><td>'
                . $e($store['accepted_through_seq']) . '</td><td>' . (empty($store['throttled']) ? 'خیر' : 'بله') . '</td></tr>';
        }
        $body .= '</tbody></table>' . ($stores === [] ? '<p class="empty">هنوز فروشگاهی ثبت نشده است.</p>' : '') . '</section>';

        $devices = $this->gateway->listDevices();
        $body .= '<section><h2>دستگاه‌ها (' . $e(count($devices)) . ')</h2>'
            . '<table><thead><tr><th>شناسه دستگاه</th><th>فروشگاه</th><th>ثبت</th><th>آخرین احراز</th><th>تأییدشده</th><th>وضعیت</th><th>ابطال</th></tr></thead><tbody>';
        foreach ($devices as $device) {
            $revoked = $device['revoked_at'] !== null;
            $status = $revoked
                ? 'باطل‌شده (' . $e($device['revoked_at']) . ')'
                : ($device['verified'] ? 'فعال' : 'ثبت‌شده');
            $body .= '<tr><td><code>' . $e($device['device_id']) . '</code></td><td><code>' . $e($device['store_id']) . '</code></td><td>'
                . $e($device['registered_at']) . '</td><td>' . $e($device['last_authenticated_at'] ?? '—') . '</td><td>'
                . ($device['verified'] ? 'بله' : 'خیر') . '</td><td>' . $status . '</td>';
            $body .= $revoked
                ? '<td>—</td>'
                : '<td><form method="post" action="/admin">' . $hidden('revoke_device', $csrf)
                    . '<input type="hidden" name="device_id" value="' . $e($device['device_id']) . '">'
                    . '<input name="reason" maxlength="255" placeholder="دلیل (اختیاری)"><button>ابطال دستگاه</button></form></td>';
            $body .= '</tr>';
        }
        $body .= '</tbody></table>' . ($devices === [] ? '<p class="empty">هنوز دستگاهی ثبت نشده است.</p>' : '') . '</section>';

        $stats = $this->gateway->smsDeliveryStatistics();
        $body .= '<section><h2>آمار پیامک‌ها</h2><p>مجموع درخواست‌های ارسال: ' . $e($stats['total']) . '</p><ul>';
        foreach ($stats['by_state'] as $state => $count) {
            $body .= '<li>' . $e($state) . ': ' . $e($count) . '</li>';
        }
        $body .= '</ul></section>';

        $body .= '<section><h2>گزارش هزینهٔ پیامک (برآورد رزرو، نه قبض یا تحویل)</h2>';
        $totals = $this->gateway->smsCostDailyTotals(30);
        if ($totals === []) {
            $body .= '<p>هنوز هزینه‌ای ثبت نشده است.</p>';
        } else {
            $body .= '<table><thead><tr><th>تاریخ</th><th>تعداد</th><th>هزینه</th></tr></thead><tbody>';
            foreach ($totals as $total) {
                $body .= '<tr><td>' . $e($total['cost_date']) . '</td><td>' . $e($total['units']) . '</td><td>' . $e($total['cost']) . '</td></tr>';
            }
            $body .= '</tbody></table>';
        }
        $entries = $this->gateway->smsCostLog(50);
        if ($entries === []) {
            $body .= '<p>موردی ثبت نشده است.</p>';
        } else {
            $body .= '<table><thead><tr><th>ردیف</th><th>الگو</th><th>فروشگاه</th><th>تعداد</th><th>قیمت واحد</th><th>ارز</th><th>تاریخ</th></tr></thead><tbody>';
            foreach ($entries as $entry) {
                $body .= '<tr><td>' . $e($entry['entry_id']) . '</td><td>' . $e($entry['pattern']) . '</td><td><code>' . $e($entry['store_id'])
                    . '</code></td><td>' . $e($entry['units']) . '</td><td>' . $e($entry['unit_cost']) . '</td><td>' . $e($entry['currency'])
                    . '</td><td>' . $e($entry['cost_date']) . '</td></tr>';
            }
            $body .= '</tbody></table>';
        }
        $body .= '</section>';

        if ($this->demoOutbox !== null) {
            $body .= '<section><h2>صندوق پیام دمو (ارسال واقعی نشده)</h2><ul>';
            $messages = ($this->demoOutbox)();
            foreach ($messages as $message) {
                $body .= '<li><bdi>' . $e($message['phone']) . '</bdi> — ' . $e($message['body']) . '</li>';
            }
            $body .= '</ul>' . ($messages === [] ? '<p class="empty">هنوز پیام دمویی ثبت نشده است.</p>' : '') . '</section>';
        }

        // No script or external resources: a per-response nonce permits only this static style block.
        $css = <<<'CSS'
@font-face{font-family:Vazirmatn;src:url(/assets/fonts/Vazirmatn-Regular.ttf) format("truetype");font-weight:400;font-display:swap}
@font-face{font-family:Vazirmatn;src:url(/assets/fonts/Vazirmatn-Bold.ttf) format("truetype");font-weight:700;font-display:swap}
:root{color-scheme:light;--honey:#F2A900;--ink:#2B1D0E;--cream:#FFF8E7;--wax:#F5E9C8;--green:#256B43;--brown:#9A3B0A}
*{box-sizing:border-box}body{margin:0;background:var(--cream);color:var(--ink);font:16px/1.8 Vazirmatn,Tahoma,sans-serif}
main{max-width:80rem;margin:auto;padding:16px;display:grid;gap:14px}.page-heading{padding:20px;border-radius:16px;background:var(--ink);color:var(--cream)}
h1{font-size:1.3rem;margin:0}h2{font-size:1.05rem;margin:0 0 12px;color:var(--ink)}p{margin:8px 0}
section{background:#fff;border:1px solid #ead9b6;border-radius:16px;padding:18px;overflow-x:auto;box-shadow:0 1px 0 rgba(43,29,14,.06)}
table{width:100%;border-collapse:collapse;text-align:right}th,td{border-bottom:1px solid #eee0bf;padding:10px;vertical-align:top}th{background:var(--wax);white-space:nowrap}code{direction:ltr;unicode-bidi:isolate;display:inline-block;overflow-wrap:anywhere}
label{font-weight:700;display:block}input{font:inherit;padding:9px 12px;border:1px solid #c9b88e;border-radius:10px;max-width:100%}input[name=phone]{direction:ltr}
button{font:inherit;font-weight:700;background:var(--honey);color:var(--ink);border:0;border-radius:10px;padding:10px 16px;cursor:pointer}button:focus-visible,input:focus-visible{outline:3px solid var(--green);outline-offset:2px}ul{padding-inline-start:24px}.empty{color:#6b5a3e}
@media(max-width:600px){main{padding:12px;gap:12px}section{padding:16px}input[name=phone]{width:100%}form button{margin-top:8px}table{min-width:36rem}}
@media print{body{background:#fff}main{padding:0}section,.page-heading{box-shadow:none;break-inside:avoid}button{display:none}}
CSS;
        $html = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>مدیریت فاکتور دیجیتال کندو</title><style nonce="'.$styleNonce.'">'.$css.'</style></head><body><main>'
            . $body . '</main></body></html>';
        return Response::html(200, $html, $headers);
    }
}
