<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DeployGitResourceLimitsTest extends TestCase
{
    #[Test]
    public function bounded_housekeeping_preserves_the_exact_commit_and_archive(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/deploy.php');
        self::assertSame(1, preg_match('/function deployGitWithResourceLimits\(string \$git\): string\n\{.*?\n\}/s', $source, $match));
        if (! function_exists('deployGitWithResourceLimits')) {
            eval($match[0]);
        }
        $git = \deployGitWithResourceLimits('git');
        self::assertStringContainsString('$git = deployGitWithResourceLimits(get(\'bin/git\'));', $source);
        $directory = sys_get_temp_dir().'/fap-git-memory-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $run = static function (string $arguments) use ($git, $directory): string {
            return Process::fromShellCommandline($git.' '.$arguments, $directory)->mustRun()->getOutput();
        };
        try {
            $run('init -q');
            file_put_contents($directory.'/body.txt', str_repeat('exact package bytes\n', 10000));
            $run('add -- body.txt');
            $run('-c user.name=Fixture -c user.email=fixture@example.test commit -qm fixture');
            $sha = trim($run('rev-parse HEAD'));
            $before = $run('archive '.$sha);
            foreach (['gc.autoDetach' => 'false', 'maintenance.autoDetach' => 'false', 'pack.threads' => '1', 'pack.windowMemory' => '64m', 'core.bigFileThreshold' => '16m'] as $key => $value) {
                self::assertSame($value, trim($run('config --get '.$key)));
            }
            $run('gc');
            self::assertSame($sha, trim($run('rev-parse HEAD')));
            self::assertSame($before, $run('archive '.$sha));
            $run('fsck --no-dangling');
            $missing = Process::fromShellCommandline($git.' archive '.str_repeat('f', 40), $directory);
            $missing->run();
            self::assertFalse($missing->isSuccessful());
        } finally {
            (new Process(['find', $directory, '-depth', '-delete']))->mustRun();
        }
    }
}
