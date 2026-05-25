<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Content\ClinicalComboPackLoader;
use App\Services\Report\ClinicalCombo\ClinicalComboBlockSelector;
use App\Services\Report\ClinicalCombo68ReportComposer;
use App\Services\Report\ReportAccess;
use Tests\TestCase;

final class ResultEnParity04ClinicalComboEnPaidTest extends TestCase
{
    public function test_clinical_combo_paid_blocks_have_english_counterparts_for_known_missing_keys(): void
    {
        /** @var ClinicalComboPackLoader $loader */
        $loader = app(ClinicalComboPackLoader::class);
        $blocks = $loader->loadBlocks('en', 'v1');
        $ids = array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['block_id'] ?? ''),
            $blocks
        )));

        foreach ($this->requiredEnglishPaidBlockIds() as $requiredId) {
            $this->assertContains($requiredId, $ids, $requiredId);
        }

        $englishPaidText = json_encode(
            array_values(array_filter(
                $blocks,
                static fn (array $row): bool => ($row['access_level'] ?? null) === 'paid'
            )),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $this->assertDoesNotMatchRegularExpression('/[\x{4e00}-\x{9fff}]/u', $englishPaidText);
    }

    public function test_clinical_combo_selector_fails_closed_instead_of_using_zh_for_english(): void
    {
        /** @var ClinicalComboBlockSelector $selector */
        $selector = app(ClinicalComboBlockSelector::class);

        $selected = $selector->select(
            allBlocks: [[
                'block_id' => 'paid_action_depression_14d_zh',
                'section' => 'action_plan',
                'locale' => 'zh-CN',
                'access_level' => 'paid',
                'priority' => 200,
            ]],
            sectionKey: 'action_plan',
            locale: 'en',
            allowedAccessLevels: ['paid'],
            minBlocks: 0,
            maxBlocks: 3,
            context: [],
            policy: []
        );

        $this->assertSame([], $selected);
    }

    public function test_english_full_report_selects_specific_paid_blocks_without_chinese_fallback(): void
    {
        $payload = app(ClinicalCombo68ReportComposer::class)->composeVariant(
            $this->attempt('en-US'),
            $this->scoredResult(),
            ReportAccess::VARIANT_FULL,
            [
                'report_access_level' => ReportAccess::REPORT_ACCESS_FULL,
            ]
        );

        $this->assertTrue((bool) ($payload['ok'] ?? false));
        $report = (array) ($payload['report'] ?? []);
        $this->assertSame('en', $report['locale'] ?? null);

        $ids = $this->reportBlockIds($report);
        foreach ([
            'paid_perf_cm_mistakes_en',
            'paid_action_depression_14d_en',
            'paid_action_anxiety_14d_en',
            'paid_action_ocd_erp_start_en',
            'paid_action_perfectionism_14d_en',
            'paid_action_burnout_en',
        ] as $expectedId) {
            $this->assertContains($expectedId, $ids, $expectedId);
        }

        $serialized = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertDoesNotMatchRegularExpression('/[\x{4e00}-\x{9fff}]/u', $serialized);
        $this->assertStringContainsString('not a diagnosis', strtolower($serialized));
        $this->assertStringContainsString('not medical advice', strtolower($serialized));

        foreach (['cure', 'guarantee', 'clinical diagnosis', 'treatment plan'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($serialized), $forbidden);
        }
    }

    public function test_generated_clinical_combo_inventory_json_parses(): void
    {
        $path = base_path('docs/seo/generated/result-en-parity-04-clinical-combo-en-paid.v1.json');
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('RESULT-EN-PARITY-04', $decoded['pr_id'] ?? null);
        $this->assertSame('clinical_combo_68', $decoded['family'] ?? null);
        $this->assertSame('fail_closed_no_zh_paid_block_fallback', $decoded['english_runtime_policy'] ?? null);
        $this->assertContains('paid_action_anxiety_14d.en', $decoded['fixed_keys'] ?? []);
        $this->assertContains('paid_perf_cm_mistakes.en', $decoded['fixed_keys'] ?? []);
    }

    /**
     * @return list<string>
     */
    private function requiredEnglishPaidBlockIds(): array
    {
        return [
            'paid_action_anxiety_14d_en',
            'paid_action_burnout_en',
            'paid_action_depression_14d_en',
            'paid_action_ocd_erp_start_en',
            'paid_action_perfectionism_14d_en',
            'paid_perf_cm_mistakes_en',
            'paid_perf_da_doubts_en',
            'paid_perf_org_order_en',
            'paid_perf_pe_parental_en',
            'paid_perf_ps_standards_en',
        ];
    }

    private function attempt(string $locale): Attempt
    {
        return new Attempt([
            'id' => 'attempt-clinical-combo-en-paid',
            'scale_code' => 'CLINICAL_COMBO_68',
            'locale' => $locale,
            'region' => 'US',
            'dir_version' => 'v1',
        ]);
    }

    private function scoredResult(): Result
    {
        return new Result([
            'scale_code' => 'CLINICAL_COMBO_68',
            'result_json' => [
                'normed_json' => [
                    'scale_code' => 'CLINICAL_COMBO_68',
                    'quality' => [
                        'crisis_alert' => false,
                        'crisis_reasons' => [],
                    ],
                    'scores' => [
                        'depression' => ['level' => 'severe'],
                        'anxiety' => ['level' => 'severe'],
                        'ocd' => ['level' => 'severe'],
                        'stress' => ['level' => 'high'],
                        'resilience' => ['level' => 'low'],
                        'perfectionism' => ['level' => 'extreme'],
                    ],
                    'facts' => [
                        'function_impairment_level' => 'moderate',
                    ],
                    'report_tags' => [
                        'trait:mistake_concern_dominant',
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $report
     * @return list<string>
     */
    private function reportBlockIds(array $report): array
    {
        $ids = [];
        foreach ((array) ($report['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }
            foreach ((array) ($section['blocks'] ?? []) as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $id = trim((string) ($block['id'] ?? ''));
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }
}
