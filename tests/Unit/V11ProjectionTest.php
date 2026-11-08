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
        [$response,$gateway,$kernel,$store]=$this->runBatch(['customer_name'=>str_repeat('م',200),'currency_unit'=>'toman','remaining_balance'=>17]);
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
            [$response,$gateway,,$store]=$this->runBatch(['payments'=>[$payment]]);
            $this->assertSame(500,$response->status());
            $this->assertSame([],$gateway->entriesOf($store));
            $this->assertSame([],$gateway->projectionsOf($store));
            $this->assertSame(0,$gateway->findStore($store)['accepted_through_seq']);
        }
    }
}
