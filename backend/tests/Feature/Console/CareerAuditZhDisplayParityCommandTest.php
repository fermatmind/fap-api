<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerAuditZhDisplayParityCommandTest extends TestCase
{
    #[Test]
    public function it_outputs_read_only_zh_display_parity_report(): void
    {
        Http::fake([
            'https://api.example.test/api/v0.5/career/jobs?locale=en' => Http::response($this->indexPayload([
                'same-slug',
                'zh-missing-modules',
                'zh-extra-modules',
                'zh-404-slug',
            ]), 200),
            'https://api.example.test/api/v0.5/career/jobs?locale=zh-CN' => Http::response($this->indexPayload([
                'same-slug',
                'zh-missing-modules',
                'zh-extra-modules',
                'en-404-slug',
            ]), 200),
            'https://api.example.test/api/v0.5/career/jobs/same-slug?locale=en' => Http::response($this->detailPayload([
                'hero',
                'path',
                'source_card',
            ]), 200),
            'https://api.example.test/api/v0.5/career/jobs/same-slug?locale=zh-CN' => Http::response($this->detailPayload([
                'hero',
                'path',
                'source_card',
            ]), 200),
            'https://api.example.test/api/v0.5/career/jobs/zh-missing-modules?locale=en' => Http::response($this->detailPayload([
                'hero',
                'path',
                'source_card',
                'responsibilities_block',
            ]), 200),
            'https://api.example.test/api/v0.5/career/jobs/zh-missing-modules?locale=zh-CN' => Http::response($this->detailPayload([
                'hero',
                'path',
            ], amberFlags: ['runtime_published_shell'], criticalMissingFields: ['compiled_recommendation_snapshot'], integrityState: 'runtime_published_shell'), 200),
            'https://api.example.test/api/v0.5/career/jobs/zh-extra-modules?locale=en' => Http::response($this->detailPayload([
                'hero',
                'path',
            ]), 200),
            'https://api.example.test/api/v0.5/career/jobs/zh-extra-modules?locale=zh-CN' => Http::response($this->detailPayload([
                'hero',
                'path',
                'zh_only_module',
            ]), 200),
            'https://api.example.test/api/v0.5/career/jobs/zh-404-slug?locale=en' => Http::response($this->detailPayload([
                'hero',
                'path',
            ]), 200),
            'https://api.example.test/api/v0.5/career/jobs/zh-404-slug?locale=zh-CN' => Http::response(['message' => 'Not found'], 404),
            'https://api.example.test/api/v0.5/career/jobs/en-404-slug?locale=en' => Http::response(['message' => 'Not found'], 404),
            'https://api.example.test/api/v0.5/career/jobs/en-404-slug?locale=zh-CN' => Http::response($this->detailPayload([
                'hero',
                'path',
            ]), 200),
        ]);

        $output = sys_get_temp_dir().'/career-zh-display-parity-test.json';
        @unlink($output);

        $exitCode = Artisan::call('career:audit-zh-display-parity', [
            '--api-base' => 'https://api.example.test/api/v0.5/career/jobs',
            '--site-base' => 'https://www.example.test',
            '--json' => true,
            '--output' => $output,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $written = json_decode((string) File::get($output), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertSame('blocked', $report['decision']);
        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['writes_database']);
        $this->assertFalse($report['sitemap_changed']);
        $this->assertFalse($report['llms_changed']);
        $this->assertFalse($report['index_strategy_changed']);
        $this->assertSame('blocked', $report['live_gate']['decision']);
        $this->assertContains('api_failures_or_http_mismatches', $report['live_gate']['blockers']);
        $this->assertContains('zh_missing_en_display_modules', $report['live_gate']['blockers']);
        $this->assertContains('zh_restricted_shell_or_integrity_gap', $report['live_gate']['blockers']);
        $this->assertSame(1, $report['live_gate']['restricted_shell_count']);
        $this->assertSame('blocked', $report['live_parity_assertions']['decision']);
        $this->assertContains('runtime_shell_count', $report['live_parity_assertions']['failed_assertions']);
        $this->assertContains('module_mismatch_count', $report['live_parity_assertions']['failed_assertions']);
        $this->assertContains('api_failure_count', $report['live_parity_assertions']['failed_assertions']);
        $this->assertContains('index_mismatch_count', $report['live_parity_assertions']['failed_assertions']);
        $this->assertSame(1, $report['live_parity_assertions']['required_assertions']['runtime_shell_count']['actual']);
        $this->assertFalse($report['live_parity_assertions']['required_assertions']['runtime_shell_count']['pass']);
        $this->assertSame('blocked', $report['ci_publish_readiness']['decision']);
        $this->assertSame('blocked', $report['ci_publish_readiness']['publish_readiness']);
        $this->assertSame(1, $report['production_live_assessment']['runtime_shell_count']);
        $this->assertSame(['zh-missing-modules'], $report['production_live_assessment']['runtime_shell_slugs']);
        $this->assertSame(1, $report['root_cause_manifest']['bucket_counts']['runtime_shell']);
        $this->assertSame(1, $report['root_cause_manifest']['bucket_counts']['zh_has_unexpected_extra_modules']);
        $this->assertSame(2, $report['root_cause_manifest']['bucket_counts']['api_failures']);
        $this->assertSame('zh-missing-modules', $report['root_cause_manifest']['buckets']['runtime_shell'][0]['slug']);
        $this->assertSame(
            'treat_as_missing_or_unpublished_until_target_check',
            $report['root_cause_manifest']['buckets']['runtime_shell'][0]['cache_status_recommendation'],
        );
        $this->assertSame(1, $report['controlled_import_manifest']['candidate_count']);
        $this->assertSame(['zh-missing-modules'], $report['controlled_import_manifest']['candidate_slugs']);
        $this->assertSame(5, $report['summary']['total_slugs']);
        $this->assertSame(1, $report['summary']['same_module_set']);
        $this->assertSame(1, $report['summary']['en_has_modules_zh_missing']);
        $this->assertSame(1, $report['summary']['zh_has_modules_en_missing']);
        $this->assertSame(1, $report['summary']['en_200_zh_not_200']);
        $this->assertSame(1, $report['summary']['zh_200_en_not_200']);
        $this->assertSame(2, $report['summary']['api_failure_count']);
        $this->assertSame($report['summary'], $written['summary']);

        $missing = collect($report['items'])->firstWhere('slug', 'zh-missing-modules');
        $this->assertSame('en_has_modules_zh_missing', $missing['classification']);
        $this->assertContains('responsibilities_block', $missing['missing_modules']['en_only']);
        $this->assertContains('source_card', $missing['missing_modules']['en_only']);
        $this->assertContains('zh_missing_en_display_modules', $missing['zh_gate_reasons']);
        $this->assertContains('amber_flag:runtime_published_shell', $missing['zh_gate_reasons']);
        $this->assertContains('critical_missing_field:compiled_recommendation_snapshot', $missing['zh_gate_reasons']);
        $this->assertSame('runtime_shell_missing_or_unpublished_zh_display_asset', $missing['root_cause']);
        $this->assertSame('not_proven', $missing['cache_stale_assessment']['cache_stale']);
        $this->assertSame('inferred_absent_or_not_published_from_public_payload', $missing['zh_public_asset_state']['cms_asset_exists']);
        $this->assertTrue($missing['controlled_import_candidate']);
        $this->assertSame('https://www.example.test/zh/career/jobs/zh-missing-modules', $missing['sample_urls']['zh']);
    }

    #[Test]
    public function it_can_scan_explicit_slugs_without_public_index(): void
    {
        Http::fake([
            'https://api.example.test/api/v0.5/career/jobs/one-slug?locale=en' => Http::response($this->detailPayload([
                'hero',
                'path',
            ]), 200),
            'https://api.example.test/api/v0.5/career/jobs/one-slug?locale=zh-CN' => Http::response($this->detailPayload([
                'hero',
                'path',
            ]), 200),
        ]);

        $exitCode = Artisan::call('career:audit-zh-display-parity', [
            '--api-base' => 'https://api.example.test/api/v0.5/career/jobs',
            '--slugs' => 'one-slug',
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('explicit_slugs', $report['scan_scope']);
        $this->assertSame('pass', $report['live_gate']['decision']);
        $this->assertSame(1, $report['summary']['total_slugs']);
        $this->assertSame(1, $report['summary']['same_module_set']);

        Http::assertNotSent(static fn ($request): bool => str_ends_with($request->url(), '/career/jobs?locale=en'));
    }

    #[Test]
    public function it_records_detail_connection_exceptions_per_slug_without_aborting_the_report(): void
    {
        Http::fake(function ($request) {
            if ($request->url() === 'https://api.example.test/api/v0.5/career/jobs/unstable-slug?locale=zh-CN') {
                throw new ConnectionException('Connection refused for zh detail request.');
            }

            return Http::response($this->detailPayload([
                'hero',
                'path',
                'source_card',
            ]), 200);
        });

        $exitCode = Artisan::call('career:audit-zh-display-parity', [
            '--api-base' => 'https://api.example.test/api/v0.5/career/jobs',
            '--site-base' => 'https://www.example.test',
            '--slugs' => 'stable-slug,unstable-slug',
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertSame('blocked', $report['decision']);
        $this->assertArrayNotHasKey('errors', $report);
        $this->assertSame(2, $report['summary']['total_slugs']);
        $this->assertSame(1, $report['summary']['same_module_set']);
        $this->assertSame(1, $report['summary']['en_200_zh_not_200']);
        $this->assertSame(1, $report['summary']['api_failure_count']);
        $this->assertSame(1, $report['summary']['detail_api_error_count']);

        $unstable = collect($report['items'])->firstWhere('slug', 'unstable-slug');
        $this->assertSame('en_200_zh_not_200', $unstable['classification']);
        $this->assertSame(200, $unstable['status']['en']);
        $this->assertNull($unstable['status']['zh-CN']);
        $this->assertSame('zh_detail_http_unknown', $unstable['zh_gate_reasons'][0]);
        $this->assertSame('zh_api_not_200', $unstable['root_cause']);
        $this->assertSame(1, $report['root_cause_manifest']['bucket_counts']['api_failures']);
        $this->assertSame('unstable-slug', $report['root_cause_manifest']['buckets']['api_failures'][0]['slug']);
        $this->assertSame('zh-CN', $unstable['api_errors'][0]['locale']);
        $this->assertSame('ConnectionException', $unstable['api_errors'][0]['type']);
        $this->assertSame(
            'https://api.example.test/api/v0.5/career/jobs/unstable-slug?locale=zh-CN',
            $unstable['api_errors'][0]['url'],
        );
    }

    #[Test]
    public function it_can_assert_the_live_parity_gate_without_mutating_content_or_index_strategy(): void
    {
        Http::fake([
            'https://api.example.test/api/v0.5/career/jobs/gated-slug?locale=en' => Http::response($this->detailPayload([
                'hero',
                'path',
                'source_card',
            ]), 200),
            'https://api.example.test/api/v0.5/career/jobs/gated-slug?locale=zh-CN' => Http::response($this->detailPayload([
                'hero',
                'path',
            ], amberFlags: ['runtime_published_shell'], integrityState: 'runtime_published_shell'), 200),
        ]);

        $exitCode = Artisan::call('career:audit-zh-display-parity', [
            '--api-base' => 'https://api.example.test/api/v0.5/career/jobs',
            '--slugs' => 'gated-slug',
            '--summary-only' => true,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('pass', $report['decision']);
        $this->assertSame('blocked', $report['live_gate']['decision']);
        $this->assertSame([], $report['items']);
        $this->assertFalse($report['live_gate']['sitemap_changed']);
        $this->assertFalse($report['live_gate']['llms_changed']);
        $this->assertFalse($report['live_gate']['index_strategy_changed']);

        $exitCode = Artisan::call('career:audit-zh-display-parity', [
            '--api-base' => 'https://api.example.test/api/v0.5/career/jobs',
            '--slugs' => 'gated-slug',
            '--assert-live-parity' => true,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertTrue($report['assert_live_parity']);
        $this->assertSame('blocked', $report['decision']);
        $this->assertSame('blocked', $report['live_gate']['decision']);
        $this->assertSame('blocked', $report['live_parity_assertions']['decision']);
        $this->assertSame('blocked', $report['ci_publish_readiness']['publish_readiness']);
        $this->assertContains('runtime_shell_count', $report['live_parity_assertions']['failed_assertions']);
        $this->assertContains('module_mismatch_count', $report['live_parity_assertions']['failed_assertions']);
        $this->assertContains('zh_missing_en_display_modules', $report['live_gate']['blockers']);
        $this->assertContains('zh_restricted_shell_or_integrity_gap', $report['live_gate']['blockers']);
        $this->assertSame(['gated-slug'], $report['controlled_import_manifest']['candidate_slugs']);
    }

    #[Test]
    public function it_does_not_count_display_asset_missing_recommendation_snapshot_as_runtime_shell(): void
    {
        Http::fake([
            'https://api.example.test/api/v0.5/career/jobs/display-asset-backed?locale=en' => Http::response($this->detailPayload([
                'hero',
                'path',
                'source_card',
            ]), 200),
            'https://api.example.test/api/v0.5/career/jobs/display-asset-backed?locale=zh-CN' => Http::response($this->detailPayload([
                'hero',
                'path',
                'source_card',
            ], criticalMissingFields: ['compiled_recommendation_snapshot']), 200),
        ]);

        $exitCode = Artisan::call('career:audit-zh-display-parity', [
            '--api-base' => 'https://api.example.test/api/v0.5/career/jobs',
            '--slugs' => 'display-asset-backed',
            '--assert-live-parity' => true,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame('pass', $report['decision']);
        $this->assertSame('pass', $report['live_gate']['decision']);
        $this->assertSame('pass', $report['live_parity_assertions']['decision']);
        $this->assertSame([], $report['live_parity_assertions']['failed_assertions']);
        $this->assertSame('ready', $report['ci_publish_readiness']['publish_readiness']);
        $this->assertTrue($report['live_parity_assertions']['required_assertions']['runtime_shell_count']['pass']);
        $this->assertTrue($report['live_parity_assertions']['required_assertions']['module_mismatch_count']['pass']);
        $this->assertTrue($report['live_parity_assertions']['required_assertions']['api_failure_count']['pass']);
        $this->assertTrue($report['live_parity_assertions']['required_assertions']['index_mismatch_count']['pass']);
        $this->assertSame(0, $report['live_gate']['restricted_shell_count']);
        $this->assertSame('inferred_present_from_public_payload', $report['items'][0]['zh_public_asset_state']['cms_asset_exists']);
        $this->assertSame('not_indicated', $report['items'][0]['cache_stale_assessment']['cache_stale']);
        $this->assertFalse($report['items'][0]['controlled_import_candidate']);
        $this->assertContains(
            'critical_missing_field:compiled_recommendation_snapshot',
            $report['items'][0]['zh_gate_reasons'],
        );
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, mixed>
     */
    private function indexPayload(array $slugs): array
    {
        return [
            'bundle_kind' => 'career_job_index',
            'items' => array_map(static fn (string $slug): array => [
                'identity' => [
                    'canonical_slug' => $slug,
                ],
            ], $slugs),
        ];
    }

    /**
     * @param  list<string>  $contentKeys
     * @param  list<string>  $amberFlags
     * @param  list<string>  $criticalMissingFields
     * @return array<string, mixed>
     */
    private function detailPayload(
        array $contentKeys,
        array $amberFlags = [],
        array $criticalMissingFields = [],
        string $integrityState = 'display_asset_backed',
    ): array {
        return [
            'bundle_kind' => 'career_job_detail',
            'warnings' => [
                'red_flags' => [],
                'amber_flags' => $amberFlags,
                'blocked_claims' => [],
            ],
            'integrity_summary' => [
                'integrity_state' => $integrityState,
                'critical_missing_fields' => $criticalMissingFields,
            ],
            'seo_contract' => [
                'reason_codes' => $integrityState === 'display_asset_backed'
                    ? ['validated_display_asset_backed_release', 'runtime_publish_projection']
                    : ['runtime_published_shell_no_strong_claims'],
            ],
            'provenance_meta' => [
                'content_version' => $integrityState === 'display_asset_backed'
                    ? 'display_asset_backed_v4_2'
                    : 'runtime_published_shell_v1',
                'data_version' => $integrityState === 'display_asset_backed'
                    ? 'career_job_display_assets.v4.2'
                    : 'runtime_projection.v1',
                'surface_type' => $integrityState === 'display_asset_backed'
                    ? 'career_job_detail_display_asset_backed_bundle'
                    : 'career_job_detail_runtime_published_shell',
            ],
            'display_surface_v1' => [
                'page' => [
                    'content' => array_fill_keys($contentKeys, ['visible' => true]),
                ],
            ],
        ];
    }
}
