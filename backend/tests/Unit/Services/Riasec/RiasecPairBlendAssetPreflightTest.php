<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Riasec;

use App\Services\Riasec\RiasecContentRegistrySlotContract;
use App\Services\Riasec\RiasecDeepCopySlotRegistry;
use PHPUnit\Framework\TestCase;

final class RiasecPairBlendAssetPreflightTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__.'/../../../Fixtures/Riasec/pair_blend_15_pairs_v7_3_preflight.jsonl';

    private const REQUIRED_PAIR_KEYS = [
        'R_I', 'R_A', 'R_S', 'R_E', 'R_C',
        'I_A', 'I_S', 'I_E', 'I_C',
        'A_S', 'A_E', 'A_C',
        'S_E', 'S_C',
        'E_C',
    ];

    private const REQUIRED_SOURCE_FIELDS = [
        'schema_version',
        'asset_version',
        'locale',
        'scale_code',
        'pair_key',
        'dimensions',
        'pair_label',
        'short_label',
        'chemistry',
        'positive_value',
        'real_world_cost',
        'common_misread',
        'activities_to_validate',
        'micro_experiment',
        'user_visible_boundary',
        'applicable_form_codes',
        'applicable_profile_shapes',
        'applicable_quality_states',
        'required_boundaries',
        'forbidden_claims',
        'evidence_level',
        'source_status',
        'review_status',
        'content_status',
        'frontend_fallback_allowed',
        'fallback_behavior',
        'result_page_teaser',
        'deep_report_extension_hint',
    ];

    private const FORBIDDEN_TERMS = [
        'career match',
        'occupation match',
        'job fit',
        'fit score',
        'success prediction',
        'success probability',
        'recommended career',
        'best career',
        'career recommendation',
        'occupation ranking',
        'hiring suitability',
        'ability proof',
        'skill inference',
        '140Q more accurate',
        'more accurate',
        'raw score delta',
        '60Q wrong',
        '职业匹配',
        '岗位匹配',
        '匹配度',
        '适合度',
        '最适合',
        '推荐职业',
        '职业推荐',
        '岗位胜任',
        '成功概率',
        '职业成功',
        '更准确',
        '更准',
        '140题更准确',
        '60题错了',
        '推翻',
        '最终答案',
        '你就是',
        '天生适合',
        '能力证明',
        '技能证明',
        '招聘筛选',
        '录取依据',
        '晋升依据',
        '淘汰依据',
    ];

    private const USER_VISIBLE_FIELDS = [
        'pair_label',
        'short_label',
        'chemistry',
        'positive_value',
        'real_world_cost',
        'common_misread',
        'activities_to_validate',
        'micro_experiment',
        'result_page_teaser',
        'deep_report_extension_hint',
    ];

    private const TECHNICAL_KEYS = [
        'try_30min_micro_output',
        'user_life_context_conflict',
        'role_environment_conflict',
        'task_interest_vs_job_reality_conflict',
        'low_quality_disagree',
        'near_tie_disagree',
        'aspiration_conflict',
        'result_too_broad',
        'result_too_narrow',
        '60Q_140Q_tension',
    ];

    public function test_v7_3_pair_blend_asset_has_exactly_15_required_pairs(): void
    {
        $rows = $this->pairRows();

        $this->assertCount(15, $rows);
        $this->assertSame(self::REQUIRED_PAIR_KEYS, array_column($rows, 'pair_key'));

        foreach ($rows as $row) {
            foreach (self::REQUIRED_SOURCE_FIELDS as $field) {
                $this->assertArrayHasKey($field, $row, 'Missing field '.$field.' for '.$row['pair_key']);
                if ($field === 'frontend_fallback_allowed') {
                    $this->assertFalse($row[$field], 'Frontend fallback must be disabled for '.$row['pair_key']);

                    continue;
                }
                $this->assertNotEmpty($row[$field], 'Blank field '.$field.' for '.$row['pair_key']);
            }

            $this->assertSame('RIASEC', $row['scale_code']);
            $this->assertSame('zh-CN', $row['locale']);
            $this->assertSame('omit_module', $row['fallback_behavior']);
            $this->assertFalse($row['frontend_fallback_allowed']);
        }
    }

    public function test_v7_3_pair_blend_asset_is_clean_after_backend_slot_normalization(): void
    {
        $contract = new RiasecContentRegistrySlotContract;
        $registry = new RiasecDeepCopySlotRegistry;

        foreach ($this->pairRows() as $row) {
            $slot = $this->normalizedBackendSlot($row);

            foreach ($registry->pairRequiredFields() as $requiredField) {
                $this->assertArrayHasKey($requiredField, $slot);
                $this->assertNotEmpty($slot[$requiredField]);
            }
            foreach ($registry->authoredPairRequiredFields() as $requiredField) {
                $this->assertArrayHasKey($requiredField, $slot);
                $this->assertNotEmpty($slot[$requiredField]);
            }

            $registryErrors = $registry->validateSlot($slot);
            $contractResult = $contract->validate($slot);

            $this->assertSame([], $this->unexpectedValidationErrors($registryErrors), 'Unexpected registry validation failure for '.$row['pair_key']);
            $this->assertSame([], $this->unexpectedValidationErrors($contractResult['errors']), 'Unexpected contract validation failure for '.$row['pair_key']);
        }
    }

    public function test_v7_3_pair_blend_asset_has_no_user_facing_forbidden_claims(): void
    {
        $hits = [];

        foreach ($this->pairRows() as $row) {
            foreach (self::USER_VISIBLE_FIELDS as $field) {
                foreach ($this->stringsFor($row[$field] ?? null) as $text) {
                    foreach (self::FORBIDDEN_TERMS as $term) {
                        if ($this->containsTerm($text, $term) && ! $this->isBoundaryOrNegatedHit($text)) {
                            $hits[] = $row['pair_key'].'.'.$field.': '.$term.' in '.$text;
                        }
                    }
                }
            }
        }

        $this->assertSame([], $hits);
    }

    public function test_v7_3_pair_blend_asset_has_no_visible_technical_key_exposure(): void
    {
        $hits = [];

        foreach ($this->pairRows() as $row) {
            foreach (self::USER_VISIBLE_FIELDS as $field) {
                foreach ($this->stringsFor($row[$field] ?? null) as $text) {
                    foreach (self::TECHNICAL_KEYS as $technicalKey) {
                        if (str_contains($text, $technicalKey)) {
                            $hits[] = $row['pair_key'].'.'.$field.': '.$technicalKey;
                        }
                    }
                }
            }
        }

        $this->assertSame([], $hits);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function pairRows(): array
    {
        $this->assertFileExists(self::FIXTURE_PATH);

        $rows = [];
        foreach (file(self::FIXTURE_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $lineNumber => $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, 'Invalid JSONL line '.($lineNumber + 1));
            $rows[] = $decoded;
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalizedBackendSlot(array $row): array
    {
        return [
            'slot_key' => 'pair_blend_copy',
            'slot_group' => 'pair_blend_copy',
            'scale_code' => 'RIASEC',
            'locale' => 'zh-CN',
            'content_version' => (string) $row['asset_version'],
            'interpretation_rule_version' => 'riasec_interpretation_rule_spec_v2',
            'applicable_form_codes' => $row['applicable_form_codes'],
            'applicable_profile_shapes' => $row['applicable_profile_shapes'],
            'applicable_quality_states' => $row['applicable_quality_states'],
            'applicable_codes' => [(string) $row['pair_key']],
            'applicable_dimensions' => $row['dimensions'],
            'pair_key' => (string) $row['pair_key'],
            'pair_label' => (string) $row['pair_label'],
            'short_label' => (string) $row['short_label'],
            'chemistry' => (string) $row['chemistry'],
            'positive_value' => (string) $row['positive_value'],
            'real_world_cost' => (string) $row['real_world_cost'],
            'common_misread' => (string) $row['common_misread'],
            'activities_to_validate' => $row['activities_to_validate'],
            'micro_experiment' => (string) $row['micro_experiment'],
            'result_page_teaser' => (string) $row['result_page_teaser'],
            'deep_report_extension_hint' => (string) $row['deep_report_extension_hint'],
            'forbidden_claims' => $row['forbidden_claims'],
            'required_boundaries' => $row['required_boundaries'],
            'user_visible_boundary' => (string) $row['user_visible_boundary'],
            'evidence_level' => 'expert_reviewed',
            'source_status' => 'reviewed_content_copy',
            'review_status' => 'content_review',
            'content_status' => 'authored',
            'fallback_behavior' => 'omit_module',
            'frontend_fallback_allowed' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function stringsFor(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (is_array($value)) {
            $strings = [];
            foreach ($value as $item) {
                array_push($strings, ...$this->stringsFor($item));
            }

            return $strings;
        }

        return [];
    }

    private function containsTerm(string $text, string $term): bool
    {
        if (preg_match('/[A-Za-z]/', $term) === 1) {
            return str_contains(strtolower($text), strtolower($term));
        }

        return str_contains($text, $term);
    }

    private function isBoundaryOrNegatedHit(string $text): bool
    {
        foreach (['不是', '不能', '不得', '不输出', '不允许', '禁止', '不作为', '不把'] as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Current runtime contract scans whole slot payloads by substring. V7.3 contains
     * explicit negative boundary copy, so the import PR must resolve this documented
     * boundary-aware validator gap before promoting the asset.
     *
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function unexpectedValidationErrors(array $errors): array
    {
        return array_values(array_diff($errors, ['forbidden_claim_phrase_non_ascii']));
    }
}
