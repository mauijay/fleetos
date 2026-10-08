<?php

declare(strict_types=1);

namespace Tests\Tooling;

use PHPUnit\Framework\TestCase;

final class SqlAllowlistCliTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    private function input(string $bytes): string
    {
        $directory = __DIR__ . '/../../build/sql-allowlist-hardening';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = tempnam($directory, 'synthetic-');
        $this->assertIsString($path);
        $this->files[] = $path;
        $this->assertNotFalse(file_put_contents($path, $bytes));

        return $path;
    }

    /** @param list<string> $arguments
     *  @return array{int, string, string}
     */
    private function cli(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, '-n', __DIR__ . '/../../tools/deployment/sql-allowlist.php', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertIsString($stdout);
        $this->assertIsString($stderr);

        return [proc_close($process), $stdout, $stderr];
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    public function testGenerateSerializeVerifyUseSharedContract(): void
    {
        $expected = ["CREATE TABLE synthetic (\r\n label VARCHAR(64) DEFAULT 'é'\r\n);", 'SELECT 12;'];
        $actual = array_map(static fn (string $sql): string => str_replace("\r\n", "\n", $sql), $expected);
        [$status, $manifest, $stderr] = $this->cli(['generate', $this->input(json_encode($expected, JSON_THROW_ON_ERROR))]);
        $this->assertSame(0, $status);
        $this->assertSame('', $stderr);
        $this->assertStringEndsWith("\n", $manifest);
        $decoded = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($expected, array_column($decoded['statements'], 'sql'));
        $this->assertSame(hash('sha256', $expected[0]), $decoded['statements'][0]['raw_sha256']);
        $this->assertSame(hash('sha256', $actual[0]), $decoded['statements'][0]['canonical_sha256']);
        $path = $this->input($manifest);
        [$status, $stdout, $stderr] = $this->cli(['verify', $path, $this->input(json_encode($actual, JSON_THROW_ON_ERROR))]);
        $this->assertSame(0, $status);
        $this->assertSame('', $stderr);
        $report = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['passed']);
        $this->assertTrue($report['statements'][0]['line_ending_only_difference']);
        $actual[0] = str_replace('VARCHAR(64)', 'VARCHAR( 64 )', $actual[0]);
        [$status, $stdout, $stderr] = $this->cli(['verify', $path, $this->input(json_encode($actual, JSON_THROW_ON_ERROR))]);
        $this->assertSame(2, $status);
        $this->assertSame('', $stderr);
        $this->assertFalse(json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['passed']);
        $this->assertStringNotContainsString('CREATE TABLE', $stdout);
    }

    public function testMalformedFilesAndUsageRejectWithoutSensitiveOutput(): void
    {
        foreach ([[], ['generate'], ['generate', $this->input('synthetic private malformed SQL')], ['generate', $this->input('123')], ['generate', $this->input('[123]')], ['generate', __DIR__]] as $arguments) {
            [$status, $stdout, $stderr] = $this->cli($arguments);
            $this->assertSame(2, $status);
            $this->assertSame('', $stdout);
            $this->assertStringContainsString('No SQL executed.', $stderr);
            $this->assertStringNotContainsString('private', $stderr);
            $this->assertStringNotContainsString(__DIR__, $stderr);
        }
    }
}
