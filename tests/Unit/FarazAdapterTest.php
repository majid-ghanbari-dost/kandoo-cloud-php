<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Sms\FarazAdapter;
use PHPUnit\Framework\TestCase;

final class FarazAdapterTest extends TestCase
{
    public function testBothPatternsInDryMode(): void
    {
        $adapter = new FarazAdapter(['dry_run'=>true, 'api_key'=>'', 'line_number'=>'TEST-LINE', 'announcement_pattern'=>'TEST-ANNOUNCEMENT', 'otp_pattern'=>'TEST-OTP']);
        $this->assertTrue($adapter->sendAnnouncement('+989120000001'));
        $this->assertTrue($adapter->sendCode('+989120000001', '123456'));
        $sent = $adapter->dryRequests();
        $this->assertCount(2, $sent);
        $this->assertSame('TEST-ANNOUNCEMENT', $sent[0]['code']);
        $this->assertSame([], (array) $sent[0]['attributes']);
        $this->assertSame('TEST-OTP', $sent[1]['code']);
        $this->assertSame(['code'=>'123456'], (array) $sent[1]['attributes']);
        $this->assertSame('09120000001', $sent[1]['recipient']);
        $this->assertFalse($adapter->sendCode('+989120000001', 'not-digits'));
        $original = getenv('KANDOO_BLOCK_REAL_SMS');
        putenv('KANDOO_BLOCK_REAL_SMS=1');
        try {
            $live = new FarazAdapter(['dry_run'=>false, 'api_key'=>'TEST-FAKE', 'line_number'=>'TEST-LINE', 'announcement_pattern'=>'TEST-ANNOUNCEMENT', 'otp_pattern'=>'TEST-OTP']);
            $this->assertFalse($live->sendCode('+989120000001', '123456'));
        } finally { $original === false ? putenv('KANDOO_BLOCK_REAL_SMS') : putenv('KANDOO_BLOCK_REAL_SMS='.$original); }
    }
}
