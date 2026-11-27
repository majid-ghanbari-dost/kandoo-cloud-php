<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Customer;

use Kandoo\Cloud\Http\Html;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Http\Response;
use Kandoo\Cloud\Store\StoreGateway;
use Kandoo\Cloud\Support\Clock;

/** Private pages and OTP API. No token, code or phone is logged. */
final class CustomerPages
{
    public function __construct(private array $config, private CustomerAuth $auth, private bool $legalGate = false, private ?StoreGateway $gateway = null, private ?Clock $clock = null, private bool $demo = false) {}

    public function handle(Request $request): Response
    {
        if (!($this->config['enabled'] ?? false)) { return Response::emptyBody(404); }
        $path = $request->path(); $method = $request->method();
        if ($method === 'POST') {
            $api = str_starts_with($path, '/v1/customer/otp/');
            $scope = ($path === '/account/login' || $path === '/v1/customer/otp/challenges') ? 'customer_issue'
                : (($path === '/account/verify' || preg_match('#^/v1/customer/otp/challenges/[^/]+/verify$#D', $path) === 1) ? 'customer_verify' : null);
            if ($scope !== null && !$this->allowIp($request, $scope)) {
                return $api ? $this->jsonError(429, 'RATE_LIMITED') : $this->page('<p>درخواست ورود انجام نشد.</p>', 429);
            }
        }
        if ($path === '/account/login' && $method === 'GET') {
            if ($this->auth->phoneForToken($this->cookie($request, 'kandoo_session')) !== null) {
                return new Response(303, ['Location' => '/account/invoices', 'Cache-Control' => 'no-store']);
            }
            $csrf = $this->cookie($request, 'kandoo_csrf');
            if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $csrf) !== 1) { $csrf = OtpSecrets::sessionToken(); }
            $key = OtpSecrets::sessionToken();
            return $this->page('<h1>ورود مشتری</h1><form method="post" action="/account/login"><label>شمارهٔ موبایل با پیش‌شمارهٔ بین‌المللی <input name="phone" required autocomplete="tel"></label><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="key" value="'.$key.'"><button>دریافت کد</button></form>', 200, ['Set-Cookie' => 'kandoo_csrf='.$csrf.'; Path=/account; Max-Age='.($this->config['device_ttl_seconds'] ?? 2592000).$this->cookieSecurity().' HttpOnly; SameSite=Strict']);
        }
        if ($path === '/account/login' && $method === 'POST') {
            $form = $this->form($request);
            if (!$this->validCsrf($request, $form)) { return Response::emptyBody(403, ['Cache-Control' => 'no-store']); }
            $result = $this->auth->issue($form['phone'] ?? '', $form['key'] ?? '', $this->cookie($request, 'kandoo_csrf'));
            if (in_array($result['status'], ['OTP_SENT', 'DUPLICATE'], true)) {
                return $this->page('<h1>تأیید کد</h1><form method="post" action="/account/verify"><label>کد <input name="code" inputmode="numeric" required autocomplete="one-time-code"></label><input type="hidden" name="challenge" value="'.self::e($result['challenge_id']).'"><input type="hidden" name="csrf" value="'.self::e($form['csrf']).'"><button>ورود</button></form>');
            }
            if ($result['status'] === 'TELEGRAM') { return $this->telegram(); }
            return $this->page('<p>درخواست ورود انجام نشد.</p>', $result['status'] === 'UNAVAILABLE' ? 503 : 400);
        }
        if ($path === '/account/verify' && $method === 'POST') {
            $form = $this->form($request);
            if (!$this->validCsrf($request, $form)) { return Response::emptyBody(403, ['Cache-Control' => 'no-store']); }
            $result = $this->auth->verify($form['challenge'] ?? '', $form['code'] ?? '', $this->cookie($request, 'kandoo_csrf'));
            if ($result['status'] !== 'OK') {
                if ($this->auth->challengeLocked($form['challenge'] ?? '', $this->cookie($request, 'kandoo_csrf'))) { return $this->telegram(); }
                return $this->page('<h1>کد معتبر نیست</h1><p>کد نادرست یا منقضی شده است.</p><a href="/account/login">بازگشت به ورود</a>', 400);
            }
            return new Response(303, ['Location' => '/account/invoices', 'Cache-Control' => 'no-store', 'Set-Cookie' => 'kandoo_session='.$result['token'].'; Path=/account; Max-Age='.$this->config['session_ttl_seconds'].$this->cookieSecurity().' HttpOnly; SameSite=Strict']);
        }
        if ($path === '/account/logout' && $method === 'POST') {
            $form = $this->form($request);
            if (!$this->validCsrf($request, $form)) { return Response::emptyBody(403, ['Cache-Control' => 'no-store']); }
            $this->auth->revokeSession($this->cookie($request, 'kandoo_session'));
            return new Response(303, ['Location' => '/account/login', 'Cache-Control' => 'no-store', 'Set-Cookie' => 'kandoo_session=; Path=/account; Max-Age=0'.$this->cookieSecurity().' HttpOnly; SameSite=Strict']);
        }
        if ($path === '/account/telegram' && $method === 'GET') { return $this->telegram(); }
        if ($method === 'GET' && ($path === '/account/invoices' || preg_match('#^/account/invoices/([^/]+)/([^/]+)$#D', $path, $m) === 1)) {
            $phone = $this->auth->phoneForToken($this->cookie($request, 'kandoo_session'));
            if ($phone === null) { return new Response(303, ['Location' => '/account/login', 'Cache-Control' => 'no-store']); }
            if ($path === '/account/invoices') {
                $html = '';
                $groups = [];
                foreach ($this->auth->invoices($phone) as $row) { $groups[$row['store_id']][] = $row; }
                if ($groups === []) { $html .= self::emptyState(); } else { $html .= '<h1>فاکتورهای من</h1>'; }
                foreach ($groups as $rows) {
                    $html .= '<section><h2>'.self::e((string)$rows[0]['store_name']).'</h2><ul>';
                    foreach ($rows as $row) {
                        $html .= '<li><a href="/account/invoices/'.self::e($row['store_id']).'/'.self::e($row['invoice_id']).'">فاکتور شماره '.self::e((string)$row['display_number']).'</a></li>';
                    }
                    $html .= '</ul></section>';
                }
                $csrf = $this->cookie($request, 'kandoo_csrf');
                $headers = [];
                if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $csrf) !== 1) {
                    $csrf = OtpSecrets::sessionToken();
                    $headers['Set-Cookie'] = 'kandoo_csrf='.$csrf.'; Path=/account; Max-Age='.($this->config['device_ttl_seconds'] ?? 2592000).$this->cookieSecurity().' HttpOnly; SameSite=Strict';
                }
                $html .= '<form method="post" action="/account/logout"><input type="hidden" name="csrf" value="'.self::e($csrf).'"><button>خروج از این دستگاه</button></form>';
                return $this->page($html, 200, $headers, $groups === [] ? 'empty' : '');
            }
            $row = $this->auth->invoice($phone, $m[1], $m[2]);
            if ($row === null) { return Response::emptyBody(404, ['Cache-Control' => 'no-store']); }
            if ($this->legalGate) { return Response::emptyBody(503, ['Cache-Control' => 'no-store']); }
            return Response::html(200, Html::renderCustomerInvoicePage($row), ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
        }
        if ($path === '/v1/customer/session' && $method === 'DELETE') {
            $authorization=$request->header('authorization') ?? '';
            $token=preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/iD',$authorization,$match) === 1 ? $match[1] : '';
            if ($this->auth->phoneForToken($token) === null) { return $this->jsonError(401,'UNAUTHENTICATED'); }
            $this->auth->revokeSession($token);
            return Response::json(200, ['status'=>'REVOKED'], ['Cache-Control'=>'no-store']);
        }
        // Native clients use the same random, expiring, revocable customer session as the browser.
        if ($method === 'GET' && ($path === '/v1/customer/invoices' || preg_match('#^/v1/customer/invoices/([^/]+)/([^/]+)$#D', $path, $nativeInvoice) === 1)) {
            $authorization = $request->header('authorization') ?? '';
            $token = preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/iD', $authorization, $match) === 1 ? $match[1] : '';
            $phone = $this->auth->phoneForToken($token);
            if ($phone === null) { return $this->jsonError(401, 'UNAUTHENTICATED'); }
            if ($path !== '/v1/customer/invoices') {
                $row = $this->auth->invoice($phone, $nativeInvoice[1], $nativeInvoice[2]);
                if ($row === null) { return $this->jsonError(404, 'NOT_FOUND'); }
                if ($this->legalGate) { return $this->jsonError(503, 'LEGAL_COPY_UNAVAILABLE'); }
                $items = json_decode((string)$row['line_items'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($items)) { throw new \UnexpectedValueException('Invalid recorded invoice lines'); }
                $safeItems = [];
                foreach ($items as $item) {
                    if (!is_array($item)) { throw new \UnexpectedValueException('Invalid recorded invoice line'); }
                    $safeItems[] = ['product_name'=>$item['product_name'] ?? '',
                        'quantity_millis'=>$item['quantity_millis'] ?? 0,
                        'unit_price_rials'=>$item['unit_price_rials'] ?? 0,
                        'line_total_rials'=>$item['line_total_rials'] ?? 0];
                }
                $invoice = ['store_id'=>$row['store_id'], 'invoice_id'=>$row['invoice_id'],
                    'store_name'=>$row['store_name'], 'display_number'=>(int)$row['display_number'],
                    'lifecycle_status'=>$row['lifecycle_status'], 'issued_at'=>\Kandoo\Cloud\Service\PublicInvoiceService::formatIssuedAtIso((string)$row['issued_at']),
                    'payable_rials'=>(int)$row['payable_rials'], 'line_items'=>$safeItems];
                foreach (['customer_name','currency_unit','remaining_balance'] as $key) {
                    if (($row[$key] ?? null) !== null) { $invoice[$key] = $key === 'remaining_balance' ? (int)$row[$key] : $row[$key]; }
                }
                if (($row['payments'] ?? null) !== null) {
                    $payments=json_decode((string)$row['payments'],true,512,JSON_THROW_ON_ERROR);
                    if (!is_array($payments)) { throw new \UnexpectedValueException('Invalid recorded payments'); }
                    $invoice['payments']=array_map(static fn(array $payment): array => ['type'=>$payment['type'] ?? '', 'amount'=>$payment['amount'] ?? 0],$payments);
                }
                return Response::json(200, ['invoice'=>$invoice], ['Cache-Control'=>'no-store']);
            }
            $groups = [];
            foreach ($this->auth->invoices($phone) as $row) {
                $store = $row['store_id'];
                if (!isset($groups[$store])) { $groups[$store] = ['store_id'=>$store, 'store_name'=>$row['store_name'], 'invoices'=>[]]; }
                $groups[$store]['invoices'][] = ['invoice_id'=>$row['invoice_id'], 'display_number'=>(int)$row['display_number']];
            }
            return Response::json(200, ['stores'=>array_values($groups)], ['Cache-Control'=>'no-store']);
        }
        // JSON API uses explicit content type, no browser cookies, and exposes no CORS policy.
        if ($method === 'POST' && ($path === '/v1/customer/otp/challenges' || preg_match('#^/v1/customer/otp/challenges/([^/]+)/verify$#D', $path, $m) === 1)) {
            if (strtolower(trim(explode(';', $request->header('content-type') ?? '')[0])) !== 'application/json') { return $this->jsonError(400, 'INVALID'); }
            if (strlen($request->body()) > 4096) { return $this->jsonError(400, 'INVALID'); }
            $input = json_decode($request->body(), true);
            if (!is_array($input)) { return $this->jsonError(400, 'INVALID'); }
            if ($path === '/v1/customer/otp/challenges') {
                $out = $this->auth->issue(is_string($input['phone'] ?? null) ? $input['phone'] : '', $request->header('idempotency-key') ?? '', $request->header('x-kandoo-device') ?? '');
                $status = match ($out['status']) { 'OTP_SENT' => 201, 'DUPLICATE' => 200, 'RATE_LIMITED', 'TELEGRAM' => 429, 'CONFLICT', 'INCOMPLETE' => 409, 'UNAVAILABLE' => 503, default => 400 };
                return Response::json($status, $out, ['Cache-Control' => 'no-store']);
            }
            $out = $this->auth->verify($m[1], is_string($input['code'] ?? null) ? $input['code'] : '', $request->header('x-kandoo-device') ?? '');
            return Response::json($out['status'] === 'OK' ? 200 : 400, $out, ['Cache-Control' => 'no-store']);
        }
        return Response::emptyBody(404);
    }

    private function cookieSecurity(): string { return $this->demo ? ';' : '; Secure;'; }

    private function allowIp(Request $request, string $scope): bool
    {
        // Trust only REMOTE_ADDR; forwarded headers are untrusted without a proxy policy.
        if ($this->gateway === null || $this->clock === null) { return false; }
        $limit = $scope === 'customer_issue' ? ($this->config['issue_ip_limit_per_minute'] ?? 10) : ($this->config['verify_ip_limit_per_minute'] ?? 20);
        $peer = $request->clientIp() !== '' ? $request->clientIp() : 'unknown';
        $key = hash_hmac('sha256', $peer, $this->config['otp_pepper']);
        $window = intdiv($this->clock->nowUnix(), 60) * 60;
        return $this->gateway->rateHit($scope, $key, $window, $limit);
    }

    private static function emptyState(): string
    {
        return '<section class="empty-state"><svg width="36" height="36" viewBox="0 0 72 72" role="img" aria-label="کندو"><polygon points="36,4 63,20 63,52 36,68 9,52 9,20" fill="#F2A900"/><path d="M24 38l8 8 16-18" fill="none" stroke="#2B1D0E" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/></svg><h2>هنوز فاکتوری ندارید</h2><p>وقتی از یک فروشگاه کندو خرید کنید و شماره‌ی شما را ثبت کنند، فاکتور همین‌جا ظاهر می‌شود.</p></section>';
    }

    private function telegram(): Response
    {
        $url = $this->config['telegram_url'] ?? null;
        $content = '<section class="locked-state"><h1>ورود نیاز به پشتیبانی دارد</h1><p>درخواست قفل شده یا سهمیه کد تمام شده است. برای ادامه با پشتیبانی تلگرام تماس بگیرید.</p>';
        if (is_string($url)) { $content .= '<p><a rel="noopener noreferrer" href="'.self::e($url).'">تلگرام</a></p>'; }
        $content .= '</section>';
        return $this->page($content, 429, [], 'locked');
    }
    private function page(string $body, int $status = 200, array $headers = [], string $layout = ''): Response
    {
        $css = '@font-face{font-family:vazir;src:url(/assets/fonts/Vazirmatn-Regular.ttf)}*{box-sizing:border-box}body{margin:0;background:#FFF8E7;color:#2B1D0E;font-family:vazir,Tahoma,sans-serif;line-height:1.9}main{max-width:34rem;margin:5vh auto;padding:24px;background:#fff;border:1px solid #ead9b6;border-radius:16px}h1{font-size:1.35rem}label{display:block;font-weight:bold}input{display:block;width:100%;padding:12px;border:1px solid #d4bd91;border-radius:12px;font:inherit;direction:ltr}button{margin-top:16px;padding:12px 20px;background:#F2A900;color:#2B1D0E;border:0;border-radius:12px;font:inherit;font-weight:bold;cursor:pointer}a{color:#256B43}.state-header{background:#2B1D0E;color:#FFF8E7;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;font-size:20px}.state-header span{font-size:13px;background:#F5E9C8;color:#2B1D0E;border-radius:12px;padding:4px 10px}.state-header+main{margin:16px auto}.empty-state{text-align:center;padding:32px 16px;border-radius:16px;background:#fff}.empty-state h2{font-size:18px;margin:10px 0}.empty-state p{font-size:14px;color:#5A4630}.state-logo{font-size:36px;color:#F2A900}.locked-state{border:2px solid #9A3B0A;border-radius:16px;padding:16px}.locked-state h1{color:#9A3B0A}.locked-state a{display:inline-block;background:#F2A900;color:#2B1D0E;padding:12px 20px;border-radius:10px;font-weight:bold}li{padding:10px;background:#F5E9C8;border-radius:16px;margin:8px 0}ul{list-style:none;padding:0}@media(max-width:600px){main{margin:16px;padding:20px}}';
        $heading = $layout === 'empty' ? 'فاکتورهای من' : ($layout === 'locked' ? 'ورود به کندو' : '');
        $header = $heading === '' ? '' : '<header class="state-header"><b>'.self::e($heading).'</b><span>نقش: مشتری</span></header>';
        return Response::html($status, '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>فاکتور دیجیتال کندو</title><style>'.$css.'</style></head><body>'.$header.'<main>'.$body.'</main></body></html>', array_merge(['Cache-Control'=>'no-store', 'Content-Security-Policy'=>"default-src 'none'; style-src 'unsafe-inline'; font-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'", 'Referrer-Policy'=>'no-referrer'], $headers));
    }
    private function form(Request $request): array
    {
        if (strlen($request->body()) > 4096) { return []; }
        parse_str($request->body(), $values);
        return array_filter($values, 'is_string');
    }
    private function validCsrf(Request $request, array $form): bool
    {
        $value = $form['csrf'] ?? ''; $cookie = $this->cookie($request, 'kandoo_csrf');
        return strlen($value) === 43 && strlen($cookie) === 43 && hash_equals($cookie, $value);
    }
    private function cookie(Request $request, string $name): string
    {
        foreach (explode(';', $request->header('cookie') ?? '') as $part) {
            $p = explode('=', trim($part), 2);
            if (count($p) === 2 && $p[0] === $name) { return $p[1]; }
        }
        return '';
    }
    private function jsonError(int $status, string $code): Response { return Response::json($status, ['status' => $code], ['Cache-Control'=>'no-store']); }
    private static function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
