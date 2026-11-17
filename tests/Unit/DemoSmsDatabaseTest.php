<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Customer\CustomerAuth;
use Kandoo\Cloud\Demo\DemoSms;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Kernel;
use Kandoo\Cloud\Sms\AnnouncementService;
use Kandoo\Cloud\Store\MariaDbGateway;
use Kandoo\Cloud\Support\FixedClock;
use Kandoo\Cloud\Tests\Support\DbTestCase;

final class DemoSmsDatabaseTest extends DbTestCase
{
    public function testLocalOutboxAnnouncementAndOtpWithAdminGuard(): void
    {
        $db=$this->db(); $pdo=$db->pdo(); $gateway=new MariaDbGateway($db); $gateway->reset();
        foreach (['cloud_otp_challenges','cloud_otp_idempotency_locks','cloud_sms_announcements','cloud_sms_delivery_requests','cloud_demo_sms_outbox','cloud_customer_sessions'] as $table) { $pdo->exec('DELETE FROM '.$table); }
        $store='11111111-1111-4111-8111-111111111111'; $invoice='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'; $id='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'; $phone='+989120000001';
        $gateway->seedStore(['store_id'=>$store,'active_writer_device_id'=>$store,'active_writer_epoch'=>1,'accepted_through_seq'=>0,'entry_count'=>0,'head_entry_hash'=>null,'projection_version'=>0]);
        $gateway->seedProjection(['public_token'=>'TESTTOKEN1234567890123456','public_token_hash'=>hash('sha256','TESTTOKEN1234567890123456'),'digital_invoice_id'=>$invoice,'invoice_id'=>$invoice,'store_id'=>$store,'store_name'=>'TEST Store','display_number'=>7,'lifecycle_status'=>'ISSUED','issued_at'=>'2026-01-02 03:04:05','issued_payload'=>'{}','issued_content_sha256'=>str_repeat('a',64),'payable_rials'=>1000,'line_items'=>'[]','customer_phone'=>$phone]);
        $dir=sys_get_temp_dir().'/demo-sms-'.bin2hex(random_bytes(8)); $this->assertTrue(mkdir($dir,0700));
        $config=self::$config; $config['env']='demo'; $config['base_url']='http://192.168.1.5:8080'; $config['faraz_sms']=['enabled'=>false];
        $config['admin_password_hash']=password_hash('TEST-admin-only',PASSWORD_DEFAULT);
        $config['customer_auth']=['enabled'=>true,'code_length'=>6,'code_ttl_seconds'=>300,'max_attempts'=>3,'codes_per_announcement'=>2,'issue_window_seconds'=>900,'max_issues_per_window'=>3,'session_ttl_seconds'=>3600,'otp_pepper'=>'TEST-only-synthetic-otp-pepper-0123456789'];
        try {
            $clock=new FixedClock(2000000000); $adapter=new DemoSms($config,$db,$clock,$dir); $service=new AnnouncementService($db,$adapter,$clock);
            $this->assertSame('ACCEPTED',$service->send($id,$store,$invoice));
            $this->assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM cloud_sms_announcements')->fetchColumn());
            // Explicit local operator confirmation, never claim actual SMS delivery.
            $this->assertSame('CONFIRMED',$service->confirmDelivery($id));
            $auth=new CustomerAuth($config['customer_auth'],$db,$clock,$adapter); $device=str_repeat('Q',43);
            $this->assertSame('OTP_SENT',$auth->issue($phone,'TEST-demo-key',$device)['status']);
            $messages=$pdo->query('SELECT * FROM cloud_demo_sms_outbox ORDER BY kind')->fetchAll(); $this->assertCount(2,$messages);
            $this->assertStringContainsString('/account/login',$messages[0]['body']);
            $this->assertStringContainsString('کد ورود',$messages[1]['body']);
            $log=file_get_contents($dir.'/sms.log'); $this->assertSame(2,count(file($dir.'/sms.log'))); $this->assertStringContainsString($messages[1]['body'],$log);
            $gateway->setAdminEnabled(true,'2026-01-01 00:00:00');
            $kernel=new Kernel($config,$gateway,$clock,$auth);
            $basic='Basic '.base64_encode('admin:TEST-admin-only');
            $this->assertSame(403,$kernel->handle(new Request('GET','/admin',['authorization'=>$basic],[],'',null,'192.168.1.6'))->status());
            $this->assertSame(401,$kernel->handle(new Request('GET','/admin',[],[],'',null,'192.168.1.5'))->status());
            $admin=$kernel->handle(new Request('GET','/admin',['authorization'=>$basic],[],'',null,'192.168.1.5'));
            $this->assertSame(200,$admin->status()); $this->assertStringContainsString($messages[1]['body'],$admin->body());
            $this->assertStringContainsString('ارسال واقعی نشده',$admin->body());
        } finally {
            foreach (['cloud_otp_challenges','cloud_otp_idempotency_locks','cloud_sms_announcements','cloud_sms_delivery_requests','cloud_demo_sms_outbox','cloud_customer_sessions','cloud_customer_phones'] as $table) { $pdo->exec('DELETE FROM '.$table); }
            $gateway->reset(); if (is_file($dir.'/sms.log')) { unlink($dir.'/sms.log'); } rmdir($dir);
        }
    }
}
