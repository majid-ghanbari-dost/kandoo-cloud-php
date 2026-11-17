<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Customer\CustomerAuth;
use Kandoo\Cloud\Customer\OtpTransport;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Kernel;
use Kandoo\Cloud\Store\MariaDbGateway;
use Kandoo\Cloud\Support\FixedClock;
use Kandoo\Cloud\Tests\Support\DbTestCase;

final class CustomerFlowDatabaseTest extends DbTestCase
{
    private const STORE = '11111111-1111-4111-8111-111111111111';
    private const INVOICE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const PHONE = '+989120000001';
    private const ANNOUNCEMENT = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    public function testFullLoginInvoiceOwnershipBudgetAndExpiry(): void
    {
        $db = $this->db(); $pdo = $db->pdo();
        foreach (['cloud_customer_sessions', 'cloud_otp_challenges', 'cloud_sms_announcements', 'cloud_customer_phones', 'cloud_otp_idempotency_locks'] as $table) { $pdo->exec('DELETE FROM '.$table); }
        $gateway = new MariaDbGateway($db); $gateway->reset();
        $gateway->seedStore(['store_id'=>self::STORE, 'active_writer_device_id'=>self::STORE, 'active_writer_epoch'=>1, 'accepted_through_seq'=>0, 'entry_count'=>0, 'head_entry_hash'=>null, 'projection_version'=>0]);
        $gateway->seedProjection(['public_token'=>'TESTTOKEN1234567890123456', 'public_token_hash'=>hash('sha256', 'TESTTOKEN1234567890123456'), 'digital_invoice_id'=>self::INVOICE, 'invoice_id'=>self::INVOICE, 'store_id'=>self::STORE, 'store_name'=>'TEST Store', 'display_number'=>7, 'lifecycle_status'=>'ISSUED', 'issued_at'=>'2026-01-02 03:04:05', 'issued_payload'=>'{}', 'issued_content_sha256'=>str_repeat('a',64), 'payable_rials'=>25000, 'line_items'=>'[]', 'customer_phone'=>self::PHONE]);
        $gateway->seedProjection(['public_token'=>'TESTTOKENOTHER123456789012', 'public_token_hash'=>hash('sha256', 'TESTTOKENOTHER123456789012'), 'digital_invoice_id'=>'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'invoice_id'=>'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'store_id'=>self::STORE, 'store_name'=>'TEST Other', 'display_number'=>8, 'lifecycle_status'=>'ISSUED', 'issued_at'=>'2026-01-02 03:04:05', 'issued_payload'=>'{}', 'issued_content_sha256'=>str_repeat('b',64), 'payable_rials'=>1000, 'line_items'=>'[]', 'customer_phone'=>'+989120000002']);
        $stmt = $pdo->prepare('INSERT INTO cloud_sms_announcements (announcement_id,store_id,invoice_id,phone,delivered_at) VALUES (?,?,?,?,?)');
        $stmt->execute([self::ANNOUNCEMENT,self::STORE,self::INVOICE,self::PHONE,1767323045]);
        $settings = ['enabled'=>true, 'code_length'=>6, 'code_ttl_seconds'=>300, 'max_attempts'=>3, 'codes_per_announcement'=>2, 'issue_window_seconds'=>900, 'max_issues_per_window'=>3, 'session_ttl_seconds'=>3600, 'otp_pepper'=>'TEST-only-synthetic-otp-pepper-0123456789', 'telegram_url'=>null];
        $sms = new class implements OtpTransport {
            public array $codes = [];
            public function sendCode(string $phone, string $code): bool { $this->codes[] = $code; return true; }
        };
        $clock = new FixedClock(1767323045);
        $auth = new CustomerAuth($settings, $db, $clock, $sms);
        $config = self::$config; $config['customer_auth'] = $settings;
        $kernel = new Kernel($config, $gateway, $clock, $auth);
        $page = $kernel->handle(new Request('GET','/account/login'));
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Secure;', $page->header('Set-Cookie'));
        $demoConfig=$config; $demoConfig['env']='demo'; $demoConfig['base_url']='http://192.168.1.5:8080';
        $demoKernel=new Kernel($demoConfig,$gateway,$clock,$auth);
        $demoLogin=$demoKernel->handle(new Request('GET','/account/login',[],[],'',null,'192.168.1.7'));
        $this->assertSame(200,$demoLogin->status()); $this->assertStringNotContainsString('Secure;', $demoLogin->header('Set-Cookie'));
        $this->assertStringContainsString('SameSite=Strict', $demoLogin->header('Set-Cookie'));
        preg_match('/name="csrf" value="([^"]+)"/', $page->body(), $csrf);
        preg_match('/name="key" value="([^"]+)"/', $page->body(), $key);
        $this->assertNotEmpty($csrf[1]); $this->assertNotEmpty($key[1]);
        $cookie = 'kandoo_csrf='.$csrf[1];
        $issue = $kernel->handle(new Request('POST','/account/login',['cookie'=>$cookie],[],http_build_query(['csrf'=>$csrf[1], 'key'=>$key[1], 'phone'=>self::PHONE])));
        $this->assertSame(200, $issue->status()); $this->assertCount(1, $sms->codes);
        preg_match('/name="challenge" value="([^"]+)"/', $issue->body(), $id); $this->assertNotEmpty($id[1]);
        $duplicate = $auth->issue(self::PHONE, $key[1], $csrf[1]); $this->assertSame('DUPLICATE', $duplicate['status']); $this->assertCount(1, $sms->codes);
        $this->assertSame('INVALID', $auth->verify($id[1], $sms->codes[0], str_repeat('Z',43))['status']);
        $verify = $kernel->handle(new Request('POST','/account/verify',['cookie'=>$cookie],[],http_build_query(['csrf'=>$csrf[1], 'challenge'=>$id[1], 'code'=>$sms->codes[0]])));
        $this->assertSame(303, $verify->status()); $this->assertStringContainsString('HttpOnly', $verify->header('Set-Cookie'));
        preg_match('/kandoo_session=([^;]+)/', $verify->header('Set-Cookie'), $session); $this->assertNotEmpty($session[1]);
        $cookie2 = ['cookie'=>'kandoo_session='.$session[1]];
        $list = $kernel->handle(new Request('GET','/account/invoices',$cookie2));
        $this->assertSame(200, $list->status()); $this->assertStringContainsString('TEST Store', $list->body());
        $this->assertSame(303, $kernel->handle(new Request('GET','/account/login',$cookie2))->status());
        $detail = $kernel->handle(new Request('GET','/account/invoices/'.self::STORE.'/'.self::INVOICE,$cookie2));
        $this->assertSame(200, $detail->status()); $this->assertStringContainsString('TEST Store', $detail->body());
        $this->assertStringContainsString('--radius: 16px', $detail->body()); $this->assertStringNotContainsString('profit', strtolower($detail->body()));
        $this->assertSame(404, $kernel->handle(new Request('GET','/account/invoices/'.self::STORE.'/cccccccc-cccc-4ccc-8ccc-cccccccccccc',$cookie2))->status());
        $this->assertSame('INVALID', $auth->verify($id[1], $sms->codes[0], $csrf[1])['status']);
        $second = $auth->issue(self::PHONE, 'TEST-second', $csrf[1]);
        $this->assertSame('OTP_SENT', $second['status']);
        $bad = $sms->codes[1] === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 3; $i++) { $this->assertSame('INVALID', $auth->verify($second['challenge_id'], $bad, $csrf[1])['status']); }
        $this->assertSame('INVALID', $auth->verify($second['challenge_id'], $sms->codes[1], $csrf[1])['status']);
        $locked=$kernel->handle(new Request('POST','/account/verify',['cookie'=>$cookie],[],http_build_query(['csrf'=>$csrf[1], 'challenge'=>$second['challenge_id'], 'code'=>$sms->codes[1]])));
        $this->assertSame(429,$locked->status()); $this->assertStringContainsString('پشتیبانی تلگرام',$locked->body());
        $this->assertFalse($auth->challengeLocked($second['challenge_id'],str_repeat('Z',43)));
        $this->assertSame('CONFLICT', $auth->issue('+989120000002', 'TEST-second', $csrf[1])['status']);
        $this->assertSame('TELEGRAM', $auth->issue(self::PHONE, 'TEST-third', $csrf[1])['status']);
        $this->assertSame('TELEGRAM', $auth->issue('+989120000002', 'TEST-other', $csrf[1])['status']);
        $this->assertSame(403, $kernel->handle(new Request('POST','/account/logout',$cookie2,[],''))->status());
        $logout = $kernel->handle(new Request('POST','/account/logout',['cookie'=>'kandoo_session='.$session[1].'; kandoo_csrf='.$csrf[1]],[],http_build_query(['csrf'=>$csrf[1]])));
        $this->assertSame(303, $logout->status());
        $this->assertStringContainsString('Max-Age=0', $logout->header('Set-Cookie'));
        $this->assertNull($auth->phoneForToken($session[1]));
        // No invoice gets the same generic JSON envelope as malformed input, and sends no SMS.
        $apiHeaders=['content-type'=>'application/json','idempotency-key'=>'TEST-api-no-invoice','x-kandoo-device'=>str_repeat('Q',43)];
        $noInvoice=$kernel->handle(new Request('POST','/v1/customer/otp/challenges',$apiHeaders,[],json_encode(['phone'=>'+989120000099'])));
        $badPhone=$kernel->handle(new Request('POST','/v1/customer/otp/challenges',$apiHeaders,[],json_encode(['phone'=>'bad'])));
        $this->assertSame(400,$noInvoice->status()); $this->assertSame($badPhone->status(),$noInvoice->status());
        $this->assertSame($badPhone->body(),$noInvoice->body()); $this->assertCount(2,$sms->codes);
        // Three distinct numbers per device/day, including non-invoice attempts.
        $this->assertSame('INVALID', $auth->issue('+989120000003','TEST-no-invoice',$csrf[1])['status']);
        $this->assertSame('RATE_LIMITED', $auth->issue('+989120000004','TEST-fourth-number',$csrf[1])['status']);
        $this->assertSame('INVALID', $auth->issue('+989120000004','TEST-other-device',str_repeat('Z',43))['status']);
        // Admin reset restores announcement budget without deleting or resetting audit entries.
        $eventsBefore=(int)$pdo->query('SELECT COUNT(*) FROM cloud_admin_events')->fetchColumn();
        $this->assertTrue($auth->resetBudget(self::PHONE));
        $this->assertSame($eventsBefore+1,(int)$pdo->query('SELECT COUNT(*) FROM cloud_admin_events')->fetchColumn());
        $this->assertSame('OTP_SENT',$auth->issue(self::PHONE,'TEST-after-reset',$csrf[1])['status']);
        $clock->set(1767323045+3600);
        $this->assertNull($auth->phoneForToken($session[1]));
        $clock->set(1767398400);
        $this->assertSame('INVALID', $auth->issue('+989120000004','TEST-new-day',$csrf[1])['status']);
        foreach (['cloud_customer_sessions', 'cloud_otp_challenges', 'cloud_sms_announcements', 'cloud_customer_phones', 'cloud_otp_idempotency_locks'] as $table) { $pdo->exec('DELETE FROM '.$table); }
    }

    public function testIpBucketIsAtomicAndIndependentByAction(): void
    {
        $gateway=new MariaDbGateway($this->db());
        $key=hash('sha256', 'TEST-IP-' . bin2hex(random_bytes(16)));
        $this->assertTrue($gateway->rateHit('customer_issue',$key,1000,1));
        $this->assertFalse($gateway->rateHit('customer_issue',$key,1000,1));
        $this->assertTrue($gateway->rateHit('customer_verify',$key,1000,1));
        $this->assertFalse($gateway->rateHit('customer_verify',$key,1000,1));
        $this->assertTrue($gateway->rateHit('customer_issue',$key,1060,1));
    }
}
