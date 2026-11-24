<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Customer\CustomerAuth;
use Kandoo\Cloud\Sms\AnnouncementService;
use Kandoo\Cloud\Sms\FarazAdapter;
use Kandoo\Cloud\Sms\SmsCostRecorder;
use Kandoo\Cloud\Store\MariaDbGateway;
use Kandoo\Cloud\Support\FixedClock;
use Kandoo\Cloud\Tests\Support\DbTestCase;

final class SmsCostIntegrationDatabaseTest extends DbTestCase
{
    public function testSyntheticCostsAreReservedOncePerAnnouncementAndOtp(): void
    {
        $db=$this->db(); $pdo=$db->pdo(); $g=new MariaDbGateway($db); $g->reset();
        foreach (['cloud_customer_sessions','cloud_otp_challenges','cloud_sms_announcements','cloud_sms_delivery_requests','cloud_customer_phones','cloud_otp_idempotency_locks'] as $t) { $pdo->exec('DELETE FROM '.$t); }
        $store='11111111-1111-4111-8111-111111111111'; $invoice='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'; $request='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'; $phone='+989120000001';
        $g->seedStore(['store_id'=>$store,'active_writer_device_id'=>$store,'active_writer_epoch'=>1,'accepted_through_seq'=>0,'entry_count'=>0,'head_entry_hash'=>null,'projection_version'=>0]);
        $g->seedProjection(['public_token'=>'TESTCOSTTOKEN123456789012','public_token_hash'=>hash('sha256','TESTCOSTTOKEN123456789012'),'digital_invoice_id'=>$invoice,'invoice_id'=>$invoice,'store_id'=>$store,'store_name'=>'TEST Store','display_number'=>1,'lifecycle_status'=>'ISSUED','issued_at'=>'2026-01-02 03:04:05','issued_payload'=>'{}','issued_content_sha256'=>str_repeat('a',64),'payable_rials'=>1,'line_items'=>'[]','customer_phone'=>$phone]);
        $clock=new FixedClock(1767323045); $cost=new SmsCostRecorder(['enabled'=>true,'notification_rials'=>1200,'otp_rials'=>700]);
        $fake=new FarazAdapter(['dry_run'=>true,'api_key'=>'TEST-FAKE','line_number'=>'TEST-LINE','announcement_pattern'=>'TEST-N','otp_pattern'=>'TEST-O']);
        try {
            $notice=new AnnouncementService($db,$fake,$clock,$cost);
            $this->assertSame('ACCEPTED',$notice->send($request,$store,$invoice));
            $this->assertSame('EXISTS',$notice->send($request,$store,$invoice));
            $this->assertSame('CONFIRMED',$notice->confirmDelivery($request));
            $settings=['enabled'=>true,'code_length'=>6,'code_ttl_seconds'=>300,'max_attempts'=>3,'codes_per_announcement'=>2,'issue_window_seconds'=>900,'max_issues_per_window'=>3,'session_ttl_seconds'=>3600,'otp_pepper'=>'TEST-only-synthetic-otp-pepper-0123456789'];
            $auth=new CustomerAuth($settings,$db,$clock,$fake,$cost);
            $this->assertSame('OTP_SENT',$auth->issue($phone,'TEST-cost-idem',str_repeat('Q',43))['status']);
            $this->assertSame('DUPLICATE',$auth->issue($phone,'TEST-cost-idem',str_repeat('Q',43))['status']);
            $rows=$pdo->query('SELECT pattern,unit_cost,currency,store_id FROM cloud_sms_cost_log ORDER BY entry_id')->fetchAll();
            $this->assertCount(2,$rows); $this->assertSame('notification',$rows[0]['pattern']);
            $this->assertSame('1200.000000',$rows[0]['unit_cost']); $this->assertSame('otp',$rows[1]['pattern']);
            $this->assertSame('700.000000',$rows[1]['unit_cost']); $this->assertSame('IRR',$rows[1]['currency']);
            $this->assertSame($store,$rows[1]['store_id']); $this->assertCount(2,$fake->dryRequests());
        } finally {
            foreach (['cloud_customer_sessions','cloud_otp_challenges','cloud_sms_announcements','cloud_sms_delivery_requests','cloud_customer_phones','cloud_otp_idempotency_locks'] as $t) { $pdo->exec('DELETE FROM '.$t); }
            $g->reset();
        }
    }
}
