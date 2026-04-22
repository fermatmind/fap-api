<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\BigFive\ReportEngine\BigFiveReportEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BigFiveReportEngineSynergyRolloutTest extends TestCase
{
    #[DataProvider('singleSynergyProvider')]
    public function test_single_synergy_renders_primary_only_in_core_portrait(string $fixtureName, string $expectedSynergyId): void
    {
        $payload = app(BigFiveReportEngine::class)->generate($this->fixture($fixtureName));

        $this->assertSame([$expectedSynergyId], $this->selectedSynergyIds($payload));
        $this->assertSame([$expectedSynergyId], $this->sectionSynergyIds($payload, 'core_portrait'));
        $this->assertSame([], $this->sectionSynergyIds($payload, 'action_plan'));
        $this->assertNoSynergyOutsideAllowedSections($payload);

        $block = $this->synergyBlocks($payload, 'core_portrait')[0];
        $this->assertSame(["synergies/{$expectedSynergyId}.json"], $block['provenance']['synergy_refs']);
        foreach (['atomic_refs', 'modifier_refs', 'synergy_refs', 'facet_refs'] as $key) {
            $this->assertIsArray($block['provenance'][$key]);
        }
    }

    public function test_multi_hit_conflict_keeps_two_synergies_and_only_one_stress_activation_match(): void
    {
        $payload = app(BigFiveReportEngine::class)->generate($this->fixture('context_multi_hit_conflict'));

        $this->assertSame(['n_high_x_e_low', 'o_high_x_c_low'], $this->selectedSynergyIds($payload));
        $this->assertSame(['n_high_x_e_low'], $this->sectionSynergyIds($payload, 'core_portrait'));
        $this->assertSame(['o_high_x_c_low'], $this->sectionSynergyIds($payload, 'action_plan'));
        $this->assertCount(1, array_filter(
            $payload['engine_decisions']['selected_synergies'],
            static fn (array $match): bool => ($match['mutex_group'] ?? '') === 'stress_activation'
        ));
        $this->assertNoSynergyOutsideAllowedSections($payload);
    }

    public function test_balanced_profile_renders_no_synergy_blocks(): void
    {
        $payload = app(BigFiveReportEngine::class)->generate($this->fixture('context_balanced_no_synergy'));

        $this->assertSame([], $this->selectedSynergyIds($payload));
        foreach ($payload['sections'] as $section) {
            $this->assertSame([], array_values(array_filter(
                (array) $section['blocks'],
                static fn (array $block): bool => str_starts_with((string) $block['block_uid'], $section['section_key'].'.synergy.')
            )));
        }
    }

    public function test_canonical_n_slice_still_selects_n_high_e_low(): void
    {
        $payload = app(BigFiveReportEngine::class)->generateCanonicalNSlice();

        $this->assertSame(['n_high_x_e_low'], $this->selectedSynergyIds($payload));
        $this->assertSame(['n_high_x_e_low'], $this->sectionSynergyIds($payload, 'core_portrait'));
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
