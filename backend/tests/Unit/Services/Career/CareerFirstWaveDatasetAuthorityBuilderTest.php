<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Domain\Career\Import\RunStatus;
use App\Models\CareerCompileRun;
use App\Models\CareerImportRun;
use App\Services\Career\Dataset\CareerFirstWaveDatasetAuthorityBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFirstWaveDatasetAuthorityBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_an_internal_first_wave_job_detail_dataset_authority(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $authority = app(CareerFirstWaveDatasetAuthorityBuilder::class)->build()->toArray();

        $this->assertSame('career_first_wave_dataset_authority', $authority['authority_kind']);
        $this->assertSame('career.dataset_authority.first_wave.v1', $authority['authority_version']);
        $this->assertSame('career_first_wave_job_detail_dataset', data_get($authority, 'descriptor.dataset_key'));
        $this->assertSame('career_first_wave_10', data_get($authority, 'descriptor.dataset_scope'));
        $this->assertSame('2026.04.09.first_wave.v1', data_get($authority, 'descriptor.manifest_version'));
        $this->assertSame('first_wave_publish_gate.v1', data_get($authority, 'descriptor.selection_policy_version'));
        $this->assertSame('first_wave_readiness_summary_subset.csv', data_get($authority, 'descriptor.dataset_name'));
        $this->assertNotSame('', (string) data_get($authority, 'descriptor.dataset_checksum'));
        $this->assertStringContainsString(
            'tests/Fixtures/Career/authority_wave/first_wave_readiness_summary_subset.csv',
            (string) data_get($authority, 'descriptor.source_path')
        );
        $this->assertSame('career_job_detail', data_get($authority, 'aggregate.member_kind'));
        $this->assertSame(10, data_get($authority, 'aggregate.member_count'));
        $this->assertSame(6, data_get($authority, 'aggregate.counts.stable'));
        $this->assertSame(0, data_get($authority, 'aggregate.counts.candidate'));
        $this->assertSame(4, data_get($authority, 'aggregate.counts.hold'));
        $this->assertSame(6, data_get($authority, 'aggregate.counts.discoverable'));
        $this->assertSame(4, data_get($authority, 'aggregate.counts.excluded'));
        $this->assertNotNull(data_get($authority, 'aggregate.import_run_id'));
        $this->assertNotNull(data_get($authority, 'aggregate.compile_run_id'));
        $this->assertNotNull(data_get($authority, 'aggregate.compiled_at'));
        $this->assertNotNull(data_get($authority, 'aggregate.last_substantive_update_at'));
        $this->assertCount(10, $authority['members']);
        $this->assertSame('career_job_detail', data_get($authority, 'aggregate.member_kind'));
        $this->assertSame('accountants-and-auditors', data_get($authority, 'members.0.canonical_slug'));
        $this->assertSame('/career/jobs/accountants-and-auditors', data_get($authority, 'members.0.canonical_path'));
        $this->assertFalse(collect($authority['members'])->contains(
            static fn (array $member): bool => str_contains((string) ($member['canonical_path'] ?? ''), '/career/family/')
        ));
    }

    public function test_it_limits_member_scope_to_first_wave_job_detail_members_only(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $authority = app(CareerFirstWaveDatasetAuthorityBuilder::class)->build()->toArray();
        $memberSlugs = collect($authority['members'])->pluck('canonical_slug')->all();

        $this->assertCount(10, $memberSlugs);
        $this->assertContains('registered-nurses', $memberSlugs);
        $this->assertContains('software-developers', $memberSlugs);
        $this->assertNotContains('computer-and-information-technology', $memberSlugs);
        $this->assertNotContains('blocked-tech-family-api', $memberSlugs);
        $this->assertFalse(collect($authority['members'])->contains(
            static fn (array $member): bool => array_key_exists('family_uuid', $member)
        ));
    }

    public function test_it_does_not_invent_public_dataset_metadata_or_schema_fields(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $authority = app(CareerFirstWaveDatasetAuthorityBuilder::class)->build()->toArray();

        $this->assertArrayNotHasKey('publisher', $authority['descriptor']);
        $this->assertArrayNotHasKey('license', $authority['descriptor']);
        $this->assertArrayNotHasKey('distribution', $authority['descriptor']);
        $this->assertArrayNotHasKey('download_url', $authority['descriptor']);
        $this->assertStringNotContainsString('Dataset', json_encode($authority, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('DataCatalog', json_encode($authority, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('DefinedTermSet', json_encode($authority, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('Article', json_encode($authority, JSON_THROW_ON_ERROR));
    }

    public function test_it_scopes_import_and_compile_freshness_to_the_current_first_wave(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $firstWaveAuthority = app(CareerFirstWaveDatasetAuthorityBuilder::class)->build()->toArray();
        $expectedImportRunId = (string) data_get($firstWaveAuthority, 'aggregate.import_run_id');
        $expectedCompileRunId = (string) data_get($firstWaveAuthority, 'aggregate.compile_run_id');

        $otherWaveImportRun = CareerImportRun::query()->create([
            'dataset_name' => 'other_wave_dataset.csv',
            'dataset_version' => 'other_wave.v1',
            'dataset_checksum' => 'other-wave-checksum',
            'source_path' => '/tmp/other-wave.csv',
            'scope_mode' => 'exact',
            'dry_run' => false,
            'status' => RunStatus::COMPLETED,
            'started_at' => now()->addMinute(),
            'finished_at' => now()->addMinutes(2),
            'rows_seen' => 1,
            'rows_accepted' => 1,
            'rows_skipped' => 0,
            'rows_failed' => 0,
            'output_counts' => [],
            'error_summary' => [],
            'meta' => [
                'source_path' => '/tmp/other-wave.csv',
                'wave_name' => 'career_other_wave',
            ],
        ]);

        $otherWaveCompileRun = CareerCompileRun::query()->create([
            'import_run_id' => $otherWaveImportRun->id,
            'compiler_version' => 'test-compiler.v1',
            'scope_mode' => 'exact',
            'dry_run' => false,
            'status' => RunStatus::COMPLETED,
            'started_at' => now()->addMinutes(3),
            'finished_at' => now()->addMinutes(4),
            'subjects_seen' => 1,
            'snapshots_created' => 1,
            'snapshots_skipped' => 0,
            'snapshots_failed' => 0,
            'output_counts' => [],
            'error_summary' => [],
            'meta' => [
                'compiled_at' => now()->addMinutes(4)->toISOString(),
            ],
        ]);

        $authority = app(CareerFirstWaveDatasetAuthorityBuilder::class)->build()->toArray();

        $this->assertSame($expectedImportRunId, data_get($authority, 'aggregate.import_run_id'));
        $this->assertSame($expectedCompileRunId, data_get($authority, 'aggregate.compile_run_id'));
        $this->assertNotSame($otherWaveImportRun->id, data_get($authority, 'aggregate.import_run_id'));
        $this->assertNotSame($otherWaveCompileRun->id, data_get($authority, 'aggregate.compile_run_id'));
        $this->assertSame('first_wave_readiness_summary_subset.csv', data_get($authority, 'descriptor.dataset_name'));
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
