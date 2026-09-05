<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SeoPlatform11GCompetitiveRuntimeConfigurationTest extends TestCase
{
    public function test_competitive_configuration_is_identical_with_and_without_config_cache(): void
    {
        $directory = sys_get_temp_dir().'/competitive-config-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $env = [
            'APP_ENV' => 'staging',
            'APP_CONFIG_CACHE' => $directory.'/config.php',
            'SEO_RELEASE_SHA' => str_repeat('a', 40),
            'SEO_COMPETITIVE_EXTERNAL_READ_ENABLED' => 'true',
            'SEO_COMPETITIVE_EVIDENCE_WRITE_ENABLED' => 'true',
        ];
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$command = $app->make(App\Console\Commands\SeoCompetitiveEvidenceIngest::class);
$boundary = new ReflectionMethod($command, 'writeBoundaryAllowed');
$result = [$boundary->invoke($command), config('seo_agent_evidence.competitive.release_sha')];
foreach (['external_read_enabled', 'evidence_write_enabled'] as $key) {
    config()->set('seo_agent_evidence.competitive.'.$key, false);
    $result[] = $boundary->invoke($command);
    try {
        $app->make(App\Services\SeoAgentEvidence\Competitive\CompetitiveSourcePolicyRegistry::class)
            ->installForControlledCli([], 'staging', str_repeat('a', 40));
        $result[] = 'unexpected-policy-install';
    } catch (RuntimeException $exception) {
        $result[] = $exception->getMessage();
    }
    config()->set('seo_agent_evidence.competitive.'.$key, true);
}
config()->set('seo_agent_evidence.competitive.release_sha', 'invalid');
$result[] = $boundary->invoke($command);
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;
        try {
            foreach ([false, true] as $cached) {
                if ($cached) {
                    (new Process([PHP_BINARY, 'artisan', 'config:cache'], base_path(), $env))->mustRun();
                }
                $process = new Process([PHP_BINARY, '-r', $script], base_path(), $env);
                $process->mustRun();
                $this->assertSame([
                    true, str_repeat('a', 40), false, 'COMPETITIVE_POLICY_INSTALL_HELD',
                    false, 'COMPETITIVE_POLICY_INSTALL_HELD', false,
                ], json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR));
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_competitive_reads_and_writes_default_to_disabled(): void
    {
        $this->assertFalse(config('seo_agent_evidence.competitive.external_read_enabled'));
        $this->assertFalse(config('seo_agent_evidence.competitive.evidence_write_enabled'));
        $command = app(\App\Console\Commands\SeoCompetitiveEvidenceIngest::class);
        $this->assertFalse((new \ReflectionMethod($command, 'writeBoundaryAllowed'))->invoke($command));
    }

    public function test_release_prepare_validates_the_resolved_cache_path_before_writer_access(): void
    {
        $directory = '/tmp/fermatmind-11g-production-'.random_int(100000, 999999).'-1';
        mkdir($directory.'/release/backend', 0700, true);
        file_put_contents($directory.'/release/REVISION', str_repeat('a', 40));
        foreach (['measurement', 'competitive-writer'] as $name) {
            file_put_contents($directory.'/'.$name.'.env', '');
            chmod($directory.'/'.$name.'.env', 0600);
        }
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$directory = $argv[1];
$app->setBasePath($directory.'/release/backend');
config()->set('seo_intel.write_enabled', false);
$command = $app->make(App\Console\Commands\SeoCompetitiveReleasePrepareCommand::class);
try {
    (new ReflectionMethod($command, 'preflight'))->invoke(
        $command, str_repeat('a', 40), 'competitive.big-five.live.v2',
        $directory.'/measurement.env', $directory.'/competitive-writer.env',
    );
    echo 'unexpected-write';
} catch (RuntimeException $exception) {
    echo $exception->getMessage();
}
PHP;
        try {
            foreach (['absent', 'outside', 'existing', 'symlink'] as $case) {
                $cache = $directory.'/competitive-config.php';
                if ($case === 'outside') {
                    $cache = $directory.'/outside.php';
                } elseif ($case === 'existing') {
                    file_put_contents($cache, '<?php return [];');
                } elseif ($case === 'symlink') {
                    symlink($directory.'/missing.php', $cache);
                }
                // Set the path after bootstrap so even an existing cache is inspected by preflight.
                $code = str_replace('$directory = $argv[1];', '$directory = $argv[1]; $_ENV["APP_CONFIG_CACHE"] = $_SERVER["APP_CONFIG_CACHE"] = $argv[2];', $script);
                $process = new Process([PHP_BINARY, '-r', $code, $directory, $cache], base_path(), [
                    'APP_ENV' => 'production', 'APP_CONFIG_CACHE' => $directory.'/bootstrap-only.php',
                ]);
                $process->mustRun();
                $this->assertSame($case === 'absent' ? 'EVIDENCE_WRITER_DISABLED' : 'CONFIG_CACHE_INVALID', $process->getOutput(), $case);
                if (is_file($cache) || is_link($cache)) {
                    unlink($cache);
                }
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
