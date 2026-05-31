<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use Tests\TestCase;

final class EqSjt16ContentPackSkeletonTest extends TestCase
{
    public function test_eq_sjt_16_content_pack_skeleton_is_non_operational_and_claim_safe(): void
    {
        $root = base_path('content_packs/EQ_SJT_16/v1');

        $module = $this->jsonFile($root.'/raw/module_contract.json');
        $domains = $this->jsonFile($root.'/raw/domains.json');
        $itemSchema = $this->jsonFile($root.'/raw/item_schema.json');
        $rubric = $this->jsonFile($root.'/raw/scoring_rubric_draft.json');
        $items = $this->jsonFile($root.'/raw/items.json');
        $goldenCases = $this->jsonFile($root.'/raw/golden_cases.json');
        $manifest = $this->jsonFile($root.'/compiled/manifest.json');

        $this->assertSame('EQ_SJT_16', $module['module_code'] ?? null);
        $this->assertSame('planned', data_get($module, 'availability.status'));
        $this->assertFalse((bool) data_get($module, 'availability.runtime_available', true));
        $this->assertFalse((bool) data_get($module, 'availability.take_entry_clickable', true));
        $this->assertFalse((bool) data_get($module, 'availability.integrated_report_visible', true));
        $this->assertFalse((bool) data_get($module, 'availability.stable_validation_claim_allowed', true));

        $domainRows = (array) ($domains['domains'] ?? []);
        $this->assertCount(8, $domainRows);
        $this->assertSame(16, (int) ($domains['total_items'] ?? 0));
        $this->assertSame(16, array_sum(array_map(static fn (array $domain): int => (int) ($domain['planned_items'] ?? 0), $domainRows)));
        $this->assertSame([
            'emotion_cue_reading',
            'pressure_pause',
            'feedback_response',
            'conflict_deescalation',
            'empathic_response',
            'boundary_setting',
            'relationship_repair',
            'constructive_influence',
        ], array_column($domainRows, 'code'));

        foreach ($domainRows as $domain) {
            $this->assertSame(2, (int) ($domain['planned_items'] ?? 0));
            $this->assertIsArray($domain['strategy_score_inputs'] ?? null);
            $this->assertNotSame('', trim((string) data_get($domain, 'label.en')));
            $this->assertNotSame('', trim((string) data_get($domain, 'label.zh-CN')));
        }

        $this->assertSame(16, (int) ($itemSchema['item_count'] ?? 0));
        $this->assertSame(4, (int) data_get($itemSchema, 'item_format.response_options.count'));
        $this->assertSame(0, (int) data_get($itemSchema, 'item_format.scoring.partial_credit_min'));
        $this->assertSame(3, (int) data_get($itemSchema, 'item_format.scoring.partial_credit_max'));
        $this->assertTrue((bool) data_get($itemSchema, 'non_operational_placeholder.items_included', false));
        $this->assertFalse((bool) data_get($itemSchema, 'non_operational_placeholder.runtime_available', true));

        $this->assertSame('likely_response', data_get($rubric, 'decision.v1_response_prompt'));
        $this->assertSame('defer_to_v2', data_get($rubric, 'decision.best_vs_likely_split'));
        $this->assertSame([0, 1, 2, 3], array_map(static fn (array $row): int => (int) ($row['score'] ?? -1), (array) ($rubric['partial_credit'] ?? [])));
        $this->assertSame(['CUE', 'PAUSE', 'EMP', 'BND', 'REPAIR', 'INFL'], array_column((array) ($rubric['strategy_scores'] ?? []), 'code'));

        $itemRows = (array) ($items['items'] ?? []);
        $this->assertCount(16, $itemRows);
        $this->assertSame(16, count(array_unique(array_column($itemRows, 'item_id'))));
        foreach ($itemRows as $item) {
            $this->assertSame('scoring_fixture', $item['review_state'] ?? null);
            $this->assertCount(4, (array) ($item['response_options'] ?? []));
            $this->assertFalse((bool) ($items['runtime_available'] ?? true));
        }

        $this->assertGreaterThanOrEqual(3, count((array) ($goldenCases['cases'] ?? [])));

        $this->assertSame('scorer_ready_not_runtime', $manifest['status'] ?? null);
        $this->assertFalse((bool) ($manifest['runtime_available'] ?? true));

        $combined = json_encode([$module, $domains, $itemSchema, $rubric, $items, $goldenCases, $manifest], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($combined);

        $this->assertSame([
            'true_ability_claim',
            'msceit_equivalence_claim',
            'certified_ei_claim',
            'hiring_fit_claim',
            'clinical_use_claim',
            'job_performance_prediction_claim',
        ], (array) ($module['no_go_claims'] ?? []));

        foreach ([
            'ability test',
            'MSCEIT-like',
            'certified emotional intelligence',
            'hiring suitable',
            'clinical assessment',
            'predicts job performance',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $combined);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonFile(string $path): array
    {
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, $path);

        return $decoded;
    }
}
