<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Models\OccupationAlias;
use App\Models\OccupationFamily;
use App\Services\Career\Import\CareerAuthorityMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class CareerAuthorityMaterializerAliasHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_materializes_only_manifest_scoped_approved_aliases_and_skips_blocked_aliases(): void
    {
        $run = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-b8-materializer',
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        app(CareerAuthorityMaterializer::class)->materializeImportRow($this->normalizedRow('software-developers', 'Software Developers'), $run);

        $occupation = Occupation::query()->where('canonical_slug', 'software-developers')->firstOrFail();
        $normalizedAliases = $occupation->aliases()->pluck('normalized')->all();

        $this->assertContains('software developer', $normalizedAliases);
        $this->assertContains('application developer', $normalizedAliases);
        $this->assertContains('软件开发工程师', $normalizedAliases);
        $this->assertNotContains('程序员', $normalizedAliases);
        $this->assertNotContains('码农', $normalizedAliases);
    }

    public function test_it_does_not_add_extra_aliases_for_first_wave_entries_without_approved_rows(): void
    {
        $run = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-b8-materializer-empty',
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        app(CareerAuthorityMaterializer::class)->materializeImportRow($this->normalizedRow('marketing-managers', 'Marketing Managers'), $run);

        $occupation = Occupation::query()->where('canonical_slug', 'marketing-managers')->firstOrFail();
        $normalizedAliases = $occupation->aliases()->pluck('normalized')->all();

        $this->assertSame(['marketing managers', 'marketing managers 中文'], $normalizedAliases);
    }

    public function test_it_materializes_explicit_family_target_alias_rows_without_promoting_leaf_aliases(): void
    {
        $run = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-b25-family-materializer',
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        app(CareerAuthorityMaterializer::class)->materializeImportRow(
            $this->normalizedRow(
                'data-scientists',
                'Data Scientists',
                'computer-and-information-technology',
                'Computer and Information Technology',
                '计算机与信息技术',
            ),
            $run
        );

        $family = OccupationFamily::query()->where('canonical_slug', 'computer-and-information-technology')->firstOrFail();
        $familyAliases = OccupationAlias::query()
            ->where('family_id', $family->id)
            ->where('target_kind', 'family')
            ->orderBy('normalized')
            ->get();

        $this->assertSame(
            [
                'computer and information technology careers',
                'information technology careers',
                '信息技术职业',
            ],
            $familyAliases->pluck('normalized')->all()
        );
        $this->assertSame([null, null, null], $familyAliases->pluck('occupation_id')->all());

        $leafAliases = OccupationAlias::query()
            ->where('family_id', $family->id)
            ->whereNotNull('occupation_id')
            ->pluck('target_kind')
            ->unique()
            ->values()
            ->all();

        $this->assertContains('occupation', $leafAliases);
        $this->assertNotContains('family', $leafAliases);
    }

    public function test_it_rejects_manifest_occupation_uuid_for_unrelated_slug(): void
    {
        $run = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-b7-import-occupation-conflict',
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
        $existingFamily = OccupationFamily::query()->create([
            'canonical_slug' => 'existing-family',
            'title_en' => 'Existing Family',
            'title_zh' => '现有家族',
        ]);
        $existingOccupation = Occupation::query()->create([
            'family_id' => $existingFamily->id,
            'canonical_slug' => 'existing-occupation',
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'US',
            'crosswalk_mode' => 'exact',
            'canonical_title_en' => 'Existing Occupation',
            'canonical_title_zh' => '现有职业',
            'search_h1_zh' => '现有职业诊断',
        ]);

        $row = $this->normalizedRow('incoming-occupation', 'Incoming Occupation');
        $row['occupation_uuid'] = $existingOccupation->id;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already belongs to slug [existing-occupation]');

        app(CareerAuthorityMaterializer::class)->materializeImportRow($row, $run);
    }

    public function test_it_rejects_manifest_family_uuid_for_unrelated_family_slug(): void
    {
        $run = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-b7-import-family-conflict',
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
        $existingFamily = OccupationFamily::query()->create([
            'canonical_slug' => 'existing-family',
            'title_en' => 'Existing Family',
            'title_zh' => '现有家族',
        ]);

        $row = $this->normalizedRow('incoming-family-occupation', 'Incoming Family Occupation');
        $row['family_uuid'] = $existingFamily->id;
        $row['family_slug'] = 'incoming-family';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already belongs to slug [existing-family]');

        app(CareerAuthorityMaterializer::class)->materializeImportRow($row, $run);
    }

    public function test_it_allows_manifest_uuids_when_canonical_identity_matches(): void
    {
        $run = CareerImportRun::query()->create([
            'dataset_name' => 'fixture',
            'dataset_version' => 'v1',
            'dataset_checksum' => 'checksum-b7-import-valid-identity',
            'scope_mode' => 'first_wave_exact',
            'dry_run' => false,
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
        $family = OccupationFamily::query()->create([
            'canonical_slug' => 'same-family',
            'title_en' => 'Same Family',
            'title_zh' => '同一家族',
        ]);
        $occupation = Occupation::query()->create([
            'family_id' => $family->id,
            'canonical_slug' => 'same-occupation',
            'entity_level' => 'market_child',
            'truth_market' => 'US',
            'display_market' => 'US',
            'crosswalk_mode' => 'exact',
            'canonical_title_en' => 'Same Occupation',
            'canonical_title_zh' => '同一职业',
            'search_h1_zh' => '同一职业诊断',
        ]);

        $row = $this->normalizedRow('same-occupation', 'Updated Same Occupation', 'same-family', 'Updated Same Family');
        $row['occupation_uuid'] = $occupation->id;
        $row['family_uuid'] = $family->id;

        app(CareerAuthorityMaterializer::class)->materializeImportRow($row, $run);

        $this->assertSame('Updated Same Occupation', $occupation->fresh()->canonical_title_en);
        $this->assertSame('Updated Same Family', $family->fresh()->title_en);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedRow(
        string $slug,
        string $title,
        ?string $familySlug = null,
        ?string $familyTitleEn = null,
        ?string $familyTitleZh = null,
    ): array {
        $titleZh = $title.' 中文';

        return [
            'family_slug' => $familySlug ?? 'test-family-'.$slug,
            'family_title_en' => $familyTitleEn ?? 'Test Family',
            'family_title_zh' => $familyTitleZh ?? '测试家族',
            'canonical_slug' => $slug,
            'truth_market' => 'US',
            'display_market' => 'US',
            'crosswalk_mode' => 'exact',
            'canonical_title_en' => $title,
            'canonical_title_zh' => $titleZh,
            'structural_stability' => 0.9,
            'task_prototype_signature' => ['analysis' => 0.8],
            'market_semantics_gap' => 0.1,
            'regulatory_divergence' => 0.05,
            'toolchain_divergence' => 0.07,
            'skill_gap_threshold' => 0.3,
            'trust_inheritance_scope' => ['allow_task_truth' => true],
            'crosswalk_source_system' => 'us_soc',
            'crosswalk_source_code' => '15-1252',
            'crosswalk_source_title' => $title,
            'mapping_mode' => 'exact',
            'source_title' => 'Fixture Source',
            'bls_url' => 'https://example.test/bls/'.$slug,
            'source_url' => 'https://example.test/source/'.$slug,
            'median_pay_usd_annual' => 120000,
            'jobs_2024' => 1000,
            'projected_jobs_2034' => 1200,
            'employment_change' => 200,
            'outlook_pct_2024_2034' => 20,
            'outlook_description' => 'Much faster than average',
            'entry_education' => "Bachelor's degree",
            'work_experience' => 'None',
            'on_the_job_training' => 'None',
            'ai_exposure' => 4.5,
            'ai_rationale' => 'fixture rationale',
            'skill_graph' => [
                'stack_key' => 'core',
                'skill_overlap_graph' => ['analysis' => 0.8],
            ],
            'canonical_path' => '/career/jobs/'.$slug,
        ];
    }
}
