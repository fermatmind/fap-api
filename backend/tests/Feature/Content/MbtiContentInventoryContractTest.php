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
        $this->assertSame('mbti_content_inventory_v1.cn-mainland.zh-CN.2026-03', $inventory['inventory_fingerprint'] ?? null);
        $this->assertSame('mbti_content_inventory.v1', $inventory['governance_profile'] ?? null);

        $families = array_values(array_filter((array) ($inventory['fragment_families'] ?? []), 'is_array'));
        $familyKeys = array_values(array_filter(array_map(
            static fn (array $family): string => trim((string) ($family['key'] ?? '')),
            $families
        )));

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
        ] as $tagKey) {
            $this->assertArrayHasKey($tagKey, $selectionTagSchema);
            $this->assertNotSame('', trim((string) ($selectionTagSchema[$tagKey]['type'] ?? '')));
        }

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
