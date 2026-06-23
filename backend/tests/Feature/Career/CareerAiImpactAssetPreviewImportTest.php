<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerJobAiImpactAsset;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Services\Career\AiImpactAssets\CareerAiImpactAssetImportService;
use App\Services\Career\AiImpactAssets\CareerAiImpactAssetPreviewService;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerAiImpactAssetPreviewImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedRuntimeProjectionAuthority([]);
    }

    public function test_ai_impact_asset_sidecar_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('career_job_ai_impact_assets'));
        $this->assertTrue(Schema::hasColumn('career_job_ai_impact_assets', 'asset_payload_json'));
        $this->assertTrue(Schema::hasColumn('career_job_ai_impact_assets', 'asset_row_hash'));
        $this->assertTrue(Schema::hasColumn('career_job_ai_impact_assets', 'preview_allowlisted'));
    }

    public function test_importer_dry_run_validates_preview_rows_without_writing(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/ai-impact-preview-dry-run.json');

        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('career_job_ai_impact_assets', 0);

        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertSame(2, $decoded['validated_preview_rows']);
        $this->assertFalse((bool) ($decoded['production_import_allowed'] ?? true));
        $this->assertFalse((bool) ($decoded['staging_write_performed'] ?? true));
    }

    public function test_importer_dry_run_reports_state_machine_sha_idempotency_and_rollback_policy(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $sha = hash_file('sha256', $file);
        $report = storage_path('framework/testing/ai-impact-preview-state-machine-dry-run.json');

        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--expected-sha256' => $sha,
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertSame($sha, $decoded['source_file_sha256']);
        $this->assertTrue((bool) $decoded['source_file_sha256_match']);
        $this->assertSame(0, $decoded['duplicate_key_count']);
        $this->assertSame(['career_job_slug', 'locale', 'asset_version'], $decoded['idempotency']['target_key']);
        $this->assertFalse((bool) $decoded['state_machine']['production_import_without_approved_allowed']);
        $this->assertSame(CareerJobAiImpactAsset::STATUS_APPROVED, $decoded['state_machine']['production_import_requires_from_status']);
        $this->assertTrue((bool) $decoded['rollback_policy']['production_import_requires_approved_status']);
        $this->assertFalse((bool) $decoded['rollback_policy']['dry_run_writes_database']);
        $this->assertTrue((bool) $decoded['rollback_policy']['staging_preview_write_supported_in_this_pr']);
    }

    public function test_importer_force_writes_staging_preview_rows_after_validation_passes(): void
    {
        Config::set('career_ai_impact_assets.staging_preview_enabled', true);
        Config::set('career_ai_impact_assets.preview_slugs', ['accountants-and-auditors']);
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $sha = hash_file('sha256', $file);
        $report = storage_path('framework/testing/ai-impact-preview-force-write.json');

        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--expected-sha256' => $sha,
            '--force' => true,
            '--status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('career_job_ai_impact_assets', 2);
        $this->assertDatabaseHas('career_job_ai_impact_assets', [
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'zh-CN',
            'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
            'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
            'source_artifact_sha256' => $sha,
        ]);

        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertSame('write', $decoded['mode']);
        $this->assertSame(2, $decoded['written_count']);
        $this->assertSame(2, $decoded['created_count']);
        $this->assertSame(0, $decoded['updated_count']);
        $this->assertTrue((bool) $decoded['staging_write_performed']);
        $this->assertFalse((bool) $decoded['production_import_allowed']);

        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/ai-impact-asset?locale=en')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('preview', true)
            ->assertJsonPath('status', CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW)
            ->assertJsonPath('ai_impact_asset_v1.slug', 'accountants-and-auditors')
            ->assertJsonMissingPath('ai_impact_asset_v1.occupation.title_zh')
            ->assertJsonMissingPath('ai_impact_asset_v1.audit_fields')
            ->assertJsonMissingPath('ai_impact_asset_v1.evidence_used')
            ->assertJsonMissingPath('ai_impact_asset_v1.derived_from_synthesis')
            ->assertJsonMissingPath('ai_impact_asset_v1.search_projection');
    }

    public function test_importer_force_all_slugs_from_file_requires_explicit_confirmation(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/ai-impact-preview-force-full-requires-confirmation.json');

        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--all-slugs-from-file' => true,
            '--force' => true,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('career_job_ai_impact_assets', 0);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('--force --all-slugs-from-file requires --confirm-full-staging-preview', implode(' ', $decoded['errors']));
    }

    public function test_importer_force_all_slugs_from_file_writes_row_allowlisted_full_preview(): void
    {
        Config::set('career_ai_impact_assets.staging_preview_enabled', true);
        Config::set('career_ai_impact_assets.preview_slugs', []);
        $this->seedCareerJobBundleAuthorities(['accountants-and-auditors', 'actuaries']);
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
            $this->assetRow('actuaries', 'zh-CN'),
            $this->assetRow('actuaries', 'en'),
        ]);
        $report = storage_path('framework/testing/ai-impact-preview-force-full-confirmed.json');

        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--all-slugs-from-file' => true,
            '--confirm-full-staging-preview' => true,
            '--force' => true,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('career_job_ai_impact_assets', 4);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertSame(4, $decoded['written_count']);
        $this->assertTrue((bool) $decoded['staging_write_performed']);
        $this->assertFalse((bool) $decoded['production_import_allowed']);

        $this->getJson('/api/v0.5/career/jobs/actuaries/ai-impact-asset?locale=en')
            ->assertOk()
            ->assertJsonPath('ai_impact_asset_v1.slug', 'actuaries')
            ->assertJsonMissingPath('ai_impact_asset_v1.evidence_used')
            ->assertJsonMissingPath('ai_impact_asset_v1.search_projection');
    }

    public function test_importer_force_rejects_non_staging_preview_status_without_writing(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/ai-impact-preview-force-production-reject.json');

        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--force' => true,
            '--status' => CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('career_job_ai_impact_assets', 0);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decoded['decision']);
        $this->assertFalse((bool) $decoded['production_import_allowed']);
        $this->assertFalse((bool) $decoded['production_import_performed']);
        $this->assertStringContainsString('--confirm-production-import is required', implode(' ', $decoded['errors']));
    }

    public function test_importer_force_approved_requires_explicit_manifest_and_confirmation(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/ai-impact-approved-requires-manifest.json');

        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--force' => true,
            '--status' => CareerJobAiImpactAsset::STATUS_APPROVED,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('career_job_ai_impact_assets', 0);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decoded['decision']);
        $this->assertFalse((bool) $decoded['production_import_allowed']);
        $this->assertFalse((bool) $decoded['approved_transition_performed']);
        $this->assertStringContainsString('--confirm-approved-transition is required', implode(' ', $decoded['errors']));

        $missingArtifactReport = storage_path('framework/testing/ai-impact-approved-requires-artifacts.json');
        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--force' => true,
            '--status' => CareerJobAiImpactAsset::STATUS_APPROVED,
            '--confirm-approved-transition' => true,
            '--output' => $missingArtifactReport,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('career_job_ai_impact_assets', 0);
        $decoded = json_decode((string) file_get_contents($missingArtifactReport), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decoded['decision']);
        $this->assertFalse((bool) $decoded['approved_transition_performed']);
        $this->assertStringContainsString('Approval manifest file is required', implode(' ', $decoded['errors']));
        $this->assertStringContainsString('Editorial review report file is required', implode(' ', $decoded['errors']));
    }

    public function test_importer_force_approved_marks_existing_reviewed_rows_without_touching_production(): void
    {
        Config::set('career_ai_impact_assets.staging_preview_enabled', true);
        Config::set('career_ai_impact_assets.preview_slugs', ['accountants-and-auditors', 'actuaries']);
        $this->seedCareerJobBundleAuthorities(['accountants-and-auditors', 'actuaries']);

        $zhRow = $this->assetRow('accountants-and-auditors', 'zh-CN');
        $enRow = $this->assetRow('accountants-and-auditors', 'en');
        $file = $this->writeJsonl([$zhRow, $enRow]);
        $assetSha = hash_file('sha256', $file);
        $approvalManifest = $this->writeJsonArtifact($this->approvalManifest($assetSha, 2, 1));
        $editorialReview = $this->writeJsonArtifact($this->editorialReviewReport($assetSha, 2, 1));
        $approvalSha = hash_file('sha256', $approvalManifest);
        $editorialSha = hash_file('sha256', $editorialReview);

        $occupation = Occupation::query()->where('canonical_slug', 'accountants-and-auditors')->firstOrFail();
        foreach ([$zhRow, $enRow] as $row) {
            CareerJobAiImpactAsset::query()->create([
                'occupation_id' => $occupation->id,
                'career_job_slug' => 'accountants-and-auditors',
                'locale' => $row['locale'],
                'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
                'status' => CareerJobAiImpactAsset::STATUS_EDITORIAL_REVIEW,
                'preview_allowlisted' => true,
                'asset_payload_json' => $row,
                'sources_json' => $row['sources'],
                'evidence_used_json' => $row['evidence_used'],
                'derived_from_synthesis_json' => $row['derived_from_synthesis'],
                'audit_fields_json' => $row['audit_fields'],
                'asset_row_hash' => $row['audit_fields']['row_hash'],
                'source_artifact_sha256' => $assetSha,
            ]);
        }

        $productionOccupation = Occupation::query()->where('canonical_slug', 'actuaries')->firstOrFail();
        $productionRow = $this->assetRow('actuaries', 'en');
        CareerJobAiImpactAsset::query()->create([
            'occupation_id' => $productionOccupation->id,
            'career_job_slug' => 'actuaries',
            'locale' => 'en',
            'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
            'status' => CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
            'preview_allowlisted' => false,
            'asset_payload_json' => $productionRow,
            'sources_json' => $productionRow['sources'],
            'evidence_used_json' => $productionRow['evidence_used'],
            'derived_from_synthesis_json' => $productionRow['derived_from_synthesis'],
            'audit_fields_json' => $productionRow['audit_fields'],
            'asset_row_hash' => $productionRow['audit_fields']['row_hash'],
        ]);

        $report = storage_path('framework/testing/ai-impact-approved-transition.json');
        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--expected-sha256' => $assetSha,
            '--force' => true,
            '--status' => CareerJobAiImpactAsset::STATUS_APPROVED,
            '--confirm-approved-transition' => true,
            '--approval-manifest' => $approvalManifest,
            '--approval-manifest-sha256' => $approvalSha,
            '--editorial-review-report' => $editorialReview,
            '--editorial-review-sha256' => $editorialSha,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('career_job_ai_impact_assets', 3);
        $this->assertDatabaseHas('career_job_ai_impact_assets', [
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'zh-CN',
            'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
            'status' => CareerJobAiImpactAsset::STATUS_APPROVED,
        ]);
        $this->assertDatabaseHas('career_job_ai_impact_assets', [
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'en',
            'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
            'status' => CareerJobAiImpactAsset::STATUS_APPROVED,
        ]);
        $this->assertDatabaseHas('career_job_ai_impact_assets', [
            'career_job_slug' => 'actuaries',
            'locale' => 'en',
            'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
            'status' => CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
        ]);

        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertSame('approved_transition', $decoded['mode']);
        $this->assertSame(2, $decoded['approved_count']);
        $this->assertSame(0, $decoded['production_rows_touched']);
        $this->assertFalse((bool) $decoded['production_import_allowed']);
        $this->assertFalse((bool) $decoded['production_import_performed']);
        $this->assertTrue((bool) $decoded['rollback_report']['available']);
        $this->assertSame([CareerJobAiImpactAsset::STATUS_EDITORIAL_REVIEW => 2], $decoded['rollback_report']['previous_status_counts']);

        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/ai-impact-asset?locale=en')
            ->assertOk()
            ->assertJsonPath('ai_impact_asset_v1.slug', 'accountants-and-auditors')
            ->assertJsonMissingPath('ai_impact_asset_v1.evidence_used')
            ->assertJsonMissingPath('ai_impact_asset_v1.search_projection');
    }

    public function test_importer_force_production_import_requires_confirmation_and_artifacts(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/ai-impact-production-import-requires-confirmation.json');

        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--force' => true,
            '--status' => CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('career_job_ai_impact_assets', 0);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decoded['decision']);
        $this->assertFalse((bool) $decoded['production_import_allowed']);
        $this->assertFalse((bool) $decoded['production_import_performed']);
        $this->assertStringContainsString('--confirm-production-import is required', implode(' ', $decoded['errors']));

        $missingArtifactReport = storage_path('framework/testing/ai-impact-production-import-requires-artifacts.json');
        $assetSha = hash_file('sha256', $file);
        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--expected-sha256' => $assetSha,
            '--force' => true,
            '--status' => CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
            '--confirm-production-import' => true,
            '--output' => $missingArtifactReport,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('career_job_ai_impact_assets', 0);
        $decoded = json_decode((string) file_get_contents($missingArtifactReport), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decoded['decision']);
        $this->assertFalse((bool) $decoded['production_import_performed']);
        $this->assertStringContainsString('Approval manifest file is required', implode(' ', $decoded['errors']));
        $this->assertStringContainsString('Editorial review report file is required', implode(' ', $decoded['errors']));
    }

    public function test_importer_force_production_import_writes_approved_package_and_serves_public_payload(): void
    {
        Config::set('career_ai_impact_assets.staging_preview_enabled', false);
        Config::set('career_ai_impact_assets.preview_slugs', []);
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');

        $zhRow = $this->assetRow('accountants-and-auditors', 'zh-CN');
        $enRow = $this->assetRow('accountants-and-auditors', 'en');
        $file = $this->writeJsonl([$zhRow, $enRow]);
        $assetSha = hash_file('sha256', $file);
        $approvalManifest = $this->writeJsonArtifact($this->approvalManifest($assetSha, 2, 1));
        $editorialReview = $this->writeJsonArtifact($this->editorialReviewReport($assetSha, 2, 1));
        $approvalSha = hash_file('sha256', $approvalManifest);
        $editorialSha = hash_file('sha256', $editorialReview);
        $report = storage_path('framework/testing/ai-impact-production-import.json');

        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--expected-sha256' => $assetSha,
            '--force' => true,
            '--status' => CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
            '--confirm-production-import' => true,
            '--approval-manifest' => $approvalManifest,
            '--approval-manifest-sha256' => $approvalSha,
            '--editorial-review-report' => $editorialReview,
            '--editorial-review-sha256' => $editorialSha,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('career_job_ai_impact_assets', 2);
        $this->assertDatabaseHas('career_job_ai_impact_assets', [
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'zh-CN',
            'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
            'status' => CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
            'preview_allowlisted' => false,
            'source_artifact_sha256' => $assetSha,
        ]);
        $this->assertDatabaseHas('career_job_ai_impact_assets', [
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'en',
            'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
            'status' => CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
            'preview_allowlisted' => false,
            'source_artifact_sha256' => $assetSha,
        ]);

        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertSame('production_import', $decoded['mode']);
        $this->assertTrue((bool) $decoded['production_import_allowed']);
        $this->assertTrue((bool) $decoded['production_import_performed']);
        $this->assertSame(2, $decoded['written_count']);
        $this->assertSame(2, $decoded['created_count']);
        $this->assertSame(0, $decoded['updated_count']);
        $this->assertSame(2, $decoded['production_imported_count']);
        $this->assertSame(['missing' => 2], $decoded['rollback_report']['previous_status_counts']);

        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/ai-impact-asset?locale=en')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('preview', false)
            ->assertJsonPath('status', CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED)
            ->assertJsonPath('ai_impact_asset_v1.slug', 'accountants-and-auditors')
            ->assertJsonMissingPath('ai_impact_asset_v1.occupation.title_zh')
            ->assertJsonMissingPath('ai_impact_asset_v1.audit_fields')
            ->assertJsonMissingPath('ai_impact_asset_v1.evidence_used')
            ->assertJsonMissingPath('ai_impact_asset_v1.derived_from_synthesis')
            ->assertJsonMissingPath('ai_impact_asset_v1.search_projection');
    }

    public function test_importer_force_production_import_rejects_unapproved_existing_rows(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $row = $this->assetRow('accountants-and-auditors', 'en');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $row,
        ]);
        $assetSha = hash_file('sha256', $file);
        $approvalManifest = $this->writeJsonArtifact($this->approvalManifest($assetSha, 2, 1));
        $editorialReview = $this->writeJsonArtifact($this->editorialReviewReport($assetSha, 2, 1));
        $occupation = Occupation::query()->where('canonical_slug', 'accountants-and-auditors')->firstOrFail();
        CareerJobAiImpactAsset::query()->create([
            'occupation_id' => $occupation->id,
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'en',
            'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
            'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
            'asset_payload_json' => $row,
            'sources_json' => $row['sources'],
            'evidence_used_json' => $row['evidence_used'],
            'derived_from_synthesis_json' => $row['derived_from_synthesis'],
            'audit_fields_json' => $row['audit_fields'],
            'asset_row_hash' => $row['audit_fields']['row_hash'],
            'source_artifact_sha256' => $assetSha,
        ]);

        $report = storage_path('framework/testing/ai-impact-production-import-rejects-staging.json');
        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--expected-sha256' => $assetSha,
            '--force' => true,
            '--status' => CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
            '--confirm-production-import' => true,
            '--approval-manifest' => $approvalManifest,
            '--editorial-review-report' => $editorialReview,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseMissing('career_job_ai_impact_assets', [
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'en',
            'status' => CareerJobAiImpactAsset::STATUS_PRODUCTION_IMPORTED,
        ]);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decoded['decision']);
        $this->assertFalse((bool) $decoded['production_import_allowed']);
        $this->assertFalse((bool) $decoded['production_import_performed']);
        $this->assertStringContainsString('production import requires approved source rows or an empty production target', implode(' ', $decoded['errors']));
    }

    public function test_importer_dry_run_rejects_unexpected_source_sha(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/ai-impact-preview-sha-mismatch.json');

        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--expected-sha256' => str_repeat('0', 64),
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('career_job_ai_impact_assets', 0);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse((bool) $decoded['source_file_sha256_match']);
        $this->assertStringContainsString('Source JSONL SHA-256 does not match expected artifact SHA', implode(' ', $decoded['errors']));
    }

    public function test_importer_dry_run_validates_full_1046_contract_without_writing(): void
    {
        $slugs = array_map(static fn (int $index): string => 'ai-impact-contract-career-'.$index, range(1, 1046));
        $this->seedCareerJobBundleAuthorities($slugs);

        $rows = [];
        foreach ($slugs as $slug) {
            $rows[] = $this->assetRow($slug, 'zh-CN');
            $rows[] = $this->assetRow($slug, 'en');
        }
        $file = $this->writeJsonl($rows);
        $report = storage_path('framework/testing/ai-impact-preview-full-1046-dry-run.json');

        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--all-slugs-from-file' => true,
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('career_job_ai_impact_assets', 0);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertSame(1046, $decoded['target_slug_count']);
        $this->assertSame(2092, $decoded['validated_preview_rows']);
        $this->assertSame(2092, $decoded['expected_preview_rows']);
        $this->assertSame(1046, $decoded['career_job_bundle_authority']['ready_slug_count']);
    }

    public function test_importer_dry_run_rejects_embedded_search_projection_and_outcome_claims(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $zh = $this->assetRow('accountants-and-auditors', 'zh-CN');
        $zh['search_projection'] = ['candidate_only_not_runtime_seo' => true];
        $en = $this->assetRow('accountants-and-auditors', 'en');
        $en['summary'] = 'This score predicts job loss for Accountants and Auditors.';
        $file = $this->writeJsonl([$zh, $en]);
        $report = storage_path('framework/testing/ai-impact-preview-projection-gate-fail.json');

        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $errors = implode(' ', $decoded['errors']);
        $this->assertStringContainsString('search_projection must remain in a separate candidate file', $errors);
        $this->assertStringContainsString('blocked AI outcome framing: predicts job loss', $errors);
    }

    public function test_importer_blocks_when_career_job_bundle_authority_is_missing(): void
    {
        $this->seedOccupation('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/ai-impact-preview-authority-fail.json');

        $exitCode = Artisan::call('career:ai-impact-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decoded['decision']);
        $this->assertStringContainsString('missing_career_job_bundle_authority', implode(' ', $decoded['errors']));
        $this->assertSame(0, $decoded['career_job_bundle_authority']['ready_slug_count']);
    }

    public function test_preview_api_projects_reader_safe_payload_when_enabled(): void
    {
        Config::set('career_ai_impact_assets.staging_preview_enabled', true);
        Config::set('career_ai_impact_assets.preview_slugs', ['accountants-and-auditors']);
        $occupation = $this->seedOccupation('accountants-and-auditors');
        $row = $this->assetRow('accountants-and-auditors', 'en');
        $row['search_projection'] = ['candidate_only_not_runtime_seo' => true];
        $row['score_rationale']['source_ids'] = ['source_should_not_leak'];
        $row['score_rationale']['evidence_ids'] = ['evidence_should_not_leak'];
        $row['items']['reader_boundary']['body'] = 'This score is not a career disappearance or job-loss risk prediction.';
        $row['sources'][] = [
            'source_id' => 'internal_rubric_v5',
            'source_name' => 'FermatMind AI Task-Exposure Rubric v5',
            'source_type' => 'internal_rubric',
            'source_url' => 'fermatmind://internal/rubric/career-ai-task-exposure-v5',
            'used_for' => 'Internal scoring lens.',
            'captured_fact' => 'Internal rubric.',
            'boundary' => 'Never used alone.',
        ];

        CareerJobAiImpactAsset::query()->create([
            'occupation_id' => $occupation->id,
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'en',
            'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
            'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
            'asset_payload_json' => $row,
            'sources_json' => $row['sources'],
            'evidence_used_json' => $row['evidence_used'],
            'derived_from_synthesis_json' => $row['derived_from_synthesis'],
            'audit_fields_json' => $row['audit_fields'],
            'asset_row_hash' => $row['audit_fields']['row_hash'],
        ]);

        $response = $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/ai-impact-asset?locale=en')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('preview', true)
            ->assertJsonPath('ai_impact_asset_v1.slug', 'accountants-and-auditors')
            ->assertJsonPath('ai_impact_asset_v1.locale', 'en');

        $asset = $response->json('ai_impact_asset_v1');
        foreach ([
            'asset_version',
            'ledger_type',
            'block_type',
            'batch_role',
            'seed_ordinal',
            'audit_fields',
            'evidence_used',
            'derived_from_synthesis',
            'search_projection',
            'score_rationale',
            'micro_family',
        ] as $internalKey) {
            $this->assertArrayNotHasKey($internalKey, $asset);
        }

        $this->assertArrayNotHasKey('source_id', $asset['sources'][0]);
        $this->assertArrayNotHasKey('used_for', $asset['sources'][0]);
        $this->assertArrayNotHasKey('source_type', $asset['sources'][0]);
        $this->assertArrayNotHasKey('boundary', $asset['sources'][0]);
        $this->assertSame('O*NET OnLine summary for Accountants and Auditors', $asset['sources'][0]['name']);
        $this->assertCount(1, $asset['sources']);
        $this->assertStringNotContainsString('fermatmind://internal', json_encode($asset, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('career disappearance', $asset['items']['reader_boundary']['body']);
        $this->assertStringNotContainsString('job-loss risk', $asset['items']['reader_boundary']['body']);
        $this->assertStringContainsString('individual career outcome', $asset['items']['reader_boundary']['body']);
    }

    public function test_preview_api_normalizes_zh_boundary_outcome_wording(): void
    {
        Config::set('career_ai_impact_assets.staging_preview_enabled', true);
        Config::set('career_ai_impact_assets.preview_slugs', ['accountants-and-auditors']);
        $occupation = $this->seedOccupation('accountants-and-auditors');
        $row = $this->assetRow('accountants-and-auditors', 'zh-CN');
        $row['summary'] = '该分数不是岗位会消失或降薪预测。';
        $row['items']['reader_boundary']['body'] = 'FermatMind 用它描述任务可被辅助的程度，不把它当作失业、个人职业结果预测。';

        CareerJobAiImpactAsset::query()->create([
            'occupation_id' => $occupation->id,
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'zh-CN',
            'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
            'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
            'asset_payload_json' => $row,
            'sources_json' => $row['sources'],
            'evidence_used_json' => $row['evidence_used'],
            'derived_from_synthesis_json' => $row['derived_from_synthesis'],
            'audit_fields_json' => $row['audit_fields'],
            'asset_row_hash' => $row['audit_fields']['row_hash'],
        ]);

        $asset = $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/ai-impact-asset?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('ai_impact_asset_v1.locale', 'zh-CN')
            ->json('ai_impact_asset_v1');

        $encodedAsset = json_encode($asset, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('岗位会消失', $encodedAsset);
        $this->assertStringNotContainsString('职业会消失', $encodedAsset);
        $this->assertStringNotContainsString('降薪', $encodedAsset);
        $this->assertStringNotContainsString('预测预测', $encodedAsset);
        $this->assertStringNotContainsString('失业', $encodedAsset);
        $this->assertStringContainsString('不是个人职业结果预测', $asset['items']['reader_boundary']['body']);
    }

    public function test_preview_api_repairs_known_cross_domain_context_in_reader_projection(): void
    {
        Config::set('career_ai_impact_assets.staging_preview_enabled', true);
        Config::set('career_ai_impact_assets.preview_slugs', [
            'writers-and-authors',
            'heavy-and-tractor-trailer-truck-drivers',
            'architects',
            'biochemists-and-biophysicists',
        ]);

        $writerOccupation = $this->seedOccupation('writers-and-authors');
        $writerZh = $this->assetRow('writers-and-authors', 'zh-CN');
        $writerZh['items']['most_ai_exposed_workflows'][0]['body'] = '整理研究笔记、物种观察、采访材料、草稿段落、引用、栖息地数据和期刊或出版规则。';
        $writerZh['items']['most_ai_exposed_workflows'][1] = [
            'label' => '复核线索',
            'body' => '正式采用仍要看运行安全、放行条件、天气改航、间隔限制、维护记录和旅客/机组安全。',
        ];
        $writerZh['items']['how_to_prepare'][0]['body'] = '把材料做成运行限制说明、异常处置日志、天气/NOTAM 核对和放行复盘。';
        $writerEn = $this->assetRow('writers-and-authors', 'en');
        $writerEn['items']['most_ai_exposed_workflows'][0]['body'] = 'organize research notes, species observations, interview material, draft sections, citations, habitat data, and journal or publisher rules.';
        $writerEn['items']['human_accountability_anchors'][0]['body'] = 'The hard part is operational safety, release conditions, weather diversion, separation limits, maintenance records, and crew or passenger safety.';

        foreach ([$writerZh, $writerEn] as $row) {
            CareerJobAiImpactAsset::query()->create([
                'occupation_id' => $writerOccupation->id,
                'career_job_slug' => 'writers-and-authors',
                'locale' => $row['locale'],
                'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
                'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
                'preview_allowlisted' => true,
                'asset_payload_json' => $row,
                'sources_json' => $row['sources'],
                'evidence_used_json' => $row['evidence_used'],
                'derived_from_synthesis_json' => $row['derived_from_synthesis'],
                'audit_fields_json' => $row['audit_fields'],
                'asset_row_hash' => $row['audit_fields']['row_hash'],
            ]);
        }

        $truckOccupation = $this->seedOccupation('heavy-and-tractor-trailer-truck-drivers');
        $truckZh = $this->assetRow('heavy-and-tractor-trailer-truck-drivers', 'zh-CN');
        $truckZh['items']['human_accountability_anchors'][0]['body'] = '正式采用仍要看运行安全、放行条件、天气改航、间隔限制、维护记录和旅客/机组安全。';
        CareerJobAiImpactAsset::query()->create([
            'occupation_id' => $truckOccupation->id,
            'career_job_slug' => 'heavy-and-tractor-trailer-truck-drivers',
            'locale' => 'zh-CN',
            'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
            'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
            'asset_payload_json' => $truckZh,
            'sources_json' => $truckZh['sources'],
            'evidence_used_json' => $truckZh['evidence_used'],
            'derived_from_synthesis_json' => $truckZh['derived_from_synthesis'],
            'audit_fields_json' => $truckZh['audit_fields'],
            'asset_row_hash' => $truckZh['audit_fields']['row_hash'],
        ]);

        $architectOccupation = $this->seedOccupation('architects');
        foreach (['zh-CN', 'en'] as $locale) {
            $architectRow = $this->assetRow('architects', $locale);
            if ($locale === 'zh-CN') {
                $architectRow['items']['human_accountability_anchors'][0]['body'] = '正式采用仍要看态势判断、指挥链、人员安全、任务规则和升级交接。';
            } else {
                $architectRow['items']['human_accountability_anchors'][0]['body'] = 'Final use still depends on situational judgment, chain of command, personnel safety, mission rules, and escalation handoff.';
            }

            CareerJobAiImpactAsset::query()->create([
                'occupation_id' => $architectOccupation->id,
                'career_job_slug' => 'architects',
                'locale' => $locale,
                'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
                'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
                'preview_allowlisted' => true,
                'asset_payload_json' => $architectRow,
                'sources_json' => $architectRow['sources'],
                'evidence_used_json' => $architectRow['evidence_used'],
                'derived_from_synthesis_json' => $architectRow['derived_from_synthesis'],
                'audit_fields_json' => $architectRow['audit_fields'],
                'asset_row_hash' => $architectRow['audit_fields']['row_hash'],
            ]);
        }

        $biochemistOccupation = $this->seedOccupation('biochemists-and-biophysicists');
        foreach (['zh-CN', 'en'] as $locale) {
            $biochemistRow = $this->assetRow('biochemists-and-biophysicists', $locale);
            if ($locale === 'zh-CN') {
                $biochemistRow['items']['human_accountability_anchors'][0]['body'] = '正式采用仍要看临床升级、用药核对、转诊判断、患者沟通和病情变化记录。';
                $biochemistRow['items']['how_to_prepare'][0]['body'] = '把实验记录做成去标识病例复盘、交接记录、关键核对表和转诊说明；再围绕统计输出建立病历/EHR 摘要、医嘱核对、生命体征趋势和随访清单。';
            } else {
                $biochemistRow['items']['human_accountability_anchors'][0]['body'] = 'Final use still depends on clinical escalation, medication checks, referral judgment, patient communication, and change-in-condition records.';
            }

            CareerJobAiImpactAsset::query()->create([
                'occupation_id' => $biochemistOccupation->id,
                'career_job_slug' => 'biochemists-and-biophysicists',
                'locale' => $locale,
                'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
                'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
                'preview_allowlisted' => true,
                'asset_payload_json' => $biochemistRow,
                'sources_json' => $biochemistRow['sources'],
                'evidence_used_json' => $biochemistRow['evidence_used'],
                'derived_from_synthesis_json' => $biochemistRow['derived_from_synthesis'],
                'audit_fields_json' => $biochemistRow['audit_fields'],
                'asset_row_hash' => $biochemistRow['audit_fields']['row_hash'],
            ]);
        }

        $writerZhAsset = $this->getJson('/api/v0.5/career/jobs/writers-and-authors/ai-impact-asset?locale=zh-CN')
            ->assertOk()
            ->json('ai_impact_asset_v1');
        $writerEnAsset = $this->getJson('/api/v0.5/career/jobs/writers-and-authors/ai-impact-asset?locale=en')
            ->assertOk()
            ->json('ai_impact_asset_v1');
        $truckZhAsset = $this->getJson('/api/v0.5/career/jobs/heavy-and-tractor-trailer-truck-drivers/ai-impact-asset?locale=zh-CN')
            ->assertOk()
            ->json('ai_impact_asset_v1');
        $architectZhAsset = $this->getJson('/api/v0.5/career/jobs/architects/ai-impact-asset?locale=zh-CN')
            ->assertOk()
            ->json('ai_impact_asset_v1');
        $architectEnAsset = $this->getJson('/api/v0.5/career/jobs/architects/ai-impact-asset?locale=en')
            ->assertOk()
            ->json('ai_impact_asset_v1');
        $biochemistZhAsset = $this->getJson('/api/v0.5/career/jobs/biochemists-and-biophysicists/ai-impact-asset?locale=zh-CN')
            ->assertOk()
            ->json('ai_impact_asset_v1');
        $biochemistEnAsset = $this->getJson('/api/v0.5/career/jobs/biochemists-and-biophysicists/ai-impact-asset?locale=en')
            ->assertOk()
            ->json('ai_impact_asset_v1');

        $equipmentOccupation = $this->seedOccupation('electrical-and-electronics-installers-and-repairers-transportation-equipment');
        $equipmentEn = $this->assetRow('electrical-and-electronics-installers-and-repairers-transportation-equipment', 'en');
        $equipmentEn['items']['human_accountability_anchors'][0]['body'] = 'When document lockout steps, passenger or crew safety, release tests, and responsible technician sign-off creates disagreement, the worker must own the final handoff.';
        CareerJobAiImpactAsset::query()->create([
            'occupation_id' => $equipmentOccupation->id,
            'career_job_slug' => 'electrical-and-electronics-installers-and-repairers-transportation-equipment',
            'locale' => 'en',
            'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
            'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
            'asset_payload_json' => $equipmentEn,
            'sources_json' => $equipmentEn['sources'],
            'evidence_used_json' => $equipmentEn['evidence_used'],
            'derived_from_synthesis_json' => $equipmentEn['derived_from_synthesis'],
            'audit_fields_json' => $equipmentEn['audit_fields'],
            'asset_row_hash' => $equipmentEn['audit_fields']['row_hash'],
        ]);

        $equipmentEnAsset = $this->getJson('/api/v0.5/career/jobs/electrical-and-electronics-installers-and-repairers-transportation-equipment/ai-impact-asset?locale=en')
            ->assertOk()
            ->json('ai_impact_asset_v1');

        $writerZhText = json_encode($writerZhAsset, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $writerEnText = json_encode($writerEnAsset, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $truckZhText = json_encode($truckZhAsset, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $architectZhText = json_encode($architectZhAsset, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $architectEnText = json_encode($architectEnAsset, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $biochemistZhText = json_encode($biochemistZhAsset, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $biochemistEnText = json_encode($biochemistEnAsset, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $equipmentEnText = json_encode($equipmentEnAsset, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('物种观察', $writerZhText);
        $this->assertStringNotContainsString('栖息地数据', $writerZhText);
        $this->assertStringNotContainsString('天气改航', $writerZhText);
        $this->assertStringContainsString('采访记录', $writerZhText);
        $this->assertStringContainsString('编辑取舍', $writerZhText);

        $this->assertStringNotContainsString('species observations', $writerEnText);
        $this->assertStringNotContainsString('habitat data', $writerEnText);
        $this->assertStringNotContainsString('weather diversion', $writerEnText);
        $this->assertStringContainsString('interview notes', $writerEnText);
        $this->assertStringContainsString('editorial choices', $writerEnText);

        $this->assertStringNotContainsString('天气改航', $truckZhText);
        $this->assertStringNotContainsString('旅客/机组安全', $truckZhText);
        $this->assertStringContainsString('道路安全', $truckZhText);
        $this->assertStringContainsString('工时合规', $truckZhText);

        $this->assertStringNotContainsString('指挥链', $architectZhText);
        $this->assertStringNotContainsString('态势判断', $architectZhText);
        $this->assertStringContainsString('责任链条', $architectZhText);
        $this->assertStringContainsString('现场判断', $architectZhText);

        $this->assertStringNotContainsString('chain of command', $architectEnText);
        $this->assertStringNotContainsString('situational judgment', $architectEnText);
        $this->assertStringContainsString('accountability chain', $architectEnText);
        $this->assertStringContainsString('context judgment', $architectEnText);

        $this->assertStringNotContainsString('临床升级', $biochemistZhText);
        $this->assertStringNotContainsString('用药核对', $biochemistZhText);
        $this->assertStringNotContainsString('转诊说明', $biochemistZhText);
        $this->assertStringNotContainsString('病历/EHR', $biochemistZhText);
        $this->assertStringNotContainsString('医嘱核对', $biochemistZhText);
        $this->assertStringNotContainsString('生命体征', $biochemistZhText);
        $this->assertStringNotContainsString('随访清单', $biochemistZhText);
        $this->assertStringContainsString('业务升级', $biochemistZhText);
        $this->assertStringContainsString('关键核对', $biochemistZhText);
        $this->assertStringContainsString('协作说明', $biochemistZhText);
        $this->assertStringContainsString('项目记录摘要', $biochemistZhText);

        $this->assertStringNotContainsString('clinical escalation', $biochemistEnText);
        $this->assertStringNotContainsString('medication checks', $biochemistEnText);
        $this->assertStringContainsString('workflow escalation', $biochemistEnText);
        $this->assertStringContainsString('critical checks', $biochemistEnText);

        $this->assertStringNotContainsString('passenger or crew safety', $equipmentEnText);
        $this->assertStringNotContainsString('crew safety', $equipmentEnText);
        $this->assertStringContainsString('user and field safety', $equipmentEnText);
    }

    public function test_preview_api_projects_wind_turbine_technicians_without_projection_error(): void
    {
        Config::set('career_ai_impact_assets.staging_preview_enabled', true);
        Config::set('career_ai_impact_assets.preview_slugs', ['wind-turbine-technicians']);

        $occupation = $this->seedOccupation('wind-turbine-technicians');

        foreach (['zh-CN', 'en'] as $locale) {
            $row = $this->assetRow('wind-turbine-technicians', $locale);
            $row['occupation']['title_en'] = 'Wind Turbine Technicians';
            $row['occupation']['title_zh'] = '风力涡轮机技术员';

            if ($locale === 'zh-CN') {
                $row['items']['most_ai_exposed_workflows'][0]['body'] = '指挥链、武器和任务风险应当投射为风机现场维护语境。';
            } else {
                $row['items']['most_ai_exposed_workflows'][0]['body'] = 'command chain, weapons, and mission risk should project into wind-site maintenance context.';
            }

            CareerJobAiImpactAsset::query()->create([
                'occupation_id' => $occupation->id,
                'career_job_slug' => 'wind-turbine-technicians',
                'locale' => $locale,
                'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
                'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
                'preview_allowlisted' => true,
                'asset_payload_json' => $row,
                'sources_json' => $row['sources'],
                'evidence_used_json' => $row['evidence_used'],
                'derived_from_synthesis_json' => $row['derived_from_synthesis'],
                'audit_fields_json' => $row['audit_fields'],
                'asset_row_hash' => $row['audit_fields']['row_hash'],
            ]);
        }

        $zhAsset = $this->getJson('/api/v0.5/career/jobs/wind-turbine-technicians/ai-impact-asset?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('ai_impact_asset_v1.slug', 'wind-turbine-technicians')
            ->assertJsonPath('ai_impact_asset_v1.locale', 'zh-CN')
            ->json('ai_impact_asset_v1');

        $enAsset = $this->getJson('/api/v0.5/career/jobs/wind-turbine-technicians/ai-impact-asset?locale=en')
            ->assertOk()
            ->assertJsonPath('ai_impact_asset_v1.slug', 'wind-turbine-technicians')
            ->assertJsonPath('ai_impact_asset_v1.locale', 'en')
            ->json('ai_impact_asset_v1');

        $zhText = json_encode($zhAsset, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $enText = json_encode($enAsset, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('指挥链', $zhText);
        $this->assertStringNotContainsString('武器', $zhText);
        $this->assertStringNotContainsString('任务风险', $zhText);
        $this->assertStringContainsString('现场安全链条', $zhText);
        $this->assertStringContainsString('设备', $zhText);
        $this->assertStringContainsString('维护风险', $zhText);

        $this->assertStringNotContainsString('command chain', $enText);
        $this->assertStringNotContainsString('weapons', $enText);
        $this->assertStringNotContainsString('mission risk', $enText);
        $this->assertStringContainsString('site safety chain', $enText);
        $this->assertStringContainsString('equipment', $enText);
        $this->assertStringContainsString('maintenance risk', $enText);
    }

    public function test_ai_impact_preview_asset_can_provide_restricted_detail_shell_when_bundle_is_missing(): void
    {
        Config::set('career_ai_impact_assets.staging_preview_enabled', true);
        Config::set('career_ai_impact_assets.preview_slugs', ['emergency-medicine-physicians']);
        $occupation = $this->seedOccupation('emergency-medicine-physicians');
        $row = $this->assetRow('emergency-medicine-physicians', 'en');
        $row['occupation']['title_en'] = 'Emergency Medicine Physicians';
        $row['occupation']['title_zh'] = '急诊医学医师';

        CareerJobAiImpactAsset::query()->create([
            'occupation_id' => $occupation->id,
            'career_job_slug' => 'emergency-medicine-physicians',
            'locale' => 'en',
            'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
            'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
            'asset_payload_json' => $row,
            'sources_json' => $row['sources'],
            'evidence_used_json' => $row['evidence_used'],
            'derived_from_synthesis_json' => $row['derived_from_synthesis'],
            'audit_fields_json' => $row['audit_fields'],
            'asset_row_hash' => $row['audit_fields']['row_hash'],
        ]);

        $this->assertNotNull(
            app(\App\Services\Career\AiImpactAssets\CareerAiImpactPreviewDetailShellBuilder::class)
                ->build('emergency-medicine-physicians', 'en')
        );

        $response = $this->getJson('/api/v0.5/career/jobs/emergency-medicine-physicians?locale=en')
            ->assertOk()
            ->assertJsonPath('identity.canonical_slug', 'emergency-medicine-physicians')
            ->assertJsonPath('display_surface_v1.surface_version', 'display.surface.v1')
            ->assertJsonPath('display_surface_v1.status', 'ready_for_pilot')
            ->assertJsonPath('display_surface_v1.subject.canonical_slug', 'emergency-medicine-physicians')
            ->assertJsonPath('display_surface_v1.page.en.path', '/en/career/jobs/emergency-medicine-physicians')
            ->assertJsonPath('seo_contract.indexable', false)
            ->assertJsonPath('seo_contract.jsonld_allowed', false);

        $encoded = json_encode($response->json(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('evidence_id', $encoded);
        $this->assertStringNotContainsString('row_hash', $encoded);
        $this->assertStringNotContainsString('source_id', $encoded);
        $this->assertStringNotContainsString('search_projection', $encoded);

        $this->getJson('/api/v0.5/career/jobs/emergency-medicine-physicians/ai-impact-asset?locale=en')
            ->assertOk()
            ->assertJsonPath('ai_impact_asset_v1.slug', 'emergency-medicine-physicians');
    }

    public function test_ai_impact_preview_detail_shell_fails_closed_when_disabled_or_not_allowlisted(): void
    {
        Config::set('career_ai_impact_assets.staging_preview_enabled', false);
        Config::set('career_ai_impact_assets.preview_slugs', ['emergency-medicine-physicians']);
        $occupation = $this->seedOccupation('emergency-medicine-physicians');
        $row = $this->assetRow('emergency-medicine-physicians', 'en');

        CareerJobAiImpactAsset::query()->create([
            'occupation_id' => $occupation->id,
            'career_job_slug' => 'emergency-medicine-physicians',
            'locale' => 'en',
            'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
            'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
            'asset_payload_json' => $row,
            'sources_json' => $row['sources'],
            'evidence_used_json' => $row['evidence_used'],
            'derived_from_synthesis_json' => $row['derived_from_synthesis'],
            'audit_fields_json' => $row['audit_fields'],
            'asset_row_hash' => $row['audit_fields']['row_hash'],
        ]);

        $this->getJson('/api/v0.5/career/jobs/emergency-medicine-physicians?locale=en')
            ->assertNotFound();

        Config::set('career_ai_impact_assets.staging_preview_enabled', true);
        CareerJobAiImpactAsset::query()
            ->where('career_job_slug', 'emergency-medicine-physicians')
            ->where('locale', 'en')
            ->update(['preview_allowlisted' => false]);

        $this->getJson('/api/v0.5/career/jobs/emergency-medicine-physicians?locale=en')
            ->assertNotFound();
    }

    public function test_ai_impact_preview_detail_shell_covers_preview_slugs_without_standard_detail_bundles(): void
    {
        $slugs = [
            'emergency-medicine-physicians',
            'pharmacists',
            'diagnostic-medical-sonographers',
            'medical-and-clinical-laboratory-technologists',
            'genetic-counselors',
            'physicians-and-surgeons',
            'aviation-inspectors',
            'aircraft-and-avionics-equipment-mechanics-and-technicians',
            'judicial-law-clerks',
            'military-careers',
            'first-line-supervisors-of-air-crew-members',
            'first-line-supervisors-of-weapons-specialists-crew-members',
            'school-and-career-counselors',
            'special-education-teachers-elementary-school',
            'multimedia-artists-and-animators',
            'police-and-sheriff-s-patrol-officers',
            'heavy-and-tractor-trailer-truck-drivers',
            'construction-and-building-inspectors',
            'information-security-analysts',
            'computer-systems-engineers-architects',
        ];

        Config::set('career_ai_impact_assets.staging_preview_enabled', true);
        Config::set('career_ai_impact_assets.preview_slugs', $slugs);

        foreach ($slugs as $slug) {
            $occupation = $this->seedOccupation($slug);
            foreach (['zh-CN', 'en'] as $locale) {
                $row = $this->assetRow($slug, $locale);
                CareerJobAiImpactAsset::query()->create([
                    'occupation_id' => $occupation->id,
                    'career_job_slug' => $slug,
                    'locale' => $locale,
                    'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
                    'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
                    'preview_allowlisted' => true,
                    'asset_payload_json' => $row,
                    'sources_json' => $row['sources'],
                    'evidence_used_json' => $row['evidence_used'],
                    'derived_from_synthesis_json' => $row['derived_from_synthesis'],
                    'audit_fields_json' => $row['audit_fields'],
                    'asset_row_hash' => $row['audit_fields']['row_hash'],
                ]);
            }
        }

        foreach ($slugs as $slug) {
            $this->getJson('/api/v0.5/career/jobs/'.$slug.'?locale=zh-CN')
                ->assertOk()
                ->assertJsonPath('identity.canonical_slug', $slug)
                ->assertJsonPath('display_surface_v1.subject.canonical_slug', $slug)
                ->assertJsonPath('display_surface_v1.page.zh.path', '/zh/career/jobs/'.$slug);

            $this->getJson('/api/v0.5/career/jobs/'.$slug.'?locale=en')
                ->assertOk()
                ->assertJsonPath('identity.canonical_slug', $slug)
                ->assertJsonPath('display_surface_v1.subject.canonical_slug', $slug)
                ->assertJsonPath('display_surface_v1.page.en.path', '/en/career/jobs/'.$slug);
        }
    }

    public function test_preview_api_fails_closed_when_disabled_or_not_allowlisted(): void
    {
        Config::set('career_ai_impact_assets.staging_preview_enabled', false);
        Config::set('career_ai_impact_assets.preview_slugs', ['accountants-and-auditors']);
        $occupation = $this->seedOccupation('accountants-and-auditors');
        $row = $this->assetRow('accountants-and-auditors', 'zh-CN');

        CareerJobAiImpactAsset::query()->create([
            'occupation_id' => $occupation->id,
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'zh-CN',
            'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
            'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
            'asset_payload_json' => $row,
            'sources_json' => $row['sources'],
            'evidence_used_json' => $row['evidence_used'],
            'derived_from_synthesis_json' => $row['derived_from_synthesis'],
            'audit_fields_json' => $row['audit_fields'],
            'asset_row_hash' => $row['audit_fields']['row_hash'],
        ]);

        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/ai-impact-asset?locale=zh-CN')
            ->assertNotFound();

        Config::set('career_ai_impact_assets.staging_preview_enabled', true);
        Config::set('career_ai_impact_assets.preview_slugs', ['actuaries']);
        CareerJobAiImpactAsset::query()
            ->where('career_job_slug', 'accountants-and-auditors')
            ->where('locale', 'zh-CN')
            ->update(['preview_allowlisted' => false]);
        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/ai-impact-asset?locale=zh-CN')
            ->assertNotFound();
    }

    public function test_preview_api_fails_closed_for_unsupported_locale_without_fallback(): void
    {
        Config::set('career_ai_impact_assets.staging_preview_enabled', true);
        Config::set('career_ai_impact_assets.preview_slugs', ['accountants-and-auditors']);
        $occupation = $this->seedOccupation('accountants-and-auditors');
        $zhRow = $this->assetRow('accountants-and-auditors', 'zh-CN');

        CareerJobAiImpactAsset::query()->create([
            'occupation_id' => $occupation->id,
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'zh-CN',
            'asset_version' => CareerJobAiImpactAsset::ASSET_VERSION_V5,
            'status' => CareerJobAiImpactAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
            'asset_payload_json' => $zhRow,
            'sources_json' => $zhRow['sources'],
            'evidence_used_json' => $zhRow['evidence_used'],
            'derived_from_synthesis_json' => $zhRow['derived_from_synthesis'],
            'audit_fields_json' => $zhRow['audit_fields'],
            'asset_row_hash' => $zhRow['audit_fields']['row_hash'],
        ]);

        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/ai-impact-asset?locale=fr')
            ->assertNotFound()
            ->assertJsonPath('ok', false);

        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/ai-impact-asset?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('ai_impact_asset_v1.locale', 'zh-CN');
    }

    private function seedCareerJobBundleAuthority(string $slug): void
    {
        $this->seedCareerJobBundleAuthorities([$slug]);
    }

    /**
     * @param  list<string>  $slugs
     */
    private function seedCareerJobBundleAuthorities(array $slugs): void
    {
        $this->seedRuntimeProjectionAuthority($slugs);

        foreach ($slugs as $slug) {
            if (! Occupation::query()->where('canonical_slug', $slug)->exists()) {
                $this->seedOccupation($slug);
            }

            app(PublicCareerAuthorityResponseCache::class)->forgetJobDetailPayload($slug, 'zh-CN');
            app(PublicCareerAuthorityResponseCache::class)->forgetJobDetailPayload($slug, 'en');
            app(PublicCareerAuthorityResponseCache::class)->warmJobDetailPayload($slug, 'zh-CN', true);
            app(PublicCareerAuthorityResponseCache::class)->warmJobDetailPayload($slug, 'en', true);
        }

        $this->app->forgetInstance(CareerAiImpactAssetImportService::class);
        $this->app->forgetInstance(CareerAiImpactAssetPreviewService::class);
        $this->app->forgetInstance('App\\Console\\Commands\\CareerImportAiImpactAssetsPreview');
    }

    /**
     * @param  list<string>  $slugs
     */
    private function seedRuntimeProjectionAuthority(array $slugs): void
    {
        $detailRouteEnabled = [];
        $robotsIndexable = [];
        $releaseGatePass = [];
        $items = [];

        foreach ($slugs as $slug) {
            $normalizedSlug = strtolower(trim($slug));
            if ($normalizedSlug === '') {
                continue;
            }

            $detailRouteEnabled[$normalizedSlug] = true;
            $robotsIndexable[$normalizedSlug] = true;
            $releaseGatePass[$normalizedSlug] = true;
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
                detailRouteEnabled: $detailRouteEnabled,
                robotsIndexable: $robotsIndexable,
                releaseGatePass: $releaseGatePass,
                items: $items,
            ),
        );
    }

    private function seedOccupation(string $slug): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'ai-impact-preview-'.$slug,
            'title_en' => 'AI Impact Preview',
            'title_zh' => 'AI 影响预览',
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
        $path = storage_path('framework/testing/ai-impact-preview-'.bin2hex(random_bytes(4)).'.jsonl');
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
     */
    private function writeJsonArtifact(array $payload): string
    {
        $path = storage_path('framework/testing/ai-impact-artifact-'.bin2hex(random_bytes(4)).'.json');
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL);

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function approvalManifest(string $assetSha, int $rows, int $slugs): array
    {
        return [
            'schema_version' => 'career_ai_impact_v5_approval_manifest_v1',
            'final_conclusion' => 'AI_IMPACT_V5_EDITORIAL_REVIEW_PASS',
            'production_import_allowed' => false,
            'next_allowed_transition' => CareerJobAiImpactAsset::STATUS_APPROVED,
            'approved_rows' => $rows,
            'rejected_rows' => 0,
            'unique_slugs' => $slugs,
            'final_repaired_asset_sha256' => $assetSha,
            'required_for_approved_transition' => [
                'asset_sha256' => $assetSha,
                'row_count' => $rows,
                'slug_count' => $slugs,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function editorialReviewReport(string $assetSha, int $rows, int $slugs): array
    {
        return [
            'schema_version' => 'career_ai_impact_v5_editorial_review_package_v1',
            'final_conclusion' => 'AI_IMPACT_V5_EDITORIAL_REVIEW_PASS',
            'inputs' => [
                'final_repaired_asset_sha256' => $assetSha,
            ],
            'metrics' => [
                'asset_rows' => $rows,
                'unique_slugs' => $slugs,
                'findings' => 0,
                'rejected_rows' => 0,
            ],
            'guarantees' => [
                'no_production_import' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assetRow(string $slug, string $locale): array
    {
        $hash = hash('sha256', 'ai-impact|'.$slug.'|'.$locale);
        $isZh = $locale === 'zh-CN';

        return [
            'ledger_type' => 'career-risk-future_asset',
            'asset_version' => 'career_risk_future_ai_impact_v5_batch_001',
            'block_type' => 'career-risk-future-ai-impact',
            'slug' => $slug,
            'locale' => $locale,
            'seed_ordinal' => 1,
            'batch_role' => 'new_50',
            'occupation' => [
                'title_en' => 'Accountants and Auditors',
                'title_zh' => '会计师和审计师',
                'soc_code' => '13-2011',
                'onet_code' => '13-2011.00',
            ],
            'micro_family' => 'audit and assurance workflow',
            'ai_exposure_score' => [
                'score_1_to_10' => 8,
                'exposure_type' => 'mixed',
                'confidence' => 'high',
            ],
            'summary' => $isZh
                ? 'FermatMind 将会计师和审计师评为 8/10 AI 任务暴露度：凭证核对、审计底稿初筛和差异汇总容易被加速，但签字责任、重大错报判断和客户沟通仍由人承担。'
                : 'FermatMind rates Accountants and Auditors at 8/10 AI task exposure: reconciliation, workpaper triage, and variance summaries can be accelerated, while sign-off accountability and materiality judgment remain human-owned.',
            'items' => [
                'most_ai_exposed_workflows' => [[
                    'label' => $isZh ? '审计底稿初筛' : 'Workpaper triage',
                    'body' => $isZh
                        ? 'AI 可把凭证、银行流水和底稿注释整理成可复核的异常清单，帮助审计人员先定位收入确认、费用截止和关联交易线索。'
                        : 'AI can organize invoices, bank activity, and workpaper notes into reviewable exception lists for revenue recognition, cutoff, and related-party follow-up.',
                ]],
                'human_accountability_anchors' => [[
                    'label' => $isZh ? '重大性和签字责任' : 'Materiality and sign-off',
                    'body' => $isZh
                        ? '是否构成重大错报、是否扩大抽样、如何向客户和合伙人解释风险，仍需要会计与审计人员承担专业判断。'
                        : 'Material misstatement calls, sample expansion, and explanations to clients or partners remain professional-accountability decisions.',
                ]],
                'how_to_prepare' => [[
                    'label' => $isZh ? '准备可展示的底稿复盘' : 'Build a workpaper review sample',
                    'body' => $isZh
                        ? '准备一份脱敏的审计底稿复盘，展示输入资料、AI 初筛、人工删改、最终判断和复核理由。'
                        : 'Create a sanitized workpaper review sample showing inputs, AI triage, human edits, final judgment, and review rationale.',
                ]],
                'reader_boundary' => [
                    'label' => $isZh ? 'AI 评分边界' : 'AI score boundary',
                    'body' => $isZh
                        ? '该分数是任务暴露信号，不是个人职业结果预测。'
                        : 'This score is a task-exposure signal, not an individual career outcome forecast.',
                ],
            ],
            'score_rationale' => [
                'why_not_higher' => $isZh
                    ? '不是 9/10 或 10/10，因为审计意见、重大性判断、客户沟通和责任归属不能由模型端到端完成。'
                    : 'The score is not 9/10 or 10/10 because audit opinions, materiality calls, client communication, and accountability cannot be delegated end-to-end.',
                'why_not_lower' => $isZh
                    ? '不是低分，因为大量凭证核对、底稿摘要、差异说明和报告初稿都属于可被 AI 加速的信息处理任务。'
                    : 'The score is not lower because reconciliation, workpaper summaries, variance explanations, and report drafting are recurring information-processing tasks.',
                'confidence_reason' => $isZh
                    ? 'O*NET 任务、审计工作流和外部 AI 暴露研究共同支持高暴露但强责任边界的判断。'
                    : 'O*NET task evidence, audit workflows, and external AI-exposure research support high exposure with a strong accountability boundary.',
                'drivers' => ['reconciliation workflow', 'workpaper summarization', 'variance explanation'],
                'anchors' => ['materiality judgment', 'audit sign-off', 'client communication'],
                'source_ids' => ['onet_13_2011_00_summary'],
                'evidence_ids' => ['ai_impact_evidence_001'],
            ],
            'sources' => [[
                'source_id' => 'onet_13_2011_00_summary',
                'source_name' => 'O*NET OnLine summary for Accountants and Auditors',
                'source_type' => 'task_taxonomy',
                'source_url' => 'https://www.onetonline.org/link/summary/13-2011.00',
                'used_for' => 'Occupation-specific task and workflow evidence.',
                'captured_fact' => 'Accountants and auditors prepare, examine, and analyze accounting records and financial statements.',
                'boundary' => 'Task taxonomy only; not a personal outcome prediction.',
            ]],
            'evidence_used' => [
                'evidence_row_hash' => str_repeat('a', 64),
                'source_ids' => ['onet_13_2011_00_summary'],
                'workflow_evidence_ids' => ['ai_impact_evidence_001'],
            ],
            'derived_from_synthesis' => [
                'synthesis_row_hash' => str_repeat('b', 64),
            ],
            'audit_fields' => [
                'generated_at' => '2026-06-19T00:00:00Z',
                'row_hash' => $hash,
            ],
        ];
    }
}
