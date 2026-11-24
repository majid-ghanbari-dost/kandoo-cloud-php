<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Json\Canonical;
use Kandoo\Cloud\Json\Parser;
use Kandoo\Cloud\Kernel;
use Kandoo\Cloud\Store\MemoryGateway;
use Kandoo\Cloud\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class V11ProjectionTest extends TestCase
{
    private function runBatch(array $v11): array
    {
        $fixture=json_decode((string)file_get_contents(dirname(__DIR__,2).'/docs/golden/g-023-replication-issue-projection-e2e.json'),true);
        $batch=json_decode($fixture['steps'][0]['request']['body_text'],true);
        $batch['entries'][2]['response_snapshot']['response']['invoice']=array_merge($batch['entries'][2]['response_snapshot']['response']['invoice'],$v11);
        $entry=$batch['entries'][2];
        $dto=Canonical::entryDto(Parser::parse((string)json_encode($entry,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
        $entry['entry_hash']=Canonical::entryHash($dto); $batch['entries'][2]=$entry;
        $g=new MemoryGateway(); $g->seedStore($fixture['given']['stores'][0]);
        $config=Config::validate(['base_url'=>'https://TEST.invalid','db'=>['host'=>'TEST','name'=>'TEST','user'=>'TEST','pass'=>'TEST'], 'env'=>'testing','replication_auth_bypass'=>true]);
        $k=new Kernel($config,$g,new FixedClock(1767323045));
        $req=$fixture['steps'][0]['request']; $headers=[]; foreach($req['headers'] as $key=>$value) { $headers[strtolower($key)]=$value; }
        $res=$k->handle(new Request('POST',$req['path'],$headers,[],(string)json_encode($batch,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
        return [$res,$g,$k,$batch['store_id']];
    }
    public function testRecordedValuesAreStoredAndRenderedIndependently(): void
    {
        [$response,$gateway,$kernel,$store]=$this->runBatch(['invoice_schema_version'=>'1.1','customer_name'=>str_repeat('م',200),'currency_unit'=>'toman','remaining_balance'=>17]);
        $this->assertSame(200,$response->status()); $rows=$gateway->projectionsOf($store); $this->assertCount(1,$rows);
        $this->assertSame(str_repeat('م',200),$rows[0]['customer_name']);
        $this->assertSame(17,$rows[0]['remaining_balance']); $this->assertNull($rows[0]['payments']);
        $html=$kernel->handle(new Request('GET','/i/'.$rows[0]['public_token']));
        $this->assertStringContainsString('مانده باقی‌مانده (ثبت‌شده)', $html->body());
        $this->assertStringContainsString('17</bdi> تومان', $html->body());
        $json=$kernel->handle(new Request('GET','/i/'.$rows[0]['public_token'],['accept'=>'application/json']));
        $this->assertStringContainsString('"remaining_balance":17',$json->body());
    }
    public function testExtraPaymentFieldsAndInvalidAmountsRollBack(): void
    {
        foreach ([['type'=>'cash','amount'=>5,'extra'=>'TEST'],['type'=>'cash','amount'=>-1],['type'=>'other','amount'=>0],['type'=>'cash']] as $payment) {
            [$response,$gateway,,$store]=$this->runBatch(['invoice_schema_version'=>'1.1','payments'=>[$payment]]);
            $this->assertSame(400,$response->status());
            $this->assertSame([],$gateway->entriesOf($store));
            $this->assertSame([],$gateway->projectionsOf($store));
            $this->assertSame(0,$gateway->findStore($store)['accepted_through_seq']);
        }
    }

    /** E-72 is a separate Python draft, not an approved Cloud v1.1 amendment. */
    public function testE72FieldsDoNotSilentlyBecomeCloudV11Features(): void
    {
        $source=json_decode((string)file_get_contents(dirname(__DIR__,2).'/docs/vectors/e72/canonical-invoice-v1-2-draft-vector.json'),true,512,JSON_THROW_ON_ERROR);
        $draft=$source['v1_2_draft_addendum'];
        [$rejected,$gateway,,$store]=$this->runBatch(['invoice_schema_version'=>'1.1','payments'=>$draft['payments']]);
        $this->assertSame(400,$rejected->status());
        $this->assertSame('VALIDATION_ERROR',json_decode($rejected->body(),true)['code']);
        $this->assertSame([],$gateway->projectionsOf($store));
        // With a valid v1.1 payment, extra E-72 fields are not projected or exposed.
        [$response,$gateway,$kernel,$store]=$this->runBatch([
            'invoice_schema_version'=>'1.1', 'payments'=>[['type'=>'cash','amount'=>850]],
            'invoice_discount'=>$draft['invoice_discount'], 'walk_in_customer'=>$draft['walk_in_customer'],
        ]);
        $this->assertSame(200,$response->status());
        $row=$gateway->projectionsOf($store)[0];
        $this->assertArrayNotHasKey('invoice_discount',$row);
        $this->assertArrayNotHasKey('walk_in_customer',$row);
        $view=json_decode($kernel->handle(new Request('GET','/i/'.$row['public_token'],['accept'=>'application/json']))->body(),true);
        $this->assertArrayNotHasKey('invoice_discount',$view);
        $this->assertArrayNotHasKey('walk_in_customer',$view);
    }

    public function testInvalidFieldsAndMarkerReturnValidationErrorAndRollback(): void
    {
        $cases = [
            ['invoice_schema_version'=>'1.1', 'customer_name'=>''],
            ['invoice_schema_version'=>'1.1', 'payments'=>'cash'],
            ['invoice_schema_version'=>'1.1', 'currency_unit'=>'dollar'],
            ['invoice_schema_version'=>'1.1', 'remaining_balance'=>-1],
            ['invoice_schema_version'=>'1.2'],
            ['invoice_schema_version'=>null],
        ];
        foreach ($cases as $case) {
            [$response,$gateway,,$store] = $this->runBatch($case);
            $this->assertSame(400, $response->status());
            $this->assertSame([
                'code'=>'VALIDATION_ERROR',
                'message'=>'The invoice version marker or v1.1 fields are invalid.',
                'retry_hint'=>['action'=>'FIX_REQUEST'],
            ], json_decode($response->body(), true));
            $this->assertSame([], $gateway->entriesOf($store));
            $this->assertSame([], $gateway->projectionsOf($store));
            $this->assertSame(0, $gateway->findStore($store)['accepted_through_seq']);
        }
    }

    public function testMarkerAbsentUsesLegacySemanticsWithoutInferringFields(): void
    {
        [$response,$gateway,$kernel,$store] = $this->runBatch([
            'customer_name'=>'Recorded but unversioned', 'payments'=>[['type'=>'cash','amount'=>5]],
            'currency_unit'=>'toman', 'remaining_balance'=>12,
        ]);
        $this->assertSame(200, $response->status());
        $row = $gateway->projectionsOf($store)[0];
        foreach (['customer_name','payments','currency_unit','remaining_balance'] as $field) {
            $this->assertNull($row[$field]);
        }
        $json = $kernel->handle(new Request('GET','/i/'.$row['public_token'],['accept'=>'application/json']));
        $view = json_decode($json->body(), true);
        foreach (['customer_name','payments','currency_unit','remaining_balance'] as $field) {
            $this->assertFalse(array_key_exists($field, $view));
        }
    }

    public function testMixedLegacyAndV11BatchKeepsOnlyRecordedFields(): void
    {
        // The G-023 batch contains two legacy entries and one issue entry.
        [$response,$gateway,,$store] = $this->runBatch([
            'invoice_schema_version'=>'1.1', 'payments'=>[['type'=>'cash','amount'=>5]],
        ]);
        $this->assertSame(200, $response->status());
        $this->assertCount(3, $gateway->entriesOf($store));
        $row = $gateway->projectionsOf($store)[0];
        $this->assertSame('[{"type":"cash","amount":5}]', $row['payments']);
        $this->assertNull($row['customer_name']);
        $this->assertNull($row['currency_unit']);
        $this->assertNull($row['remaining_balance']);
    }

    public function testExistingProjectionFailuresRemainInternalError(): void
    {
        [$response,$gateway,,$store] = $this->runBatch([
            'invoice_schema_version'=>'1.1', 'issued_content_sha256'=>str_repeat('0',64),
        ]);
        $this->assertSame(500, $response->status());
        $this->assertSame('INTERNAL_ERROR', json_decode($response->body(), true)['code']);
        $this->assertSame([], $gateway->entriesOf($store));
        $this->assertSame([], $gateway->projectionsOf($store));
    }
}
