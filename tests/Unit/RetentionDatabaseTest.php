<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Customer\CustomerAuth;
use Kandoo\Cloud\Customer\OtpTransport;
use Kandoo\Cloud\Retention\Cleanup;
use Kandoo\Cloud\Store\MariaDbGateway;
use Kandoo\Cloud\Support\FixedClock;
use Kandoo\Cloud\Tests\Support\DbTestCase;

final class RetentionDatabaseTest extends DbTestCase
{
    public function testRetentionAndReplayProtectActiveAndPermanentRecords(): void
    {
        $db=$this->db(); $pdo=$db->pdo(); $gateway=new MariaDbGateway($db); $gateway->reset();
        foreach (['cloud_customer_sessions','cloud_otp_challenges','cloud_otp_idempotency_locks','cloud_sms_announcements','cloud_sms_delivery_requests'] as $table) { $pdo->exec('DELETE FROM '.$table); }
        $store='11111111-1111-4111-8111-111111111111'; $invoice='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $phone='+989120000001'; $ann='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $gateway->seedStore(['store_id'=>$store,'active_writer_device_id'=>$store,'active_writer_epoch'=>1,'accepted_through_seq'=>0,'entry_count'=>0,'head_entry_hash'=>null,'projection_version'=>0]);
        $gateway->seedProjection(['public_token'=>'TESTRETENTION123456789012', 'public_token_hash'=>hash('sha256','TESTRETENTION123456789012'), 'digital_invoice_id'=>$invoice,'invoice_id'=>$invoice,'store_id'=>$store,'store_name'=>'TEST','display_number'=>7,'lifecycle_status'=>'ISSUED','issued_at'=>'2026-01-02 03:04:05','issued_payload'=>'{}','issued_content_sha256'=>str_repeat('a',64),'payable_rials'=>1,'line_items'=>'[]','customer_phone'=>$phone]);
        $pdo->prepare('INSERT INTO cloud_sms_announcements (announcement_id,store_id,invoice_id,phone,delivered_at) VALUES (?,?,?,?,?)')->execute([$ann,$store,$invoice,$phone,1]);
        $pdo->prepare('INSERT INTO cloud_sms_delivery_requests (request_id,store_id,invoice_id,phone,state,created_at) VALUES (?,?,?,?,?,?)')->execute([$ann,$store,$invoice,$phone,'confirmed',1]);
        $pdo->prepare('INSERT INTO cloud_batches (store_id,batch_id,fingerprint) VALUES (?,?,?)')->execute([$store,'TEST-retention',str_repeat('f',64)]);
        $pdo->exec("INSERT INTO cloud_admin_events (event_type,occurred_at) VALUES ('RETENTION_TEST','2000-01-01 00:00:00')");
        $eventId=(int)$pdo->lastInsertId();
        $eventCount=(int)$pdo->query('SELECT COUNT(*) FROM cloud_admin_events')->fetchColumn();
        $settings=['enabled'=>true,'code_length'=>6,'code_ttl_seconds'=>300,'max_attempts'=>3,'codes_per_announcement'=>2,'issue_window_seconds'=>900,'max_issues_per_window'=>3,'session_ttl_seconds'=>3600,'otp_pepper'=>'TEST-only-synthetic-otp-pepper-0123456789'];
        $sms=new class implements OtpTransport { public function sendCode(string $phone,string $code): bool { return true; } };
        $clock=new FixedClock(2000000000); $auth=new CustomerAuth($settings,$db,$clock,$sms); $device=str_repeat('A',43);
        $issued=$auth->issue($phone,'TEST-retention-key',$device); $this->assertSame('OTP_SENT',$issued['status']);
        $pdo->prepare('INSERT INTO cloud_customer_sessions (token_hash,phone,expires_at) VALUES (?,?,?),(?,?,?)')->execute([str_repeat('a',64),$phone,1999999900,str_repeat('b',64),$phone,2000100000]);
        $ret=['otp_challenge_seconds'=>100,'expired_session_seconds'=>200,'idempotency_seconds'=>1100,'undeliverable_sms_seconds'=>3000];
        $cleanup=new Cleanup($db,$ret,900);
        $first=$cleanup->run(2000000200);
        $this->assertSame(0,$first['otp_challenges']); // still unexpired
        $this->assertSame(1,$first['expired_sessions']);
        $counts=$cleanup->run(2000001000);
        $this->assertSame(1,$counts['otp_challenges']); $this->assertSame(0,$counts['expired_sessions']);
        $this->assertSame('DUPLICATE',$auth->issue($phone,'TEST-retention-key',$device)['status']);
        $this->assertSame('CONFLICT',$auth->issue('+989120000002','TEST-retention-key',$device)['status']);
        $this->assertSame(1,(int)$pdo->query("SELECT COUNT(*) FROM cloud_customer_sessions WHERE token_hash='".str_repeat('b',64)."'")->fetchColumn());
        $this->assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM cloud_sms_announcements')->fetchColumn());
        $this->assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM cloud_sms_delivery_requests')->fetchColumn());
        $this->assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM cloud_batches')->fetchColumn());
        $this->assertSame($eventCount,(int)$pdo->query('SELECT COUNT(*) FROM cloud_admin_events')->fetchColumn());
        $this->assertSame(1,$cleanup->run(2000001101)['otp_idempotency_keys']);
        // Failed delivery is held to the 90-day-style SMS interval; pending is never pruned.
        $insert=$pdo->prepare('INSERT INTO cloud_otp_challenges (challenge_id,phone,announcement_id,idempotency_hash,code_mac,created_at,expires_at,delivery_state) VALUES (?,?,?,?,?,?,?,?)');
        $insert->execute(['cccccccc-cccc-4ccc-8ccc-cccccccccccc',$phone,$ann,str_repeat('c',64),str_repeat('a',64),2000000000,2000000300,'failed']);
        $insert->execute(['dddddddd-dddd-4ddd-8ddd-dddddddddddd',$phone,$ann,str_repeat('d',64),str_repeat('a',64),2000000000,2000000300,'pending']);
        $this->assertSame(0,$cleanup->run(2000002999)['otp_challenges']);
        $this->assertSame(1,$cleanup->run(2000003001)['otp_challenges']);
        $this->assertSame(1,(int)$pdo->query("SELECT COUNT(*) FROM cloud_otp_challenges WHERE delivery_state='pending'")->fetchColumn());
        $pdo->prepare('DELETE FROM cloud_admin_events WHERE event_id=?')->execute([$eventId]);
        $gateway->reset();
        foreach (['cloud_customer_sessions','cloud_otp_challenges','cloud_otp_idempotency_locks','cloud_customer_phones','cloud_sms_announcements','cloud_sms_delivery_requests'] as $table) { $pdo->exec('DELETE FROM '.$table); }
    }
}
