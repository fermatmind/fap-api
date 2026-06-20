<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoIntelGscSidecarRunnerTest extends TestCase
{
    #[Test]
    public function sidecar_runner_preflight_writes_artifact_without_external_calls_or_writes(): void
    {
        Http::fake();
        $artifactDir = $this->artifactDir();

        $exitCode = Artisan::call('seo-intel:gsc-sidecar-runner', [
            '--mode' => 'preflight',
            '--artifact-dir' => $artifactDir,
        ]);

        $output = trim(Artisan::output());
        $artifactPath = $this->singleArtifactPath($artifactDir);
        $artifact = $this->jsonArtifact($artifactPath);

        $this->assertSame(1, $exitCode);
        $this->assertStringNotContainsString('Authorization', $output);
        $this->assertSame('gsc-hk-sidecar-runner-wrapper.v1', $artifact['schema_version'] ?? null);
        $this->assertSame('SEO-GSC-HK-SIDECAR-RUNNER-WRAPPER-01', $artifact['task'] ?? null);
        $this->assertSame('preflight', $artifact['mode'] ?? null);
        $this->assertSame('seo-intel:collect', data_get($artifact, 'collector_command.name'));
        $this->assertContains('--dry-run', data_get($artifact, 'collector_command.forced_flags'));
        $this->assertContains('--no-write', data_get($artifact, 'collector_command.forced_flags'));
        $this->assertContains('--gsc-live-preflight', data_get($artifact, 'collector_command.forced_flags'));
        $this->assertTrue((bool) data_get($artifact, 'boundary_check.passed'));
        $this->assertSame('blocked', data_get($artifact, 'payload.status'));
        $this->assertFalse((bool) data_get($artifact, 'payload.external_calls_attempted', true));
        $this->assertFalse((bool) data_get($artifact, 'payload.writes_attempted', true));
        Http::assertNothingSent();
    }

    #[Test]
    public function sidecar_runner_live_read_writes_sanitized_artifact_and_summary(): void
    {
        $this->enableAccessTokenConfig();
        $artifactDir = $this->artifactDir();

        Http::fake([
            'searchconsole.googleapis.com/*' => Http::response([
                'rows' => [
                    [
                        'keys' => ['mbti测试', 'https://fermatmind.com/zh/articles/mbti-basics'],
                        'clicks' => 0,
                        'impressions' => 60,
                        'ctr' => 0,
                        'position' => 9.0,
                    ],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('seo-intel:gsc-sidecar-runner', [
            '--mode' => 'live-read',
            '--start-date' => '2026-06-17',
            '--end-date' => '2026-06-17',
            '--limit' => 25,
            '--dimensions' => 'query,page',
            '--artifact-dir' => $artifactDir,
        ]);

        $output = trim(Artisan::output());
        $artifactPath = $this->singleArtifactPath($artifactDir);
        $artifact = $this->jsonArtifact($artifactPath);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('mbti测试', $output);
        $this->assertStringNotContainsString('https://fermatmind.com/zh/articles/mbti-basics', $output);
        $this->assertStringNotContainsString('secret-gsc-token', $output);
        $this->assertStringNotContainsString('Authorization', $output);

        $this->assertSame('live-read', $artifact['mode'] ?? null);
        $this->assertContains('--gsc-live-read', data_get($artifact, 'collector_command.forced_flags'));
        $this->assertSame('2026-06-17', data_get($artifact, 'collector_command.live_read_options.start_date'));
        $this->assertSame('2026-06-17', data_get($artifact, 'collector_command.live_read_options.end_date'));
        $this->assertSame(25, data_get($artifact, 'collector_command.live_read_options.limit'));
        $this->assertSame('query,page', data_get($artifact, 'collector_command.live_read_options.dimensions'));
        $this->assertTrue((bool) data_get($artifact, 'boundary_check.passed'));
        $this->assertSame('success', data_get($artifact, 'payload.status'));
        $this->assertTrue((bool) data_get($artifact, 'payload.external_calls_attempted'));
        $this->assertFalse((bool) data_get($artifact, 'payload.writes_attempted', true));
        $this->assertFalse((bool) data_get($artifact, 'payload.writes_committed', true));
        $this->assertFalse((bool) data_get($artifact, 'payload.metadata.cms_write_allowed', true));
        $this->assertFalse((bool) data_get($artifact, 'payload.metadata.search_channel_enqueue_allowed', true));
        $this->assertFalse((bool) data_get($artifact, 'payload.metadata.indexing_request_allowed', true));
        $this->assertSame('pass', data_get($artifact, 'payload.metadata.data_quality_gate.status'));
        $this->assertSame('m****试', data_get($artifact, 'payload.metadata.safe_row_preview.0.query_display_masked'));

        $artifactText = (string) file_get_contents($artifactPath);
        $this->assertStringNotContainsString('mbti测试', $artifactText);
        $this->assertStringNotContainsString('https://fermatmind.com/zh/articles/mbti-basics', $artifactText);
        $this->assertStringNotContainsString('secret-gsc-token', $artifactText);
        $this->assertStringNotContainsString('Authorization', $artifactText);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'searchconsole.googleapis.com/webmasters/v3/sites/sc-domain%3Afermatmind.com/searchAnalytics/query')
                && $request->hasHeader('Authorization', 'Bearer secret-gsc-token')
                && $request['rowLimit'] === 25
                && $request['startDate'] === '2026-06-17'
                && $request['endDate'] === '2026-06-17'
                && $request['dimensions'] === ['query', 'page']
                && $request['type'] === 'web';
        });
    }

    #[Test]
    public function sidecar_runner_rejects_invalid_live_read_bounds_before_artifact_write(): void
    {
        Http::fake();
        $artifactDir = $this->artifactDir();

        $exitCode = Artisan::call('seo-intel:gsc-sidecar-runner', [
            '--mode' => 'live-read',
            '--start-date' => '2026-06-17',
            '--end-date' => '2026-06-17',
            '--limit' => 251,
            '--dimensions' => 'query,page',
            '--artifact-dir' => $artifactDir,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('error=live_read_limit_invalid', Artisan::output());
        $this->assertSame([], File::files($artifactDir));
        Http::assertNothingSent();
    }

    #[Test]
    public function generated_contract_locks_sidecar_wrapper_boundary(): void
    {
        $artifact = json_decode(
            (string) file_get_contents(base_path('docs/seo/generated/gsc-hk-sidecar-runner.v1.json')),
            true
        );

        $this->assertIsArray($artifact);
        $this->assertSame('SEO-GSC-HK-SIDECAR-RUNNER-WRAPPER-01', data_get($artifact, 'runner_wrapper_contract.task'));
        $this->assertSame('php artisan seo-intel:gsc-sidecar-runner', data_get($artifact, 'runner_wrapper_contract.command'));
        $this->assertSame('seo-intel:collect', data_get($artifact, 'runner_wrapper_contract.internal_command'));
        $this->assertSame('gsc_foundation', data_get($artifact, 'runner_wrapper_contract.forced_collector'));
        $this->assertFalse((bool) data_get($artifact, 'runner_wrapper_contract.scheduler_enabled', true));
        $this->assertFalse((bool) data_get($artifact, 'runner_wrapper_contract.queue_worker_enabled', true));
        $this->assertContains('--dry-run', data_get($artifact, 'runner_wrapper_contract.forced_flags'));
        $this->assertContains('--no-write', data_get($artifact, 'runner_wrapper_contract.forced_flags'));
        $this->assertContains('writes_attempted', data_get($artifact, 'runner_wrapper_contract.fail_closed_if_true'));
        $this->assertFalse((bool) data_get($artifact, 'runner_wrapper_contract.negative_guarantees.db_writes', true));
        $this->assertFalse((bool) data_get($artifact, 'runner_wrapper_contract.negative_guarantees.seo_gsc_daily_import', true));
        $this->assertFalse((bool) data_get($artifact, 'runner_wrapper_contract.negative_guarantees.opportunity_queue_enqueue', true));
        $this->assertFalse((bool) data_get($artifact, 'runner_wrapper_contract.negative_guarantees.cms_write', true));
        $this->assertFalse((bool) data_get($artifact, 'runner_wrapper_contract.negative_guarantees.search_channel_submit', true));
    }

    private function enableAccessTokenConfig(): void
    {
        config([
            'seo_intel.gsc_enabled' => true,
            'seo_intel.gsc_live_api_enabled' => true,
            'seo_intel.allow_external_api_calls' => true,
            'seo_intel.gsc_property_url' => 'sc-domain:fermatmind.com',
            'seo_intel.gsc_readonly_adapter.auth_mode' => 'access_token',
            'seo_intel.gsc_readonly_adapter.access_token' => 'secret-gsc-token',
            'seo_intel.gsc_readonly_adapter.default_limit' => 250,
            'seo_intel.gsc_readonly_adapter.max_limit' => 250,
        ]);
    }

    private function artifactDir(): string
    {
        $dir = storage_path('framework/testing/gsc-sidecar-runner-'.Str::uuid()->toString());
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    private function singleArtifactPath(string $artifactDir): string
    {
        $files = File::files($artifactDir);
        $this->assertCount(1, $files);
        $path = $files[0]->getPathname();

        $this->assertFileExists($path);

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonArtifact(string $artifactPath): array
    {
        $artifact = json_decode((string) file_get_contents($artifactPath), true);
        $this->assertIsArray($artifact);

        return $artifact;
    }
}
