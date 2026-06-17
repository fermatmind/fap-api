<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Models\CareerJobSalaryAsset;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Career\SalaryAssets\CareerSalaryAssetImportService;
use App\Services\Career\SalaryAssets\CareerSalaryAssetPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture;
use Tests\TestCase;

final class CareerSalaryAssetPreviewImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedRuntimeProjectionAuthority([]);
    }

    public function test_salary_asset_sidecar_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('career_job_salary_assets'));
        $this->assertTrue(Schema::hasColumn('career_job_salary_assets', 'asset_payload_json'));
        $this->assertTrue(Schema::hasColumn('career_job_salary_assets', 'asset_row_hash'));
        $this->assertTrue(Schema::hasColumn('career_job_salary_assets', 'preview_allowlisted'));
    }

    public function test_importer_dry_run_validates_preview_rows_without_writing(): void
    {
        $this->seedOccupation('accountants-and-auditors');
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/salary-preview-dry-run.json');

        $exitCode = Artisan::call('career:salary-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('career_job_salary_assets', 0);

        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertSame(2, $decoded['validated_preview_rows']);
        $this->assertFalse((bool) ($decoded['production_import_allowed'] ?? true));
    }

    public function test_importer_dry_run_reports_state_machine_sha_idempotency_and_rollback_policy(): void
    {
        $this->seedOccupation('accountants-and-auditors');
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $sha = hash_file('sha256', $file);
        $report = storage_path('framework/testing/salary-preview-state-machine-dry-run.json');

        $exitCode = Artisan::call('career:salary-assets-import-preview', [
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
        $this->assertSame(CareerJobSalaryAsset::STATUS_APPROVED, $decoded['state_machine']['production_import_requires_from_status']);
        $this->assertTrue((bool) $decoded['rollback_policy']['production_import_requires_approved_status']);
    }

    public function test_importer_dry_run_rejects_unexpected_source_sha(): void
    {
        $this->seedOccupation('accountants-and-auditors');
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/salary-preview-sha-mismatch.json');

        $exitCode = Artisan::call('career:salary-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--expected-sha256' => str_repeat('0', 64),
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('career_job_salary_assets', 0);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse((bool) $decoded['source_file_sha256_match']);
        $this->assertStringContainsString('Source JSONL SHA-256 does not match expected artifact SHA', implode(' ', $decoded['errors']));
    }

    public function test_importer_dry_run_validates_full_1046_contract_without_writing(): void
    {
        $slugs = array_map(static fn (int $index): string => 'contract-career-'.$index, range(1, 1046));
        Config::set('career_salary_assets.preview_slugs', $slugs);
        $this->seedCareerJobBundleAuthorities($slugs);

        $rows = [];
        foreach ($slugs as $slug) {
            $rows[] = $this->assetRow($slug, 'zh-CN');
            $rows[] = $this->assetRow($slug, 'en');
        }
        $file = $this->writeJsonl($rows);
        $report = storage_path('framework/testing/salary-preview-full-1046-dry-run.json');

        $exitCode = Artisan::call('career:salary-assets-import-preview', [
            '--file' => $file,
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('career_job_salary_assets', 0);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertSame(1046, $decoded['target_slug_count']);
        $this->assertSame(2092, $decoded['validated_preview_rows']);
        $this->assertSame(2092, $decoded['expected_preview_rows']);
        $this->assertSame(1046, $decoded['career_job_bundle_authority']['ready_slug_count']);
        $this->assertFalse((bool) ($decoded['production_import_allowed'] ?? true));
    }

    public function test_importer_dry_run_can_validate_all_slugs_from_file_without_preview_allowlist(): void
    {
        Config::set('career_salary_assets.preview_slugs', ['not-in-source-file']);
        $slugs = ['all-file-career-a', 'all-file-career-b'];
        $this->seedCareerJobBundleAuthorities($slugs);

        $file = $this->writeJsonl([
            $this->assetRow('all-file-career-a', 'zh-CN'),
            $this->assetRow('all-file-career-a', 'en'),
            $this->assetRow('all-file-career-b', 'zh-CN'),
            $this->assetRow('all-file-career-b', 'en'),
        ]);
        $report = storage_path('framework/testing/salary-preview-all-file-dry-run.json');

        $exitCode = Artisan::call('career:salary-assets-import-preview', [
            '--file' => $file,
            '--all-slugs-from-file' => true,
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('career_job_salary_assets', 0);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertTrue((bool) $decoded['all_slugs_from_file']);
        $this->assertSame(2, $decoded['target_slug_count']);
        $this->assertSame(4, $decoded['validated_preview_rows']);
        $this->assertSame($slugs, $decoded['target_slugs']);
    }

    public function test_importer_force_all_slugs_from_file_requires_explicit_full_staging_confirmation(): void
    {
        Config::set('career_salary_assets.preview_slugs', ['not-in-source-file']);
        $slugs = ['all-file-career-a', 'all-file-career-b'];
        $this->seedCareerJobBundleAuthorities($slugs);

        $file = $this->writeJsonl([
            $this->assetRow('all-file-career-a', 'zh-CN'),
            $this->assetRow('all-file-career-a', 'en'),
            $this->assetRow('all-file-career-b', 'zh-CN'),
            $this->assetRow('all-file-career-b', 'en'),
        ]);
        $report = storage_path('framework/testing/salary-preview-all-file-force-without-confirmation.json');

        $exitCode = Artisan::call('career:salary-assets-import-preview', [
            '--file' => $file,
            '--all-slugs-from-file' => true,
            '--force' => true,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('career_job_salary_assets', 0);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decoded['decision']);
        $this->assertStringContainsString('--confirm-full-staging-preview', implode(' ', $decoded['errors']));
    }

    public function test_importer_force_can_write_all_slugs_from_file_when_confirmed(): void
    {
        Config::set('career_salary_assets.preview_slugs', ['not-in-source-file']);
        $slugs = ['all-file-career-a', 'all-file-career-b'];
        $this->seedCareerJobBundleAuthorities($slugs);

        $file = $this->writeJsonl([
            $this->assetRow('all-file-career-a', 'zh-CN'),
            $this->assetRow('all-file-career-a', 'en'),
            $this->assetRow('all-file-career-b', 'zh-CN'),
            $this->assetRow('all-file-career-b', 'en'),
        ]);
        $report = storage_path('framework/testing/salary-preview-all-file-force-confirmed.json');

        $exitCode = Artisan::call('career:salary-assets-import-preview', [
            '--file' => $file,
            '--all-slugs-from-file' => true,
            '--confirm-full-staging-preview' => true,
            '--force' => true,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('career_job_salary_assets', 4);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertTrue((bool) $decoded['all_slugs_from_file']);
        $this->assertTrue((bool) $decoded['full_staging_preview_confirmed']);
        $this->assertSame(4, $decoded['written_count']);
        $this->assertSame($slugs, $decoded['target_slugs']);
        $this->assertFalse((bool) ($decoded['production_import_allowed'] ?? true));
    }

    public function test_importer_editorial_gate_uses_reader_safe_projection_for_source_labels(): void
    {
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $enRow = $this->assetRow('accountants-and-auditors', 'en');
        $enRow['sources'][0]['name'] = '/';
        $enRow['sources'][0]['url'] = 'https://m.jobui.com/salary/quanguo-huijishi/';
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $enRow,
        ]);
        $report = storage_path('framework/testing/salary-preview-reader-safe-source-dry-run.json');

        $exitCode = Artisan::call('career:salary-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $decoded['decision']);
        $this->assertSame(2, $decoded['editorial_quality_gate']['ready_row_count']);
    }

    public function test_importer_force_writes_staging_preview_rows_only(): void
    {
        $this->seedOccupation('accountants-and-auditors');
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);

        $exitCode = Artisan::call('career:salary-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--force' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseCount('career_job_salary_assets', 2);
        $this->assertDatabaseHas('career_job_salary_assets', [
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'zh-CN',
            'asset_version' => CareerJobSalaryAsset::ASSET_VERSION_V3_6,
            'status' => CareerJobSalaryAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
        ]);
    }

    public function test_importer_force_blocks_staging_preview_over_production_imported_rows(): void
    {
        $occupation = $this->seedOccupation('accountants-and-auditors');
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $row = $this->assetRow('accountants-and-auditors', 'zh-CN');
        CareerJobSalaryAsset::query()->create([
            'occupation_id' => $occupation->id,
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'zh-CN',
            'asset_version' => CareerJobSalaryAsset::ASSET_VERSION_V3_6,
            'status' => CareerJobSalaryAsset::STATUS_PRODUCTION_IMPORTED,
            'preview_allowlisted' => false,
            'asset_payload_json' => $row,
            'sources_json' => $row['sources'],
            'evidence_used_json' => $row['evidence_used'],
            'derived_from_estimate_json' => $row['derived_from_estimate'],
            'audit_fields_json' => $row['audit_fields'],
            'asset_row_hash' => $row['audit_fields']['row_hash'],
        ]);
        $file = $this->writeJsonl([
            $row,
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/salary-preview-production-row-blocked.json');

        $exitCode = Artisan::call('career:salary-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--force' => true,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseHas('career_job_salary_assets', [
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'zh-CN',
            'status' => CareerJobSalaryAsset::STATUS_PRODUCTION_IMPORTED,
        ]);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('cannot transition salary asset from production_imported to staging_preview', implode(' ', $decoded['errors']));
    }

    public function test_importer_approval_gate_promotes_1046_staging_rows_to_approved_only_after_manifest_confirmation(): void
    {
        $slugs = array_map(static fn (int $index): string => 'approved-career-'.$index, range(1, 1046));
        Config::set('career_salary_assets.preview_slugs', $slugs);
        $this->seedCareerJobBundleAuthorities($slugs);

        $rows = [];
        foreach ($slugs as $slug) {
            $rows[] = $this->assetRow($slug, 'zh-CN');
            $rows[] = $this->assetRow($slug, 'en');
        }
        $file = $this->writeJsonl($rows);
        $sourceSha = hash_file('sha256', $file);
        $forceReport = storage_path('framework/testing/salary-preview-approved-gate-force.json');

        $forceCode = Artisan::call('career:salary-assets-import-preview', [
            '--file' => $file,
            '--all-slugs-from-file' => true,
            '--confirm-full-staging-preview' => true,
            '--force' => true,
            '--output' => $forceReport,
        ]);

        $this->assertSame(0, $forceCode);
        $this->assertSame(2092, CareerJobSalaryAsset::query()->where('status', CareerJobSalaryAsset::STATUS_STAGING_PREVIEW)->count());

        $approvalManifest = $this->writeApprovalManifest($slugs, (string) $sourceSha);
        $approvalManifestSha = hash_file('sha256', $approvalManifest);
        $dryRunReport = storage_path('framework/testing/salary-preview-approved-gate-dry-run.json');

        $dryRunCode = Artisan::call('career:salary-assets-import-preview', [
            '--approve-staging-preview' => true,
            '--approval-manifest' => $approvalManifest,
            '--expected-approval-manifest-sha256' => $approvalManifestSha,
            '--output' => $dryRunReport,
        ]);

        $this->assertSame(0, $dryRunCode);
        $this->assertSame(2092, CareerJobSalaryAsset::query()->where('status', CareerJobSalaryAsset::STATUS_STAGING_PREVIEW)->count());
        $dryRun = json_decode((string) file_get_contents($dryRunReport), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $dryRun['decision']);
        $this->assertSame('approve_staging_preview_dry_run', $dryRun['mode']);
        $this->assertSame(2092, $dryRun['database_gate']['matching_row_count']);
        $this->assertFalse((bool) $dryRun['production_import_allowed']);
        $this->assertSame(0, $dryRun['production_rows_touched']);

        $applyReport = storage_path('framework/testing/salary-preview-approved-gate-apply.json');
        $applyCode = Artisan::call('career:salary-assets-import-preview', [
            '--approve-staging-preview' => true,
            '--approval-manifest' => $approvalManifest,
            '--expected-approval-manifest-sha256' => $approvalManifestSha,
            '--confirm-approval-transition' => true,
            '--output' => $applyReport,
        ]);

        $this->assertSame(0, $applyCode);
        $this->assertSame(2092, CareerJobSalaryAsset::query()->where('status', CareerJobSalaryAsset::STATUS_APPROVED)->count());
        $this->assertSame(0, CareerJobSalaryAsset::query()->where('status', CareerJobSalaryAsset::STATUS_PRODUCTION_IMPORTED)->count());
        $apply = json_decode((string) file_get_contents($applyReport), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('pass', $apply['decision']);
        $this->assertSame('approve_staging_preview', $apply['mode']);
        $this->assertTrue((bool) $apply['did_write']);
        $this->assertSame(2092, $apply['updated_count']);
        $this->assertSame(0, $apply['rollback_report']['production_rows_touched']);
        $this->assertFalse((bool) $apply['production_import_allowed']);

        $approved = CareerJobSalaryAsset::query()
            ->where('career_job_slug', 'approved-career-1')
            ->where('locale', 'en')
            ->firstOrFail();
        $this->assertSame(CareerJobSalaryAsset::STATUS_APPROVED, $approved->status);
        $this->assertSame($approvalManifestSha, $approved->audit_fields_json['approval_gate']['approval_manifest_sha256'] ?? null);
    }

    public function test_importer_approval_gate_rejects_missing_or_unconfirmed_manifest_inputs(): void
    {
        $report = storage_path('framework/testing/salary-preview-approved-gate-missing-manifest.json');

        $exitCode = Artisan::call('career:salary-assets-import-preview', [
            '--approve-staging-preview' => true,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decoded['decision']);
        $this->assertStringContainsString('--approval-manifest is required', implode(' ', $decoded['errors']));
        $this->assertDatabaseCount('career_job_salary_assets', 0);
    }

    public function test_preview_api_accepts_allowlisted_slug_samples_and_fail_closed_others(): void
    {
        Config::set('career_salary_assets.staging_preview_enabled', true);
        Config::set('career_salary_assets.preview_slugs', [
            'accountants-and-auditors',
            'actuaries',
            'computer-programmers',
        ]);

        $this->seedCareerJobBundleAuthorities(['accountants-and-auditors', 'actuaries', 'computer-programmers', 'actors']);

        $this->seedPreviewAsset('accountants-and-auditors', 'zh-CN');
        $this->seedPreviewAsset('actuaries', 'en');
        $this->seedPreviewAsset('computer-programmers', 'zh-CN');

        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/salary-asset?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('preview', true)
            ->assertJsonPath('salary_asset_v1.slug', 'accountants-and-auditors');

        $this->getJson('/api/v0.5/career/jobs/actuaries/salary-asset?locale=en')
            ->assertOk()
            ->assertJsonPath('salary_asset_v1.slug', 'actuaries')
            ->assertJsonPath('salary_asset_v1.locale', 'en')
            ->assertJsonPath('salary_asset_v1.sources.0.used_for', 'China recruitment-market reference');

        $payload = $this->getJson('/api/v0.5/career/jobs/actuaries/salary-asset?locale=en')->json('salary_asset_v1');
        $this->assertArrayNotHasKey('research_notes', $payload);
        $this->assertArrayNotHasKey('audit_fields', $payload);
        $this->assertArrayNotHasKey('evidence_used', $payload);
        $this->assertArrayNotHasKey('derived_from_estimate', $payload);
        $this->assertArrayNotHasKey('forbidden_claims', $payload);
        $this->assertSame('JobUI', $payload['sources'][0]['name']);

        $this->getJson('/api/v0.5/career/jobs/actors/salary-asset?locale=en')
            ->assertNotFound();
    }

    public function test_preview_importer_and_runtime_service_use_same_allowlist_source(): void
    {
        Config::set('career_salary_assets.staging_preview_enabled', true);
        Config::set('career_salary_assets.preview_slugs', ['accountants-and-auditors', 'actuaries']);

        $this->seedCareerJobBundleAuthorities(['accountants-and-auditors', 'actuaries', 'actors']);

        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
            $this->assetRow('actuaries', 'zh-CN'),
            $this->assetRow('actuaries', 'en'),
            $this->assetRow('actors', 'zh-CN'),
            $this->assetRow('actors', 'en'),
        ]);

        $rejectReport = storage_path('framework/testing/salary-preview-requested-slugs-not-allowlisted.json');
        $rejectCode = Artisan::call('career:salary-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors,actuaries,actors',
            '--force' => true,
            '--output' => $rejectReport,
        ]);
        $this->assertSame(1, $rejectCode);
        $decodedReject = json_decode((string) file_get_contents($rejectReport), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decodedReject['decision']);
        $this->assertStringContainsString('not in the staging preview allowlist', implode(' ', $decodedReject['errors']));

        $forceReport = storage_path('framework/testing/salary-preview-force-shared-allowlist.json');
        $forceCode = Artisan::call('career:salary-assets-import-preview', [
            '--file' => $file,
            '--force' => true,
            '--output' => $forceReport,
        ]);

        $this->assertSame(0, $forceCode);
        $decodedForce = json_decode((string) file_get_contents($forceReport), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(4, $decodedForce['validated_preview_rows']);
        $this->assertSame(4, $decodedForce['written_count']);

        $this->getJson('/api/v0.5/career/jobs/actuaries/salary-asset?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('salary_asset_v1.slug', 'actuaries')
            ->assertJsonPath('salary_asset_v1.locale', 'zh-CN');
        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/salary-asset?locale=en')
            ->assertOk()
            ->assertJsonPath('salary_asset_v1.slug', 'accountants-and-auditors')
            ->assertJsonPath('salary_asset_v1.locale', 'en');

        $this->getJson('/api/v0.5/career/jobs/actors/salary-asset?locale=zh-CN')
            ->assertNotFound();
    }

    public function test_importer_force_blocks_when_career_job_bundle_authority_is_missing(): void
    {
        $this->seedOccupation('accountants-and-auditors');
        $file = $this->writeJsonl([
            $this->assetRow('accountants-and-auditors', 'zh-CN'),
            $this->assetRow('accountants-and-auditors', 'en'),
        ]);
        $report = storage_path('framework/testing/salary-preview-authority-fail.json');

        $exitCode = Artisan::call('career:salary-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--force' => true,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('career_job_salary_assets', 0);

        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decoded['decision']);
        $this->assertStringContainsString('missing_career_job_bundle_authority', implode(' ', $decoded['errors']));
        $this->assertSame(0, $decoded['career_job_bundle_authority']['ready_slug_count']);
    }

    public function test_preview_api_projects_allowlisted_staging_asset_when_enabled(): void
    {
        Config::set('career_salary_assets.staging_preview_enabled', true);
        $occupation = $this->seedOccupation('accountants-and-auditors');
        $row = $this->assetRow('accountants-and-auditors', 'zh-CN');
        $row['sources'][0]['name'] = '//';
        $row['sources'][0]['url'] = 'https://www.jobui.com/salary/quanguo-kuaiji/';
        $row['sources'][0]['used_for'] = 'CN evidence cn_001: internal ledger wording must not be reader-facing.';
        $row['china_recruitment_reference']['facts']['range_source_evidence_ids'] = ['cn_001'];
        $row['us_official_reference']['source_ids'] = ['us_001'];
        $row['uk_reference']['source_id'] = 'uk_001';
        $row['eu_context_boundary']['source_id'] = 'eu_001';
        CareerJobSalaryAsset::query()->create([
            'occupation_id' => $occupation->id,
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'zh-CN',
            'asset_version' => CareerJobSalaryAsset::ASSET_VERSION_V3_6,
            'status' => CareerJobSalaryAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
            'asset_payload_json' => $row,
            'sources_json' => $row['sources'],
            'evidence_used_json' => $row['evidence_used'],
            'derived_from_estimate_json' => $row['derived_from_estimate'],
            'audit_fields_json' => $row['audit_fields'],
            'asset_row_hash' => $row['audit_fields']['row_hash'],
        ]);

        $response = $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/salary-asset?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('preview', true)
            ->assertJsonPath('salary_asset_v1.slug', 'accountants-and-auditors')
            ->assertJsonPath('salary_asset_v1.locale', 'zh-CN');

        $payload = $response->json();
        $this->assertArrayNotHasKey('lineage', $payload);
        $asset = $payload['salary_asset_v1'];
        foreach (['research_notes', 'audit_fields', 'evidence_used', 'derived_from_estimate', 'forbidden_claims'] as $internalKey) {
            $this->assertArrayNotHasKey($internalKey, $asset);
        }

        $this->assertSame('职友集/JobUI', $asset['sources'][0]['name']);
        $this->assertSame('中国招聘市场参考', $asset['sources'][0]['used_for']);
        $this->assertArrayNotHasKey('source_id', $asset['sources'][0]);
        $this->assertArrayNotHasKey('range_source_evidence_ids', $asset['china_recruitment_reference']['facts']);
        $this->assertArrayNotHasKey('source_ids', $asset['us_official_reference']);
        $this->assertArrayNotHasKey('source_id', $asset['uk_reference']);
        $this->assertArrayNotHasKey('source_id', $asset['eu_context_boundary']);
    }

    public function test_preview_api_sanitizes_english_cn_source_display_names(): void
    {
        Config::set('career_salary_assets.staging_preview_enabled', true);
        Config::set('career_salary_assets.preview_slugs', ['zoologists-and-wildlife-biologists']);
        $occupation = $this->seedOccupation('zoologists-and-wildlife-biologists');
        $row = $this->assetRow('zoologists-and-wildlife-biologists', 'en');
        $row['sources'][0]['market'] = 'CN';
        $row['sources'][0]['name'] = '猎聘';
        $row['sources'][0]['url'] = 'https://www.liepin.com/zpshengwuxingyeyanjiuyuan/xinzi/';
        CareerJobSalaryAsset::query()->create([
            'occupation_id' => $occupation->id,
            'career_job_slug' => 'zoologists-and-wildlife-biologists',
            'locale' => 'en',
            'asset_version' => CareerJobSalaryAsset::ASSET_VERSION_V3_6,
            'status' => CareerJobSalaryAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
            'asset_payload_json' => $row,
            'sources_json' => $row['sources'],
            'evidence_used_json' => $row['evidence_used'],
            'derived_from_estimate_json' => $row['derived_from_estimate'],
            'audit_fields_json' => $row['audit_fields'],
            'asset_row_hash' => $row['audit_fields']['row_hash'],
        ]);

        $asset = $this->getJson('/api/v0.5/career/jobs/zoologists-and-wildlife-biologists/salary-asset?locale=en')
            ->assertOk()
            ->assertJsonPath('salary_asset_v1.locale', 'en')
            ->json('salary_asset_v1');

        $this->assertSame('Liepin', $asset['sources'][0]['name']);
        $this->assertSame('China recruitment-market reference', $asset['sources'][0]['used_for']);
        $this->assertDoesNotMatchRegularExpression('/\p{Han}/u', $asset['sources'][0]['name']);
        $this->assertArrayNotHasKey('source_id', $asset['sources'][0]);
    }

    public function test_preview_api_fails_closed_when_disabled_or_not_allowlisted(): void
    {
        $occupation = $this->seedOccupation('accountants-and-auditors');
        $row = $this->assetRow('accountants-and-auditors', 'zh-CN');
        CareerJobSalaryAsset::query()->create([
            'occupation_id' => $occupation->id,
            'career_job_slug' => 'accountants-and-auditors',
            'locale' => 'zh-CN',
            'asset_version' => CareerJobSalaryAsset::ASSET_VERSION_V3_6,
            'status' => CareerJobSalaryAsset::STATUS_STAGING_PREVIEW,
            'preview_allowlisted' => true,
            'asset_payload_json' => $row,
            'sources_json' => $row['sources'],
            'evidence_used_json' => $row['evidence_used'],
            'derived_from_estimate_json' => $row['derived_from_estimate'],
            'audit_fields_json' => $row['audit_fields'],
            'asset_row_hash' => $row['audit_fields']['row_hash'],
        ]);

        Config::set('career_salary_assets.staging_preview_enabled', false);
        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/salary-asset?locale=zh-CN')
            ->assertNotFound();

        Config::set('career_salary_assets.staging_preview_enabled', true);
        Config::set('career_salary_assets.preview_slugs', ['actuaries']);
        $this->getJson('/api/v0.5/career/jobs/accountants-and-auditors/salary-asset?locale=zh-CN')
            ->assertOk()
            ->assertJsonPath('salary_asset_v1.slug', 'accountants-and-auditors');

        $this->getJson('/api/v0.5/career/jobs/actuaries/salary-asset?locale=zh-CN')
            ->assertNotFound();
    }

    public function test_importer_blocks_reader_facing_editorial_quality_failures(): void
    {
        $this->seedOccupation('accountants-and-auditors');
        $this->seedCareerJobBundleAuthority('accountants-and-auditors');
        $zh = $this->assetRow('accountants-and-auditors', 'zh-CN');
        $en = $this->assetRow('accountants-and-auditors', 'en');
        foreach ([&$zh, &$en] as &$row) {
            $row['sources'][0]['name'] = '//';
            $row['salary_drivers'] = array_fill(0, 5, [
                'factor' => '岗位边界',
                'description' => '会计师和审计师 的薪资会随具体岗位标题、职责范围和相邻岗位口径变化。',
            ]);
            $row['reader_guidance'] = array_fill(0, 4, '中国薪资只读作招聘市场样本信号，不读作官方全国职业工资。');
        }
        unset($row);

        $file = $this->writeJsonl([$zh, $en]);
        $report = storage_path('framework/testing/salary-preview-editorial-gate-fail.json');

        $exitCode = Artisan::call('career:salary-assets-import-preview', [
            '--file' => $file,
            '--slugs' => 'accountants-and-auditors',
            '--dry-run' => true,
            '--output' => $report,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertDatabaseCount('career_job_salary_assets', 0);

        $decoded = json_decode((string) file_get_contents($report), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('fail', $decoded['decision']);
        $this->assertSame(0, $decoded['editorial_quality_gate']['ready_row_count']);
        $errors = implode(' ', $decoded['errors']);
        $this->assertStringContainsString('salary_preview_editorial_gate', $errors);
        $this->assertStringNotContainsString('reader-safe source label', $errors);
        $this->assertStringContainsString('generic description', $errors);
        $this->assertStringContainsString('generic sentence', $errors);
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

        $this->app->forgetInstance(CareerSalaryAssetImportService::class);
        $this->app->forgetInstance(CareerSalaryAssetPreviewService::class);
        $this->app->forgetInstance('App\\Console\\Commands\\CareerImportSalaryAssetsPreview');
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
            'canonical_slug' => 'salary-preview-'.$slug,
            'title_en' => 'Salary Preview',
            'title_zh' => '薪资预览',
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
     * @param  list<string>  $slugs
     */
    private function writeApprovalManifest(array $slugs, string $sourceSha): string
    {
        $manifest = [
            'artifact_type' => 'career_salary_1046_editorial_review_approval_manifest',
            'version' => 'v3.6.1046.editorial_review.1',
            'source_asset' => [
                'path' => 'generated/career-salary-v3-6-1046-reader-repair-final-2092/career_job_salary_assets_1046_v3_6_reader_repaired.jsonl',
                'sha256' => $sourceSha,
                'row_count' => 2092,
                'slug_count' => 1046,
                'locale_counts' => ['zh-CN' => 1046, 'en' => 1046],
            ],
            'source_audits' => [
                'independent_qa_sha256' => str_repeat('a', 64),
                'staging_api_smoke_sha256' => str_repeat('b', 64),
                'staging_summary_sha256' => str_repeat('c', 64),
            ],
            'gate_results' => [
                'independent_qa_conclusion' => 'READY_FOR_EXPANDED_STAGING_PREVIEW',
                'known_good_10slug_pass' => true,
                'projection_ready_rows' => 2092,
                'projection_blocked_rows' => 0,
                'staging_api_smoke_status' => 'pass',
                'staging_api_ready_rows' => 2092,
                'staging_api_failed_rows' => 0,
                'staging_preview_summary_conclusion' => 'EXPANDED_STAGING_PREVIEW_1046_PASS',
            ],
            'editorial_review' => [
                'status' => 'editorial_review_pass',
                'approved_for_next_state' => CareerJobSalaryAsset::STATUS_APPROVED,
                'production_import_approved' => false,
                'rejected_count' => 0,
                'rejected_slugs' => [],
                'high_risk_reviewed_slug_count' => 89,
                'reviewed_categories' => ['military_or_command', 'variable_pay_or_performance'],
                'manual_approval_required_for_production_import' => true,
            ],
            'approved_slugs' => $slugs,
        ];
        $path = storage_path('framework/testing/salary-preview-approval-manifest.json');
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $path;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeJsonl(array $rows): string
    {
        $path = storage_path('framework/testing/salary-preview-'.bin2hex(random_bytes(4)).'.jsonl');
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

    private function seedPreviewAsset(string $slug, string $locale): void
    {
        $occupation = Occupation::query()->where('canonical_slug', $slug)->first();
        if (! $occupation instanceof Occupation) {
            $occupation = $this->seedOccupation($slug);
        }

        $row = $this->assetRow($slug, $locale);

        CareerJobSalaryAsset::query()->updateOrCreate(
            [
                'occupation_id' => $occupation->id,
                'career_job_slug' => $slug,
                'locale' => $locale,
                'asset_version' => CareerJobSalaryAsset::ASSET_VERSION_V3_6,
            ],
            [
                'career_job_slug' => $slug,
                'locale' => $locale,
                'asset_version' => CareerJobSalaryAsset::ASSET_VERSION_V3_6,
                'status' => CareerJobSalaryAsset::STATUS_STAGING_PREVIEW,
                'preview_allowlisted' => true,
                'asset_payload_json' => $row,
                'sources_json' => $row['sources'],
                'evidence_used_json' => $row['evidence_used'],
                'derived_from_estimate_json' => $row['derived_from_estimate'],
                'audit_fields_json' => $row['audit_fields'],
                'asset_row_hash' => $row['audit_fields']['row_hash'],
                'source_artifact_sha256' => null,
                'evidence_artifact_sha256' => null,
                'estimate_artifact_sha256' => null,
                'import_run_id' => null,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function assetRow(string $slug, string $locale): array
    {
        $hash = hash('sha256', $slug.'|'.$locale);

        return [
            'asset_type' => 'career_job_salary_asset',
            'asset_version' => CareerJobSalaryAsset::ASSET_VERSION_V3_6,
            'slug' => $slug,
            'locale' => $locale,
            'occupation' => [
                'title_en' => 'Accountants and Auditors',
                'title_zh' => '会计师和审计师',
                'soc_code' => '13-2011',
                'onet_code' => '13-2011.00',
            ],
            'heading' => $locale === 'zh-CN' ? '会计师和审计师薪资与就业参考' : 'Accountants and Auditors salary reference',
            'summary' => [
                'headline' => 'Salary reference',
                'short_answer' => 'China uses recruitment-market evidence, not official wage data.',
                'confidence_label' => 'medium',
            ],
            'china_recruitment_reference' => [
                'heading' => '中国招聘市场参考',
                'evidence_status' => 'calculable',
                'display_monthly_range_cny' => '约 ¥6,000–10,000/月',
                'body' => $locale === 'zh-CN'
                    ? '中国大陆部分只使用招聘市场证据，不是官方职业中位薪资，也不是个人收入预测。'
                    : 'China salary content uses recruitment-market evidence and is not official Chinese wage data.',
                'data_boundary' => 'This is a China recruitment-market reference; it is not an official Chinese single-occupation median wage.',
                'facts' => [
                    'monthly_cny_low_observed' => 6000,
                    'monthly_cny_high_observed' => 10000,
                    'monthly_cny_average_observed' => null,
                    'monthly_cny_p25' => null,
                    'monthly_cny_median' => null,
                    'monthly_cny_p75' => null,
                ],
                'limitations' => ['Recruitment samples are not official wage statistics.'],
            ],
            'us_official_reference' => ['status' => 'available', 'facts' => ['median_annual_usd' => 81680]],
            'uk_reference' => ['status' => 'available', 'facts' => ['starter_annual_gbp' => 25000]],
            'eu_context_boundary' => ['status' => 'macro_context_only'],
            'salary_drivers' => $locale === 'zh-CN'
                ? [
                    ['factor' => '审计季节性', 'description' => '审计旺季的加班、出差和项目密度会影响总收入与补贴结构。'],
                    ['factor' => '证书与签字责任', 'description' => 'CPA、税务经验和是否承担签字或复核责任会改变岗位定价。'],
                    ['factor' => '行业账务复杂度', 'description' => '制造、金融、互联网或跨境业务的准则复杂度不同，薪酬带宽也不同。'],
                    ['factor' => '系统能力', 'description' => '熟悉 ERP、合并报表、成本核算和数据分析工具的候选人通常更有议价空间。'],
                    ['factor' => '机构类型', 'description' => '事务所、企业财务、内审和咨询岗位的绩效奖金与晋升节奏不同。'],
                ]
                : [
                    ['factor' => 'Audit season load', 'description' => 'Busy-season overtime, travel, and project density can change total compensation and allowances.'],
                    ['factor' => 'Licensure and sign-off responsibility', 'description' => 'CPA status, tax exposure, and review or sign-off accountability materially affect pay.'],
                    ['factor' => 'Industry accounting complexity', 'description' => 'Manufacturing, finance, internet, and cross-border reporting roles price accounting complexity differently.'],
                    ['factor' => 'Systems capability', 'description' => 'ERP, consolidation, cost accounting, and analytics skills can improve negotiation leverage.'],
                    ['factor' => 'Employer setting', 'description' => 'Public accounting, corporate finance, internal audit, and advisory roles use different bonus and promotion models.'],
                ],
            'reader_guidance' => $locale === 'zh-CN'
                ? [
                    '先分清样本是审计、税务、企业财务还是内审岗位，再比较薪资。',
                    '中国区间只代表招聘市场样本，不代表官方职业工资或个人收入预测。',
                    '美国和英国数据要按 SOC、职业 profile 和统计年份边界阅读。',
                    '比较 offer 时同时看忙季强度、证书要求、出差、奖金和晋升路径。',
                ]
                : [
                    'Separate audit, tax, corporate accounting, and internal-audit roles before comparing pay.',
                    'Read the China range only as recruitment-market evidence, not an official wage or personal prediction.',
                    'Read US and UK figures within their SOC, profile, source-year, and coverage boundaries.',
                    'Compare offers alongside busy-season load, certification requirements, travel, bonuses, and promotion path.',
                ],
            'forbidden_claims' => [],
            'sources' => [[
                'market' => 'CN',
                'name' => 'JobUI',
                'url' => 'https://example.test/salary',
                'source_id' => 'cn_001',
            ]],
            'evidence_used' => ['cn_evidence_ids' => ['cn_001']],
            'derived_from_estimate' => [
                'source_estimate_file' => 'career_job_salary_estimates_1046_v3_6.jsonl',
                'estimate_schema_version' => 'career_job_salary_estimate_v3_6',
                'estimate_row_hash' => str_repeat('b', 64),
            ],
            'research_notes' => [],
            'audit_fields' => [
                'schema_version' => 'career_job_salary_asset_v3_6',
                'generated_at' => '2026-06-15T18:10:45Z',
                'ready_for_codex_audit' => true,
                'row_hash' => $hash,
            ],
        ];
    }
}
