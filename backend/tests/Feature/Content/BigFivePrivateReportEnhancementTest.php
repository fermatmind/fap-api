<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\BigFive\ReportEngine\BigFiveReportEngine;
use Tests\TestCase;

final class BigFivePrivateReportEnhancementTest extends TestCase
{
    public function test_quality_d_fixture_drives_tone_composites_facets_norms_and_safe_actions(): void
    {
        $payload = $this->generateFixture('zh-CN');

        $this->assertSame('D', data_get($payload, 'quality.grade'));
        $this->assertSame('low', data_get($payload, 'quality.confidence_mode'));
        $this->assertTrue((bool) data_get($payload, 'quality.prominent_notice'));
        $this->assertSame('tentative', data_get($payload, 'quality.tone_level'));

        $insights = (array) ($payload['composite_insights'] ?? []);
        $this->assertCount(3, $insights);
        foreach ($insights as $insight) {
            $this->assertGreaterThanOrEqual(2, count((array) ($insight['combination'] ?? [])));
            $this->assertNotEmpty($insight['mechanism'] ?? null);
            $this->assertNotEmpty($insight['context_boundary'] ?? null);
            $this->assertSame('low', $insight['confidence'] ?? null);
        }

        $this->assertNotEmpty($payload['facet_deviations'] ?? []);
        foreach ((array) ($payload['facet_deviations'] ?? []) as $deviation) {
            $this->assertGreaterThanOrEqual(35, (int) ($deviation['delta_abs'] ?? 0));
            $this->assertContains($deviation['direction'] ?? null, ['above_domain', 'below_domain']);
        }

        $this->assertSame('provisional', data_get($payload, 'norm_evidence.status'));
        $this->assertTrue((bool) data_get($payload, 'norm_evidence.comparison_allowed'));
        $this->assertFalse((bool) data_get($payload, 'norm_evidence.show_precise_percentiles'));
        $this->assertSame(26000, data_get($payload, 'norm_evidence.sample_n'));

        $actions = $this->selectedActions($payload);
        $this->assertNotEmpty($actions);
        foreach ($actions as $action) {
            $this->assertContains($action['bucket'] ?? null, ['start', 'observe']);
            $this->assertSame('low', $action['difficulty_level'] ?? null);
            $this->assertNotEmpty($action['why_recommended'] ?? null);
            $this->assertNotEmpty($action['completion_signal'] ?? null);
            $this->assertNotEmpty($action['evidence'] ?? null);
        }

        $hero = collect((array) ($payload['sections'] ?? []))->firstWhere('section_key', 'hero_summary');
        $this->assertSame('BigFiveQualityNotice', data_get($hero, 'blocks.0.component'));
    }

    public function test_english_uses_the_same_rule_ids_and_evidence_without_chinese_copy(): void
    {
        $zh = $this->generateFixture('zh-CN');
        $en = $this->generateFixture('en');

        $this->assertSame(
            array_column((array) ($zh['composite_insights'] ?? []), 'rule_id'),
            array_column((array) ($en['composite_insights'] ?? []), 'rule_id'),
        );
        $this->assertSame(
            array_column((array) ($zh['facet_deviations'] ?? []), 'rule_id'),
            array_column((array) ($en['facet_deviations'] ?? []), 'rule_id'),
        );
        $this->assertDoesNotMatchRegularExpression('/[\x{3400}-\x{9FFF}]/u', json_encode($en, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function test_unknown_quality_and_missing_norm_metadata_fail_closed(): void
    {
        $fixture = $this->fixture('zh-CN');
        $fixture['quality']['level'] = 'Z';
        $fixture['meta']['norms'] = [];

        $payload = app(BigFiveReportEngine::class)->generate($fixture);

        $this->assertSame('UNKNOWN', data_get($payload, 'quality.grade'));
        $this->assertSame('unavailable', data_get($payload, 'norm_evidence.status'));
        $this->assertFalse((bool) data_get($payload, 'norm_evidence.comparison_allowed'));
        foreach ($this->selectedActions($payload) as $action) {
            $this->assertSame('observe', $action['bucket'] ?? null);
        }
    }

    public function test_generation_is_deterministic_for_the_fixed_fixture(): void
    {
        $engine = app(BigFiveReportEngine::class);
        $fixture = $this->fixture('zh-CN');

        $this->assertSame($engine->generate($fixture), $engine->generate($fixture));
    }

    public function test_quality_and_norm_state_matrix_is_structured_and_fail_closed(): void
    {
        $expectations = [
            'A' => ['standard', 'precise', false],
            'B' => ['standard', 'precise', false],
            'C' => ['cautious', 'provisional', true],
            'D' => ['low', 'provisional', true],
        ];
        foreach ($expectations as $grade => [$confidence, $normMode, $notice]) {
            $fixture = $this->fixture('zh-CN');
            $fixture['quality']['level'] = $grade;
            $payload = app(BigFiveReportEngine::class)->generate($fixture);
            $this->assertSame($confidence, data_get($payload, 'quality.confidence_mode'));
            $this->assertSame($normMode, data_get($payload, 'quality.norm_mode'));
            $this->assertSame($notice, (bool) data_get($payload, 'quality.prominent_notice'));
        }

        foreach (['exact', 'fallback', 'global'] as $matchType) {
            $fixture = $this->fixture('en');
            $fixture['quality']['level'] = 'A';
            $fixture['meta']['norms']['match_type'] = $matchType;
            $payload = app(BigFiveReportEngine::class)->generate($fixture);
            $this->assertSame($matchType, data_get($payload, 'norm_evidence.match_type'));
            $this->assertSame('calibrated', data_get($payload, 'norm_evidence.status'));
        }
    }

    /** @return array<string,mixed> */
    private function generateFixture(string $locale): array
    {
        return app(BigFiveReportEngine::class)->generate($this->fixture($locale));
    }

    /** @return array<string,mixed> */
    private function fixture(string $locale): array
    {
        $directory = $locale === 'en' ? 'en/' : '';
        $path = base_path("content_packs/BIG5_OCEAN/v2/registry/{$directory}fixtures/quality_d_common_profile.context.json");

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $payload @return list<array<string,mixed>> */
    private function selectedActions(array $payload): array
    {
        $actions = [];
        foreach ((array) data_get($payload, 'action_matrix.scenarios', []) as $scenario) {
            foreach ((array) ($scenario['selected_rules'] ?? []) as $rule) {
                if (is_array($rule)) {
                    $actions[] = $rule;
                }
            }
        }

        return $actions;
    }
}
