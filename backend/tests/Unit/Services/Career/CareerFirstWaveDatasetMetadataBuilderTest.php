<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Import\RunStatus;
use App\DTO\Career\CareerFirstWaveDatasetDescriptor;
use App\DTO\Career\CareerFirstWaveDatasetMember;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Models\OccupationFamily;
use App\Models\TrustManifest;
use App\Services\Career\Dataset\CareerFirstWaveDatasetAuthorityBuilder;
use App\Services\Career\Dataset\CareerFirstWaveDatasetMetadataBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareerFirstWaveDatasetMetadataBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_internal_only_coverage_provenance_and_freshness_metadata_from_grounded_truth(): void
    {
        $family = OccupationFamily::query()->create([
            'id' => (string) Str::uuid(),
            'canonical_slug' => 'computer-and-information-technology',
            'title_en' => 'Computer and information technology',
            'title_zh' => '计算机与信息技术',
        ]);

        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'career_dataset_fixture.csv',
            'dataset_version' => 'fixture.v1',
            'dataset_checksum' => 'fixture-checksum',
            'source_path' => '/tmp/career-dataset-fixture.csv',
            'scope_mode' => 'exact',
            'dry_run' => false,
            'status' => RunStatus::COMPLETED,
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(30),
            'meta' => [
                'wave_name' => 'career_first_wave_10',
                'source_path' => '/tmp/career-dataset-fixture.csv',
            ],
        ]);

        $compileRun = CareerCompileRun::query()->create([
            'import_run_id' => $importRun->id,
            'compiler_version' => 'career-compiler.v1',
            'scope_mode' => 'exact',
            'dry_run' => false,
            'status' => RunStatus::COMPLETED,
            'started_at' => now()->subMinutes(20),
            'finished_at' => now()->subMinutes(10),
            'meta' => [
                'compiled_at' => '2026-04-14T01:23:45Z',
            ],
        ]);

        $occupation = Occupation::query()->create([
            'id' => (string) Str::uuid(),
            'family_id' => $family->id,
            'parent_id' => null,
            'canonical_slug' => 'data-scientists',
            'entity_level' => 'occupation',
            'truth_market' => 'US',
            'display_market' => 'US',
            'crosswalk_mode' => 'exact',
            'canonical_title_en' => 'Data scientists',
            'canonical_title_zh' => '数据科学家',
            'search_h1_zh' => '数据科学家',
        ]);

        TrustManifest::query()->create([
            'id' => (string) Str::uuid(),
            'occupation_id' => $occupation->id,
            'content_version' => 'career_first_wave.v1',
            'data_version' => 'fixture.v1',
            'logic_version' => 'career-compiler.v1',
            'locale_context' => ['truth_market' => 'US'],
            'methodology' => ['scope_mode' => 'exact'],
            'reviewer_status' => 'approved',
            'reviewed_at' => null,
            'ai_assistance' => [],
            'quality' => [],
            'last_substantive_update_at' => '2026-04-13T00:00:00Z',
            'next_review_due_at' => null,
            'import_run_id' => $importRun->id,
            'row_fingerprint' => 'tm-fixture',
        ]);

        $olderImportRun = CareerImportRun::query()->create([
            'dataset_name' => 'career_dataset_fixture.csv',
            'dataset_version' => 'fixture.v0',
            'dataset_checksum' => 'fixture-checksum-older',
            'source_path' => '/tmp/career-dataset-fixture-older.csv',
            'scope_mode' => 'exact',
            'dry_run' => false,
            'status' => RunStatus::COMPLETED,
            'started_at' => now()->subHours(2),
            'finished_at' => now()->subHours(2)->addMinutes(15),
            'meta' => [
                'wave_name' => 'career_first_wave_10',
                'source_path' => '/tmp/career-dataset-fixture-older.csv',
            ],
        ]);

        TrustManifest::query()->create([
            'id' => (string) Str::uuid(),
            'occupation_id' => $occupation->id,
            'content_version' => 'career_first_wave.v0',
            'data_version' => 'fixture.v0',
            'logic_version' => 'career-compiler.v0',
            'locale_context' => ['truth_market' => 'US'],
            'methodology' => ['scope_mode' => 'exact'],
            'reviewer_status' => 'approved',
            'reviewed_at' => null,
            'ai_assistance' => [],
            'quality' => [],
            'last_substantive_update_at' => '2026-04-12T00:00:00Z',
            'next_review_due_at' => null,
            'import_run_id' => $olderImportRun->id,
            'row_fingerprint' => 'tm-fixture-older',
        ]);

        $descriptor = new CareerFirstWaveDatasetDescriptor(
            datasetKey: 'career_first_wave_job_detail_dataset',
            datasetScope: 'career_first_wave_10',
            manifestVersion: '2026.04.09.first_wave.v1',
            selectionPolicyVersion: 'first_wave_publish_gate.v1',
            datasetName: 'career_dataset_fixture.csv',
            datasetVersion: 'fixture.v1',
            datasetChecksum: 'fixture-checksum',
            sourcePath: '/tmp/career-dataset-fixture.csv',
        );

        $members = [
            new CareerFirstWaveDatasetMember(
                occupationUuid: 'occ-1',
                canonicalSlug: 'data-scientists',
                canonicalTitleEn: 'Data scientists',
                canonicalPath: '/career/jobs/data-scientists',
                launchTier: 'stable',
                discoverabilityState: 'discoverable',
                indexEligible: true,
            ),
        ];

        $aggregate = [
            'member_kind' => 'career_job_detail',
            'member_count' => 1,
            'compiled_at' => '2026-04-14T01:23:45Z',
            'last_substantive_update_at' => '2026-04-13T00:00:00Z',
        ];

        $metadata = app(CareerFirstWaveDatasetMetadataBuilder::class)
            ->build($descriptor, $aggregate, $members, $importRun, $compileRun)
            ->toArray();

        $this->assertSame('career_first_wave_10', data_get($metadata, 'coverage.dataset_scope'));
        $this->assertSame('career_first_wave_10', data_get($metadata, 'coverage.wave_name'));
        $this->assertSame(['career_job_detail'], data_get($metadata, 'coverage.included_route_kinds'));
        $this->assertFalse((bool) data_get($metadata, 'coverage.family_hubs_included'));
        $this->assertSame('career_dataset_fixture.csv', data_get($metadata, 'provenance.dataset_name'));
        $this->assertSame('fixture.v1', data_get($metadata, 'provenance.dataset_version'));
        $this->assertSame('fixture-checksum', data_get($metadata, 'provenance.dataset_checksum'));
        $this->assertSame('/tmp/career-dataset-fixture.csv', data_get($metadata, 'provenance.source_path'));
        $this->assertSame($importRun->id, data_get($metadata, 'provenance.import_run_id'));
        $this->assertSame($compileRun->id, data_get($metadata, 'provenance.compile_run_id'));
        $this->assertSame('career_first_wave.v1', data_get($metadata, 'provenance.content_version'));
        $this->assertSame('fixture.v1', data_get($metadata, 'provenance.data_version'));
        $this->assertSame('career-compiler.v1', data_get($metadata, 'provenance.logic_version'));
        $this->assertSame('2026-04-14T01:23:45Z', data_get($metadata, 'freshness.compiled_at'));
        $this->assertSame('2026-04-13T00:00:00Z', data_get($metadata, 'freshness.last_substantive_update_at'));
        $this->assertSame('2026-04-09T00:00:00Z', data_get($metadata, 'freshness.manifest_generated_at'));
    }

    public function test_it_uses_real_first_wave_truth_without_changing_member_scope(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $authority = app(CareerFirstWaveDatasetAuthorityBuilder::class)->build()->toArray();
        $descriptor = new CareerFirstWaveDatasetDescriptor(
            datasetKey: (string) data_get($authority, 'descriptor.dataset_key'),
            datasetScope: (string) data_get($authority, 'descriptor.dataset_scope'),
            manifestVersion: (string) data_get($authority, 'descriptor.manifest_version'),
            selectionPolicyVersion: (string) data_get($authority, 'descriptor.selection_policy_version'),
            datasetName: data_get($authority, 'descriptor.dataset_name'),
            datasetVersion: data_get($authority, 'descriptor.dataset_version'),
            datasetChecksum: data_get($authority, 'descriptor.dataset_checksum'),
            sourcePath: data_get($authority, 'descriptor.source_path'),
        );
        $members = collect((array) ($authority['members'] ?? []))
            ->map(static fn (array $member): CareerFirstWaveDatasetMember => new CareerFirstWaveDatasetMember(
                occupationUuid: (string) ($member['occupation_uuid'] ?? ''),
                canonicalSlug: (string) ($member['canonical_slug'] ?? ''),
                canonicalTitleEn: (string) ($member['canonical_title_en'] ?? ''),
                canonicalPath: (string) ($member['canonical_path'] ?? ''),
                launchTier: (string) ($member['launch_tier'] ?? ''),
                discoverabilityState: (string) ($member['discoverability_state'] ?? ''),
                indexEligible: (bool) ($member['index_eligible'] ?? false),
            ))
            ->all();
        $aggregate = (array) ($authority['aggregate'] ?? []);
        $importRun = CareerImportRun::query()->find((string) data_get($authority, 'aggregate.import_run_id'));
        $compileRun = CareerCompileRun::query()->find((string) data_get($authority, 'aggregate.compile_run_id'));

        $metadata = app(CareerFirstWaveDatasetMetadataBuilder::class)
            ->build($descriptor, $aggregate, $members, $importRun, $compileRun)
            ->toArray();

        $this->assertSame('career_first_wave_10', data_get($metadata, 'coverage.dataset_scope'));
        $this->assertSame('career_first_wave_10', data_get($metadata, 'coverage.wave_name'));
        $this->assertSame(10, data_get($metadata, 'coverage.member_count'));
        $this->assertSame(10, data_get($metadata, 'coverage.count_expected'));
        $this->assertSame(10, data_get($metadata, 'coverage.count_actual'));
        $this->assertSame(['career_job_detail'], data_get($metadata, 'coverage.included_route_kinds'));
        $this->assertFalse((bool) data_get($metadata, 'coverage.family_hubs_included'));
        $this->assertSame('first_wave_readiness_summary_subset.csv', data_get($metadata, 'provenance.dataset_name'));
        $this->assertNotSame('', (string) data_get($metadata, 'provenance.dataset_checksum'));
        $this->assertStringContainsString(
            'tests/Fixtures/Career/authority_wave/first_wave_readiness_summary_subset.csv',
            (string) data_get($metadata, 'provenance.source_path')
        );
        $this->assertNotNull(data_get($metadata, 'provenance.import_run_id'));
        $this->assertNotNull(data_get($metadata, 'provenance.compile_run_id'));
        $this->assertNotNull(data_get($metadata, 'freshness.compiled_at'));
        $this->assertNotNull(data_get($metadata, 'freshness.last_substantive_update_at'));
        $this->assertSame('2026-04-09T00:00:00Z', data_get($metadata, 'freshness.manifest_generated_at'));
        $this->assertFalse(collect($members)->contains(
            static fn (CareerFirstWaveDatasetMember $member): bool => str_contains($member->canonicalPath, '/career/family/')
        ));
    }

    public function test_it_omits_unsupported_metadata_families_and_keeps_member_scope_separate(): void
    {
        $descriptor = new CareerFirstWaveDatasetDescriptor(
            datasetKey: 'career_first_wave_job_detail_dataset',
            datasetScope: 'career_first_wave_10',
            manifestVersion: '2026.04.09.first_wave.v1',
            selectionPolicyVersion: 'first_wave_publish_gate.v1',
            datasetName: null,
            datasetVersion: null,
            datasetChecksum: null,
            sourcePath: null,
        );

        $metadata = app(CareerFirstWaveDatasetMetadataBuilder::class)
            ->build(
                $descriptor,
                [
                    'member_kind' => 'career_job_detail',
                    'member_count' => 0,
                    'compiled_at' => null,
                    'last_substantive_update_at' => null,
                ],
                [],
                null,
                null,
            )
            ->toArray();

        $this->assertArrayHasKey('coverage', $metadata);
        $this->assertArrayHasKey('provenance', $metadata);
        $this->assertArrayHasKey('freshness', $metadata);
        $this->assertArrayNotHasKey('publisher', $metadata);
        $this->assertArrayNotHasKey('license', $metadata);
        $this->assertArrayNotHasKey('terms_of_use', $metadata);
        $this->assertArrayNotHasKey('distribution', $metadata);
        $this->assertArrayNotHasKey('download_url', $metadata);
        $this->assertArrayNotHasKey('description', $metadata);
        $this->assertArrayNotHasKey('methodology', $metadata);
        $this->assertFalse((bool) data_get($metadata, 'coverage.family_hubs_included'));
        $this->assertSame(['career_job_detail'], data_get($metadata, 'coverage.included_route_kinds'));
    }

    private function materializeCurrentFirstWaveFixture(): void
    {
        $exitCode = Artisan::call('career:validate-first-wave-publish-ready', [
            '--source' => base_path('tests/Fixtures/Career/authority_wave/first_wave_readiness_summary_subset.csv'),
            '--materialize-missing' => true,
            '--compile-missing' => true,
            '--repair-safe-partials' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }
}
