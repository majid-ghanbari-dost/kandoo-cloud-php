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
        // Native API: only the session owner can list their own invoices, grouped by store.
        $missing=$kernel->handle(new Request('GET','/v1/customer/invoices'));
        $this->assertSame(401,$missing->status());
        $invalid=$kernel->handle(new Request('GET','/v1/customer/invoices',['authorization'=>'Bearer '.str_repeat('A',43)]));
        $this->assertSame(401,$invalid->status());
        $apiList=$kernel->handle(new Request('GET','/v1/customer/invoices',['authorization'=>'Bearer '.$session[1]]));
        $this->assertSame(200,$apiList->status());
        $this->assertSame('no-store',$apiList->header('Cache-Control'));
        $native=json_decode($apiList->body(),true);
        $this->assertCount(1,$native['stores']);
        $this->assertSame(self::STORE,$native['stores'][0]['store_id']);
        $this->assertSame(self::INVOICE,$native['stores'][0]['invoices'][0]['invoice_id']);
        $this->assertCount(1,$native['stores'][0]['invoices']);
        $this->assertStringNotContainsString('profit',strtolower($apiList->body()));
        $this->assertStringNotContainsString('TEST Other',$apiList->body());
        $nativePath='/v1/customer/invoices/'.self::STORE.'/'.self::INVOICE;
        $this->assertSame(401,$kernel->handle(new Request('GET',$nativePath))->status());
        $nativeDetail=$kernel->handle(new Request('GET',$nativePath,['authorization'=>'Bearer '.$session[1]]));
        $this->assertSame(200,$nativeDetail->status());
        $this->assertSame(25000,json_decode($nativeDetail->body(),true)['invoice']['payable_rials']);
        $this->assertStringNotContainsString('public_token',$nativeDetail->body());
        $this->assertStringNotContainsString('profit',strtolower($nativeDetail->body()));
        $otherPath='/v1/customer/invoices/'.self::STORE.'/cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $this->assertSame(404,$kernel->handle(new Request('GET',$otherPath,['authorization'=>'Bearer '.$session[1]]))->status());
        $gateConfig=$config; $gateConfig['public_legal_copy_required']=true;
        $gateKernel=new Kernel($gateConfig,$gateway,$clock,$auth);
        $this->assertSame(503,$gateKernel->handle(new Request('GET',$nativePath,['authorization'=>'Bearer '.$session[1]]))->status());
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
        $this->assertStringContainsString('class="locked-state"', $locked->body());
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

    public function testNativeOtpListDetailLogoutAndDeviceBinding(): void
    {
        $db=$this->db(); $pdo=$db->pdo(); $gateway=new MariaDbGateway($db); $gateway->reset();
        foreach (['cloud_customer_sessions','cloud_otp_challenges','cloud_sms_announcements','cloud_customer_phones','cloud_otp_idempotency_locks'] as $table) { $pdo->exec('DELETE FROM '.$table); }
        $gateway->seedStore(['store_id'=>self::STORE,'active_writer_device_id'=>self::STORE,'active_writer_epoch'=>1,'accepted_through_seq'=>0,'entry_count'=>0,'head_entry_hash'=>null,'projection_version'=>0]);
        $gateway->seedProjection(['public_token'=>'TESTNATIVETOKEN12345678901','public_token_hash'=>hash('sha256','TESTNATIVETOKEN12345678901'),'digital_invoice_id'=>self::INVOICE,'invoice_id'=>self::INVOICE,'store_id'=>self::STORE,'store_name'=>'TEST Store','display_number'=>7,'lifecycle_status'=>'ISSUED','issued_at'=>'2026-01-02 03:04:05','issued_payload'=>'{}','issued_content_sha256'=>str_repeat('a',64),'payable_rials'=>25000,'line_items'=>'[]','customer_phone'=>self::PHONE]);
        $q=$pdo->prepare('INSERT INTO cloud_sms_announcements (announcement_id,store_id,invoice_id,phone,delivered_at) VALUES (?,?,?,?,?)');
        $q->execute([self::ANNOUNCEMENT,self::STORE,self::INVOICE,self::PHONE,1767323045]);
        $settings=['enabled'=>true,'code_length'=>6,'code_ttl_seconds'=>300,'max_attempts'=>3,'codes_per_announcement'=>2,'issue_window_seconds'=>900,'max_issues_per_window'=>3,'session_ttl_seconds'=>3600,'otp_pepper'=>'TEST-only-synthetic-otp-pepper-0123456789','telegram_url'=>null];
        $transport=new class implements OtpTransport { public string $code=''; public function sendCode(string $phone,string $code): bool { $this->code=$code; return true; } };
        $clock=new FixedClock(1767323045); $auth=new CustomerAuth($settings,$db,$clock,$transport); $config=self::$config; $config['customer_auth']=$settings;
        $kernel=new Kernel($config,$gateway,$clock,$auth); $device=str_repeat('Q',43);
        // The same language-neutral proposed vectors can be replayed by C-92.
        $vectors=json_decode(file_get_contents(__DIR__.'/../../docs/vectors/customer-api-v1-2-proposed.json'),true,512,JSON_THROW_ON_ERROR);
        $vars=$vectors['setup'];
        $resolve=static function ($value) use (&$resolve, &$vars) {
            if (is_array($value)) { return array_map($resolve,$value); }
            if (!is_string($value)) { return $value; }
            return preg_replace_callback('/\{\{([a-z_]+)\}\}/',static function ($match) use (&$vars) {
                if (!isset($vars[$match[1]]) || !is_string($vars[$match[1]])) { throw new \RuntimeException('Missing vector variable: '.$match[1]); }
                return $vars[$match[1]];
            },$value);
        };
        try {
            foreach ($vectors['steps'] as $step) {
                $vars['otp']=$transport->code;
                $headers=$resolve($step['headers']);
                $body=isset($step['body']) ? json_encode($resolve($step['body']),JSON_THROW_ON_ERROR) : '';
                $response=$kernel->handle(new Request($step['method'],$resolve($step['path']),$headers,[],$body,null,'192.0.2.1'));
                $this->assertSame($step['expect']['http'],$response->status(),$step['id']);
                $this->assertSame('no-store',$response->header('Cache-Control'),$step['id']);
                $actual=json_decode($response->body(),true,512,JSON_THROW_ON_ERROR);
                $this->assertVectorSubset($resolve($step['expect']['body']),$actual,$step['id']);
                foreach (($step['expect']['capture'] ?? []) as $name=>$field) {
                    $this->assertIsString($actual[$field] ?? null,$step['id'].' capture '.$field);
                    $vars[$name]=$actual[$field];
                }
            }
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D',$vars['session']);
            $this->assertSame(1,(int)$pdo->query('SELECT code_issues FROM cloud_sms_announcements')->fetchColumn());
        } finally {
            foreach (['cloud_customer_sessions','cloud_otp_challenges','cloud_sms_announcements','cloud_customer_phones','cloud_otp_idempotency_locks'] as $table) { $pdo->exec('DELETE FROM '.$table); }
            $gateway->reset();
        }
    }

    /** Objects are required subsets; arrays remain exact, including order and count. */
    private function assertVectorSubset($expected, $actual, string $label): void
    {
        if (is_array($expected) && $expected !== [] && array_keys($expected) !== range(0,count($expected)-1)) {
            $this->assertIsArray($actual,$label);
            foreach ($expected as $key=>$value) {
                $this->assertArrayHasKey($key,$actual,$label);
                $this->assertVectorSubset($value,$actual[$key],$label.'.'.$key);
            }
            return;
        }
        $this->assertSame($expected,$actual,$label);
    }

    public function testAdminRateBucketIsAtomicAndPrunableOnMariaDb(): void
    {
        $gateway=new MariaDbGateway($this->db());
        $key=hash('sha256','TEST-admin-peer-'.bin2hex(random_bytes(16)));
        $this->assertTrue($gateway->rateHit('admin',$key,1000,1));
        $this->assertFalse($gateway->rateHit('admin',$key,1000,1));
        $this->assertTrue($gateway->rateHit('admin',$key,1060,1));
        $gateway->pruneRateBuckets(1120);
        $this->assertSame(0,(int)$this->db()->pdo()->query("SELECT COUNT(*) FROM admin_rate_buckets WHERE window_start < 1120")->fetchColumn());
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
