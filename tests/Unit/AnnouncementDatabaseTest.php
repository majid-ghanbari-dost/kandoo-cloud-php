<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Sms\AnnouncementService;
use Kandoo\Cloud\Sms\FarazAdapter;
use Kandoo\Cloud\Store\MariaDbGateway;
use Kandoo\Cloud\Support\FixedClock;
use Kandoo\Cloud\Tests\Support\DbTestCase;

final class AnnouncementDatabaseTest extends DbTestCase
{
    public function testDrySendAndVerifiedConfirmationAreIdempotent(): void
    {
        $db=$this->db(); $pdo=$db->pdo();
        foreach (['cloud_otp_challenges','cloud_sms_announcements','cloud_sms_delivery_requests'] as $table) { $pdo->exec('DELETE FROM '.$table); }
        $g=new MariaDbGateway($db); $g->reset();
        $store='11111111-1111-4111-8111-111111111111'; $invoice='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'; $id='bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $g->seedStore(['store_id'=>$store,'active_writer_device_id'=>$store,'active_writer_epoch'=>1,'accepted_through_seq'=>0,'entry_count'=>0,'head_entry_hash'=>null,'projection_version'=>0]);
        $g->seedProjection(['public_token'=>'TESTTOKEN1234567890123456','public_token_hash'=>hash('sha256','TESTTOKEN1234567890123456'),'digital_invoice_id'=>$invoice,'invoice_id'=>$invoice,'store_id'=>$store,'store_name'=>'TEST Store','display_number'=>7,'lifecycle_status'=>'ISSUED','issued_at'=>'2026-01-02 03:04:05','issued_payload'=>'{}','issued_content_sha256'=>str_repeat('a',64),'payable_rials'=>1000,'line_items'=>'[]','customer_phone'=>'+989120000001']);
        $adapter=new FarazAdapter(['dry_run'=>true,'api_key'=>'TEST-FAKE','line_number'=>'TEST-LINE','announcement_pattern'=>'TEST-ANNOUNCEMENT','otp_pattern'=>'TEST-OTP']);
        $svc=new AnnouncementService($db,$adapter,new FixedClock(1767323045));
        $this->assertSame('ACCEPTED',$svc->send($id,$store,$invoice));
        $this->assertSame(0,(int)$pdo->query('SELECT COUNT(*) FROM cloud_sms_announcements')->fetchColumn());
        $this->assertSame('EXISTS',$svc->send($id,$store,$invoice));
        $this->assertCount(1,$adapter->dryRequests());
        $this->assertSame('CONFIRMED',$svc->confirmDelivery($id));
        $this->assertSame('EXISTS',$svc->confirmDelivery($id));
        $this->assertSame(1,(int)$pdo->query('SELECT COUNT(*) FROM cloud_sms_announcements')->fetchColumn());
        foreach (['cloud_otp_challenges','cloud_sms_announcements','cloud_sms_delivery_requests'] as $table) { $pdo->exec('DELETE FROM '.$table); }
    }
}
