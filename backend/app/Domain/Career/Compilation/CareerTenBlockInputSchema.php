<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

final class CareerTenBlockInputSchema
{
    public const VERSION = 'career.ten_block.accountants.v1';

    /** @var list<string> */
    public const FILES = [
        'ai-impact.json',
        'compare-links.json',
        'definition.json',
        'faq.json',
        'fit-personality.json',
        'geo.json',
        'identity.json',
        'page-meta.json',
        'risk.json',
        'salary.json',
    ];

    /** @var array<string,array<string,string>> */
    private const FIELDS = [
        'identity.json' => [
            'slug' => 'string', 'title_zh' => 'string', 'title_en' => 'string', 'soc' => 'string',
            'onet' => 'string', 'ai_score' => 'integer', 'riasec' => 'string', 'riasec_short' => 'string',
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
            'china_name_row' => 'string', 'china_soc_row' => 'string', 'china_class_row' => 'string',
            'china_ai_row' => 'string', 'china_salary_table' => 'array', 'china_salary_note' => 'string',
            'china_edu_table' => 'array', 'china_industry_table' => 'array', 'china_intl' => 'string',
            'bls_table' => 'array',
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
            'oc_salary_median' => 'string', 'oc_salary_min' => 'string', 'oc_salary_max' => 'string',
            'oc_skills' => 'array', 'oc_responsibilities' => 'array', 'oc_edu' => 'string',
            'jp_location' => 'string', 'jp_base_min' => 'string', 'jp_base_max' => 'string',
            'jp_skills' => 'array', 'jp_industry' => 'string', 'hot_skills' => 'array',
            'meta_title' => 'string', 'meta_description' => 'string', 'canonical' => 'string', 'cta_strategy' => 'object',
        ],
    ];

    /** @var array<string,int> */
    private const ARRAY_LENGTHS = [
        'definition.json:duties' => 6, 'definition.json:qa1_table' => 3, 'definition.json:qa2_table' => 3,
        'definition.json:qa3_table' => 3, 'definition.json:onet_struct' => 6,
        'ai-impact.json:ai_s2_auto' => 4, 'ai-impact.json:ai_s2_accel' => 4,
        'ai-impact.json:ai_s3_list' => 5, 'ai-impact.json:ai_s5_persona' => 4,
        'ai-impact.json:ai_s6_tools' => 8, 'ai-impact.json:ai_s7_trends' => 3,
        'salary.json:china_salary_table' => 3, 'salary.json:china_edu_table' => 4,
        'salary.json:china_industry_table' => 3, 'salary.json:bls_table' => 8,
        'fit-personality.json:fit_traits' => 5, 'risk.json:risk_list' => 5,
        'risk.json:risk_path_table' => 5, 'compare-links.json:internal_links' => 27,
        'compare-links.json:compare_rows' => 5, 'faq.json:faq' => 9,
        'geo.json:quotable_snippets' => 5, 'page-meta.json:signal_list' => 4,
        'page-meta.json:signal_facts' => 3, 'page-meta.json:oc_skills' => 6,
        'page-meta.json:oc_responsibilities' => 4, 'page-meta.json:jp_skills' => 5,
        'page-meta.json:hot_skills' => 6,
    ];

    /** @var array<string,list<string>> */
    private const OBJECT_KEYS = [
        'faq.json:intent' => ['intent_source', 'primary_intent', 'search_queries', 'secondary_intents', 'updated_at'],
        'geo.json:fact_card' => ['ai_score', 'title_zh', 'us_growth', 'us_median'],
        'geo.json:eeat_signals' => ['author', 'source', 'updated_at'],
        'page-meta.json:cta_strategy' => ['audience', 'cta_headline', 'cta_type', 'rationale', 'trigger'],
    ];

    /** @var array<string,list<string>> */
    private const ITEM_KEYS = [
        'definition.json:qa1_table' => ['k', 'v'], 'definition.json:qa2_table' => ['k', 'v'],
        'definition.json:qa3_table' => ['k', 'v'], 'definition.json:onet_struct' => ['label', 'value'],
        'ai-impact.json:ai_s5_persona' => ['人群', '建议'],
        'ai-impact.json:ai_s6_tools' => ['代表能力', '定位', '工具'],
        'salary.json:china_salary_table' => ['城市/区间', '月薪参考'],
        'salary.json:china_edu_table' => ['学历段', '岗位方向', '说明'],
        'salary.json:china_industry_table' => ['行业', '需求'],
        'salary.json:bls_table' => ['指标', '数值', '说明'],
        'risk.json:risk_path_table' => ['说明', '路径', '风险'],
        'compare-links.json:internal_links' => ['nofollow', 'slug', 'source', 'title_en'],
        'compare-links.json:compare_rows' => ['AI影响', '区别', '职业'],
        'faq.json:faq' => ['a', 'q'],
    ];

    /** @param array<string,mixed> $data */
    public function assertFile(string $file, array $data): void
    {
        $contract = self::FIELDS[$file] ?? null;
        if ($contract === null || $this->sorted(array_keys($data)) !== $this->sorted(array_keys($contract))) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_UNKNOWN_KEY');
        }
        foreach ($contract as $key => $type) {
            if (! $this->matchesType($data[$key], $type)) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_TYPE_MISMATCH');
            }
            $path = $file.':'.$key;
            if (isset(self::ARRAY_LENGTHS[$path]) && count($data[$key]) !== self::ARRAY_LENGTHS[$path]) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_ARRAY_LENGTH_MISMATCH');
            }
            if (isset(self::OBJECT_KEYS[$path])) {
                $this->assertObjectKeys($data[$key], self::OBJECT_KEYS[$path]);
            }
            if (isset(self::ITEM_KEYS[$path])) {
                foreach ($data[$key] as $item) {
                    if (! is_array($item)) {
                        throw new CareerTenBlockCompileFailure('TEN_BLOCK_ITEM_TYPE_MISMATCH');
                    }
                    $this->assertObjectKeys($item, self::ITEM_KEYS[$path]);
                }
            } elseif (is_array($data[$key]) && array_is_list($data[$key])) {
                foreach ($data[$key] as $item) {
                    if (! is_string($item)) {
                        throw new CareerTenBlockCompileFailure('TEN_BLOCK_ITEM_TYPE_MISMATCH');
                    }
                }
            }
        }
    }

    /** @param array<mixed> $value @param list<string> $keys */
    private function assertObjectKeys(array $value, array $keys): void
    {
        if (array_is_list($value) || $this->sorted(array_keys($value)) !== $this->sorted($keys)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_UNKNOWN_ITEM_KEY');
        }
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ! array_is_list($value),
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
