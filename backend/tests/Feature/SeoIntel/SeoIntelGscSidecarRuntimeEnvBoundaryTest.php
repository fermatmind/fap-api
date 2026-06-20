<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

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

        $this->assertStringContainsString('SIDECAR_ENV_FILE="${SIDECAR_ENV_FILE:-/opt/fermatmind/seo-gsc-runner/env/gsc-sidecar.env}"', $script);
        $this->assertStringContainsString('APP_CONFIG_CACHE="${SIDECAR_CONFIG_CACHE:-${SIDECAR_CONFIG_CACHE_DEFAULT}}"', $script);
        $this->assertStringContainsString('sidecar_config_cache_forbidden', $script);
        $this->assertStringContainsString('/bootstrap/cache/', $script);
        $this->assertStringContainsString('SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON_PATH', $script);
        $this->assertStringContainsString('sidecar_inline_service_account_json_forbidden', $script);
        $this->assertStringContainsString('sidecar_access_token_forbidden', $script);
        $this->assertStringContainsString('exec php artisan seo-intel:gsc-sidecar-runner "$@"', $script);

        $this->assertStringNotContainsString('seo-intel:collect', $script);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $script);
        $this->assertStringNotContainsString('private_key', $script);
        $this->assertStringNotContainsString('client_email', $script);
        $this->assertStringNotContainsString('ya29.', $script);
        $this->assertStringNotContainsString('Bearer ', $script);
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
        $this->assertSame('/tmp/fermatmind-gsc-sidecar-config.php', data_get($artifact, 'sidecar_launcher_contract.default_app_config_cache'));
        $this->assertFalse((bool) data_get($artifact, 'sidecar_launcher_contract.production_env_edited_by_pr', true));
        $this->assertFalse((bool) data_get($artifact, 'sidecar_launcher_contract.scheduler_enabled', true));
        $this->assertSame('php artisan seo-intel:gsc-sidecar-runner', data_get($artifact, 'sidecar_launcher_contract.delegates_to'));
        $this->assertContains('APP_CONFIG_CACHE must not point under bootstrap/cache', data_get($artifact, 'sidecar_launcher_contract.fail_closed_if'));
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
}
