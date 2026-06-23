<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerJobPageAssemblyAsset;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Services\Career\PageAssemblyAssets\CareerPageAssemblyImportService;
use App\Services\Career\PageAssemblyAssets\CareerPageAssemblyPreviewService;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerPageAssemblyAssetPreviewImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedRuntimeProjectionAuthority([]);
    }

    public function test_page_assembly_asset_sidecar_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('career_job_page_assembly_assets'));
        $this->assertTrue(Schema::hasColumn('career_job_page_assembly_assets', 'asset_payload_json'));
        $this->assertTrue(Schema::hasColumn('career_job_page_assembly_assets', 'asset_row_hash'));
        $this->assertTrue(Schema::hasColumn('career_job_page_assembly_assets', 'preview_allowlisted'));
    }

    public function test_importer_dry_run_validates_page_assembly_rows_without_writing(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assemblyRow('accountants-and-auditors', 'zh-CN'),
            $this->assemblyRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/page-assembly-preview-dry-run.json');

        $exitCode = Artisan::call('career:page-assembly-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('career_job_page_assembly_assets', 0);

        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertSame(2, $decoded['validated_preview_rows']);
        $this->assertSame(1, $decoded['career_job_bundle_authority']['ready_slug_count']);
        $this->assertFalse((bool) ($decoded['production_import_allowed'] ?? true));
        $this->assertFalse((bool) ($decoded['staging_write_performed'] ?? true));
        $this->assertFalse((bool) ($decoded['search_projection_activated'] ?? true));
    }

    public function test_importer_force_writes_staging_preview_rows_after_validation_passes(): void
    {
        Config::set('career_content_page_assembly_assets.staging_preview_enabled', true);
        Config::set('career_content_page_assembly_assets.preview_slugs', ['accountants-and-auditors']);
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assemblyRow('accountants-and-auditors', 'zh-CN'),
            $this->assemblyRow('accountants-and-auditors', 'en'),
        ]);
        $sha = hash_file('sha256', $file);
        $report = storage_path('framework/testing/page-assembly-preview-force-write.json');

        $exitCode = Artisan::call('career:page-assembly-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--expected-sha256' => $sha,
            '--force' => true,
            '--status' => CareerJobPageAssemblyAsset::STATUS_STAGING_PREVIEW,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('career_job_page_assembly_assets', 2);
        $this->assertDatabaseHas('career_job_page_assembly_assets', [
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'zh-CN',
            'asset_version' => CareerJobPageAssemblyAsset::ASSET_VERSION_V1,
            'status' => CareerJobPageAssemblyAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
            'source_artifact_sha256' => $sha,
        ]);

        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertSame('write', $decoded['mode']);
        $this->assertSame(2, $decoded['written_count']);
        $this->assertTrue((bool) $decoded['staging_write_performed']);
        $this->assertFalse((bool) $decoded['production_import_allowed']);
        $this->assertFalse((bool) $decoded['runtime_modified']);
        $this->assertFalse((bool) $decoded['seo_runtime_modified']);

        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/page-assembly-asset?locale=en')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('preview', true)
            ->assertJsonPath('status', CareerJobPageAssemblyAsset::STATUS_STAGING_PREVIEW)
            ->assertJsonPath('career_page_assembly_v1.slug', 'accountants-and-auditors')
            ->assertJsonPath('career_page_assembly_v1.locale', 'en')
            ->assertJsonMissingPath('career_page_assembly_v1.occupation.title_zh')
            ->assertJsonMissingPath('career_page_assembly_v1.block_refs')
            ->assertJsonMissingPath('career_page_assembly_v1.audit_fields')
            ->assertJsonMissingPath('career_page_assembly_v1.page_sections.0.source_row_hash')
            ->assertJsonMissingPath('career_page_assembly_v1.search_projection');
    }

    public function test_preview_api_fails_closed_when_flag_off_or_locale_is_invalid(): void
    {
        Config::set('career_content_page_assembly_assets.staging_preview_enabled', false);
        $occupation = $this->seedOccupation('accountants-and-auditors');
        CareerJobPageAssemblyAsset::query()->create([
            'occupation_id' => $occupation->id,
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'zh-CN',
            'asset_version' => CareerJobPageAssemblyAsset::ASSET_VERSION_V1,
            'status' => CareerJobPageAssemblyAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
            'asset_payload_json' => $this->assemblyRow('accountants-and-auditors', 'zh-CN'),
            'block_refs_json' => [],
            'audit_fields_json' => ['row_hash' => str_repeat('a', 64)],
            'asset_row_hash' => str_repeat('a', 64),
        ]);

        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/page-assembly-asset?locale=zh-CN')
            ->assertNotFound()
            ->assertJsonPath('ok', false);

        Config::set('career_content_page_assembly_assets.staging_preview_enabled', true);

        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/page-assembly-asset?locale=fr')
            ->assertNotFound()
            ->assertJsonPath('ok', false);
    }

    public function test_force_all_slugs_from_file_requires_explicit_confirmation(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assemblyRow('accountants-and-auditors', 'zh-CN'),
            $this->assemblyRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/page-assembly-preview-force-missing-confirmation.json');

        $exitCode = Artisan::call('career:page-assembly-assets-import-preview', [
            '--file' => $file,
            '--all-slugs-from-file' => true,
            '--force' => true,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('career_job_page_assembly_assets', 0);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decoded['decision']);
        $this->assertContains('--force --all-slugs-from-file requires --confirm-full-staging-preview.', $decoded['errors']);
    }

    public function test_force_approved_requires_explicit_confirmation_and_artifacts(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assemblyRow('accountants-and-auditors', 'zh-CN'),
            $this->assemblyRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/page-assembly-approved-missing-confirmation.json');

        $exitCode = Artisan::call('career:page-assembly-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--force' => true,
            '--status' => CareerJobPageAssemblyAsset::STATUS_APPROVED,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decoded['decision']);
        $this->assertFalse((bool) $decoded['approved_transition_performed']);
        $this->assertStringContainsString('--confirm-approved-transition is required', implode(' ', $decoded['errors']));
    }

    public function test_force_approved_marks_existing_staging_rows_without_touching_production(): void
    {
        Config::set('career_content_page_assembly_assets.staging_preview_enabled', true);
        Config::set('career_content_page_assembly_assets.preview_slugs', ['accountants-and-auditors']);
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assemblyRow('accountants-and-auditors', 'zh-CN'),
            $this->assemblyRow('accountants-and-auditors', 'en'),
        ]);
        $assetSha = hash_file('sha256', $file);
        $stagingReport = storage_path('framework/testing/page-assembly-approved-staging-write.json');

        $stagingExitCode = Artisan::call('career:page-assembly-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--expected-sha256' => $assetSha,
            '--force' => true,
            '--status' => CareerJobPageAssemblyAsset::STATUS_STAGING_PREVIEW,
            '--output' => $stagingReport,
        ]);

        $this->assertSame(0, $stagingExitCode);
        $this->assertSame(2, CareerJobPageAssemblyAsset::query()->where('status', CareerJobPageAssemblyAsset::STATUS_STAGING_PREVIEW)->count());

        [$approvalManifest, $approvalManifestSha] = $this->writeJsonArtifact('page-assembly-approval-manifest', [
            'qa_final_conclusion' => 'CAREER_CONTENT_1046_STAGING_EDITORIAL_QA_PASS',
            'approved_count' => 2,
            'rejected_count' => 0,
            'slug_count' => 1,
            'production_import_approved' => false,
            'approved_transition_allowed' => true,
            'required_for_approved_transition' => [
                'asset_sha256' => $assetSha,
                'row_count' => 2,
                'slug_count' => 1,
            ],
        ]);
        [$editorialReport, $editorialReportSha] = $this->writeJsonArtifact('page-assembly-editorial-review', [
            'final_conclusion' => 'CAREER_CONTENT_1046_EDITORIAL_REVIEW_PASS',
            'approved_rows' => 2,
            'rejected' => 0,
            'production_import_approved' => false,
            'metrics' => [
                'slug_count' => 1,
                'expected_locale_rows' => 2,
                'finding_count' => 0,
            ],
            'inputs' => [
                'final_repaired_asset_sha256' => $assetSha,
            ],
        ]);
        $approvalReport = storage_path('framework/testing/page-assembly-approved-transition.json');

        $approvalExitCode = Artisan::call('career:page-assembly-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--expected-sha256' => $assetSha,
            '--force' => true,
            '--status' => CareerJobPageAssemblyAsset::STATUS_APPROVED,
            '--confirm-approved-transition' => true,
            '--approval-manifest' => $approvalManifest,
            '--approval-manifest-sha256' => $approvalManifestSha,
            '--editorial-review-report' => $editorialReport,
            '--editorial-review-sha256' => $editorialReportSha,
            '--output' => $approvalReport,
        ]);

        $this->assertSame(0, $approvalExitCode);
        $this->assertSame(2, CareerJobPageAssemblyAsset::query()->where('status', CareerJobPageAssemblyAsset::STATUS_APPROVED)->count());
        $this->assertSame(0, CareerJobPageAssemblyAsset::query()->where('status', CareerJobPageAssemblyAsset::STATUS_PRODUCTION_IMPORTED)->count());

        $decoded = json_decode((string) file_get_contents($approvalReport), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertSame('approved_transition', $decoded['mode']);
        $this->assertTrue((bool) $decoded['approved_transition_performed']);
        $this->assertFalse((bool) $decoded['production_import_allowed']);
        $this->assertFalse((bool) $decoded['production_import_performed']);
        $this->assertSame(2, $decoded['approved_count']);
        $this->assertSame(0, $decoded['production_rows_touched']);
        $this->assertSame($approvalManifestSha, $decoded['approval_manifest_sha256']);
        $this->assertSame($editorialReportSha, $decoded['editorial_review_sha256']);

        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/page-assembly-asset?locale=en')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('preview', true)
            ->assertJsonPath('status', CareerJobPageAssemblyAsset::STATUS_APPROVED)
            ->assertJsonPath('career_page_assembly_v1.slug', 'accountants-and-auditors')
            ->assertJsonPath('career_page_assembly_v1.locale', 'en')
            ->assertJsonMissingPath('career_page_assembly_v1.occupation.title_zh')
            ->assertJsonMissingPath('career_page_assembly_v1.block_refs')
            ->assertJsonMissingPath('career_page_assembly_v1.audit_fields')
            ->assertJsonMissingPath('career_page_assembly_v1.search_projection');
    }

    public function test_force_production_import_requires_explicit_confirmation(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assemblyRow('accountants-and-auditors', 'zh-CN'),
            $this->assemblyRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/page-assembly-production-missing-confirmation.json');

        $exitCode = Artisan::call('career:page-assembly-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--force' => true,
            '--status' => CareerJobPageAssemblyAsset::STATUS_PRODUCTION_IMPORTED,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decoded['decision']);
        $this->assertFalse((bool) $decoded['production_import_allowed']);
        $this->assertFalse((bool) $decoded['production_import_performed']);
        $this->assertStringContainsString('--confirm-production-import is required', implode(' ', $decoded['errors']));
    }

    public function test_production_import_requires_exact_asset_sha(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assemblyRow('accountants-and-auditors', 'zh-CN'),
            $this->assemblyRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/page-assembly-production-missing-source-sha.json');

        $exitCode = Artisan::call('career:page-assembly-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--force' => true,
            '--status' => CareerJobPageAssemblyAsset::STATUS_PRODUCTION_IMPORTED,
            '--confirm-production-import' => true,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decoded['decision']);
        $this->assertFalse((bool) $decoded['production_import_allowed']);
        $this->assertStringContainsString('--expected-sha256 with the approved asset artifact SHA is required', implode(' ', $decoded['errors']));
    }

    public function test_production_import_dry_run_validates_approved_rows_without_writing(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assemblyRow('accountants-and-auditors', 'zh-CN'),
            $this->assemblyRow('accountants-and-auditors', 'en'),
        ]);
        $assetSha = hash_file('sha256', $file);
        $this->seedPageAssemblyRows($file, CareerJobPageAssemblyAsset::STATUS_APPROVED);
        [$approvalManifest, $approvalManifestSha, $editorialReport, $editorialReportSha] = $this->writeProductionApprovalArtifacts($assetSha);
        $report = storage_path('framework/testing/page-assembly-production-dry-run.json');

        $exitCode = Artisan::call('career:page-assembly-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--expected-sha256' => $assetSha,
            '--dry-run' => true,
            '--status' => CareerJobPageAssemblyAsset::STATUS_PRODUCTION_IMPORTED,
            '--confirm-production-import' => true,
            '--approval-manifest' => $approvalManifest,
            '--approval-manifest-sha256' => $approvalManifestSha,
            '--editorial-review-report' => $editorialReport,
            '--editorial-review-sha256' => $editorialReportSha,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, CareerJobPageAssemblyAsset::query()->where('status', CareerJobPageAssemblyAsset::STATUS_APPROVED)->count());
        $this->assertSame(0, CareerJobPageAssemblyAsset::query()->where('status', CareerJobPageAssemblyAsset::STATUS_PRODUCTION_IMPORTED)->count());

        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertSame('production_import_dry_run', $decoded['mode']);
        $this->assertTrue((bool) $decoded['production_import_allowed']);
        $this->assertFalse((bool) $decoded['production_import_performed']);
        $this->assertSame(0, $decoded['production_rows_touched']);
        $this->assertSame(2, $decoded['expected_written_count']);
        $this->assertSame($approvalManifestSha, $decoded['approval_manifest_sha256']);
        $this->assertSame($editorialReportSha, $decoded['editorial_review_sha256']);
        $this->assertTrue((bool) $decoded['rollback_report']['available']);
    }

    public function test_force_production_import_blocks_non_approved_source_rows(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assemblyRow('accountants-and-auditors', 'zh-CN'),
            $this->assemblyRow('accountants-and-auditors', 'en'),
        ]);
        $assetSha = hash_file('sha256', $file);
        $this->seedPageAssemblyRows($file, CareerJobPageAssemblyAsset::STATUS_STAGING_PREVIEW);
        [$approvalManifest, $approvalManifestSha, $editorialReport, $editorialReportSha] = $this->writeProductionApprovalArtifacts($assetSha);
        $report = storage_path('framework/testing/page-assembly-production-staging-blocked.json');

        $exitCode = Artisan::call('career:page-assembly-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--expected-sha256' => $assetSha,
            '--force' => true,
            '--status' => CareerJobPageAssemblyAsset::STATUS_PRODUCTION_IMPORTED,
            '--confirm-production-import' => true,
            '--approval-manifest' => $approvalManifest,
            '--approval-manifest-sha256' => $approvalManifestSha,
            '--editorial-review-report' => $editorialReport,
            '--editorial-review-sha256' => $editorialReportSha,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, CareerJobPageAssemblyAsset::query()->where('status', CareerJobPageAssemblyAsset::STATUS_PRODUCTION_IMPORTED)->count());
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decoded['decision']);
        $this->assertFalse((bool) $decoded['production_import_allowed']);
        $this->assertFalse((bool) $decoded['production_import_performed']);
        $this->assertStringContainsString('production import requires approved source rows, found staging_preview', implode(' ', $decoded['errors']));
    }

    public function test_force_production_import_marks_approved_rows_production_imported_with_rollback_report(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assemblyRow('accountants-and-auditors', 'zh-CN'),
            $this->assemblyRow('accountants-and-auditors', 'en'),
        ]);
        $assetSha = hash_file('sha256', $file);
        $this->seedPageAssemblyRows($file, CareerJobPageAssemblyAsset::STATUS_APPROVED);
        [$approvalManifest, $approvalManifestSha, $editorialReport, $editorialReportSha] = $this->writeProductionApprovalArtifacts($assetSha);
        $report = storage_path('framework/testing/page-assembly-production-import.json');

        $exitCode = Artisan::call('career:page-assembly-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--expected-sha256' => $assetSha,
            '--force' => true,
            '--status' => CareerJobPageAssemblyAsset::STATUS_PRODUCTION_IMPORTED,
            '--confirm-production-import' => true,
            '--approval-manifest' => $approvalManifest,
            '--approval-manifest-sha256' => $approvalManifestSha,
            '--editorial-review-report' => $editorialReport,
            '--editorial-review-sha256' => $editorialReportSha,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, CareerJobPageAssemblyAsset::query()->where('status', CareerJobPageAssemblyAsset::STATUS_APPROVED)->count());
        $this->assertSame(2, CareerJobPageAssemblyAsset::query()->where('status', CareerJobPageAssemblyAsset::STATUS_PRODUCTION_IMPORTED)->count());

        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertSame('production_import', $decoded['mode']);
        $this->assertTrue((bool) $decoded['production_import_allowed']);
        $this->assertTrue((bool) $decoded['production_import_performed']);
        $this->assertFalse((bool) $decoded['staging_write_performed']);
        $this->assertSame(2, $decoded['written_count']);
        $this->assertSame(2, $decoded['updated_count']);
        $this->assertSame(2, $decoded['production_imported_count']);
        $this->assertSame(2, $decoded['production_rows_touched']);
        $this->assertSame($approvalManifestSha, $decoded['approval_manifest_sha256']);
        $this->assertSame($editorialReportSha, $decoded['editorial_review_sha256']);
        $this->assertSame(2, $decoded['rollback_report']['previous_status_counts'][CareerJobPageAssemblyAsset::STATUS_APPROVED]);
    }

    private function seedCareerJobBundleAuthority(string $slug): void
    {
        $this->seedRuntimeProjectionAuthority([$slug]);

        if (! Occupation::query()->where('canonical_slug', $slug)->exists()) {
            $this->seedOccupation($slug);
        }

        $authorityCache = app(PublicCareerAuthorityResponseCache::class);
        Cache::forever($authorityCache->jobDetailCacheKey($slug, 'zh-CN'), [
            'slug' => $slug,
            'locale' => 'zh-CN',
            'sections' => [['key' => 'identity']],
        ]);
        Cache::forever($authorityCache->jobDetailCacheKey($slug, 'en'), [
            'slug' => $slug,
            'locale' => 'en',
            'sections' => [['key' => 'identity']],
        ]);

        $this->app->forgetInstance(CareerPageAssemblyImportService::class);
        $this->app->forgetInstance(CareerPageAssemblyPreviewService::class);
        $this->app->forgetInstance('App\\Console\\Commands\\CareerImportPageAssemblyAssetsPreview');
    }

    /**
     * @param  list<string>  $slugs
     */
    private function seedRuntimeProjectionAuthority(array $slugs): void
    {
        $items = [];
        foreach ($slugs as $slug) {
            $normalizedSlug = strtolower(trim($slug));
            foreach (['en', 'zh', 'zh-CN'] as $locale) {
                $items[$normalizedSlug.'|'.$locale] = [
                    'slug' => $normalizedSlug,
                    'locale' => $locale,
                    'public_resolution_type' => 'public_canonical_job',
                    'dataset_visible' => true,
                    'search_visible' => true,
                    'detail_route_enabled' => true,
                    'robots_indexable' => true,
                    'release_gate_pass' => true,
                    'runtime_publish_state' => 'published',
                ];
            }
        }

        $this->app->instance(
            CareerRuntimePublishProjectionVisibility::class,
            new CareerRuntimePublishProjectionVisibilityFixture(
                defaultDatasetVisible: false,
                defaultSearchVisible: false,
                defaultDetailRouteEnabled: false,
                defaultRobotsIndexable: false,
                defaultReleaseGatePass: false,
                items: $items,
            ),
        );
    }

    private function seedOccupation(string $slug): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'page-assembly-preview-'.$slug,
            'title_en' => 'Page Assembly Preview',
            'title_zh' => '页面组装预览',
        ]);

        return Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => $slug,
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'CN',
            'crosswalk_mode' => 'direct_match',
            'canonical_title_en' => 'Accountants and Auditors',
            'canonical_title_zh' => '会计师和审计师',
            'search_h1_zh' => '会计师和审计师',
            'structural_stability' => 0.9,
            'task_prototype_signature' => ['analysis' => 0.8],
            'market_semantics_gap' => 0.1,
            'regulatory_divergence' => 0.1,
            'toolchain_divergence' => 0.1,
            'skill_gap_threshold' => 0.4,
            'trust_inheritance_scope' => ['allow_task_truth' => true],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeJsonl(array $rows): string
    {
        $path = storage_path('framework/testing/page-assembly-preview-'.bin2hex(random_bytes(4)).'.jsonl');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, implode('', array_map(
            static fn (array $row): string => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
            $rows
        )));

        return $path;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    private function writeJsonArtifact(string $prefix, array $payload): array
    {
        $path = storage_path('framework/testing/'.$prefix.'-'.bin2hex(random_bytes(4)).'.json');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);

        return [$path, hash_file('sha256', $path)];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function writeProductionApprovalArtifacts(string $assetSha): array
    {
        [$approvalManifest, $approvalManifestSha] = $this->writeJsonArtifact('page-assembly-production-approval-manifest', [
            'final_conclusion' => 'CAREER_CONTENT_1046_EDITORIAL_REVIEW_PASS',
            'approved_count' => 2,
            'rejected_count' => 0,
            'slug_count' => 1,
            'production_import_approved' => false,
            'production_import_allowed' => false,
            'next_allowed_transition' => CareerJobPageAssemblyAsset::STATUS_APPROVED,
            'required_for_approved_transition' => [
                'asset_sha256' => $assetSha,
                'row_count' => 2,
                'slug_count' => 1,
            ],
        ]);
        [$editorialReport, $editorialReportSha] = $this->writeJsonArtifact('page-assembly-production-editorial-review', [
            'final_conclusion' => 'CAREER_CONTENT_1046_EDITORIAL_REVIEW_PASS',
            'approved_rows' => 2,
            'rejected' => 0,
            'production_import_approved' => false,
            'metrics' => [
                'slug_count' => 1,
                'expected_locale_rows' => 2,
                'finding_count' => 0,
            ],
            'inputs' => [
                'final_repaired_asset_sha256' => $assetSha,
            ],
        ]);

        return [$approvalManifest, $approvalManifestSha, $editorialReport, $editorialReportSha];
    }

    private function seedPageAssemblyRows(string $file, string $status): void
    {
        $sourceSha = hash_file('sha256', $file);
        foreach (['zh-CN', 'en'] as $locale) {
            $row = $this->assemblyRow('accountants-and-auditors', $locale);
            CareerJobPageAssemblyAsset::query()->create([
                'occupation_id' => Occupation::query()->where('canonical_slug', 'accountants-and-auditors')->value('id'),
                'career_job_slug' => 'accountants-and-auditors',
                'locale' => $locale,
                'asset_version' => CareerJobPageAssemblyAsset::ASSET_VERSION_V1,
                'status' => $status,
                'preview_allowlisted' => $status !== CareerJobPageAssemblyAsset::STATUS_PRODUCTION_IMPORTED,
                'asset_payload_json' => $row,
                'block_refs_json' => $row['block_refs'],
                'audit_fields_json' => $row['audit_fields'],
                'asset_row_hash' => (string) $row['audit_fields']['row_hash'],
                'source_artifact_sha256' => $sourceSha,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function assemblyRow(string $slug, string $locale): array
    {
        return [
            'asset_version' => CareerJobPageAssemblyAsset::ASSET_VERSION_V1,
            'audit_fields' => [
                'row_hash' => hash('sha256', $slug.'|'.$locale),
                'source_block_count' => 7,
            ],
            'block_refs' => [
                'career-identity' => [
                    'status' => 'available',
                    'source_row_hash' => str_repeat('b', 64),
                ],
            ],
            'block_type' => 'career-page-assembly',
            'ledger_type' => 'career-page-assembly',
            'locale' => $locale,
            'missing_blocks' => [],
            'occupation' => [
                'title_en' => 'Accountants and Auditors',
                'title_zh' => '会计师和审计师',
                'soc_code' => '13-2011',
                'onet_code' => '13-2011.00',
            ],
            'page_sections' => [
                [
                    'section_key' => 'hero_identity',
                    'display_priority' => 10,
                    'availability_status' => 'available',
                    'source_block' => 'career-identity',
                    'source_row_hash' => str_repeat('c', 64),
                ],
                [
                    'section_key' => 'work_activities',
                    'display_priority' => 20,
                    'availability_status' => 'available',
                    'source_block' => 'career-work-activities',
                    'source_row_hash' => str_repeat('d', 64),
                ],
            ],
            'reader_boundary' => 'Preview payload is assembled from PASS or mature registered blocks and contains no new career facts.',
            'runtime_boundary' => 'Preview-only page assembly; not a runtime SEO instruction.',
            'section_order' => ['hero_identity', 'work_activities'],
            'seed_ordinal' => 1,
            'slug' => $slug,
            'source_coverage' => [
                'available_block_count' => 7,
            ],
        ];
    }
}
