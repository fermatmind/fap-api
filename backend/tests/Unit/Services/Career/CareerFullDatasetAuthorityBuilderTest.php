<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Models\OccupationCrosswalk;
use App\Models\OccupationFamily;
use App\Services\Career\Dataset\CareerFullDatasetAuthorityBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CareerFullDatasetAuthorityBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_full_342_dataset_authority_with_included_excluded_truth(): void
    {
        $this->materializeCurrentFirstWaveFixture();

        $authority = app(CareerFullDatasetAuthorityBuilder::class)->build()->toArray();

        $this->assertSame('career_full_dataset_authority', $authority['authority_kind'] ?? null);
        $this->assertSame('career.dataset_authority.full_342_plus_directory_drafts.v1', $authority['authority_version'] ?? null);
        $this->assertSame('career_all_342_occupations_dataset', $authority['dataset_key'] ?? null);
        $this->assertSame('career_all_342', $authority['dataset_scope'] ?? null);
        $this->assertSame('career_tracked_occupation', $authority['member_kind'] ?? null);
        $this->assertSame(342, (int) ($authority['member_count'] ?? 0));
        $this->assertSame(342, (int) data_get($authority, 'tracking_counts.tracked_total_occupations', 0));
        $this->assertTrue((bool) data_get($authority, 'tracking_counts.tracking_complete', false));

        $this->assertSame(342, (int) data_get($authority, 'summary.included_count', 0) + (int) data_get($authority, 'summary.excluded_count', 0));
        $this->assertIsArray(data_get($authority, 'summary.release_cohort_counts'));
        $this->assertIsArray(data_get($authority, 'summary.public_index_state_counts'));
        $this->assertIsArray(data_get($authority, 'summary.strong_index_decision_counts'));
        $this->assertIsArray(data_get($authority, 'facet_distributions.family'));
        $this->assertIsArray(data_get($authority, 'facet_distributions.publish_track'));

        $members = (array) ($authority['members'] ?? []);
        $this->assertCount(342, $members);

        $excludedMembers = array_values(array_filter($members, static fn (array $member): bool => ! (bool) ($member['included_in_public_dataset'] ?? false)));
        $this->assertNotEmpty($excludedMembers);
        foreach ($excludedMembers as $member) {
            $this->assertNotEmpty((array) ($member['exclusion_reasons'] ?? []));
        }
    }

    public function test_it_adds_directory_draft_occupations_as_public_dataset_entries_without_detail_indexability(): void
    {
        $this->materializeCurrentFirstWaveFixture();
        $draft = $this->createDirectoryDraftOccupation();

        $authority = app(CareerFullDatasetAuthorityBuilder::class)->build()->toArray();
        $members = collect((array) ($authority['members'] ?? []));
        $draftMember = $members->firstWhere('canonical_slug', $draft->canonical_slug);

        $this->assertSame(343, (int) ($authority['member_count'] ?? 0));
        $this->assertSame(343, (int) data_get($authority, 'tracking_counts.tracked_total_occupations', 0));
        $this->assertIsArray($draftMember);
        $this->assertSame('directory_draft_pending_detail', $draftMember['release_cohort'] ?? null);
        $this->assertSame('noindex', $draftMember['public_index_state'] ?? null);
        $this->assertSame('directory_draft_detail_pending', $draftMember['strong_index_decision'] ?? null);
        $this->assertTrue((bool) ($draftMember['included_in_public_dataset'] ?? false));
        $this->assertSame('目录草稿职业', $draftMember['canonical_title_zh'] ?? null);
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

    private function createDirectoryDraftOccupation(): Occupation
    {
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'directory-draft-dataset-family',
            'title_en' => 'Directory Draft Dataset Family',
            'title_zh' => '目录草稿数据集职业族',
        ]);
        $occupation = Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => 'directory-draft-dataset-role',
            'entity_level' => 'dataset_candidate',
            'truth_market' => 'CN',
            'display_market' => 'CN',
            'crosswalk_mode' => 'directory_draft',
            'canonical_title_en' => 'Directory Draft Dataset Role',
            'canonical_title_zh' => '目录草稿职业',
            'search_h1_zh' => '目录草稿职业',
        ]);
        $importRun = CareerImportRun::query()->create([
            'dataset_name' => 'china_us_occupation_directories_2026',
            'dataset_version' => '2026',
            'dataset_checksum' => 'directory-draft-dataset-checksum',
            'scope_mode' => 'occupation_directory_draft',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
        ]);

        OccupationCrosswalk::query()->create([
            'occupation_id' => $occupation->id,
            'source_system' => 'CN_2026',
            'source_code' => 'CN-DATASET-001',
            'source_title' => '目录草稿职业',
            'mapping_type' => 'directory_draft',
            'confidence_score' => 0.5,
            'import_run_id' => $importRun->id,
            'row_fingerprint' => hash('sha256', 'directory-draft-dataset-crosswalk'),
        ]);

        return $occupation->fresh();
    }
}
