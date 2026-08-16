<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\BigFive\ReportEngine\BigFiveReportEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BigFiveReportEngineSynergyRolloutTest extends TestCase
{
    #[DataProvider('singleSynergyProvider')]
    public function test_specific_synergy_leads_three_composites_in_core_portrait(string $fixtureName, string $expectedSynergyId): void
    {
        $fixture = $this->fixture($fixtureName);
        $fixture['quality'] = ['level' => 'A'];
        $payload = app(BigFiveReportEngine::class)->generate($fixture);

        $this->assertSame($expectedSynergyId, $this->selectedSynergyIds($payload)[0]);
        $this->assertCount(3, $this->selectedSynergyIds($payload));
        $this->assertSame(1, data_get($payload, 'engine_decisions.selected_synergies.0.render_rank'));
        $this->assertSame('core_portrait', data_get($payload, 'engine_decisions.selected_synergies.0.render_section'));
        $this->assertSame('composite_1', data_get($payload, 'engine_decisions.selected_synergies.0.render_slot'));
        $this->assertSame([
            [
                'section_key' => 'core_portrait',
                'slot' => 'composite_1',
                'kind' => 'callout',
            ],
        ], data_get($payload, 'engine_decisions.selected_synergies.0.section_targets'));
        $this->assertSame($this->selectedSynergyIds($payload), $this->sectionSynergyIds($payload, 'core_portrait'));
        $this->assertSame([], $this->sectionSynergyIds($payload, 'action_plan'));
        $this->assertNoSynergyOutsideAllowedSections($payload);

        $block = $this->synergyBlocks($payload, 'core_portrait')[0];
        $this->assertSame(["synergies/{$expectedSynergyId}.json"], $block['provenance']['synergy_refs']);
        foreach (['atomic_refs', 'modifier_refs', 'synergy_refs', 'facet_refs'] as $key) {
            $this->assertIsArray($block['provenance'][$key]);
        }
    }

    public function test_multi_hit_conflict_keeps_three_composites_and_only_one_stress_activation_match(): void
    {
        $fixture = $this->fixture('context_multi_hit_conflict');
        $fixture['quality'] = ['level' => 'A'];
        $payload = app(BigFiveReportEngine::class)->generate($fixture);

        $this->assertSame(['n_high_x_e_low', 'o_x_e_exploration_expression', 'c_x_n_load_balance'], $this->selectedSynergyIds($payload));
        $this->assertSame([1, 2, 3], array_map(
            static fn (array $match): int => (int) $match['render_rank'],
            $payload['engine_decisions']['selected_synergies']
        ));
        $this->assertSame(['core_portrait', 'core_portrait', 'core_portrait'], array_map(
            static fn (array $match): string => (string) $match['render_section'],
            $payload['engine_decisions']['selected_synergies']
        ));
        $this->assertSame($this->selectedSynergyIds($payload), $this->sectionSynergyIds($payload, 'core_portrait'));
        $this->assertSame([], $this->sectionSynergyIds($payload, 'action_plan'));
        $this->assertCount(1, array_filter(
            $payload['engine_decisions']['selected_synergies'],
            static fn (array $match): bool => ($match['mutex_group'] ?? '') === 'stress_activation'
        ));
        $this->assertNoSynergyOutsideAllowedSections($payload);
    }

    public function test_balanced_profile_renders_three_baseline_composites(): void
    {
        $fixture = $this->fixture('context_balanced_no_synergy');
        $fixture['quality'] = ['level' => 'A'];
        $payload = app(BigFiveReportEngine::class)->generate($fixture);

        $this->assertCount(3, $this->selectedSynergyIds($payload));
        $this->assertSame($this->selectedSynergyIds($payload), $this->sectionSynergyIds($payload, 'core_portrait'));
        $this->assertNoSynergyOutsideAllowedSections($payload);
    }

    public function test_canonical_n_slice_still_selects_n_high_e_low(): void
    {
        $payload = app(BigFiveReportEngine::class)->generateCanonicalNSlice();

        $this->assertSame('n_high_x_e_low', $this->selectedSynergyIds($payload)[0]);
        $this->assertCount(3, $this->selectedSynergyIds($payload));
        $this->assertSame($this->selectedSynergyIds($payload), $this->sectionSynergyIds($payload, 'core_portrait'));
        $this->assertSame([], $this->sectionSynergyIds($payload, 'action_plan'));
    }

    /**
     * @return iterable<string,array{0:string,1:string}>
     */
    public static function singleSynergyProvider(): iterable
    {
        yield 'n_high_e_low' => ['context_n_high_e_low', 'n_high_x_e_low'];
        yield 'o_high_c_low' => ['context_o_high_c_low', 'o_high_x_c_low'];
        yield 'o_high_n_high' => ['context_o_high_n_high', 'o_high_x_n_high'];
        yield 'c_high_n_high' => ['context_c_high_n_high', 'c_high_x_n_high'];
        yield 'e_high_a_low' => ['context_e_high_a_low', 'e_high_x_a_low'];
    }

    /**
     * @return array<string,mixed>
     */
    private function fixture(string $fixtureName): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/big5_engine/contexts/{$fixtureName}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function selectedSynergyIds(array $payload): array
    {
        return array_map(static fn (array $match): string => (string) $match['synergy_id'], $payload['engine_decisions']['selected_synergies']);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function sectionSynergyIds(array $payload, string $sectionKey): array
    {
        return array_map(
            static fn (array $block): string => (string) $block['analytics']['synergy_id'],
            $this->synergyBlocks($payload, $sectionKey)
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<array<string,mixed>>
     */
    private function synergyBlocks(array $payload, string $sectionKey): array
    {
        $section = collect($payload['sections'])->firstWhere('section_key', $sectionKey);

        return array_values(array_filter(
            (array) ($section['blocks'] ?? []),
            static fn (array $block): bool => str_starts_with((string) $block['block_uid'], "{$sectionKey}.synergy.")
        ));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function assertNoSynergyOutsideAllowedSections(array $payload): void
    {
        foreach ($payload['sections'] as $section) {
            if (in_array($section['section_key'], ['core_portrait', 'action_plan'], true)) {
                continue;
            }
            $this->assertSame([], $this->synergyBlocks($payload, (string) $section['section_key']));
        }
    }
}
