<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Json\Canonical;
use Kandoo\Cloud\Json\Parser;
use Kandoo\Cloud\Kernel;
use Kandoo\Cloud\Store\MariaDbGateway;
use Kandoo\Cloud\Support\FixedClock;
use Kandoo\Cloud\Tests\Support\DbTestCase;

final class V11DatabaseTest extends DbTestCase
{
    public function testInvalidExtensionRollsBackThenValidPersists(): void
    {
        $f=json_decode((string)file_get_contents(dirname(__DIR__,2).'/docs/golden/g-023-replication-issue-projection-e2e.json'),true);
        $base=json_decode($f['steps'][0]['request']['body_text'],true);
        $gateway=new MariaDbGateway($this->db()); $gateway->reset(); $gateway->seedStore($f['given']['stores'][0]);
        $kernel=new Kernel(self::$config,$gateway,new FixedClock(1767323045));
        $headers=[]; foreach($f['steps'][0]['request']['headers'] as $key=>$value) { $headers[strtolower($key)]=$value; }
        foreach ([false,true] as $valid) {
            $batch=$base;
            $batch['entries'][2]['response_snapshot']['response']['invoice']['payments']=[['type'=>'cash','amount'=>$valid ? 12 : -1]];
            $batch['entries'][2]['response_snapshot']['response']['invoice']['remaining_balance']=4;
            $entry=$batch['entries'][2];
            $dto=Canonical::entryDto(Parser::parse((string)json_encode($entry,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
            $batch['entries'][2]['entry_hash']=Canonical::entryHash($dto);
            $response=$kernel->handle(new Request('POST',$f['steps'][0]['request']['path'],$headers,[],(string)json_encode($batch,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
            $this->assertSame($valid ? 200 : 500,$response->status());
            $this->assertCount($valid ? 3 : 0,$gateway->entriesOf($batch['store_id']));
            $rows=$gateway->projectionsOf($batch['store_id']); $this->assertCount($valid ? 1 : 0,$rows);
            if ($valid) { $this->assertSame(4,$rows[0]['remaining_balance']); $this->assertSame('[{"type":"cash","amount":12}]',$rows[0]['payments']); }
        }
    }
}
