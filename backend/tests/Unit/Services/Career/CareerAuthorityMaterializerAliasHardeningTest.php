<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Models\CareerImportRun;
use App\Models\Occupation;
use App\Services\Career\Import\CareerAuthorityMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /**
     * @return array<string, mixed>
     */
    private function normalizedRow(string $slug, string $title): array
    {
        $titleZh = $title.' 中文';

        return [
            'family_slug' => 'test-family-'.$slug,
            'family_title_en' => 'Test Family',
            'family_title_zh' => '测试家族',
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
