<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Json\IntegerProfileException;
use Kandoo\Cloud\Json\JsonNumber;
use Kandoo\Cloud\Json\JsonObject;
use Kandoo\Cloud\Json\JsonParseException;
use Kandoo\Cloud\Json\Parser;
use Kandoo\Cloud\Json\UndefinedElement;
use Kandoo\Cloud\Json\Writer;
use PHPUnit\Framework\TestCase;

/**
 * Strict parser + dual-mode writer tests (CLOUD-CONTRACT.md §5.5).
 */
final class ParserWriterTest extends TestCase
{
    public function testParsesOrderedObjectWithTypedValues(): void
    {
        $node = Parser::parse('{"b":1,"a":[true,null,"x"],"c":-42}');
        $this->assertInstanceOf(JsonObject::class, $node);
        $this->assertSame(['b', 'a', 'c'], $node->keys()); // insertion order preserved
        $this->assertSame(1, $node->get('b'));
        $this->assertSame(-42, $node->get('c'));
        $this->assertSame([true, null, 'x'], $node->get('a'));
    }

    public function testRejectsDuplicateKeys(): void
    {
        try {
            Parser::parse('{"a":1,"a":2}');
            $this->fail('Expected duplicate-key rejection.');
        } catch (JsonParseException $e) {
            $this->assertStringContainsString('Duplicate object key', $e->getMessage());
        }
    }

    public function testRejectsMalformedInput(): void
    {
        foreach ([
            'trailing'   => '{"a":1} x',
            'empty'      => '',
            'bad-utf8'   => "\"\xC3\x28\"",
            'ctrl-char'  => "\"a\x01b\"",
            'bad-escape' => '"\\x"',
            'lone-high'  => '"\uD83D"',
            'lone-low'   => '"\uDE00"',
            'leading-0'  => '01',
            'unclosed'   => '{"a":',
        ] as $label => $input) {
            try {
                Parser::parse($input);
                $this->fail("Expected rejection for: $label");
            } catch (JsonParseException) {
                $this->assertTrue(true, $label);
            }
        }
    }

    public function testDepthLimitIs64(): void
    {
        $ok = str_repeat('[', 63) . '1' . str_repeat(']', 63); // innermost value at depth 64
        $this->assertTrue(is_array(Parser::parse($ok)));        // parses without error
        $tooDeep = str_repeat('[', 64) . '1' . str_repeat(']', 64);
        try {
            Parser::parse($tooDeep);
            $this->fail('Expected depth-limit rejection.');
        } catch (JsonParseException $e) {
            $this->assertStringContainsString('depth', $e->getMessage());
        }
    }

    public function testNumbersIntVsNonInt(): void
    {
        $this->assertSame(9223372036854775807, Parser::parse('9223372036854775807'));
        $big = Parser::parse('9223372036854775808');
        $this->assertInstanceOf(JsonNumber::class, $big);
        $this->assertSame('9223372036854775808', $big->raw());
        $float = Parser::parse('1.5');
        $this->assertInstanceOf(JsonNumber::class, $float);
        $exp = Parser::parse('1e3');
        $this->assertInstanceOf(JsonNumber::class, $exp);
        $this->assertSame('1e3', $exp->raw());
    }

    public function testSurrogatePairsDecodeToUtf8(): void
    {
        $this->assertSame('😀', Parser::parse('"\uD83D\uDE00"'));
    }

    public function testWriterPreserveModeKeepsOrderAndEscapesPerContract(): void
    {
        $node = JsonObject::fromMap(['b' => 1, 'a' => 2]);
        $this->assertSame('{"b":1,"a":2}', Writer::encode($node));
        $this->assertSame('"a\\u0001\\b\\t\\n\\"\\\\zب"', Writer::encode("a\x01\x08\t\n\"\\zب"));
        $this->assertSame('[1,2,[]]', Writer::encode([1, 2, []]));
        $this->assertSame('null', Writer::encode(null));
        $this->assertSame('true', Writer::encode(true));
    }

    public function testWriterCanonicalModeSortsKeysByBytes(): void
    {
        $node = JsonObject::fromMap(['b' => 1, 'A' => 2, 'a' => 3, '_' => 4]);
        // byte order: 'A'(65) < '_'(95) < 'a'(97) < 'b'(98)
        $this->assertSame('{"A":2,"_":4,"a":3,"b":1}', Writer::encodeCanonical($node));
    }

    public function testCanonicalRejectsNonIntegersAndUndefined(): void
    {
        try {
            Writer::encodeCanonical(new JsonNumber('1.5'));
            $this->fail('Expected integer-profile violation for float.');
        } catch (IntegerProfileException) {
            $this->assertTrue(true);
        }
        try {
            Writer::encodeCanonical(UndefinedElement::instance());
            $this->fail('Expected integer-profile violation for absent element.');
        } catch (IntegerProfileException) {
            $this->assertTrue(true);
        }
        // preserve mode passes JsonNumber through raw (payload stored as delivered, U16)
        $this->assertSame('{"x":1.5}', Writer::encode(JsonObject::fromMap(['x' => new JsonNumber('1.5')])));
    }
}
