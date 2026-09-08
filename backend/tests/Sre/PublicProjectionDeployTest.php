<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PublicProjectionDeployTest extends TestCase
{
    public function test_existing_pre_activation_guard_refuses_an_unaware_reader_after_migration(): void
    {
        $deploy = file_get_contents(dirname(__DIR__, 3).'/deploy.php');
        preg_match("/<<<'PYTHON'\n(import json, pathlib, sys.*?)\nPY\nPYTHON/s", $deploy, $match);
        $this->assertNotEmpty($match[1] ?? null);
        $directory = sys_get_temp_dir().'/projection-guard-'.bin2hex(random_bytes(5));
        mkdir($directory);
        $state = $directory.'/state.json';
        $reader = $directory.'/reader.php';
        try {
            foreach (['legacy' => true, 'mirror' => false, 'primary' => false, 'isolated' => false, 'unknown' => false] as $mode => $expected) {
                file_put_contents($state, json_encode(['version' => 1, 'mode' => $mode]));
                $process = new Process(['python3', '-c', $match[1], $state, $reader]);
                $process->run();
                $this->assertSame($expected, $process->isSuccessful(), $mode);
            }
            file_put_contents($reader, '<?php');
            file_put_contents($state, json_encode(['version' => 1, 'mode' => 'primary']));
            (new Process(['python3', '-c', $match[1], $state, $reader]))->mustRun();
            $this->assertStringContainsString("after('prepare:cache-lifecycle-storage', 'cache:prepare-public-projection')", $deploy);
            $this->assertStringContainsString("after('cache:prepare-public-projection', 'career:prune-public-cache-versions')", $deploy);
            $this->assertStringContainsString("after('deploy:success', 'cache:accept-public-projection')", $deploy);
        } finally {
            @unlink($reader);
            @unlink($state);
            rmdir($directory);
        }
    }
}
