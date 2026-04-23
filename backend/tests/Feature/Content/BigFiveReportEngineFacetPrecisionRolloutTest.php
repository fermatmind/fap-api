<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\BigFive\ReportEngine\BigFiveReportEngine;
use Tests\TestCase;

final class BigFiveReportEngineFacetPrecisionRolloutTest extends TestCase
{
    public function test_facet_details_uses_precision_stream_and_full_directory(): void
    {
        $payload = app(BigFiveReportEngine::class)->generate($this->fixture('context_cross_domain_anomaly_canonical.json'));
        $facetSection = collect($payload['sections'])->firstWhere('section_key', 'facet_details');
        $blocks = collect($facetSection['blocks']);

        $this->assertSame('populated', $facetSection['status']);
        $this->assertSame('paragraph', $blocks->first()['kind']);
        $this->assertCount(3, $blocks->where('kind', 'metric_card'));
        $this->assertCount(1, $blocks->where('kind', 'callout'));
        $this->assertCount(30, $blocks->where('kind', 'table_row'));
        $this->assertCount(6, $payload['engine_decisions']['facet_anomalies']);
        $this->assertSame(
            array_slice(array_map(static fn (array $match): string => $match['rule_id'], $payload['engine_decisions']['facet_anomalies']), 0, 3),
            array_map(static fn (array $match): string => $match['rule_id'], $payload['engine_decisions']['standout_anomalies'])
        );
    }

    public function test_facet_anomaly_blocks_do_not_escape_facet_details(): void
    {
        $payload = app(BigFiveReportEngine::class)->generate($this->fixture('context_cross_domain_anomaly_canonical.json'));

        foreach ($payload['sections'] as $section) {
            if ($section['section_key'] === 'facet_details') {
                continue;
            }

            foreach ($section['blocks'] as $block) {
                $this->assertNotContains($block['kind'], ['metric_card', 'table_row']);
                $this->assertStringNotContainsString('facet_anomaly_', (string) $block['block_id']);
            }
        }
    }

    public function test_balanced_profile_has_directory_without_selected_anomalies(): void
    {
        $payload = app(BigFiveReportEngine::class)->generate($this->fixture('context_balanced_no_anomaly.json'));
        $facetSection = collect($payload['sections'])->firstWhere('section_key', 'facet_details');
        $blocks = collect($facetSection['blocks']);

        $this->assertSame([], $payload['engine_decisions']['facet_anomalies']);
        $this->assertCount(0, $blocks->where('kind', 'metric_card'));
        $this->assertCount(0, $blocks->where('kind', 'callout'));
        $this->assertCount(30, $blocks->where('kind', 'table_row'));
    }

    public function test_missing_facet_scores_render_as_not_available_not_zero(): void
    {
        $payload = app(BigFiveReportEngine::class)->generate([
            'locale' => 'zh-CN',
            'scale_code' => 'BIG5_OCEAN',
            'form_code' => 'big5_90',
            'score_vector' => [
                'domains' => [
                    'O' => ['percentile' => 50, 'band' => 'mid', 'gradient_id' => 'o_g3'],
                    'C' => ['percentile' => 50, 'band' => 'mid', 'gradient_id' => 'c_g3'],
                    'E' => ['percentile' => 50, 'band' => 'mid', 'gradient_id' => 'e_g3'],
                    'A' => ['percentile' => 50, 'band' => 'mid', 'gradient_id' => 'a_g3'],
                    'N' => ['percentile' => 50, 'band' => 'mid', 'gradient_id' => 'n_g3'],
                ],
                'facets' => [],
            ],
        ]);

        $facetSection = collect($payload['sections'])->firstWhere('section_key', 'facet_details');
        $rows = collect($facetSection['blocks'])->where('kind', 'table_row');

        $this->assertCount(30, $rows);
        foreach ($rows as $row) {
            $this->assertNull($row['resolved_copy']['percentile']);
            $this->assertSame('not_available', $row['resolved_copy']['band']);
            $this->assertFalse($row['analytics']['has_percentile']);
        }
    }

    public function test_domain_and_report_caps_are_enforced(): void
    {
        $payload = app(BigFiveReportEngine::class)->generate($this->fixture('context_cross_domain_anomaly_canonical.json'));

        $matches = $payload['engine_decisions']['facet_anomalies'];
        $this->assertLessThanOrEqual(6, count($matches));

        $byDomain = [];
        foreach ($matches as $match) {
            $byDomain[$match['domain_code']] = ($byDomain[$match['domain_code']] ?? 0) + 1;
        }

        foreach ($byDomain as $count) {
            $this->assertLessThanOrEqual(2, $count);
        }
    }

    public function test_canonical_n_slice_still_hits_capped_n_precision(): void
    {
        $payload = app(BigFiveReportEngine::class)->generateCanonicalNSlice();

        $ruleIds = array_map(static fn (array $match): string => $match['rule_id'], $payload['engine_decisions']['facet_anomalies']);
        $this->assertContains('n1_high_spike', $ruleIds);
        $this->assertContains('n3_high_spike', $ruleIds);
        $this->assertSame('n_high_x_e_low', data_get($payload, 'engine_decisions.selected_synergies.0.synergy_id'));
    }

    /**
     * @return array<string,mixed>
     */
    private function fixture(string $file): array
    {
        $decoded = json_decode((string) file_get_contents(base_path("tests/Fixtures/big5_engine/facet_contexts/{$file}")), true);

        return is_array($decoded) ? $decoded : [];
    }
}
