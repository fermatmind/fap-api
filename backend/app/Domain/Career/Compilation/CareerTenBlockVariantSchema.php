<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

final class CareerTenBlockVariantSchema
{
    public const VERSION = 'career.ten_block.variants.v1';

    public const PROFILE_STANDARD = 'career.ten_block.standard.v1';

    public const PROFILE_OBJECT_TABLE = 'career.ten_block.object_table.v1';

    public const PROFILE_ENGLISH_KEYS = 'career.ten_block.english_keys.v1';

    /** @var array<string,array<string,string>> */
    private const FIELDS = [
        'identity.json' => [
            'slug' => 'string', 'title_zh' => 'string', 'title_en' => 'string', 'soc' => 'string',
            'onet' => 'nullable_string', 'ai_score' => 'integer', 'riasec' => 'string', 'riasec_short' => 'string',
            'canonical_slug' => 'optional_string', 'title_zh_note' => 'optional_string',
        ],
        'definition.json' => [
            'scene' => 'string', 'quick_suit' => 'string', 'quick_bound' => 'string', 'quick_how' => 'string',
            'definition' => 'string', 'def_callout' => 'string', 'duties' => 'array', 'work_scene' => 'string',
            'qa1_q' => 'string', 'qa1_a' => 'string', 'qa1_table' => 'array', 'qa2_q' => 'string',
            'qa2_a' => 'string', 'qa2_table' => 'array', 'qa3_q' => 'string', 'qa3_a' => 'string',
            'qa3_table' => 'array', 'onet_struct' => 'array',
        ],
        'ai-impact.json' => [
            'ai_head_sub' => 'string', 'ai_s1_p' => 'string', 'ai_s1_bls' => 'string', 'ai_s2_auto' => 'array',
            'ai_s2_accel' => 'array', 'ai_s3_list' => 'array', 'ai_s4_p' => 'string', 'ai_s4_p2' => 'string',
            'ai_s5_persona' => 'array', 'ai_s6_tools' => 'array', 'ai_s7_trends' => 'array',
        ],
        'salary.json' => [
            'us_median' => 'string', 'us_growth' => 'string', 'china_ref' => 'string', 'china_open' => 'string',
            'china_name_row' => 'string_or_object', 'china_soc_row' => 'string_or_object',
            'china_class_row' => 'string_or_object', 'china_ai_row' => 'string_or_object',
            'china_salary_table' => 'array', 'china_salary_note' => 'string', 'china_edu_table' => 'array',
            'china_industry_table' => 'array', 'china_intl' => 'string', 'bls_table' => 'array',
            'sources_note' => 'optional_string', 'edu' => 'optional_string', 'china_open_note' => 'optional_string',
        ],
        'fit-personality.json' => [
            'interest' => 'string', 'fit_interest' => 'string', 'fit_traits' => 'array',
            'fit_callout' => 'string', 'disclaimer' => 'string',
        ],
        'risk.json' => [
            'risk_badge' => 'string', 'risk_fact' => 'string', 'risk_list' => 'array',
            'risk_callout' => 'string', 'risk_path_table' => 'array', 'risk_contract' => 'string',
        ],
        'compare-links.json' => [
            'relgrid_intro' => 'string', 'internal_links' => 'array', 'compare_intro' => 'string',
            'compare_rows' => 'array',
        ],
        'faq.json' => ['faq' => 'array', 'intent' => 'object'],
        'geo.json' => [
            'one_line_definition' => 'string', 'fact_card' => 'object', 'quotable_snippets' => 'array',
            'eeat_signals' => 'object', 'migrated_from_content_json' => 'boolean',
        ],
        'page-meta.json' => [
            'hero_lead' => 'string', 'gauge_note' => 'string', 'scene_fact' => 'string',
            'snapshot_callout' => 'string', 'signal_intro' => 'string', 'signal_list' => 'array',
            'signal_facts' => 'array', 'signal_callout' => 'string', 'sources_note' => 'string',
            'oc_salary_median' => 'string', 'oc_salary_min' => 'nullable_string', 'oc_salary_max' => 'nullable_string',
            'oc_skills' => 'array', 'oc_responsibilities' => 'array', 'oc_edu' => 'string',
            'jp_location' => 'string', 'jp_base_min' => 'string', 'jp_base_max' => 'string',
            'jp_skills' => 'array', 'jp_industry' => 'string', 'hot_skills' => 'array',
            'meta_title' => 'string', 'meta_description' => 'string', 'canonical' => 'string', 'cta_strategy' => 'object',
        ],
    ];

    /** @var array<string,list<list<string>>> */
    private const ITEM_SIGNATURES = [
        'definition.json:qa1_table' => [['k', 'v'], ['label', 'value'], ['label', 'value', 'v']],
        'definition.json:qa2_table' => [['k', 'v'], ['label', 'value']],
        'definition.json:qa3_table' => [['k', 'v'], ['label', 'value']],
        'definition.json:onet_struct' => [['label', 'value'], ['label', 'v'], ['label', 'value', 'value2']],
        'ai-impact.json:ai_s5_persona' => [['人群', '建议'], ['persona', 'advice']],
        'ai-impact.json:ai_s6_tools' => [['工具', '定位', '代表能力'], ['name', 'desc']],
        'salary.json:china_salary_table' => [['城市/区间', '月薪参考'], ['label', 'value']],
        'salary.json:china_edu_table' => [['学历段', '岗位方向', '说明'], ['label', 'value']],
        'salary.json:china_industry_table' => [['行业', '需求'], ['行业', '需求', '备注'], ['label', 'value']],
        'salary.json:bls_table' => [
            ['指标', '数值', '说明'], ['label', 'value'], ['label', 'value', '数值'], ['label', 'value', '数值', '说明'],
        ],
        'risk.json:risk_path_table' => [['路径', '风险', '说明'], ['风险', '可控', '说明'], ['label', 'path']],
        'compare-links.json:internal_links' => [['slug', 'title_en', 'source', 'nofollow']],
        'compare-links.json:compare_rows' => [
            ['职业', '区别', 'AI 影响'], ['职业', '区别', 'AI影响'], ['occupation', 'diff'], ['岗位', '重心', '产出'],
        ],
        'faq.json:faq' => [['q', 'a']],
        'page-meta.json:signal_list' => [['信号', '解读']],
        'page-meta.json:oc_skills' => [['技能', '说明']],
        'page-meta.json:jp_skills' => [['技能', '级别']],
    ];

    /** @var array<string,list<string>> */
    private const OBJECT_SIGNATURES = [
        'faq.json:intent' => ['intent_source', 'primary_intent', 'search_queries', 'secondary_intents', 'updated_at'],
        'geo.json:fact_card' => ['ai_score', 'title_zh', 'us_growth', 'us_median'],
        'geo.json:eeat_signals' => ['author', 'source', 'updated_at'],
        'page-meta.json:cta_strategy' => ['audience', 'cta_headline', 'cta_type', 'rationale', 'trigger'],
    ];

    /** @param array<string,array<string,mixed>> $blocks */
    public function detectAndValidate(array $blocks): string
    {
        foreach (self::FIELDS as $file => $contract) {
            $data = $blocks[$file] ?? null;
            if (! is_array($data) || array_is_list($data)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_TYPE_MISMATCH');
            }
            $allowed = array_keys($contract);
            foreach (array_keys($data) as $key) {
                if (! in_array($key, $allowed, true)) {
                    throw new CareerTenBlockCompileFailure('TEN_BLOCK_UNKNOWN_KEY');
                }
            }
            foreach ($contract as $key => $type) {
                $optional = str_starts_with($type, 'optional_');
                if (! array_key_exists($key, $data)) {
                    if ($optional) {
                        continue;
                    }
                    throw new CareerTenBlockCompileFailure('TEN_BLOCK_UNKNOWN_KEY');
                }
                if (! $this->matchesType($data[$key], $type)) {
                    throw new CareerTenBlockCompileFailure('TEN_BLOCK_TYPE_MISMATCH');
                }
                $this->validateNested($file.':'.$key, $data[$key]);
            }
        }

        $english = $this->itemKeys($blocks['ai-impact.json']['ai_s5_persona']) === ['advice', 'persona'];
        $objectTable = $this->itemKeys($blocks['compare-links.json']['compare_rows']) === ['产出', '岗位', '重心'];
        if ($english && $objectTable) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_PROFILE_AMBIGUOUS');
        }

        return $english ? self::PROFILE_ENGLISH_KEYS : ($objectTable ? self::PROFILE_OBJECT_TABLE : self::PROFILE_STANDARD);
    }

    private function validateNested(string $path, mixed $value): void
    {
        if (isset(self::OBJECT_SIGNATURES[$path])) {
            if (! is_array($value) || array_is_list($value) || $this->sorted(array_keys($value)) !== $this->sorted(self::OBJECT_SIGNATURES[$path])) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_UNKNOWN_ITEM_KEY');
            }

            return;
        }
        if (! is_array($value) || ! array_is_list($value)) {
            return;
        }
        if (isset(self::ITEM_SIGNATURES[$path])) {
            foreach ($value as $item) {
                if (is_string($item) && in_array($path, [
                    'page-meta.json:signal_list', 'page-meta.json:oc_skills', 'page-meta.json:jp_skills',
                ], true)) {
                    continue;
                }
                if (! is_array($item) || array_is_list($item)) {
                    throw new CareerTenBlockCompileFailure('TEN_BLOCK_ITEM_TYPE_MISMATCH');
                }
                $keys = $this->sorted(array_keys($item));
                $valid = false;
                foreach (self::ITEM_SIGNATURES[$path] as $signature) {
                    $valid = $valid || $keys === $this->sorted($signature);
                }
                if (! $valid) {
                    throw new CareerTenBlockCompileFailure('TEN_BLOCK_UNKNOWN_ITEM_KEY');
                }
            }

            return;
        }
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_ITEM_TYPE_MISMATCH');
            }
        }
    }

    /** @param list<mixed> $items @return list<string> */
    private function itemKeys(array $items): array
    {
        $first = $items[0] ?? null;

        return is_array($first) && ! array_is_list($first) ? $this->sorted(array_keys($first)) : [];
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string', 'optional_string' => is_string($value),
            'nullable_string' => is_string($value) || $value === null,
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ! array_is_list($value),
            'string_or_object' => is_string($value) || (is_array($value) && ! array_is_list($value)
                && $this->sorted(array_keys($value)) === ['label', 'value']),
            default => false,
        };
    }

    /** @param list<string|int> $values @return list<string|int> */
    private function sorted(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }
}
