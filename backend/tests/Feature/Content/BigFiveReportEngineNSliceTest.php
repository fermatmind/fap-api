<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\BigFive\ReportEngine\BigFiveReportEngine;
use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use Tests\TestCase;

final class BigFiveReportEngineNSliceTest extends TestCase
{
    public function test_it_generates_the_canonical_n_slice_payload_snapshot(): void
    {
        $payload = app(BigFiveReportEngine::class)->generateCanonicalNSlice();

        $this->assertSame('fap.big5.report.v1', $payload['schema_version']);
        $this->assertSame(['N', 'O'], $payload['engine_decisions']['dominant_traits']);
        $this->assertSame('n_high_x_e_low', data_get($payload, 'engine_decisions.selected_synergies.0.synergy_id'));
        $this->assertSame(
            ['c1_high_with_c_low', 'c5_low_with_c_low', 'n1_high_spike', 'n3_high_spike'],
            array_map(static fn (array $match): string => (string) $match['rule_id'], $payload['engine_decisions']['facet_anomalies'])
        );
        $this->assertCount(8, $payload['sections']);
        $this->assertSame('populated', data_get($payload, 'sections.1.status'));
        $this->assertSame('stress_recovery', $payload['action_matrix']['top_priority_scenario']);
        $this->assertCount(4, data_get($payload, 'action_matrix.scenarios.0.selected_rules'));

        $expected = json_decode((string) file_get_contents(base_path('tests/Fixtures/big5_engine/expected_canonical_n_slice_payload.json')), true);
        $this->assertSame($expected, $payload);
    }

    public function test_it_preserves_v1_block_contract_and_provenance_shape(): void
    {
        $payload = app(BigFiveReportEngine::class)->generateCanonicalNSlice();

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

        foreach ($payload['sections'] as $section) {
            foreach ((array) ($section['blocks'] ?? []) as $block) {
                foreach (['block_uid', 'kind', 'component', 'block_id', 'resolved_copy', 'provenance', 'analytics'] as $requiredKey) {
                    $this->assertArrayHasKey($requiredKey, $block);
                }

                $this->assertIsArray($block['resolved_copy']);
                $this->assertIsArray($block['analytics']);

                foreach (['atomic_refs', 'modifier_refs', 'synergy_refs', 'facet_refs', 'action_refs'] as $provenanceKey) {
                    $this->assertArrayHasKey($provenanceKey, $block['provenance']);
                    $this->assertIsArray($block['provenance'][$provenanceKey]);
                }
            }
        }
    }

    public function test_registry_fixture_declares_expected_hits_that_match_engine_output(): void
    {
        $registry = app(RegistryLoader::class)->load();
        $fixture = (array) data_get($registry, 'fixtures.canonical_n_slice_sensitive_independent');
        $payload = app(BigFiveReportEngine::class)->generate($fixture);

        $this->assertSame(data_get($fixture, 'expected_hits.selected_synergies'), [
            data_get($payload, 'engine_decisions.selected_synergies.0.synergy_id'),
        ]);
        $this->assertSame(
            data_get($fixture, 'expected_hits.facet_anomalies'),
            array_map(static fn (array $match): string => (string) $match['rule_id'], $payload['engine_decisions']['facet_anomalies'])
        );
    }

    public function test_registry_keeps_pr3a_synergy_rollout_and_pr3b_facet_scope(): void
    {
        $registry = app(RegistryLoader::class)->load();

        $this->assertSame(['O', 'C', 'E', 'A', 'N'], array_keys((array) $registry['atomic']));
        $this->assertSame(['O', 'C', 'E', 'A', 'N'], array_keys((array) $registry['modifiers']));
        $this->assertSame(['O', 'C', 'E', 'A', 'N'], array_keys((array) $registry['facet_glossary']));
        $this->assertSame(['O', 'C', 'E', 'A', 'N'], array_keys((array) $registry['facet_precision']));
        $this->assertSame(['n_high_x_e_low', 'o_high_x_c_low', 'o_high_x_n_high', 'c_high_x_n_high', 'e_high_x_a_low'], array_keys((array) $registry['synergies']));
        $this->assertSame(['workplace', 'relationships', 'stress_recovery', 'personal_growth'], array_keys((array) $registry['action_rules']));
    }
}
