<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Json\JsonObject;
use Kandoo\Cloud\Json\Parser;
use Kandoo\Cloud\Json\Writer;
use PHPUnit\Framework\TestCase;

/** Synthetic cross-lane review only, not acceptance of a new runtime contract. */
final class E72DraftVectorTest extends TestCase
{
    public function testPinnedVectorRoundTripsByteForByte(): void
    {
        $raw=file_get_contents(dirname(__DIR__,2).'/docs/vectors/e72/canonical-invoice-v1-2-draft-vector.json');
        $this->assertIsString($raw);
        $this->assertSame(1505,strlen($raw));
        $this->assertSame('0885c7571fb3ccc781f1a73e5505cd94805b065fb13e93f35fa4feb12d061d36',hash('sha256',$raw));
        $node=Parser::parse($raw); $this->assertInstanceOf(JsonObject::class,$node);
        $this->assertSame($raw,Writer::encodeCanonical($node));
        $base=$node->get('base_invoice'); $draft=$node->get('v1_2_draft_addendum');
        $this->assertInstanceOf(JsonObject::class,$base); $this->assertInstanceOf(JsonObject::class,$draft);
        $this->assertSame($base->get('invoice_id'),$draft->get('invoice_id'));
        $this->assertSame('1.2-draft',$draft->get('schema_version'));
        $this->assertSame('cheque',$draft->get('payments')[0]->get('type'));
        $this->assertSame(50,$draft->get('invoice_discount')->get('amount'));
        $this->assertTrue($draft->get('walk_in_customer'));
    }

    public function testFrozenU7EscapingMustNotBeAssumedToMatchPythonJsonDefaults(): void
    {
        $bytes=Writer::encodeCanonical(JsonObject::fromMap(['value'=>"\n\x7f"]));
        $this->assertSame('{"value":"\\u000A\\u007F"}',$bytes);
        // Python ensure_ascii=False JSON typically uses backslash-n and raw DEL instead.
        $this->assertNotSame("{\"value\":\"\\n\x7f\"}",$bytes);
    }
}
