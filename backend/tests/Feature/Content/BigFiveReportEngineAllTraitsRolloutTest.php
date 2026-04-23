<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\BigFive\ReportEngine\BigFiveReportEngine;
use Tests\TestCase;

final class BigFiveReportEngineAllTraitsRolloutTest extends TestCase
{
    public function test_mixed_profiles_generate_stable_trait_aware_sections(): void
    {
        foreach ($this->profiles() as $profileId => $context) {
            $payload = app(BigFiveReportEngine::class)->generate($context);

            $this->assertSame('fap.big5.report.v1', $payload['schema_version'], $profileId);
            $this->assertSame([
                'hero_summary',
                'domains_overview',
                'domain_deep_dive',
                'facet_details',
                'core_portrait',
                'norms_comparison',
                'action_plan',
                'methodology_and_access',
            ], array_map(static fn (array $section): string => (string) $section['section_key'], $payload['sections']));

            foreach (['hero_summary', 'domains_overview', 'domain_deep_dive', 'core_portrait', 'norms_comparison'] as $sectionKey) {
                $section = collect($payload['sections'])->firstWhere('section_key', $sectionKey);
                $this->assertSame('populated', $section['status'], "{$profileId}: {$sectionKey}");
                $this->assertCount(5, array_filter(
                    (array) $section['blocks'],
                    static fn (array $block): bool => ($block['kind'] ?? '') === 'trait_atomic'
                ), "{$profileId}: {$sectionKey}");
            }

            $actionPlan = collect($payload['sections'])->firstWhere('section_key', 'action_plan');
            $this->assertSame('populated', $actionPlan['status'], "{$profileId}: action_plan");
            $this->assertSame('action_matrix_intro_v1', $actionPlan['blocks'][0]['block_id'], "{$profileId}: action_plan");
        }
    }

    public function test_pr2_trait_rollout_still_keeps_balanced_profiles_without_synergy_or_facet_anomalies(): void
    {
        $payload = app(BigFiveReportEngine::class)->generate($this->profiles()['balanced']);

        $this->assertSame([], $payload['engine_decisions']['selected_synergies']);
        $this->assertSame([], $payload['engine_decisions']['facet_anomalies']);
        $this->assertSame('scenario_bound_action_matrix_pr3c', data_get($payload, 'render_hints.limited_rollouts.action_rule_scope'));
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function profiles(): array
    {
        return [
            'open_high' => $this->context(['O' => [86, 'high', 'o_g5'], 'C' => [52, 'mid', 'c_g3'], 'E' => [48, 'mid', 'e_g3'], 'A' => [57, 'mid', 'a_g3'], 'N' => [35, 'low', 'n_g2']]),
            'c_low' => $this->context(['O' => [55, 'mid', 'o_g3'], 'C' => [18, 'low', 'c_g1'], 'E' => [42, 'mid', 'e_g3'], 'A' => [62, 'high', 'a_g4'], 'N' => [45, 'mid', 'n_g3']]),
            'e_high_a_low' => $this->context(['O' => [51, 'mid', 'o_g3'], 'C' => [65, 'high', 'c_g4'], 'E' => [83, 'high', 'e_g5'], 'A' => [24, 'low', 'a_g2'], 'N' => [30, 'low', 'n_g2']]),
            'balanced' => $this->context(['O' => [50, 'mid', 'o_g3'], 'C' => [50, 'mid', 'c_g3'], 'E' => [50, 'mid', 'e_g3'], 'A' => [50, 'mid', 'a_g3'], 'N' => [10, 'low', 'n_g1']]),
        ];
    }

    /**
     * @param  array<string,array{0:int,1:string,2:string}>  $domains
     * @return array<string,mixed>
     */
    private function context(array $domains): array
    {
        $mapped = [];
        foreach ($domains as $traitCode => [$percentile, $band, $gradientId]) {
            $mapped[$traitCode] = [
                'percentile' => $percentile,
                'band' => $band,
                'gradient_id' => $gradientId,
            ];
        }

        return [
            'locale' => 'zh-CN',
            'scale_code' => 'BIG5_OCEAN',
            'form_code' => 'big5_90',
            'score_vector' => [
                'domains' => $mapped,
                'facets' => [],
            ],
        ];
    }
}
