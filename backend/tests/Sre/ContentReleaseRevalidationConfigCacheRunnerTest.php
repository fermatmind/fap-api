<?php

declare(strict_types=1);

namespace Tests\Sre;

use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

final class ContentReleaseRevalidationConfigCacheRunnerTest extends TestCase
{
    private string $fixtureRoot;

    private string $binDir;

    private const CONTROL_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const ACTIVE_SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const SECRET = 'shared_secret_with_sufficient_entropy_123456';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = sys_get_temp_dir().'/fap-api-revalidation-config-'.bin2hex(random_bytes(6));
        $this->binDir = $this->fixtureRoot.'/bin';
        $release = $this->fixtureRoot.'/releases/release-1';
        File::ensureDirectoryExists($this->binDir);
        File::ensureDirectoryExists($release.'/backend/bootstrap/cache');
        File::ensureDirectoryExists($release.'/backend/config');
        File::ensureDirectoryExists($this->fixtureRoot.'/shared/backend');
        File::ensureDirectoryExists($this->fixtureRoot.'/.dep');
        symlink($release, $this->fixtureRoot.'/current');

        File::put($release.'/REVISION', self::ACTIVE_SHA."\n");
        File::put($release.'/backend/config/ops.php', "<?php return [];\n");
        File::put(
            $this->fixtureRoot.'/shared/backend/.env',
            "APP_ENV=production\nCONTENT_RELEASE_REVALIDATE_SECRET=wrong_secret_with_sufficient_entropy\n",
        );
        File::put(
            $release.'/backend/bootstrap/cache/config.php',
            "<?php return ['ops' => ['content_release_observability' => ['hmac_revalidation_secret' => 'wrong_secret_with_sufficient_entropy', 'hmac_revalidation_url' => 'https://old.example.test/revalidate']]];\n",
        );
        File::put(
            $release.'/backend/artisan',
            <<<'PHP'
<?php
$env = [];
foreach (file(__DIR__.'/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[$key] = $value;
}
$config = [
    'ops' => [
        'content_release_observability' => [
            'hmac_revalidation_secret' => $env['CONTENT_RELEASE_REVALIDATE_SECRET'] ?? '',
            'hmac_revalidation_url' => $env['ENNEAGRAM_AUTHORITY_V2_REVALIDATION_URL'] ?? '',
        ],
    ],
];
file_put_contents(__DIR__.'/bootstrap/cache/config.php', '<?php return '.var_export($config, true).';'."\n");
PHP,
        );
        symlink($this->fixtureRoot.'/shared/backend/.env', $release.'/backend/.env');
        File::put(
            $this->binDir.'/sudo',
            <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
while [[ "${1:-}" == -* || "${1:-}" == "www-data" ]]; do shift; done
exec "$@"
BASH,
        );
        File::put(
            $this->binDir.'/timeout',
            <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
shift
exec "$@"
BASH,
        );
        chmod($this->binDir.'/sudo', 0755);
        chmod($this->binDir.'/timeout', 0755);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureRoot);
        parent::tearDown();
    }

    public function test_preflight_is_zero_write_and_reports_wrong_cached_secret_as_residue(): void
    {
        $beforeEnv = hash_file('sha256', $this->fixtureRoot.'/shared/backend/.env');
        $beforeCache = hash_file('sha256', $this->fixtureRoot.'/current/backend/bootstrap/cache/config.php');

        $result = $this->executeRunner();
        $receipt = $this->receipt($result);

        $this->assertTrue($result->successful(), $result->output().$result->errorOutput());
        $this->assertSame('PASS_AUTHORIZATION_REQUIRED', $receipt['status']);
        $this->assertTrue($receipt['apply_ready']);
        $this->assertTrue($receipt['config_residue_present']);
        $this->assertFalse($receipt['writes_committed']);
        $this->assertSame($beforeEnv, hash_file('sha256', $this->fixtureRoot.'/shared/backend/.env'));
        $this->assertSame($beforeCache, hash_file('sha256', $this->fixtureRoot.'/current/backend/bootstrap/cache/config.php'));
    }

    public function test_exact_authorized_apply_writes_only_two_keys_and_rebuilds_config_cache(): void
    {
        $preflight = $this->receipt($this->executeRunner());
        $runId = '123456';
        $attempt = '1';
        $phrase = sprintf(
            'I explicitly approve production fap-api content-release revalidation config convergence from preflight run %s attempt %s with control-plane SHA %s active SHA %s environment SHA256 %s config-cache SHA256 %s config-source SHA256 %s runtime fingerprint %s source bundle SHA256 %s; write only CONTENT_RELEASE_REVALIDATE_SECRET and ENNEAGRAM_AUTHORITY_V2_REVALIDATION_URL, rebuild only Laravel config cache, no deploy/symlink/migration/CMS/database-authority/public-cache-revalidation/queue/service-restart/publication/sitemap/llms/search/PR23/automatic rollback.',
            $runId,
            $attempt,
            self::CONTROL_SHA,
            self::ACTIVE_SHA,
            $preflight['env_sha256'],
            $preflight['config_cache_sha256'],
            $preflight['config_source_sha256'],
            $preflight['runtime_fingerprint_sha256'],
            $preflight['source_bundle_sha256'],
        );

        $result = $this->executeRunner([
            'MODE' => 'apply',
            'PREFLIGHT_RUN_ID' => $runId,
            'PREFLIGHT_RUN_ATTEMPT' => $attempt,
            'EXPECTED_ENV_SHA256' => $preflight['env_sha256'],
            'EXPECTED_CONFIG_CACHE_SHA256' => $preflight['config_cache_sha256'],
            'EXPECTED_CONFIG_SOURCE_SHA256' => $preflight['config_source_sha256'],
            'EXPECTED_RUNTIME_FINGERPRINT_SHA256' => $preflight['runtime_fingerprint_sha256'],
            'EXPECTED_SOURCE_BUNDLE_SHA256' => $preflight['source_bundle_sha256'],
            'AUTHORIZATION_PHRASE' => $phrase,
        ]);
        $receipt = $this->receipt($result);

        $this->assertTrue($result->successful(), $result->output().$result->errorOutput());
        $this->assertSame('PASS_CONFIG_CACHE_CONVERGED', $receipt['status']);
        $this->assertTrue($receipt['writes_committed']);
        $this->assertSame(2, $receipt['env_setting_write_count']);
        $this->assertTrue($receipt['config_cache_rebuild_committed']);
        $this->assertTrue($receipt['env_matches_source']);
        $this->assertTrue($receipt['cached_config_matches_source']);
        $this->assertFalse($receipt['config_residue_present']);

        $env = File::get($this->fixtureRoot.'/shared/backend/.env');
        $this->assertStringContainsString('APP_ENV=production', $env);
        $this->assertStringContainsString('CONTENT_RELEASE_REVALIDATE_SECRET='.self::SECRET, $env);
        $this->assertStringContainsString(
            'ENNEAGRAM_AUTHORITY_V2_REVALIDATION_URL=https://fermatmind.com/api/content-release/revalidate',
            $env,
        );
        $this->assertStringNotContainsString('wrong_secret', $env);
        $this->assertStringNotContainsString(self::SECRET, json_encode($receipt, JSON_THROW_ON_ERROR));
    }

    public function test_runner_and_workflow_fail_closed_around_exact_state_and_scope(): void
    {
        $backendRoot = dirname(__DIR__, 2);
        $runner = File::get($backendRoot.'/scripts/deploy/content_release_revalidation_config_cache.sh');
        $workflow = File::get($backendRoot.'/../.github/workflows/content-release-revalidation-config-cache.yml');

        foreach ([
            'ENV_FILE_DRIFT',
            'CONFIG_CACHE_DRIFT',
            'CONFIG_SOURCE_DRIFT',
            'RUNTIME_FINGERPRINT_DRIFT',
            'SOURCE_BUNDLE_DRIFT',
            'AUTHORIZATION_PHRASE_MISMATCH',
            'DEPLOY_LOCK_PRESENT',
            'FAIL_CLOSED_PARTIAL_CONFIG_WRITE',
        ] as $expected) {
            $this->assertStringContainsString($expected, $runner);
        }

        $this->assertStringContainsString('group: deploy-${{ github.repository }}-production', $workflow);
        $this->assertStringContainsString('Validate immutable preflight receipt', $workflow);
        $this->assertStringContainsString('Verify required checks are green', $workflow);
        $this->assertStringContainsString('secrets.CONTENT_RELEASE_REVALIDATE_SECRET', $workflow);
        $this->assertStringContainsString('secrets.PRODUCTION_DEPLOY_PATH', $workflow);
        $this->assertStringNotContainsString('vars.PRODUCTION_DEPLOY_', $workflow);

        $combined = $runner."\n".$workflow;
        foreach ([
            'vendor/bin/dep deploy',
            'deploy:symlink',
            'php artisan migrate',
            'queue:restart',
            'supervisorctl',
            'systemctl',
            'public_cache_revalidation: true',
            'automatic_rollback: true',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $combined);
        }
    }

    /** @param array<string, string> $extra */
    private function executeRunner(array $extra = []): ProcessResult
    {
        $env = array_merge([
            'PATH' => $this->binDir.':'.getenv('PATH'),
            'MODE' => 'preflight',
            'DEPLOY_PATH' => $this->fixtureRoot,
            'MANAGED_DEPLOY_PATH' => $this->fixtureRoot,
            'EXPECTED_CONTROL_PLANE_SHA' => self::CONTROL_SHA,
            'EXPECTED_ACTIVE_SHA' => self::ACTIVE_SHA,
            'CONTENT_RELEASE_REVALIDATE_SECRET' => self::SECRET,
            'PHP_BIN' => PHP_BINARY,
        ], $extra);

        return Process::env($env)
            ->path(dirname(__DIR__, 2))
            ->run(['bash', 'scripts/deploy/content_release_revalidation_config_cache.sh']);
    }

    /** @return array<string, mixed> */
    private function receipt(ProcessResult $result): array
    {
        return json_decode(trim($result->output()), true, flags: JSON_THROW_ON_ERROR);
    }
}
