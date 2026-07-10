<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGscSidecarRuntimeEnvBoundaryTest extends TestCase
{
    #[Test]
    public function sidecar_launcher_locks_env_file_and_config_cache_boundary(): void
    {
        $scriptPath = base_path('scripts/seo/gsc_sidecar_runner.sh');

        $this->assertFileExists($scriptPath);
        $this->assertTrue(is_executable($scriptPath), 'sidecar launcher must be executable');

        $script = (string) file_get_contents($scriptPath);

        $this->assertStringContainsString('PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"', $script);
        $this->assertStringContainsString('SIDECAR_ENV_FILE="${SIDECAR_ENV_FILE:-/opt/fermatmind/seo-gsc-runner/env/gsc-sidecar.env}"', $script);
        $this->assertStringContainsString('sidecar_env_key_forbidden', $script);
        $this->assertStringContainsString('sidecar_env_line_invalid', $script);
        $this->assertStringContainsString('sidecar_config_cache_override_forbidden', $script);
        $this->assertStringContainsString('mktemp -d /tmp/fermatmind-gsc-sidecar.XXXXXX', $script);
        $this->assertStringContainsString('trap cleanup EXIT', $script);
        $this->assertStringContainsString('SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON_PATH', $script);
        $this->assertStringContainsString('sidecar_inline_service_account_json_forbidden', $script);
        $this->assertStringContainsString('sidecar_access_token_forbidden', $script);
        $this->assertStringContainsString('php artisan seo-intel:gsc-sidecar-runner "$@"', $script);

        $this->assertStringNotContainsString('. "${SIDECAR_ENV_FILE}"', $script);
        $this->assertStringNotContainsString('/tmp/fermatmind-gsc-sidecar-config.php', $script);
        $this->assertStringNotContainsString('seo-intel:collect', $script);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $script);
        $this->assertStringNotContainsString('private_key', $script);
        $this->assertStringNotContainsString('client_email', $script);
        $this->assertStringNotContainsString('ya29.', $script);
        $this->assertStringNotContainsString('Bearer ', $script);
    }

    #[Test]
    public function sidecar_env_values_are_parsed_as_literals_without_shell_execution(): void
    {
        $marker = '/tmp/fap-api-gsc-sidecar-'.Str::uuid()->toString();
        $envFile = storage_path('framework/testing/gsc-sidecar-env-'.Str::uuid()->toString());
        File::ensureDirectoryExists(dirname($envFile));
        File::put($envFile, implode("\n", [
            'SEO_INTEL_GSC_ENABLED=true',
            'SEO_INTEL_GSC_LIVE_API_ENABLED=true',
            'SEO_INTEL_ALLOW_EXTERNAL_API_CALLS=true',
            'SEO_INTEL_GSC_PROPERTY_URL=$(touch '.$marker.')',
            'SEO_INTEL_GSC_AUTH_MODE=disabled',
            'SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON_PATH=/tmp/not-used.json',
            '',
        ]));
        @unlink($marker);

        [$exitCode, $output] = $this->runScript(base_path('scripts/seo/gsc_sidecar_runner.sh'), [
            'PATH' => (string) getenv('PATH'),
            'SIDECAR_ENV_FILE' => $envFile,
        ]);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('sidecar_auth_mode_not_service_account', $output);
        $this->assertFileDoesNotExist($marker);
    }

    #[Test]
    public function generated_contract_records_runtime_env_cache_boundary_and_negative_guarantees(): void
    {
        $artifact = json_decode(
            (string) file_get_contents(base_path('docs/seo/generated/gsc-hk-sidecar-runner.v1.json')),
            true
        );

        $this->assertIsArray($artifact);

        $this->assertSame('backend/scripts/seo/gsc_sidecar_runner.sh', data_get($artifact, 'sidecar_launcher_contract.script'));
        $this->assertSame('/opt/fermatmind/seo-gsc-runner/env/gsc-sidecar.env', data_get($artifact, 'sidecar_launcher_contract.default_env_file'));
        $this->assertSame('private mktemp directory under /tmp', data_get($artifact, 'sidecar_launcher_contract.default_app_config_cache'));
        $this->assertSame('strict literal KEY=VALUE parser without shell evaluation', data_get($artifact, 'sidecar_launcher_contract.env_file_parser'));
        $this->assertSame('0700', data_get($artifact, 'sidecar_launcher_contract.cache_directory_mode'));
        $this->assertTrue((bool) data_get($artifact, 'sidecar_launcher_contract.cache_cleanup_on_exit'));
        $this->assertFalse((bool) data_get($artifact, 'sidecar_launcher_contract.production_env_edited_by_pr', true));
        $this->assertFalse((bool) data_get($artifact, 'sidecar_launcher_contract.scheduler_enabled', true));
        $this->assertSame('php artisan seo-intel:gsc-sidecar-runner', data_get($artifact, 'sidecar_launcher_contract.delegates_to'));
        $this->assertContains('env file contains malformed, unsupported, or unbalanced quoted input', data_get($artifact, 'sidecar_launcher_contract.fail_closed_if'));
        $this->assertContains('APP_CONFIG_CACHE or SIDECAR_CONFIG_CACHE override is present', data_get($artifact, 'sidecar_launcher_contract.fail_closed_if'));
        $this->assertContains('SEO_INTEL_GSC_ACCESS_TOKEN is non-empty', data_get($artifact, 'sidecar_launcher_contract.fail_closed_if'));
        $this->assertContains('SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON is non-empty', data_get($artifact, 'sidecar_launcher_contract.fail_closed_if'));

        $this->assertSame(3, data_get($artifact, 'readmodel_dryrun_revalidation.import_dryrun.rows_would_insert'));
        $this->assertSame('pass', data_get($artifact, 'readmodel_dryrun_revalidation.import_dryrun.data_quality_gate'));
        $this->assertFalse((bool) data_get($artifact, 'readmodel_dryrun_revalidation.import_dryrun.would_write', true));

        foreach ([
            'db_writes',
            'seo_gsc_daily_import',
            'opportunity_queue_enqueue',
            'cms_write',
            'search_channel_submit',
            'scheduler_activation',
        ] as $field) {
            $this->assertFalse((bool) data_get($artifact, 'runner_wrapper_contract.negative_guarantees.'.$field, true), $field);
        }
    }

    /**
     * @param  array<string, string>  $environment
     * @return array{int, string}
     */
    private function runScript(string $scriptPath, array $environment): array
    {
        $pipes = [];
        $process = proc_open(
            [$scriptPath, '--mode=preflight'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $environment
        );
        $this->assertIsResource($process);

        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
