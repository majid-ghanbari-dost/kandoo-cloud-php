<?php

declare(strict_types=1);

/**
 * LOCAL DEV TEST RUNNER — runs tests/Unit (+ tests/Contract when present) with the
 * minimal shim when the real PHPUnit is unavailable (e.g. PHP-in-WASM sandbox).
 * CI ALWAYS uses the real PHPUnit; this runner only pre-validates locally.
 *
 * Usage: php tools/dev/run-tests.php
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */

$root = dirname(__DIR__, 2);

if (!class_exists(PHPUnit\Framework\TestCase::class, false)) {
    require $root . '/tools/dev/phpunit-shim.php';
}
require $root . '/tests/bootstrap.php';

$dirs = ['tests/Unit', 'tests/Contract'];
$files = [];
foreach ($dirs as $dir) {
    $full = $root . '/' . $dir;
    if (!is_dir($full)) {
        continue;
    }
    foreach (scandir($full) ?: [] as $name) {
        if (str_ends_with($name, 'Test.php')) {
            $files[] = $full . '/' . $name;
        }
    }
}
sort($files);

$passed = 0;
$failed = 0;
$skipped = 0;
$failures = [];

$before = get_declared_classes();
foreach ($files as $file) {
    require_once $file;
}
$newClasses = array_values(array_diff(get_declared_classes(), $before));

foreach ($newClasses as $class) {
    if (!is_subclass_of($class, PHPUnit\Framework\TestCase::class)) {
        continue;
    }
    $reflection = new ReflectionClass($class);
    if ($reflection->isAbstract()) {
        continue;
    }
    $classSkipped = null;
    try {
        $class::setUpBeforeClass();
    } catch (PHPUnit\Framework\SkippedTestError $e) {
        $classSkipped = $e->getMessage();
    } catch (Throwable $e) {
        $failed++;
        $failures[] = "$class::setUpBeforeClass → " . get_class($e) . ': ' . $e->getMessage();
        continue;
    }

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!str_starts_with($method->getName(), 'test')) {
            continue;
        }
        $label = $class . '::' . $method->getName();
        if ($classSkipped !== null) {
            $skipped++;
            echo "s $label ($classSkipped)\n";
            continue;
        }
        // @dataProvider support (mirrors PHPUnit): resolve datasets, run each one.
        $datasets = [null => []];
        $doc = $method->getDocComment();
        if (is_string($doc) && preg_match('/@dataProvider\s+([A-Za-z0-9_]+)/', $doc, $providerMatch) === 1) {
            $providerName = $providerMatch[1];
            if (!$reflection->hasMethod($providerName)) {
                $failed++;
                $failures[] = "$label → @dataProvider $providerName not found";
                echo "E $label: @dataProvider $providerName not found\n";
                continue;
            }
            $provider = $reflection->getMethod($providerName);
            $provider->setAccessible(true);
            $data = $provider->invoke(null);
            $datasets = [];
            foreach ((array) $data as $key => $args) {
                $datasets[$key] = is_array($args) ? array_values($args) : [$args];
            }
        }
        foreach ($datasets as $dataKey => $args) {
            $caseLabel = count($datasets) > 1 && !is_int($dataKey) ? "$label [$dataKey]" : $label;
            try {
                /** @var PHPUnit\Framework\TestCase $instance */
                $instance = $reflection->newInstance();
                $setUp = $reflection->getMethod('setUp');
                $setUp->setAccessible(true);
                $setUp->invoke($instance);
                $method->invokeArgs($instance, $args);
                $tearDown = $reflection->getMethod('tearDown');
                $tearDown->setAccessible(true);
                $tearDown->invoke($instance);
                $passed++;
                echo ". $caseLabel\n";
            } catch (PHPUnit\Framework\SkippedTestError $e) {
                $skipped++;
                echo "s $caseLabel (" . $e->getMessage() . ")\n";
            } catch (PHPUnit\Framework\AssertionFailedError $e) {
                $failed++;
                $failures[] = "$caseLabel → " . $e->getMessage();
                echo "F $caseLabel: " . $e->getMessage() . "\n";
            } catch (Throwable $e) {
                $failed++;
                $failures[] = "$caseLabel → " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
                echo "E $caseLabel: " . get_class($e) . ': ' . $e->getMessage() . "\n";
            }
        }
    }
    try {
        $class::tearDownAfterClass();
    } catch (Throwable) {
        // ignore in the shim runner
    }
}

echo "\n──────────────────────────────\n";
echo "passed: $passed, failed: $failed, skipped: $skipped\n";
if ($failures !== []) {
    echo "\nFailures:\n";
    foreach ($failures as $line) {
        echo " - $line\n";
    }
    exit(1);
}
exit(0);
