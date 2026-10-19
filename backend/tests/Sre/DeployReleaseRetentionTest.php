<?php

declare(strict_types=1);

namespace Tests\Sre;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class DeployReleaseRetentionTest extends TestCase
{
    #[Test]
    public function only_old_empty_locks_without_metadata_are_reclaimed(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/deploy.php');
        $start = strpos($source, "    \$reclaimScript = <<<'PHP'\n");
        self::assertNotFalse($start);
        $start += strlen("    \$reclaimScript = <<<'PHP'\n");
        $end = strpos($source, "\nPHP;", $start);
        self::assertNotFalse($end);
        $script = substr($source, $start, $end - $start);
        $root = sys_get_temp_dir().'/fap-lock-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        try {
            foreach ([['', 600, false, true], ['', 0, false, false], ['', 600, true, false], [' ', 600, false, false], ['operator', 600, false, false]] as [$contents, $age, $metadata, $success]) {
                file_put_contents($root.'/lock', $contents);
                touch($root.'/lock', time() - $age);
                if ($metadata) {
                    file_put_contents($root.'/meta', '{}');
                }
                $process = new Process([PHP_BINARY, '-r', $script, $root.'/lock', $root.'/meta', '999', '1']);
                $process->run();
                self::assertSame($success, $process->isSuccessful(), $process->getErrorOutput());
                self::assertSame(! $success, file_exists($root.'/lock'));
                if (file_exists($root.'/lock')) {
                    unlink($root.'/lock');
                }
                if (file_exists($root.'/meta')) {
                    unlink($root.'/meta');
                }
            }
        } finally {
            (new Process(['find', $root, '-depth', '-delete']))->mustRun();
        }
    }

    #[Test]
    public function failed_candidates_do_not_displace_live_rollback_or_prepared_releases(): void
    {
        $result = $this->exercise(false);
        self::assertSame(['r10', 'r3', 'r4', 'r7', 'r8', 'r9'], $result['remaining']);
        self::assertSame(['r10', 'r9', 'r8', 'r7', 'r4', 'r3'], $result['registered']);
        self::assertTrue($result['shared_preserved']);
    }

    #[Test]
    public function a_symlink_candidate_aborts_before_deleting_any_release(): void
    {
        $result = $this->exercise(true);
        self::assertTrue($result['rejected']);
        self::assertSame(['r10', 'r3', 'r4', 'r5', 'r6', 'r7', 'r8', 'r9'], $result['remaining']);
        self::assertTrue($result['shared_preserved']);
    }

    #[Test]
    public function non_serialized_invocations_do_not_prune_releases(): void
    {
        $result = $this->exercise(false, false);
        self::assertFalse($result['rejected']);
        self::assertCount(8, $result['remaining']);
    }

    private function exercise(bool $symlink, bool $serialized = true): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/deploy.php');
        self::assertSame(1, preg_match('/function deployReleaseRetentionCandidates\(.*?\n\}\n/s', $source, $function));
        self::assertSame(1, preg_match("/task\('fap:prune-retained-releases-before-materialization', function \(\) \{.*?\n\}\);/s", $source, $task));
        self::assertStringContainsString("after('fap:reclaim-stale-serialized-ci-lock', 'fap:prune-retained-releases-before-materialization');", $source);
        $root = sys_get_temp_dir().'/fap-retention-'.bin2hex(random_bytes(8));
        mkdir($root, 0700);
        mkdir($root.'/releases');
        mkdir($root.'/shared');
        file_put_contents($root.'/shared/authority', 'preserve');
        foreach (['r10', 'r9', 'r8', 'r7', 'r6', 'r5', 'r4', 'r3'] as $name) {
            if ($symlink && $name === 'r5') {
                symlink($root.'/shared', $root.'/releases/'.$name);
            } else {
                mkdir($root.'/releases/'.$name);
            }
        }
        symlink($root.'/releases/r4', $root.'/current');
        symlink($root.'/releases/r7', $root.'/release');
        $harness = <<<'PHP'
<?php
putenv('TRUNK_DEPLOY_SERIALIZED='.$argv[2]);
putenv('DEPLOY_LOCK_RUN_ID=999');
putenv('DEPLOY_LOCK_RUN_ATTEMPT=1');
$root = $argv[1];
$settings = ['releases_list' => ['r10','r9','r8','r7','r6','r5','r4','r3'], 'keep_releases' => 3, 'rollback_candidate' => 'r3', 'cleanup_use_sudo' => false];
function get($key) { return $GLOBALS['settings'][$key]; }
function set($key, $value) { $GLOBALS['settings'][$key] = $value; }
function run($command) {
    $command = str_replace(['{{current_path}}','{{deploy_path}}'], [escapeshellarg($GLOBALS['root'].'/current'), escapeshellarg($GLOBALS['root'])], $command);
    exec($command.' 2>/dev/null', $output, $exit);
    if ($exit !== 0) throw new RuntimeException('Remote command rejected');
    return implode("\n", $output);
}
function test($command) { try { run($command); return true; } catch (RuntimeException) { return false; } }
function deployPlaceholderPathArg($base, $suffix) { return escapeshellarg($GLOBALS['root'].'/'.$suffix); }
function writeln($text) {}
function task($name, $callback) { $GLOBALS['callback'] = $callback; }
PHP;
        $harness .= "\n".$function[0]."\n".$task[0]."\n";
        $harness .= <<<'PHP'
$rejected = false;
try { ($GLOBALS['callback'])(); } catch (RuntimeException) { $rejected = true; }
$remaining = array_values(array_diff(scandir($root.'/releases'), ['.','..']));
echo json_encode(['remaining'=>$remaining,'registered'=>$settings['releases_list'],'rejected'=>$rejected,'shared_preserved'=>file_get_contents($root.'/shared/authority')==='preserve']);
PHP;
        file_put_contents($root.'/exercise.php', $harness);
        try {
            $process = new Process([PHP_BINARY, $root.'/exercise.php', $root, $serialized ? 'true' : 'false']);
            $process->mustRun();

            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            (new Process(['find', $root, '-depth', '-delete']))->mustRun();
        }
    }
}
