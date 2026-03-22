<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use Tests\TestCase;

final class MbtiContentInventoryContractTest extends TestCase
{
    private function contentPath(string $file): string
    {
        return base_path('../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3/'.$file);
    }

    /**
     * @return array<string,mixed>
     */
    private function readJson(string $file): array
    {
        $raw = file_get_contents($this->contentPath($file));
        $this->assertIsString($raw, "Unable to read {$file}");

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Invalid JSON in {$file}");

        return $decoded;
    }

    /**
     * @return array<string,mixed>
     */
    private function readFixture(string $file): array
    {
        $path = base_path('tests/Fixtures/'.$file);
        $raw = file_get_contents($path);
        $this->assertIsString($raw, "Unable to read fixture {$file}");

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Invalid JSON in fixture {$file}");

        return $decoded;
    }

    public function test_mbti_inventory_contract_defines_fragment_families_tags_and_section_matrix(): void
    {
        $inventory = $this->readJson('mbti_content_inventory.json');

        $this->assertSame('fap.mbti.content_inventory.v1', $inventory['schema'] ?? null);
        $this->assertSame(1, $inventory['inventory_contract_version'] ?? null);
        $this->assertSame('MBTI.cn-mainland.zh-CN.v0.3', $inventory['pack_id'] ?? null);
        $this->assertSame('CN_MAINLAND.zh-CN', $inventory['cultural_context'] ?? null);
        $this->assertSame('mbti_content_inventory_v1.cn-mainland.zh-CN.2026-03-ce5', $inventory['inventory_fingerprint'] ?? null);
        $this->assertSame('mbti_content_inventory.v1', $inventory['governance_profile'] ?? null);

        $families = array_values(array_filter((array) ($inventory['fragment_families'] ?? []), 'is_array'));
        $familyKeys = array_values(array_filter(array_map(
            static fn (array $family): string => trim((string) ($family['key'] ?? '')),
            $families
        )));
        $familyIndex = [];
        foreach ($families as $family) {
            $familyKey = trim((string) ($family['key'] ?? ''));
            if ($familyKey !== '') {
                $familyIndex[$familyKey] = $family;
            }
        }

        $this->assertEqualsCanonicalizing([
            'explainability_fragment',
            'boundary_fragment',
            'stress_fragment',
            'recovery_fragment',
            'scene_fragment',
            'work_fragment',
            'relationship_fragment',
            'action_fragment',
            'watchout_fragment',
            'recommendation_fragment',
            'cta_bundle_fragment',
            'tone_fragment',
            'revisit_fragment',
            'adaptive_response_fragment',
        ], $familyKeys);
        $this->assertContains('misunderstanding_fix', data_get($familyIndex, 'explainability_fragment.block_kinds', []));
        $this->assertContains('stability_reframe', data_get($familyIndex, 'explainability_fragment.block_kinds', []));
        $this->assertContains('action_experiment', data_get($familyIndex, 'action_fragment.block_kinds', []));
        $this->assertContains('watchout_overextension', data_get($familyIndex, 'watchout_fragment.block_kinds', []));
        $this->assertContains('work_scene_execution', data_get($familyIndex, 'work_fragment.block_kinds', []));
        $this->assertContains('relationship_bridge', data_get($familyIndex, 'relationship_fragment.block_kinds', []));
        $this->assertContains('recovery_reset', data_get($familyIndex, 'recovery_fragment.block_kinds', []));
        $this->assertContains('revisit_resume', data_get($familyIndex, 'revisit_fragment.block_kinds', []));
        $this->assertContains('adaptive_retry', data_get($familyIndex, 'adaptive_response_fragment.block_kinds', []));
        $this->assertContains('recommendation_career_next_step', data_get($familyIndex, 'recommendation_fragment.block_kinds', []));
        $this->assertContains('recommendation_relationship_repair', data_get($familyIndex, 'recommendation_fragment.block_kinds', []));
        $this->assertContains('cta_bundle_career', data_get($familyIndex, 'cta_bundle_fragment.block_kinds', []));
        $this->assertContains('cta_bundle_revisit', data_get($familyIndex, 'cta_bundle_fragment.block_kinds', []));

        $selectionTagSchema = is_array($inventory['selection_tag_schema'] ?? null) ? $inventory['selection_tag_schema'] : [];
        foreach ([
            'section_key',
            'block_family',
            'axis_band',
            'boundary_flag',
            'scene_key',
            'intent_cluster',
            'memory_state',
            'adaptive_state',
            'cross_assessment_key',
            'tone_mode',
            'access_tier',
            'locale_scope',
            'evidence_ref',
            'cta_intent',
            'cta_softness_mode',
            'cta_entry_reason',
        ] as $tagKey) {
            $this->assertArrayHasKey($tagKey, $selectionTagSchema);
            $this->assertNotSame('', trim((string) ($selectionTagSchema[$tagKey]['type'] ?? '')));
        }
        $this->assertSame(
            ['supportive', 'direct', 'reflective', 'stabilizing', 'low_pressure'],
            data_get($selectionTagSchema, 'tone_mode.values')
        );

        $matrix = array_values(array_filter((array) ($inventory['section_family_matrix'] ?? []), 'is_array'));
        $matrixIndex = [];
        foreach ($matrix as $row) {
            $sectionKey = trim((string) ($row['section_key'] ?? ''));
            if ($sectionKey !== '') {
                $matrixIndex[$sectionKey] = $row;
            }
        }

        foreach ([
            'overview',
            'trait_overview',
            'traits.why_this_type',
            'growth.summary',
            'growth.stability_confidence',
            'traits.adjacent_type_contrast',
            'growth.next_actions',
            'growth.watchouts',
            'career.summary',
            'career.next_step',
            'career.work_experiments',
            'relationships.summary',
            'relationships.try_this_week',
            'recommendation.surface',
            'cta.surface',
        ] as $sectionKey) {
            $this->assertArrayHasKey($sectionKey, $matrixIndex);
            $this->assertNotSame('', trim((string) ($matrixIndex[$sectionKey]['primary_family'] ?? '')));
        }

        $this->assertSame('scene_fragment', $matrixIndex['overview']['primary_family'] ?? null);
        $this->assertSame('explainability_fragment', $matrixIndex['traits.why_this_type']['primary_family'] ?? null);
        $this->assertContains('explainability_fragment', $matrixIndex['growth.stability_confidence']['secondary_families'] ?? []);
        $this->assertContains('tone_fragment', $matrixIndex['growth.stability_confidence']['secondary_families'] ?? []);
        $this->assertContains('tone_fragment', $matrixIndex['traits.adjacent_type_contrast']['secondary_families'] ?? []);
        $this->assertContains('tone_fragment', $matrixIndex['growth.next_actions']['secondary_families'] ?? []);
        $this->assertContains('tone_fragment', $matrixIndex['growth.watchouts']['secondary_families'] ?? []);
        $this->assertContains('tone_fragment', $matrixIndex['career.next_step']['secondary_families'] ?? []);
        $this->assertContains('tone_fragment', $matrixIndex['relationships.try_this_week']['secondary_families'] ?? []);
        $this->assertSame('action_fragment', $matrixIndex['growth.next_actions']['primary_family'] ?? null);
        $this->assertSame('cta_bundle_fragment', $matrixIndex['cta.surface']['primary_family'] ?? null);
    }

    public function test_compiled_inventory_artifact_matches_expected_summary_contract(): void
    {
        $this->artisan('content:lint --pack=MBTI.cn-mainland.zh-CN.v0.3')->assertExitCode(0);
        $this->artisan('content:compile --pack=MBTI.cn-mainland.zh-CN.v0.3')->assertExitCode(0);

        $compiled = $this->readJson('compiled/inventory.spec.json');
        $compiledManifest = $this->readJson('compiled/manifest.json');
        $fixture = $this->readFixture('mbti_content_inventory_snapshot_expected.json');

        $actual = [
            'inventory_contract_version' => $compiled['inventory_contract_version'] ?? null,
            'inventory_fingerprint' => $compiled['inventory_fingerprint'] ?? null,
            'governance_profile' => $compiled['governance_profile'] ?? null,
            'fragment_family_keys' => data_get($compiled, 'summary.fragment_family_keys', []),
            'selection_tag_keys' => data_get($compiled, 'summary.selection_tag_keys', []),
            'section_family_keys' => data_get($compiled, 'summary.section_family_keys', []),
        ];

        $this->assertSame($fixture, $actual);
        $this->assertContains('inventory.spec.json', (array) ($compiledManifest['files'] ?? []));
    }
}
