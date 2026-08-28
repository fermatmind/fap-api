<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3FactResolver;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use PHPUnit\Framework\TestCase;

final class CareerContentV3FactResolverTest extends TestCase
{
    public function test_it_resolves_controlled_fact_references_and_preserves_structured_evidence(): void
    {
        $content = $this->content();

        $resolved = (new CareerContentV3FactResolver)->resolve($content);

        self::assertSame(
            '年薪中位数为 $83,680；编辑换算月均为 $6,973。',
            $resolved['blocks'][0]['items'][0]['data']['paragraphs'][0],
        );
        self::assertSame(
            '$83,680 ÷ 12，四舍五入',
            $resolved['fact_register']['facts'][1]['derivation'],
        );
        self::assertSame('BLS OEWS', $resolved['blocks'][1]['items'][0]['data']['entries'][0]['publisher']);
        self::assertStringNotContainsString('{{fact:', json_encode($resolved, JSON_THROW_ON_ERROR));
    }

    public function test_it_fails_closed_when_a_fact_reference_is_unknown(): void
    {
        $content = $this->content();
        $content['blocks'][0]['items'][0]['data']['paragraphs'][0] = '未知 {{fact:missing-fact}}';

        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        $this->expectExceptionMessage('CURRENT_CONTENT_V3_INVALID');

        (new CareerContentV3FactResolver)->resolve($content);
    }

    public function test_it_fails_closed_when_a_source_reference_is_unknown(): void
    {
        $content = $this->content();
        $content['fact_register']['facts'][0]['source_refs'] = ['missing-source'];

        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        $this->expectExceptionMessage('CURRENT_CONTENT_V3_INVALID');

        (new CareerContentV3FactResolver)->resolve($content);
    }

    public function test_it_rejects_an_incorrect_editorial_monthly_derivation(): void
    {
        $content = $this->content();
        $content['fact_register']['facts'][1]['display_value'] = '$6,900';

        try {
            (new CareerContentV3FactResolver)->resolve($content);
            self::fail('An incorrect derived value must fail closed.');
        } catch (CareerCurrentAuthorityPackageFailure $failure) {
            self::assertSame('CURRENT_CONTENT_V3_FACT_DERIVATION_INVALID', $failure->safeCode);
        }
    }

    /** @return array<string,mixed> */
    private function content(): array
    {
        return [
            'contract_version' => 'career.detail.content.v3',
            'locale' => 'zh-CN',
            'subject' => [
                'canonical_slug' => 'accountants-and-auditors',
                'name' => '会计师和审计师',
                'summary' => '职业内容。',
            ],
            'content_state' => 'enhanced',
            'source_content_sha256' => str_repeat('a', 64),
            'fact_register' => [
                'facts' => [
                    [
                        'fact_id' => 'bls-us-accountants-wage-median-2025',
                        'display_value' => '$83,680',
                        'market' => '美国',
                        'period' => '2025 年 5 月',
                        'measure' => '年薪中位数',
                        'occupation_scope' => 'Accountants and Auditors (SOC 13-2011)',
                        'source_refs' => ['source-bls-oews-2025'],
                        'derivation' => null,
                    ],
                    [
                        'fact_id' => 'editorial-us-accountants-monthly-median-2025',
                        'display_value' => '$6,973',
                        'market' => '美国',
                        'period' => '2025 年 5 月',
                        'measure' => '税前年薪月均等值',
                        'occupation_scope' => 'Accountants and Auditors (SOC 13-2011)',
                        'source_refs' => ['source-bls-oews-2025'],
                        'derivation' => '{{fact:bls-us-accountants-wage-median-2025}} ÷ 12，四舍五入',
                    ],
                ],
            ],
            'blocks' => [
                [
                    'id' => 'overview',
                    'copy_key' => 'career.block.overview',
                    'content_state' => 'enhanced',
                    'availability' => 'available',
                    'items' => [[
                        'id' => 'overview-facts',
                        'copy_key' => 'career.item.definition-block',
                        'type' => 'prose',
                        'availability' => 'available',
                        'fact_refs' => [
                            'bls-us-accountants-wage-median-2025',
                            'editorial-us-accountants-monthly-median-2025',
                        ],
                        'source_refs' => ['source-bls-oews-2025'],
                        'data' => ['paragraphs' => [
                            '年薪中位数为 {{fact:bls-us-accountants-wage-median-2025}}；编辑换算月均为 {{fact:editorial-us-accountants-monthly-median-2025}}。',
                        ]],
                    ]],
                ],
                [
                    'id' => 'sources',
                    'copy_key' => 'career.block.source-register',
                    'content_state' => 'enhanced',
                    'availability' => 'available',
                    'items' => [[
                        'id' => 'published-sources',
                        'copy_key' => 'career.item.published-sources',
                        'type' => 'sources',
                        'availability' => 'available',
                        'data' => ['entries' => [[
                            'id' => 'source-bls-oews-2025',
                            'name' => 'BLS 2025 全国工资数据',
                            'url' => 'https://www.bls.gov/news.release/ocwage.t01.htm',
                            'details' => ['年薪中位数与工资分位。'],
                            'publisher' => 'BLS OEWS',
                            'market' => '美国',
                            'period' => '2025 年 5 月',
                            'evidence_type' => '官方工资统计',
                            'scope' => 'Accountants and Auditors (SOC 13-2011)',
                            'limitation' => '不代表个人起薪或到手工资。',
                            'accessed_at' => '2026-08-29',
                        ]]],
                    ]],
                ],
            ],
        ];
    }
}
