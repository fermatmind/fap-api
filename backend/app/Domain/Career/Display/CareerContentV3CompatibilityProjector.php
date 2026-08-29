<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

final class CareerContentV3CompatibilityProjector
{
    private const ACCOUNTING_SLUG = 'accountants-and-auditors';

    private const CORE_FACTS = [
        'bls-us-accountants-wage-p10-2025',
        'bls-us-accountants-wage-p25-2025',
        'bls-us-accountants-wage-median-2025',
        'bls-us-accountants-wage-p75-2025',
        'bls-us-accountants-wage-p90-2025',
        'bls-us-accountants-employment-2025',
        'bls-us-accountants-employment-2035',
        'bls-us-accountants-employment-net-change-2025-2035',
        'bls-us-accountants-employment-growth-2025-2035',
        'bls-us-accountants-openings-2025-2035',
        'bls-us-accountants-industry-finance-2025',
        'bls-us-accountants-industry-management-2025',
        'bls-us-accountants-industry-government-2025',
        'bls-us-accountants-industry-accounting-services-2025',
        'editorial-us-accountants-monthly-median-2025',
        'mohrss-cn-economic-financial-wage-median-2024',
        'editorial-cn-economic-financial-monthly-median-2024',
        'randstad-cn-first-tier-finance-monthly-range-2026',
        'randstad-cn-core-city-finance-monthly-range-2026',
    ];

    /** @param array<string,mixed> $surface @param array<string,mixed> $content @return array<string,mixed> */
    public function project(array $surface, array $content): array
    {
        if (($content['locale'] ?? null) !== 'zh-CN'
            || data_get($content, 'subject.canonical_slug') !== self::ACCOUNTING_SLUG) {
            return $surface;
        }
        $facts = $this->facts($content);
        foreach (self::CORE_FACTS as $factId) {
            if (! isset($facts[$factId])) {
                return $surface;
            }
        }

        $surface = $this->projectHero($surface, $content, $facts);
        $surface = $this->projectChinaSalary($surface, $facts);
        $surface = $this->projectSalary($surface, $facts);
        $surface = $this->projectLegacySources($surface, $facts);
        $surface = $this->projectAi($surface, $facts);
        $surface = $this->projectOutlook($surface, $facts);

        return $this->projectFaq($surface, $content);
    }

    /** @param array<string,mixed> $surface @param array<string,array<string,mixed>> $facts @return array<string,mixed> */
    private function projectChinaSalary(array $surface, array $facts): array
    {
        $path = 'page.content.career_snapshot_primary_locale.salary';
        $salary = data_get($surface, $path);
        if (! is_array($salary)) {
            return $surface;
        }
        $employment = (string) $facts['bls-us-accountants-employment-2025']['display_value'];
        $projected = (string) $facts['bls-us-accountants-employment-2035']['display_value'];
        $net = (string) $facts['bls-us-accountants-employment-net-change-2025-2035']['display_value'];
        $growth = (string) $facts['bls-us-accountants-employment-growth-2025-2035']['display_value'];
        $openings = (string) $facts['bls-us-accountants-openings-2025-2035']['display_value'];
        $salary['us_median'] = $facts['bls-us-accountants-wage-median-2025']['display_value'];
        $salary['us_growth'] = $growth;
        $salary['bls_table'] = $this->salaryRows($facts, $employment, $projected, $net, $growth, $openings);
        $salary['fact_refs'] = [
            'mohrss-cn-economic-financial-wage-median-2024',
            'editorial-cn-economic-financial-monthly-median-2024',
            'randstad-cn-first-tier-finance-monthly-range-2026',
            'randstad-cn-core-city-finance-monthly-range-2026',
        ];
        data_set($surface, $path, $salary);

        return $surface;
    }

    /** @param array<string,mixed> $surface @param array<string,array<string,mixed>> $facts @return array<string,mixed> */
    private function projectLegacySources(array $surface, array $facts): array
    {
        $references = data_get($surface, 'sources.references');
        if (! is_array($references)) {
            return $surface;
        }
        foreach ($references as $index => $reference) {
            if (! is_array($reference)) {
                continue;
            }
            $url = (string) ($reference['url'] ?? '');
            if (str_contains($url, 'bls.gov/news.release/ocwage.t01.htm')) {
                $references[$index]['usage'] = [
                    sprintf(
                        '美国会计师和审计师 2025 年 5 月年薪中位数：%s',
                        $facts['bls-us-accountants-wage-median-2025']['display_value'],
                    ),
                    '2025 年工资快照与 2025—2035 年就业预测分开解释',
                ];
                $references[$index]['source_ref'] = 'source-6';
            } elseif (str_contains($url, 'bls.gov/ooh/business-and-financial/accountants-and-auditors.htm')) {
                $references[$index]['usage'] = [
                    sprintf('2025 年就业人数：%s', $facts['bls-us-accountants-employment-2025']['display_value']),
                    sprintf('2035 年预测就业人数：%s', $facts['bls-us-accountants-employment-2035']['display_value']),
                    sprintf(
                        '2025—2035 年就业增长：%s，净增加 %s 人',
                        $facts['bls-us-accountants-employment-growth-2025-2035']['display_value'],
                        $facts['bls-us-accountants-employment-net-change-2025-2035']['display_value'],
                    ),
                    sprintf(
                        '2025—2035 年年均岗位空缺：%s 个，包含替代需求',
                        $facts['bls-us-accountants-openings-2025-2035']['display_value'],
                    ),
                ];
                $references[$index]['source_ref'] = 'source-8';
            }
        }
        data_set($surface, 'sources.references', $references);

        return $surface;
    }

    /** @param array<string,mixed> $surface @param array<string,mixed> $content @param array<string,array<string,mixed>> $facts @return array<string,mixed> */
    private function projectHero(array $surface, array $content, array $facts): array
    {
        $map = [
            'us_median_pay' => 'bls-us-accountants-wage-median-2025',
            'us_growth' => 'bls-us-accountants-employment-growth-2025-2035',
            'employment' => 'bls-us-accountants-employment-2025',
            'annual_openings' => 'bls-us-accountants-openings-2025-2035',
            'china_median_pay' => 'mohrss-cn-economic-financial-wage-median-2024',
        ];
        foreach (['presentation_v1', 'presentation_v2'] as $presentationKey) {
            $stats = data_get($surface, $presentationKey.'.hero.stats');
            if (! is_array($stats)) {
                continue;
            }
            foreach ($stats as $index => $stat) {
                $factId = is_array($stat) ? ($map[$stat['key'] ?? ''] ?? null) : null;
                if ($factId === null) {
                    continue;
                }
                $fact = $facts[$factId];
                $stats[$index]['value'] = $this->heroValue($factId, (string) $fact['display_value']);
                if ($factId === 'mohrss-cn-economic-financial-wage-median-2024') {
                    $stats[$index]['label'] = '中国经济和金融专业人员年工资中位数';
                    $stats[$index]['source_label'] = '中国大陆｜2024 年｜宽口径企业薪酬调查';
                } else {
                    $stats[$index]['source_label'] = $this->evidenceLabel($content, $fact);
                }
                $stats[$index]['fact_ref'] = $factId;
            }
            data_set($surface, $presentationKey.'.hero.stats', $stats);
        }

        return $surface;
    }

    /** @param array<string,mixed> $surface @param array<string,array<string,mixed>> $facts @return array<string,mixed> */
    private function projectSalary(array $surface, array $facts): array
    {
        $path = 'page.content.career_snapshot_secondary_locale';
        $salary = data_get($surface, $path);
        if (! is_array($salary)) {
            return $surface;
        }
        $median = $facts['bls-us-accountants-wage-median-2025']['display_value'];
        $monthly = $facts['editorial-us-accountants-monthly-median-2025']['display_value'];
        $growth = $facts['bls-us-accountants-employment-growth-2025-2035']['display_value'];
        $employment = $facts['bls-us-accountants-employment-2025']['display_value'];
        $projected = $facts['bls-us-accountants-employment-2035']['display_value'];
        $net = $facts['bls-us-accountants-employment-net-change-2025-2035']['display_value'];
        $openings = $facts['bls-us-accountants-openings-2025-2035']['display_value'];
        $salary['median'] = $median;
        $salary['growth'] = $growth;
        $salary['wage_heading'] = '2025 年美国工资分布';
        $salary['outlook_heading'] = '2025—2035 年美国就业前景';
        $salary['direct_answer'] = "按 BLS 2025 年 5 月全国工资数据，会计师和审计师年薪中位数为 {$median}；编辑换算的税前月均约为 {$monthly}。这不是入门工资、到手月薪或某个城市的岗位报价。";
        $salary['boundary'] = '2025 年 5 月工资数据来自 BLS OEWS；2025—2035 年就业预测来自 BLS Employment Projections。两者统计期和指标不同，不能拼成同一条工资趋势。OEWS 不覆盖自雇者。';
        $salary['bls_table'] = $this->salaryRows($facts, $employment, $projected, $net, $growth, $openings);
        $salary['industry_rows'] = $this->industryRows($facts);
        if (is_array($salary['interpretation_rows'] ?? null)) {
            foreach ($salary['interpretation_rows'] as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $question = (string) ($row['question'] ?? '');
                if (str_contains($question, '83,680') || str_contains($question, '年薪')) {
                    $salary['interpretation_rows'][$index]['question'] = "年薪 {$median} 是入门工资吗？";
                    $salary['interpretation_rows'][$index]['answer'] = '不是。这是美国全体会计师和审计师在 2025 年 5 月的全国年薪中位数，不是平均起薪或个人报价。';
                } elseif (str_contains($question, '月均') || str_contains($question, '到手')) {
                    $salary['interpretation_rows'][$index]['question'] = "税前月均 {$monthly} 是到手工资吗？";
                    $salary['interpretation_rows'][$index]['answer'] = "不是。{$monthly} 是把 {$median} 除以 12 后四舍五入得到的编辑换算，不是 BLS 发布的月薪，也不等于实际到账金额。";
                }
            }
        }
        $salary['authority_sources'] = 'BLS OEWS 2025 年 5 月全国工资数据｜https://www.bls.gov/news.release/ocwage.t01.htm；BLS OOH 2025—2035 年就业预测与行业工资｜https://www.bls.gov/ooh/business-and-financial/accountants-and-auditors.htm';
        data_set($surface, $path, $salary);

        return $surface;
    }

    /** @param array<string,array<string,mixed>> $facts @return list<array<string,string>> */
    private function salaryRows(array $facts, string $employment, string $projected, string $net, string $growth, string $openings): array
    {
        $rows = [];
        foreach ([
            ['bls-us-accountants-wage-p10-2025', '2025 薪资 · 10 分位'],
            ['bls-us-accountants-wage-p25-2025', '2025 薪资 · 25 分位'],
            ['bls-us-accountants-wage-median-2025', '2025 薪资 · 中位数'],
            ['bls-us-accountants-wage-p75-2025', '2025 薪资 · 75 分位'],
            ['bls-us-accountants-wage-p90-2025', '2025 薪资 · 90 分位'],
        ] as [$factId, $label]) {
            if (! isset($facts[$factId])) {
                continue;
            }
            $rows[] = [
                '指标' => $label,
                '数值' => (string) $facts[$factId]['display_value'],
                '说明' => 'BLS OEWS｜美国｜2025 年 5 月｜会计师和审计师工资分位',
                'fact_ref' => $factId,
            ];
        }
        $rows[] = [
            '指标' => '2025—2035 就业 · 就业规模',
            '数值' => "{$employment} → {$projected} 人",
            '说明' => "净增加 {$net} 人；BLS Employment Projections",
            'fact_ref' => 'bls-us-accountants-employment-2025',
        ];
        $rows[] = [
            '指标' => '2025—2035 就业 · 增长率',
            '数值' => $growth,
            '说明' => '美国国家级职业预测，不代表其他市场或每个地区。',
            'fact_ref' => 'bls-us-accountants-employment-growth-2025-2035',
        ];
        $rows[] = [
            '指标' => '2025—2035 就业 · 年均职位空缺',
            '数值' => $openings.' 个',
            '说明' => '包含退休、离职和职业转换产生的替代需求，不等于净新增。',
            'fact_ref' => 'bls-us-accountants-openings-2025-2035',
        ];

        return $rows;
    }

    /** @param array<string,array<string,mixed>> $facts @return list<array<string,string>> */
    private function industryRows(array $facts): array
    {
        $rows = [];
        foreach ([
            ['bls-us-accountants-industry-finance-2025', '金融与保险'],
            ['bls-us-accountants-industry-management-2025', '公司与企业管理'],
            ['bls-us-accountants-industry-government-2025', '政府'],
            ['bls-us-accountants-industry-accounting-services-2025', '会计、税务、簿记与薪酬服务'],
        ] as [$factId, $industry]) {
            if (! isset($facts[$factId])) {
                continue;
            }
            $rows[] = [
                'industry' => $industry,
                'median' => (string) $facts[$factId]['display_value'],
                'note' => 'BLS OOH 2025 行业年薪中位数；行业统计不代表个人报价。',
                'fact_ref' => $factId,
            ];
        }

        return $rows;
    }

    /** @param array<string,mixed> $surface @param array<string,array<string,mixed>> $facts @return array<string,mixed> */
    private function projectAi(array $surface, array $facts): array
    {
        $path = 'page.content.ai_impact_table';
        $ai = data_get($surface, $path);
        if (! is_array($ai)) {
            return $surface;
        }
        $growth = (string) $facts['bls-us-accountants-employment-growth-2025-2035']['display_value'];
        $openings = (string) $facts['bls-us-accountants-openings-2025-2035']['display_value'];
        foreach ((array) ($ai['evidence_rows'] ?? []) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $sourceRef = $this->aiSourceRef($row);
            if ($sourceRef !== null) {
                $ai['evidence_rows'][$index]['source_ref'] = $sourceRef;
            }
            if (str_contains((string) ($row['来源'] ?? ''), 'BLS')) {
                $ai['evidence_rows'][$index]['研究对象'] = '美国 SOC 13-2011，2025—2035 年官方职业预测';
                $ai['evidence_rows'][$index]['结论'] = "就业预计增长 {$growth}，年均约 {$openings} 个职位空缺；预测不等于岗位安全保证。";
                $ai['evidence_rows'][$index]['fact_ref'] = 'bls-us-accountants-openings-2025-2035';
            }
        }
        foreach ((array) ($ai['questions'] ?? []) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $sourceRef = $this->aiSourceRef($row);
            if ($sourceRef !== null) {
                $ai['questions'][$index]['source_ref'] = $sourceRef;
            }
            if (str_contains((string) ($row['回答'] ?? ''), 'BLS')) {
                $answer = (string) $row['回答'];
                $answer = str_replace(['2024—2034', '2024–2034', '124,200'], ['2025—2035', '2025–2035', '115,300'], $answer);
                $ai['questions'][$index]['回答'] = $answer;
                $ai['questions'][$index]['fact_ref'] = 'bls-us-accountants-employment-growth-2025-2035';
            }
        }
        data_set($surface, $path, $ai);

        return $surface;
    }

    /** @param array<string,mixed> $surface @param array<string,array<string,mixed>> $facts @return array<string,mixed> */
    private function projectOutlook(array $surface, array $facts): array
    {
        $path = 'page.content.market_signal_card';
        $outlook = data_get($surface, $path);
        if (! is_array($outlook) || ! is_array($outlook['outlook_evidence'] ?? null)) {
            return $surface;
        }
        foreach ($outlook['outlook_evidence'] as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $sourceRef = $this->outlookSourceRef($row);
            if ($sourceRef !== null) {
                $outlook['outlook_evidence'][$index]['source_ref'] = $sourceRef;
            }
            if (($row['geography'] ?? null) === '美国') {
                $outlook['outlook_evidence'][$index]['horizon'] = '2025—2035';
                $outlook['outlook_evidence'][$index]['value'] = sprintf(
                    '%s；约 %s 个/年',
                    $facts['bls-us-accountants-employment-growth-2025-2035']['display_value'],
                    $facts['bls-us-accountants-openings-2025-2035']['display_value'],
                );
                $outlook['outlook_evidence'][$index]['fact_ref'] = 'bls-us-accountants-openings-2025-2035';
            }
        }
        data_set($surface, $path, $outlook);

        return $surface;
    }

    /** @param array<string,mixed> $row */
    private function aiSourceRef(array $row): ?string
    {
        $haystack = implode(' ', array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $row));

        return match (true) {
            str_contains($haystack, 'BLS'), str_contains($haystack, 'bls.gov') => 'source-8',
            str_contains($haystack, 'WEF'), str_contains($haystack, 'weforum.org') => 'source-22',
            str_contains($haystack, 'ILO'), str_contains($haystack, 'ilo.org') => 'source-9',
            str_contains($haystack, 'Law'), str_contains($haystack, 'Management Science'), str_contains($haystack, 'pubsonline.informs.org') => 'source-23',
            str_contains($haystack, 'Fedyk'), str_contains($haystack, 'Review of Accounting Studies'), str_contains($haystack, 'link.springer.com') => 'source-24',
            str_contains($haystack, 'PCAOB'), str_contains($haystack, 'pcaobus.org') => 'source-25',
            str_contains($haystack, '中注协'), str_contains($haystack, 'cicpa.org.cn') => 'source-26',
            default => null,
        };
    }

    /** @param array<string,mixed> $row */
    private function outlookSourceRef(array $row): ?string
    {
        $haystack = implode(' ', array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $row));

        return match (true) {
            ($row['geography'] ?? null) === '美国' => 'source-8',
            str_contains($haystack, '雇主'), str_contains($haystack, 'WEF') => 'source-22',
            str_contains($haystack, '全球研究'), str_contains($haystack, 'ILO'), str_contains($haystack, '任务暴露') => 'source-9',
            default => null,
        };
    }

    /** @param array<string,mixed> $surface @param array<string,mixed> $content @return array<string,mixed> */
    private function projectFaq(array $surface, array $content): array
    {
        $faq = collect($content['blocks'])
            ->flatMap(static fn (array $block): array => $block['items'])
            ->first(static fn (array $item): bool => ($item['type'] ?? null) === 'faq');
        $legacy = data_get($surface, 'page.content.faq_block.items');
        if (! is_array($faq) || ! is_array($legacy)) {
            return $surface;
        }
        $entries = data_get($faq, 'data.entries');
        if (! is_array($entries) || count($entries) !== count($legacy)) {
            return $surface;
        }
        foreach ($entries as $index => $entry) {
            if (is_array($entry) && is_array($legacy[$index] ?? null) && is_string($entry['answer'] ?? null)) {
                $legacy[$index]['answer'] = $entry['answer'];
            }
        }
        data_set($surface, 'page.content.faq_block.items', $legacy);

        $faqPage = data_get($surface, 'structured_data_from_visible_content.faq_page.zh');
        if (is_array($faqPage)) {
            $mainEntity = [];
            foreach ($legacy as $item) {
                if (! is_array($item) || ! is_string($item['question'] ?? null) || ! is_string($item['answer'] ?? null)) {
                    $mainEntity = [];
                    break;
                }
                $mainEntity[] = [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $item['answer'],
                    ],
                ];
            }
            if (count($mainEntity) === count($legacy)) {
                $faqPage['mainEntity'] = $mainEntity;
                data_set($surface, 'structured_data_from_visible_content.faq_page.zh', $faqPage);
            }
        }

        return $surface;
    }

    /** @param array<string,mixed> $content @return array<string,array<string,mixed>> */
    private function facts(array $content): array
    {
        $facts = [];
        foreach ((array) data_get($content, 'fact_register.facts', []) as $fact) {
            if (is_array($fact) && is_string($fact['fact_id'] ?? null)) {
                $facts[$fact['fact_id']] = $fact;
            }
        }

        return $facts;
    }

    /** @param array<string,mixed> $content @param array<string,mixed> $fact */
    private function evidenceLabel(array $content, array $fact): string
    {
        $sourceRef = $fact['source_refs'][0] ?? null;
        $source = collect($content['blocks'])
            ->flatMap(static fn (array $block): array => $block['items'])
            ->filter(static fn (array $item): bool => ($item['type'] ?? null) === 'sources')
            ->flatMap(static fn (array $item): array => (array) data_get($item, 'data.entries', []))
            ->first(static fn (array $entry): bool => ($entry['id'] ?? null) === $sourceRef);
        $publisher = is_array($source) ? ($source['publisher'] ?? $source['name'] ?? '来源') : '来源';

        return implode('｜', [(string) $publisher, (string) $fact['market'], (string) $fact['period'], (string) $fact['measure']]);
    }

    private function heroValue(string $factId, string $display): string
    {
        return match ($factId) {
            'bls-us-accountants-employment-2025' => $display.' 人',
            'bls-us-accountants-openings-2025-2035' => $display.' 个',
            default => $display,
        };
    }
}
