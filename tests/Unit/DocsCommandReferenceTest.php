<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ANY AGENT (test hardening): documentation rot guard.
 *
 * The runbooks, deploy guide and P-30/P-33 kits tell the operator to run specific commands. If
 * a script is renamed or removed, the documentation keeps promising it — which on a host means
 * a failed go-live at the worst moment. This test makes every documented repo path a promise CI
 * checks, and pins the rule that every `bin/*.php` entry point refuses to run over HTTP.
 *
 * No network, no database, no writes.
 */
final class DocsCommandReferenceTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> */
    private static function documents(): array
    {
        $root = self::root();
        $documents = [];
        foreach (['README.md', 'AGENT_HANDOFF.md'] as $name) {
            if (is_file($root.'/'.$name)) { $documents[] = $root.'/'.$name; }
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/docs',
            \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'md') { $documents[] = $file->getPathname(); }
        }
        sort($documents);
        return $documents;
    }

    public function testEveryDocumentedRepositoryPathExists(): void
    {
        $pattern = '/\b(?:php\s+|python3?\s+)?((?:bin|tools\/dev|src|tests|migrations|docs)\/[A-Za-z0-9_.\/-]+\.(?:php|py|sql|md))/';
        $checked = 0;
        $broken = [];
        foreach (self::documents() as $document) {
            $text = (string) file_get_contents($document);
            preg_match_all($pattern, $text, $matches);
            foreach (array_unique($matches[1]) as $reference) {
                // Placeholders and globs are documentation conventions, not files.
                if (str_contains($reference, '<') || str_contains($reference, '*')) { continue; }
                $checked++;
                if (!file_exists(self::root().'/'.$reference)) {
                    $broken[] = basename(dirname($document)).'/'.basename($document).' → '.$reference;
                }
            }
        }
        $this->assertGreaterThan(20, $checked, 'the documents must reference real commands');
        $this->assertSame([], array_values(array_unique($broken)),
            'documentation references files that do not exist');
    }

    public function testEveryCliEntryPointRefusesWebAccess(): void
    {
        $entries = glob(self::root().'/bin/*.php') ?: [];
        $this->assertNotEmpty($entries, 'bin/ entry points must exist');
        foreach ($entries as $entry) {
            $source = (string) file_get_contents($entry);
            $this->assertMatchesRegularExpression("/PHP_SAPI\s*!==\s*'cli'/", $source,
                basename($entry).' must refuse to run outside the CLI');
            $this->assertStringContainsString('declare(strict_types=1);', $source,
                basename($entry).' must declare strict types');
        }
    }

    /** `--sample` must stay isolated: the self-test may only ever write to a TEST_ database. */
    public function testSampleSelfTestKeepsItsIsolationGuard(): void
    {
        $source = (string) file_get_contents(self::root().'/bin/selftest.php');
        $this->assertStringContainsString("'testing'", $source);
        $this->assertStringContainsString("KANDOO_BLOCK_REAL_SMS", $source);
        $this->assertStringContainsString("--sample", $source);
    }
}
