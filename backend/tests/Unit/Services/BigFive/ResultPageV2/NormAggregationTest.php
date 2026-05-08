<?php

declare(strict_types=1);

namespace Tests\Unit\Services\BigFive\ResultPageV2;

use App\Models\BigFiveNormObservation;
use App\Services\BigFive\Norms\BigFiveNormAggregationDryRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NormAggregationTest extends TestCase
{
    use RefreshDatabase;

    private const QA_PATH = 'content_assets/big5/result_page_v2/qa/norm_aggregation_dry_run/v0_1';

    public function test_dry_run_aggregates_from_append_only_eligible_observations_only(): void
    {
        $this->observation('a', 'en-US', 'US', 'A', false, ['O' => 10.0, 'C' => 20.0]);
        $this->observation('b', 'en-US', 'US', 'B', false, ['O' => 14.0, 'C' => 24.0]);
        $this->observation('c', 'en-US', 'US', 'C', false, ['O' => 100.0, 'C' => 100.0]);
        $this->observation('d', 'en-US', 'US', 'A', true, ['O' => 100.0, 'C' => 100.0]);

        $result = (new BigFiveNormAggregationDryRun)->aggregate(['minimum_cell_count' => 2])->toArray();

        $this->assertSame('norm_aggregation_dry_run', $result['summary']['mode'] ?? null);
        $this->assertTrue((bool) ($result['summary']['dry_run_only'] ?? false));
        $this->assertSame(2, $result['summary']['eligible_observation_count'] ?? null);
        $this->assertFalse((bool) ($result['summary']['public_norm_values_published'] ?? true));
        $this->assertCount(1, $result['groups']);
        $this->assertSame(['C' => 22.0, 'O' => 12.0], $result['groups'][0]['mean_domain_scores'] ?? null);
        $this->assertSame(['C' => 2.828427, 'O' => 2.828427], $result['groups'][0]['sd_domain_scores'] ?? null);
    }

    public function test_small_cell_groups_are_suppressed_and_never_public_output(): void
    {
        $this->observation('a', 'en-US', 'US', 'A', false, ['O' => 10.0]);

        $group = (new BigFiveNormAggregationDryRun)->aggregate(['minimum_cell_count' => 2])->groups[0];

        $this->assertTrue((bool) ($group['small_cell_suppressed'] ?? false));
        $this->assertFalse((bool) ($group['public_output_allowed'] ?? true));
        $this->assertNull($group['mean_domain_scores'] ?? null);
        $this->assertNull($group['sd_domain_scores'] ?? null);
    }

    public function test_locale_region_grouping_is_supported_without_public_display(): void
    {
        $this->observation('a', 'en-US', 'US', 'A', false, ['O' => 10.0]);
        $this->observation('b', 'zh-CN', 'CN', 'A', false, ['O' => 20.0]);

        $result = (new BigFiveNormAggregationDryRun)->aggregate(['minimum_cell_count' => 1])->toArray();
        $groups = collect($result['groups'])->pluck('group_key')->all();

        $this->assertContains('BIG5_OCEAN|en-US|US', $groups);
        $this->assertContains('BIG5_OCEAN|zh-CN|CN', $groups);
        $this->assertSame('disabled', $result['summary']['runtime_attachment'] ?? null);
        $this->assertSame('disabled', $result['summary']['public_percentile_display'] ?? null);
    }

    public function test_qa_package_documents_dry_run_without_runtime_attachment(): void
    {
        $manifest = $this->jsonFile('manifest.json');
        $report = $this->jsonFile('big5_v2_norm_aggregation_dry_run_report_v0_1.json');

        $this->assertSame('big5_v2_norm_aggregation_dry_run', $manifest['package'] ?? null);
        $this->assertTrue((bool) ($report['append_only_source_required'] ?? false));
        $this->assertTrue((bool) ($report['quality_exclusion_required'] ?? false));
        $this->assertTrue((bool) ($report['small_cell_suppression_required'] ?? false));
        $this->assertFalse((bool) ($report['public_norm_values_published'] ?? true));
        $this->assertSafetyDefaults($manifest);
        $this->assertSafetyDefaults($report);
    }

    public function test_sha256sums_are_reproducible(): void
    {
        $entries = file(base_path(self::QA_PATH.'/SHA256SUMS'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($entries);

        foreach ($entries as $entry) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}  [A-Za-z0-9_.-]+$/', $entry);
            [$expectedHash, $fileName] = explode('  ', $entry, 2);
            $this->assertSame($expectedHash, hash_file('sha256', base_path(self::QA_PATH.'/'.$fileName)), $fileName);
        }
    }

    /**
     * @param  array<string,float>  $scores
     */
    private function observation(
        string $key,
        string $locale,
        string $region,
        string $qualityLevel,
        bool $excluded,
        array $scores,
    ): BigFiveNormObservation {
        return BigFiveNormObservation::query()->create([
            'id' => fake()->uuid(),
            'observation_schema_version' => 'big5_norm_observation.v0.1',
            'observation_idempotency_key' => 'norm-agg-'.$key,
            'observation_source' => 'aggregation_test',
            'environment' => 'testing',
            'scale_code' => 'BIG5_OCEAN',
            'form_code' => 'standard',
            'content_version' => 'big5-v2-content-v0.1',
            'score_version' => 'score-v1',
            'score_trace_hash' => hash('sha256', $key),
            'norm_eligibility_status' => $excluded ? 'excluded' : 'eligible',
            'norm_excluded' => $excluded,
            'exclusion_reasons_json' => $excluded ? ['test_excluded'] : [],
            'quality_level' => $qualityLevel,
            'quality_flags_json' => [],
            'locale' => $locale,
            'region' => $region,
            'raw_domain_scores_json' => $scores,
            'raw_facet_scores_json' => ['O1' => $scores['O'] ?? 0.0],
            'observed_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $document
     */
    private function assertSafetyDefaults(array $document): void
    {
        $this->assertSame('not_runtime', $document['runtime_use'] ?? null);
        $this->assertFalse((bool) ($document['production_use_allowed'] ?? true));
        $this->assertFalse((bool) ($document['ready_for_production'] ?? true));
        $this->assertFalse((bool) ($document['production_rollout_enabled'] ?? true));
        $this->assertFalse((bool) ($document['dynamic_norm_engine_attached'] ?? true));
        $this->assertFalse((bool) ($document['public_percentile_display_enabled'] ?? true));
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $fileName): array
    {
        $json = file_get_contents(base_path(self::QA_PATH.'/'.$fileName));
        $this->assertIsString($json, $fileName);
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded, $fileName);

        return $decoded;
    }
}
