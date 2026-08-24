<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerInternalLinkResolver;
use App\Domain\Career\Compilation\CareerTenBlockCompileFailure;
use App\Domain\Career\Compilation\CareerTenBlockVariantNormalizer;
use App\Domain\Career\Compilation\CareerTenBlockVariantSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CareerTenBlockVariantNormalizerTest extends TestCase
{
    #[DataProvider('fixtureProvider')]
    public function test_versioned_profiles_are_mutually_exclusive_and_lossless(string $slug, string $expected, string $variant): void
    {
        $blocks = $this->blocks($slug, $variant);
        $schema = new CareerTenBlockVariantSchema;

        self::assertSame($expected, $schema->detectAndValidate($blocks));

        $ir = (new CareerTenBlockVariantNormalizer)->normalize($blocks, $expected, []);
        self::assertNotEmpty($ir['field_coverage']);
        self::assertSame(['qa3', 'qa2', 'qa1'], array_column($ir['canonical_tables']['quick_answers'], 'key'));
        self::assertSame('value', $ir['canonical_tables']['quick_answers'][0]['question']);
        self::assertSame('value', $ir['canonical_tables']['quick_answers'][0]['answer']);
        self::assertSame(
            $ir['canonical_tables']['definition']['onet_struct'],
            $ir['canonical_tables']['onet_structured_fields']['rows'],
        );
        foreach ($ir['field_coverage'] as $coverage) {
            if (preg_match('/\.(?:qa[123]_(?:q|a|table)|onet_struct)\z/', $coverage['input_jsonpath']) === 1) {
                self::assertSame('mapped_to_ir', $coverage['ir_disposition']);
                self::assertSame('mapped_to_public_component', $coverage['public_disposition']);
            }
        }
        self::assertNotContains('产出', $ir['canonical_tables']['comparison']['semantic_columns']);
        if ($variant === 'role_focus_output') {
            self::assertSame(['role', 'focus', 'output'], $ir['canonical_tables']['comparison']['semantic_columns']);
            self::assertSame('value', $ir['canonical_tables']['comparison']['rows'][0]['output']);
        }
        if ($variant === 'english_two_column') {
            self::assertSame(['occupation', 'difference'], $ir['canonical_tables']['comparison']['semantic_columns']);
            self::assertNull($ir['canonical_tables']['ai_tools'][0]['representative_capability']);
            self::assertSame(['TEN_BLOCK_AI_TOOL_CAPABILITY_MISSING'], $ir['canonical_tables']['ai_tools'][0]['blocker_codes']);
        }
        if ($variant === 'nullable_salary') {
            self::assertNull($ir['files']['page-meta.json']['oc_salary_min']['value']);
            self::assertNull($ir['files']['page-meta.json']['oc_salary_max']['value']);
            self::assertFalse($ir['canonical_tables']['salary_range']['display_eligible']);
            self::assertSame(['TEN_BLOCK_SALARY_RANGE_NULL'], $ir['canonical_tables']['salary_range']['blocker_codes']);
        }
    }

    public function test_onet_value2_is_preserved_as_a_separate_semantic_value(): void
    {
        $blocks = $this->blocks('health-educators', 'value2');
        $profile = (new CareerTenBlockVariantSchema)->detectAndValidate($blocks);
        $ir = (new CareerTenBlockVariantNormalizer)->normalize($blocks, $profile, []);

        self::assertSame('secondary', $ir['canonical_tables']['definition']['onet_struct'][0]['secondary_value']);
        self::assertSame(['label', 'value', 'value2'], $ir['canonical_tables']['definition']['onet_struct'][0]['column_contract']);
        self::assertSame('secondary', $ir['canonical_tables']['onet_structured_fields']['rows'][0]['secondary_value']);
    }

    public function test_empty_optional_value2_is_canonicalized_to_null_without_losing_its_column_contract(): void
    {
        $blocks = $this->blocks('health-educators', 'value2');
        $blocks['definition.json']['onet_struct'][0]['value2'] = '';
        $profile = (new CareerTenBlockVariantSchema)->detectAndValidate($blocks);
        $ir = (new CareerTenBlockVariantNormalizer)->normalize($blocks, $profile, []);

        self::assertNull($ir['canonical_tables']['onet_structured_fields']['rows'][0]['secondary_value']);
        self::assertSame(
            ['label', 'value', 'value2'],
            $ir['canonical_tables']['onet_structured_fields']['rows'][0]['column_contract'],
        );
    }

    public function test_english_value_and_v_columns_remain_distinct(): void
    {
        $blocks = $this->blocks('customs-brokers', 'english_two_column');
        $blocks['definition.json']['qa1_table'][0]['v'] = 'alternate';
        $profile = (new CareerTenBlockVariantSchema)->detectAndValidate($blocks);
        $ir = (new CareerTenBlockVariantNormalizer)->normalize($blocks, $profile, []);

        self::assertSame('value', $ir['canonical_tables']['definition']['qa1_table'][0]['value']);
        self::assertSame('alternate', $ir['canonical_tables']['definition']['qa1_table'][0]['alternate_value']);
        self::assertSame('alternate', $ir['canonical_tables']['quick_answers'][2]['table']['rows'][0]['alternate_value']);
    }

    public function test_variant_links_resolve_to_canonical_targets_without_mutating_source_identity(): void
    {
        $links = (new CareerInternalLinkResolver)->canonicalize('accountants-and-auditors', [[
            'slug' => 'health-and-safety-engineers-except-mining-safety-engineers-and-inspectors',
            'title_en' => 'Health and Safety Engineers',
            'source' => 'lookup',
            'nofollow' => false,
        ]], [
            'health-and-safety-engineers-except-mining-safety-engineers-and-inspectors' => [
                'canonical_slug' => 'health-and-safety-engineers',
            ],
        ], ['health-and-safety-engineers' => true]);

        self::assertSame('variant', $links[0]['target_kind']);
        self::assertSame('health-and-safety-engineers', $links[0]['canonical_target']);
        self::assertTrue($links[0]['rewrite_applied']);
        self::assertSame('health-and-safety-engineers-except-mining-safety-engineers-and-inspectors', $links[0]['input_target_slug']);
    }

    public function test_unresolved_and_hold_targets_fail_closed_even_with_nofollow(): void
    {
        $canonicalizer = new CareerInternalLinkResolver;
        foreach ([
            ['missing', [], ['missing' => true], 'TEN_BLOCK_INTERNAL_LINK_UNRESOLVED'],
            ['software-developers', ['software-developers' => ['canonical_slug' => 'software-developers']], ['software-developers' => true], 'TEN_BLOCK_INTERNAL_LINK_HOLD_TARGET'],
        ] as [$target, $lookup, $inventory, $safeCode]) {
            try {
                $canonicalizer->canonicalize('actors', [[
                    'slug' => $target, 'title_en' => 'Target', 'source' => 'fixture', 'nofollow' => true,
                ]], $lookup, $inventory);
                self::fail('Expected fail-closed link rejection.');
            } catch (CareerTenBlockCompileFailure $failure) {
                self::assertSame($safeCode, $failure->safeCode);
            }
        }
    }

    public function test_unknown_and_ambiguous_profiles_fail_closed(): void
    {
        $schema = new CareerTenBlockVariantSchema;
        $unknown = $this->blocks('actors', 'generic_template_guard');
        $unknown['identity.json']['unexpected'] = true;
        try {
            $schema->detectAndValidate($unknown);
            self::fail('Expected unknown field rejection.');
        } catch (CareerTenBlockCompileFailure $failure) {
            self::assertSame('TEN_BLOCK_UNKNOWN_KEY', $failure->safeCode);
        }

        $ambiguous = $this->blocks('customs-brokers', 'english_two_column');
        $ambiguous['compare-links.json']['compare_rows'] = [['岗位' => 'value', '重心' => 'value', '产出' => 'value']];
        try {
            $schema->detectAndValidate($ambiguous);
            self::fail('Expected ambiguous profile rejection.');
        } catch (CareerTenBlockCompileFailure $failure) {
            self::assertSame('TEN_BLOCK_PROFILE_AMBIGUOUS', $failure->safeCode);
        }
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function fixtureProvider(): iterable
    {
        $manifest = json_decode((string) file_get_contents(
            dirname(__DIR__, 4).'/Fixtures/Career/TenBlock/schema-variants.v1.json',
        ), true, 512, JSON_THROW_ON_ERROR);
        $profiles = [
            'standard' => CareerTenBlockVariantSchema::PROFILE_STANDARD,
            'object_table' => CareerTenBlockVariantSchema::PROFILE_OBJECT_TABLE,
            'english_keys' => CareerTenBlockVariantSchema::PROFILE_ENGLISH_KEYS,
        ];
        foreach ($manifest['fixtures'] as $fixture) {
            yield $fixture['slug'] => [$fixture['slug'], $profiles[$fixture['profile']], $fixture['variant']];
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function blocks(string $slug, string $variant): array
    {
        $reflection = new ReflectionClass(CareerTenBlockVariantSchema::class);
        $fields = $reflection->getConstant('FIELDS');
        $blocks = [];
        foreach ($fields as $file => $contract) {
            foreach ($contract as $key => $type) {
                if (str_starts_with($type, 'optional_')) {
                    continue;
                }
                $blocks[$file][$key] = match ($type) {
                    'integer' => 1,
                    'boolean' => true,
                    'array' => ['value'],
                    'object' => [],
                    default => 'value',
                };
            }
        }
        $blocks['identity.json']['slug'] = $slug;
        $blocks['faq.json']['intent'] = array_fill_keys(
            ['intent_source', 'primary_intent', 'search_queries', 'secondary_intents', 'updated_at'],
            'value',
        );
        $blocks['geo.json']['fact_card'] = array_fill_keys(['ai_score', 'title_zh', 'us_growth', 'us_median'], 'value');
        $blocks['geo.json']['eeat_signals'] = array_fill_keys(['author', 'source', 'updated_at'], 'value');
        $blocks['page-meta.json']['cta_strategy'] = array_fill_keys(
            ['audience', 'cta_headline', 'cta_type', 'rationale', 'trigger'],
            'value',
        );
        foreach (['qa1_table', 'qa2_table', 'qa3_table'] as $key) {
            $blocks['definition.json'][$key] = [['k' => 'value', 'v' => 'value']];
        }
        $blocks['definition.json']['onet_struct'] = [['label' => 'value', 'value' => 'value']];
        $blocks['ai-impact.json']['ai_s5_persona'] = [['人群' => 'value', '建议' => 'value']];
        $blocks['ai-impact.json']['ai_s6_tools'] = [['工具' => 'value', '定位' => 'value', '代表能力' => 'value']];
        $blocks['salary.json']['china_salary_table'] = [['城市/区间' => 'value', '月薪参考' => 'value']];
        $blocks['salary.json']['china_edu_table'] = [['学历段' => 'value', '岗位方向' => 'value', '说明' => 'value']];
        $blocks['salary.json']['china_industry_table'] = [['行业' => 'value', '需求' => 'value']];
        $blocks['salary.json']['bls_table'] = [['指标' => 'value', '数值' => 'value', '说明' => 'value']];
        $blocks['risk.json']['risk_path_table'] = [['路径' => 'value', '风险' => 'value', '说明' => 'value']];
        $blocks['compare-links.json']['internal_links'] = [[
            'slug' => 'actors', 'title_en' => 'Actors', 'source' => 'fixture', 'nofollow' => false,
        ]];
        $blocks['compare-links.json']['compare_rows'] = [['职业' => 'value', '区别' => 'value', 'AI 影响' => 'value']];
        $blocks['faq.json']['faq'] = [['q' => 'value', 'a' => 'value']];
        if ($variant === 'ai_impact_compact') {
            $blocks['compare-links.json']['compare_rows'] = [['职业' => 'value', '区别' => 'value', 'AI影响' => 'value']];
        } elseif ($variant === 'nullable_salary') {
            $blocks['page-meta.json']['oc_salary_min'] = null;
            $blocks['page-meta.json']['oc_salary_max'] = null;
        } elseif ($variant === 'english_two_column') {
            foreach (['qa1_table', 'qa2_table', 'qa3_table'] as $key) {
                $blocks['definition.json'][$key] = [['label' => 'value', 'value' => 'value']];
            }
            $blocks['ai-impact.json']['ai_s5_persona'] = [['persona' => 'value', 'advice' => 'value']];
            $blocks['ai-impact.json']['ai_s6_tools'] = [['name' => 'value', 'desc' => 'value']];
            foreach (['china_name_row', 'china_soc_row', 'china_class_row', 'china_ai_row'] as $key) {
                $blocks['salary.json'][$key] = ['label' => $key, 'value' => 'value'];
            }
            $blocks['salary.json']['china_salary_table'] = [['label' => 'value', 'value' => 'value']];
            $blocks['salary.json']['china_edu_table'] = [['label' => 'value', 'value' => 'value']];
            $blocks['salary.json']['china_industry_table'] = [['label' => 'value', 'value' => 'value']];
            $blocks['salary.json']['bls_table'] = [['label' => 'value', 'value' => 'value']];
            $blocks['risk.json']['risk_path_table'] = [['label' => 'value', 'path' => 'value']];
            $blocks['compare-links.json']['compare_rows'] = [['occupation' => 'value', 'diff' => 'value']];
        } elseif ($variant === 'role_focus_output') {
            $blocks['salary.json']['china_industry_table'] = [['行业' => 'value', '需求' => 'value', '备注' => 'value']];
            $blocks['risk.json']['risk_path_table'] = [['风险' => 'value', '可控' => 'value', '说明' => 'value']];
            $blocks['compare-links.json']['compare_rows'] = [['岗位' => 'value', '重心' => 'value', '产出' => 'value']];
            $blocks['page-meta.json']['signal_list'] = [['信号' => 'value', '解读' => 'value']];
            $blocks['page-meta.json']['oc_skills'] = [['技能' => 'value', '说明' => 'value']];
            $blocks['page-meta.json']['jp_skills'] = [['技能' => 'value', '级别' => 'value']];
        } elseif ($variant === 'value2') {
            $blocks['definition.json']['onet_struct'] = [[
                'label' => 'value', 'value' => 'value', 'value2' => 'secondary',
            ]];
        }

        return $blocks;
    }
}
