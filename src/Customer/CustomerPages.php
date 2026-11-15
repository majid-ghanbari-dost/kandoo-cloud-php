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
    public function __construct(private array $config, private CustomerAuth $auth, private bool $legalGate = false, private ?StoreGateway $gateway = null, private ?Clock $clock = null) {}

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
            return $this->page('<h1>ورود مشتری</h1><form method="post" action="/account/login"><label>شمارهٔ موبایل با پیش‌شمارهٔ بین‌المللی <input name="phone" required autocomplete="tel"></label><input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="key" value="'.$key.'"><button>دریافت کد</button></form>', 200, ['Set-Cookie' => 'kandoo_csrf='.$csrf.'; Path=/account; Max-Age='.($this->config['device_ttl_seconds'] ?? 2592000).'; Secure; HttpOnly; SameSite=Strict']);
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
            if ($result['status'] !== 'OK') { return $this->page('<p>کد معتبر نیست یا منقضی شده است.</p>', 400); }
            return new Response(303, ['Location' => '/account/invoices', 'Cache-Control' => 'no-store', 'Set-Cookie' => 'kandoo_session='.$result['token'].'; Path=/account; Max-Age='.$this->config['session_ttl_seconds'].'; Secure; HttpOnly; SameSite=Strict']);
        }
        if ($path === '/account/logout' && $method === 'POST') {
            $form = $this->form($request);
            if (!$this->validCsrf($request, $form)) { return Response::emptyBody(403, ['Cache-Control' => 'no-store']); }
            $this->auth->revokeSession($this->cookie($request, 'kandoo_session'));
            return new Response(303, ['Location' => '/account/login', 'Cache-Control' => 'no-store', 'Set-Cookie' => 'kandoo_session=; Path=/account; Max-Age=0; Secure; HttpOnly; SameSite=Strict']);
        }
        if ($path === '/account/telegram' && $method === 'GET') { return $this->telegram(); }
        if ($method === 'GET' && ($path === '/account/invoices' || preg_match('#^/account/invoices/([^/]+)/([^/]+)$#D', $path, $m) === 1)) {
            $phone = $this->auth->phoneForToken($this->cookie($request, 'kandoo_session'));
            if ($phone === null) { return new Response(303, ['Location' => '/account/login', 'Cache-Control' => 'no-store']); }
            if ($path === '/account/invoices') {
                $html = '<h1>فاکتورهای من</h1><ul>';
                foreach ($this->auth->invoices($phone) as $row) {
                    $html .= '<li><a href="/account/invoices/'.self::e($row['store_id']).'/'.self::e($row['invoice_id']).'">'.self::e($row['store_name']).' — '.self::e((string) $row['display_number']).'</a></li>';
                }
                $csrf = $this->cookie($request, 'kandoo_csrf');
                $headers = [];
                if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $csrf) !== 1) {
                    $csrf = OtpSecrets::sessionToken();
                    $headers['Set-Cookie'] = 'kandoo_csrf='.$csrf.'; Path=/account; Max-Age='.($this->config['device_ttl_seconds'] ?? 2592000).'; Secure; HttpOnly; SameSite=Strict';
                }
                $html .= '</ul><form method="post" action="/account/logout"><input type="hidden" name="csrf" value="'.self::e($csrf).'"><button>خروج از این دستگاه</button></form>';
                return $this->page($html, 200, $headers);
            }
            $row = $this->auth->invoice($phone, $m[1], $m[2]);
            if ($row === null) { return Response::emptyBody(404, ['Cache-Control' => 'no-store']); }
            if ($this->legalGate) { return Response::emptyBody(503, ['Cache-Control' => 'no-store']); }
            return Response::html(200, Html::renderInvoicePage($row), ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
        }
        // JSON API uses explicit content type, no browser cookies, and exposes no CORS policy.
        if ($method === 'POST' && ($path === '/v1/customer/otp/challenges' || preg_match('#^/v1/customer/otp/challenges/([^/]+)/verify$#D', $path, $m) === 1)) {
            if (strtolower(trim(explode(';', $request->header('content-type') ?? '')[0])) !== 'application/json') { return $this->jsonError(400, 'INVALID'); }
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

    private function telegram(): Response
    {
        $url = $this->config['telegram_url'] ?? null;
        $content = '<h1>مسیر تلگرام</h1><p>برای ادامه از مسیر پشتیبانی تلگرام استفاده کنید.</p>';
        if (is_string($url)) { $content .= '<p><a rel="noopener noreferrer" href="'.self::e($url).'">تلگرام</a></p>'; }
        return $this->page($content, 429);
    }
    private function page(string $body, int $status = 200, array $headers = []): Response
    {
        return Response::html($status, '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>فاکتور دیجیتال کندو</title></head><body>'.$body.'</body></html>', array_merge(['Cache-Control'=>'no-store', 'Content-Security-Policy'=>"default-src 'none'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'", 'Referrer-Policy'=>'no-referrer'], $headers));
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
