<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Service;

use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Http\Response;
use Kandoo\Cloud\Store\StoreGateway;

/** Administration is opt-in and separately authenticated on every page request. */
final class AdminService
{
    public function __construct(private array $config, private StoreGateway $gateway, private ?\Closure $resetBudget = null, private ?\Closure $demoOutbox = null) {}

    public function handle(Request $request): Response
    {
        $hash = $this->config['admin_password_hash'] ?? null;
        if (!is_string($hash) || !$this->gateway->isAdminEnabled()) {
            return Response::emptyBody(404);
        }
        if (!in_array($request->method(), ['GET', 'POST'], true)) {
            return Response::emptyBody(405, ['Allow' => 'GET, POST', 'Cache-Control' => 'no-store']);
        }
        $authorization = $request->header('authorization') ?? '';
        $decoded = preg_match('/^Basic\s+([^\s]+)$/i', $authorization, $m) === 1
            ? base64_decode($m[1], true) : false;
        $parts = is_string($decoded) ? explode(':', $decoded, 2) : [];
        if (count($parts) !== 2 || $parts[0] !== 'admin' || !password_verify($parts[1], $hash)) {
            return Response::emptyBody(401, ['WWW-Authenticate' => 'Basic realm="Administration", charset="UTF-8"', 'Cache-Control' => 'no-store']);
        }
        if ($request->method() === 'POST') {
            if ($this->resetBudget === null || strlen($request->body()) > 4096) { return Response::emptyBody(404, ['Cache-Control'=>'no-store']); }
            $origin = $request->header('origin') ?? '';
            $base = parse_url((string)($this->config['base_url'] ?? ''));
            $expected = is_array($base) && isset($base['scheme'], $base['host']) ? $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '') : '';
            parse_str($request->body(), $form);
            $cookie = '';
            foreach (explode(';', $request->header('cookie') ?? '') as $part) {
                $pair = explode('=', trim($part), 2);
                if (count($pair) === 2 && $pair[0] === 'kandoo_admin_csrf') { $cookie = $pair[1]; }
            }
            $csrf = $form['csrf'] ?? null; $phone = $form['phone'] ?? null;
            if ($expected === '' || $origin !== $expected || !is_string($csrf) || strlen($csrf) !== 43 || !hash_equals($cookie, $csrf) || !is_string($phone)) {
                return Response::emptyBody(403, ['Cache-Control'=>'no-store']);
            }
            if (!(($this->resetBudget)($phone))) { return Response::emptyBody(400, ['Cache-Control'=>'no-store']); }
            return Response::html(200, '<!doctype html><html lang="fa" dir="rtl"><body><p>درخواست ثبت شد.</p></body></html>', ['Cache-Control'=>'no-store']);
        }
        $form = ''; $headers = [
            'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
        ];
        if ($this->resetBudget !== null) {
            $csrf = \Kandoo\Cloud\Customer\OtpSecrets::sessionToken();
            $headers['Set-Cookie'] = 'kandoo_admin_csrf='.$csrf.'; Path=/admin; '.(($this->config['env'] ?? '') === 'demo' ? '' : 'Secure; ').'HttpOnly; SameSite=Strict';
            $form = '<form method="post" action="/admin"><input type="hidden" name="csrf" value="'.$csrf.'"><label>شماره موبایل <input name="phone" required></label><button>بازنشانی بودجه کد</button></form>';
        }
        $messages = '';
        if ($this->demoOutbox !== null) {
            $messages = '<section><h2>صندوق پیام دمو (ارسال واقعی نشده)</h2><ul>';
            foreach (($this->demoOutbox)() as $message) {
                $escape = static fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $messages .= '<li><bdi>'.$escape($message['phone']).'</bdi> — '.$escape($message['body']).'</li>';
            }
            $messages .= '</ul></section>';
        }
        return Response::html(200, '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>مدیریت فاکتور دیجیتال کندو</title></head><body><h1>مدیریت فاکتور دیجیتال کندو</h1><p>صفحهٔ مدیریت فعال است.</p>'.$form.$messages.'</body></html>', $headers);
    }
}
