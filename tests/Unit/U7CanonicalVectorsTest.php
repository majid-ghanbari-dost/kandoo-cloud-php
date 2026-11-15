<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Json\Canonical;
use Kandoo\Cloud\Json\JsonObject;
use Kandoo\Cloud\Json\Parser;
use Kandoo\Cloud\Json\Writer;
use PHPUnit\Framework\TestCase;

/** Shared U7 and expanded cross-stack fixtures are sourced from the portable vector file. */
final class U7CanonicalVectorsTest extends TestCase
{
    public function testSharedCanonicalBytesAndSha256(): void
    {
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/docs/vectors/canonical-vectors-v1.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue(count($fixture['vectors']) >= 40);
        $ids = [];
        foreach ($fixture['vectors'] as $vector) {
            $id = $vector['id'];
            $this->assertTrue(!isset($ids[$id]));
            $ids[$id] = true;
            $actual = Writer::encodeCanonical(Parser::parse($vector['input_json']));
            $this->assertSame($vector['canonical_hex'], bin2hex($actual), "$id bytes");
            $this->assertSame($vector['sha256'], hash('sha256', $actual), "$id SHA-256");
        }
        for ($i = 1; $i <= 12; $i++) { $this->assertTrue(isset($ids['V' . $i])); }
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
