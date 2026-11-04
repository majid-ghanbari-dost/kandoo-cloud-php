<?php

declare(strict_types=1);

/**
 * LOCAL DEV SHIM — a minimal PHPUnit\Framework\TestCase substitute so the same test
 * files can run in environments without PHPUnit (e.g. the PHP-in-WASM sandbox used
 * during development). CI ALWAYS uses the real PHPUnit 9 (fetched by the workflow);
 * this file is never referenced by phpunit.xml.dist or any deployment.
 *
 * Only the assertion surface actually used by tests/ is implemented.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */

namespace PHPUnit\Framework {

    class AssertionFailedError extends \Exception
    {
    }

    class SkippedTestError extends \Exception
    {
    }

    abstract class TestCase
    {
        private int $assertionCount = 0;

        public function getAssertionCount(): int
        {
            return $this->assertionCount;
        }

        protected function setUp(): void
        {
        }

        protected function tearDown(): void
        {
        }

        public static function setUpBeforeClass(): void
        {
        }

        public static function tearDownAfterClass(): void
        {
        }

        public static function markTestSkipped(string $message = ''): never
        {
            throw new SkippedTestError($message);
        }

        public function fail(string $message = ''): never
        {
            throw new AssertionFailedError($message);
        }

        protected function ok(bool $condition, string $message): void
        {
            $this->assertionCount++;
            if (!$condition) {
                throw new AssertionFailedError($message);
            }
        }

        public function assertTrue(mixed $condition, string $message = ''): void
        {
            $this->ok($condition === true, $message ?: 'Failed asserting that value is true.');
        }

        public function assertFalse(mixed $condition, string $message = ''): void
        {
            $this->ok($condition === false, $message ?: 'Failed asserting that value is false.');
        }

        public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
        {
            $this->ok(
                $expected === $actual,
                $message ?: 'Failed asserting same: expected ' . self::export($expected) . ', got ' . self::export($actual)
            );
        }

        public function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
        {
            $this->ok($expected !== $actual, $message ?: 'Failed asserting not-same.');
        }

        public function assertNull(mixed $actual, string $message = ''): void
        {
            $this->ok($actual === null, $message ?: 'Failed asserting null, got ' . self::export($actual));
        }

        public function assertNotNull(mixed $actual, string $message = ''): void
        {
            $this->ok($actual !== null, $message ?: 'Failed asserting not-null.');
        }

        /** @phpstan-assert !false $actual */
        public function assertNotFalse(mixed $actual, string $message = ''): void
        {
            $this->ok($actual !== false, $message ?: 'Failed asserting not-false.');
        }

        public function assertGreaterThan(mixed $expected, mixed $actual, string $message = ''): void
        {
            $this->ok($actual > $expected, $message ?: "Failed asserting $actual > $expected");
        }

        public function assertCount(int $expected, \Countable|array $actual, string $message = ''): void
        {
            $count = is_array($actual) ? count($actual) : $actual->count();
            $this->ok($count === $expected, $message ?: "Failed asserting count $expected, got $count");
        }

        public function assertNotEmpty(mixed $actual, string $message = ''): void
        {
            $this->ok(!empty($actual), $message ?: 'Failed asserting not empty.');
        }

        public function assertContains(mixed $needle, array $haystack, string $message = ''): void
        {
            $this->ok(in_array($needle, $haystack, true), $message ?: 'Failed asserting array contains ' . self::export($needle));
        }

        public function assertArrayHasKey(string|int $key, array $actual, string $message = ''): void
        {
            $this->ok(array_key_exists($key, $actual), $message ?: "Failed asserting array has key '$key'");
        }

        public function assertInstanceOf(string $expected, mixed $actual, string $message = ''): void
        {
            $this->ok($actual instanceof $expected, $message ?: 'Failed asserting instance of ' . $expected . ', got ' . get_debug_type($actual));
        }

        public function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
        {
            $this->ok(str_contains($haystack, $needle), $message ?: "Failed asserting string contains '$needle'");
        }

        public function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
        {
            $this->ok(!str_contains($haystack, $needle), $message ?: "Failed asserting string does not contain '$needle'");
        }

        public function assertMatchesRegularExpression(string $pattern, string $subject, string $message = ''): void
        {
            $this->ok(preg_match($pattern, $subject) === 1, $message ?: "Failed asserting match for $pattern");
        }

        private static function export(mixed $value): string
        {
            if (is_string($value)) {
                return "'" . (strlen($value) > 120 ? substr($value, 0, 117) . '…' : $value) . "'";
            }
            if (is_scalar($value) || $value === null) {
                return var_export($value, true);
            }
            return get_debug_type($value);
        }
    }
}
