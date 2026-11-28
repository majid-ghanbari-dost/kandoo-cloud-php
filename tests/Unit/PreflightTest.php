<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Tests\Support\DbTestCase;

/**
 * PHP-B / P-30: the host preflight must be honest in both directions — it passes on a correct
 * installation, and it FAILS (exit 1) when a deployment mistake is planted, e.g. a config file
 * inside the web root. It must also never print a secret value.
 *
 * Runs the real CLI against the CI database with FAKE credentials; no writes, no network.
 */
final class PreflightTest extends DbTestCase
{
    /** @param array<string,string> $extraEnv */
    private function run(array $arguments, array $extraEnv = []): array
    {
        $env = array_merge($_ENV, ['PATH' => (string) getenv('PATH'),
            'KANDOO_CONFIG' => Config::defaultPath()], $extraEnv);
        $command = array_merge([PHP_BINARY, dirname(__DIR__, 2).'/bin/preflight.php'], $arguments);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $this->assertIsResource($process, 'preflight CLI must start');
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process), $output, $error];
    }

    public function testPassesOnAHealthyInstallationAndPrintsNoSecret(): void
    {
        [$status, $output, $error] = $this->run([]);
        $this->assertSame(0, $status, $output.$error);
        $this->assertStringContainsString('PREFLIGHT PASS', $output);
        foreach (['php', 'config', 'config_location', 'web_root', 'no_dumps_in_web_root', 'database', 'migrations', 'tables'] as $check) {
            $this->assertMatchesRegularExpression('/^PASS\s+'.$check.':/m', $output, 'check '.$check);
        }
        $this->assertStringContainsString('Passing preflight is not deploy approval', $output);
        // No configured secret may surface: not the DB password, not the OTP pepper.
        $secret = (string) (self::$config['db']['pass'] ?? '');
        if ($secret !== '') { $this->assertStringNotContainsString($secret, $output.$error, 'database password leaked'); }
        $pepper = (string) (self::$config['customer_auth']['otp_pepper'] ?? '');
        if ($pepper !== '') { $this->assertStringNotContainsString($pepper, $output.$error, 'OTP pepper leaked'); }
    }

    public function testStrictModeTurnsAnExistingWarningIntoFailure(): void
    {
        [$lenient, $output] = $this->run(['--json']);
        $this->assertSame(0, $lenient, $output);
        $report = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('PASS', $report['status']);
        $this->assertNotEmpty($report['checks']);
        if ((int) $report['warn'] === 0) {
            $this->markTestSkipped('CI configuration has no warnings to gate on');
        }
        [$status, $strictOutput, $error] = $this->run(['--strict']);
        $this->assertSame(1, $status, 'strict mode must fail while a warning remains: '.$strictOutput.$error);
        $this->assertStringContainsString('PREFLIGHT FAIL (0 fail', $strictOutput);
    }

    public function testConfigInsideTheWebRootIsAFailure(): void
    {
        $directory = sys_get_temp_dir().'/kandoo-preflight-'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($directory.'/public', 0700, true));
        try {
            file_put_contents($directory.'/public/index.php', "<?php echo 'ok';");
            file_put_contents($directory.'/public/config.php', '<?php return [];');
            [$status, $output, $error] = $this->run([], ['KANDOO_APP_ROOT' => $directory,
                'KANDOO_CONFIG' => $directory.'/public/config.php']);
            $this->assertSame(1, $status, 'planted config.php must fail the preflight: '.$output.$error);
            $this->assertMatchesRegularExpression('/^FAIL\s+web_root:/m', $output);
            $this->assertMatchesRegularExpression('/^FAIL\s+config_location:.*INSIDE the web root/m', $output);
        } finally {
            @unlink($directory.'/public/index.php');
            @unlink($directory.'/public/config.php');
            @rmdir($directory.'/public');
            @rmdir($directory);
        }
    }
}
