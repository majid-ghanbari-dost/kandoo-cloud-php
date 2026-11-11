<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Json\Canonical;
use Kandoo\Cloud\Json\JsonObject;
use Kandoo\Cloud\Json\Parser;
use Kandoo\Cloud\Json\Writer;
use PHPUnit\Framework\TestCase;

/** Shared U7 V1-V12: inputs are built from code points, never copied from rendered JSON. */
final class U7CanonicalVectorsTest extends TestCase
{
    private static function fromCodepoints(array $codepoints): string
    {
        $text = '';
        foreach ($codepoints as $cp) {
            if ($cp <= 0x7f) {
                $text .= chr($cp);
            } elseif ($cp <= 0x7ff) {
                $text .= chr(0xc0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3f));
            } else {
                $text .= chr(0xe0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3f)) . chr(0x80 | ($cp & 0x3f));
            }
        }
        return $text;
    }

    public function testSharedCanonicalBytesAndSha256(): void
    {
        $vectors = [
            'V1' => [[0x06a9,0x0646,0x062f,0x0648], '7b2270726f6265223a22daa9d986d8afd988227d', 'ceed0b19692bbc3184107f0a4c4f1d328a895656ae247b74b1bcf8006d61e7db'],
            'V2' => [[0x062f,0x06cc,0x062c,0x06cc,0x062a,0x0627,0x0644,0x200c,0x0641,0x0627,0x06a9,0x062a,0x0648,0x0631], '7b2270726f6265223a22d8afdb8cd8acdb8cd8aad8a7d984e2808cd981d8a7daa9d8aad988d8b1227d', '039a818afb59196c436d4058a6aace3d545645a1ba013f003694cffd6a127531'],
            'V3' => [[0x0645,0x062f,0x064a,0x0631], '7b2270726f6265223a22d985d8afd98ad8b1227d', '4463ef265ef926d185317b2fe5e1ba85f50a5f997b02e7f833a9ade4b93749fb'],
            'V4' => [[0x0643,0x0646,0x062f,0x0648], '7b2270726f6265223a22d983d986d8afd988227d', 'c8e64e79a339b24cadc288bf5d9e43ca4fa922f02d05b1bf78a0a8f1c638adf0'],
            'V5' => [[0x06a9,0x0646,0x062f,0x0648,0x0020,0x0645,0x062f,0x06cc,0x0631], '7b2270726f6265223a22daa9d986d8afd98820d985d8afdb8cd8b1227d', 'edd9bfff735b41d49585defca405ae536b3798692237fe7519740e4291f8eb76'],
            'V6' => [[0x0061,0x0000,0x0062,0x0001,0x0063,0x001f,0x0064], '7b2270726f6265223a22615c7530303030625c7530303031635c753030314664227d', 'e86f78c17e387ba3f7c3d537389bd6a8fad119cf6ddc46795cc28795f0bc30a4'],
            'V7' => [[0x0064,0x0065,0x006c,0x007f,0x0078], '7b2270726f6265223a2264656c5c753030374678227d', '0d51cd0b6002c85b33a72365357afd73575f2dcc1507df5b21757efb27f68aa0'],
            'V8' => [[0x0639,0x0644,0x06cc,0x200c,0x0631,0x0636,0x0627,0x0007,0x0628], '7b2270726f6265223a22d8b9d984db8ce2808cd8b1d8b6d8a75c7530303037d8a8227d', '9217d07efe51414fed0987f807631f80e5a11c8b1842671045ea17c6c3853006'],
            'V9' => [[0x0022,0x005c,0x0022,0x002f], '7b2270726f6265223a225c225c5c5c222f227d', '199c497795cd4b23321f62baa1ab9a5715ddc9dfc4a8f10f7e2b3eebc514283a'],
            'V10' => [[0x0074,0x0009,0x006e,0x000a,0x06a9], '7b2270726f6265223a22745c75303030396e5c7530303041daa9227d', '8c4e12e66dc4824a50677b24ce0e62e932c5a615015a6634ef4e9e0715fdebe0'],
            'V11' => [[0x200c], '7b2270726f6265223a22e2808c227d', '7386d86c90f48784415b6f59fcf171bf95f6fd7710e68661bbb8a49ad82ed849'],
            'V12' => [[], '7b2270726f6265223a22227d', '2c59ad8dd7bbce6c3bf974f4a5911ee9758a403f386bc058b373dda3daa6b3c4'],
        ];
        foreach ($vectors as $id => [$codepoints, $expectedHex, $expectedHash]) {
            $actual = Writer::encodeCanonical(JsonObject::fromMap(['probe' => self::fromCodepoints($codepoints)]));
            $this->assertSame($expectedHex, bin2hex($actual), "$id bytes");
            $this->assertSame($expectedHash, hash('sha256', $actual), "$id SHA-256");
        }
    }

    public function testEveryControlEscapedInKeysAndNestedValues(): void
    {
        for ($cp = 0; $cp <= 0x1f; $cp++) {
            $char = chr($cp);
            $escaped = sprintf('\\u%04X', $cp);
            $this->assertSame('{"'.$escaped.'":["'.$escaped.'"]}', Writer::encodeCanonical(JsonObject::fromMap([$char => [$char]])));
        }
        $this->assertSame('{"\\u007F":["\\u007F"]}', Writer::encodeCanonical(JsonObject::fromMap([chr(0x7f) => [chr(0x7f)]])));
    }

    public function testEntryAndBatchHashUseCanonicalProfile(): void
    {
        $fixture = json_decode((string)file_get_contents(dirname(__DIR__, 2).'/docs/golden/g-002-ingest-accept.json'), true);
        $batch = Parser::parse($fixture['steps'][0]['request']['body_text']);
        $entries = $batch->get('entries');
        $pairs = $entries[0]->pairs();
        foreach ($pairs as &$pair) {
            if ($pair[0] === 'payload') {
                $pair[1] = JsonObject::fromMap(['probe' => chr(0x09).chr(0x0a)]);
            }
        }
        unset($pair);
        $dto = Canonical::entryDto(new JsonObject($pairs));
        $entryPairs = array_values(array_filter($dto->pairs(), static fn ($pair) => $pair[0] !== 'entry_hash'));
        $entryBytes = Writer::encodeCanonical(new JsonObject($entryPairs));
        $this->assertStringContainsString('"probe":"\\u0009\\u000A"', $entryBytes);
        $this->assertSame(hash('sha256', $entryBytes), Canonical::entryHash($dto));
        $batchDto = JsonObject::fromMap(['batch_id'=>$batch->get('batch_id'), 'store_id'=>$batch->get('store_id'),
            'writer_device_id'=>$batch->get('writer_device_id'), 'writer_epoch'=>$batch->get('writer_epoch'), 'entries'=>[$dto]]);
        $batchBytes = Writer::encodeCanonical($batchDto);
        $this->assertStringContainsString('"probe":"\\u0009\\u000A"', $batchBytes);
        $this->assertSame(hash('sha256', $batchBytes), Canonical::batchFingerprint($batch->get('batch_id'), $batch->get('store_id'), $batch->get('writer_device_id'), $batch->get('writer_epoch'), [$dto]));
    }
}
