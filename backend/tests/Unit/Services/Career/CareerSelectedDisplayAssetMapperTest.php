<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Career;

use App\Services\Career\Import\CareerSelectedDisplayAssetMapper;
use Tests\TestCase;

final class CareerSelectedDisplayAssetMapperTest extends TestCase
{
    public function test_it_maps_valid_row_to_display_asset_payload(): void
    {
        $result = app(CareerSelectedDisplayAssetMapper::class)->mapRow($this->row('data-scientists'));

        $this->assertSame([], $result['errors']);
        $this->assertSame('data-scientists', $result['slug']);
        $this->assertSame('15-2051', $result['expected_soc']);
        $this->assertSame('15-2051.00', $result['expected_onet']);
        $this->assertSame(24, $result['summary']['component_order_count']);
        $this->assertTrue($result['summary']['has_zh_page']);
        $this->assertTrue($result['summary']['has_en_page']);
        $this->assertSame(3, $result['summary']['faq_main_entity_count']['en']);
        $this->assertSame([], $result['summary']['public_payload_forbidden_keys_found']);

        $payload = $result['payload'];
        $this->assertSame('Data Scientists', $payload['page_payload_json']['page']['en']['hero']['h1']);
        $this->assertSame('start_riasec_test', $payload['page_payload_json']['page']['en']['primary_cta']['target_action']);
        $this->assertFalse($payload['structured_data_json']['schema_rules']['occupation_schema_generated_locally']);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('raw_ai_exposure_score', $encoded);
        $this->assertStringNotContainsString('"@type":"Occupation"', $encoded);
    }

    public function test_it_accepts_plain_string_ai_exposure_explanation(): void
    {
        $row = $this->row('registered-nurses', title: 'Registered Nurses', cnTitle: '注册护士', soc: '29-1141', onet: '29-1141.00');
        $row['AI_Exposure_Explanation'] = 'Plain language explanation from the workbook.';

        $result = app(CareerSelectedDisplayAssetMapper::class)->mapRow($row);

        $this->assertSame([], $result['errors']);
        $this->assertSame('Plain language explanation from the workbook.', $result['payload']['page_payload_json']['page']['en']['ai_impact_table']['explanation']);
    }

    public function test_it_maps_d5_selected_rows_to_display_asset_payloads(): void
    {
        foreach ($this->d5Rows() as $row) {
            $result = app(CareerSelectedDisplayAssetMapper::class)->mapRow($row);

            $this->assertSame([], $result['errors'], $row['Slug'].' should map cleanly.');
            $this->assertSame($row['Slug'], $result['slug']);
            $this->assertSame($row['SOC_Code'], $result['expected_soc']);
            $this->assertSame($row['O_NET_Code'], $result['expected_onet']);
            $this->assertSame(24, $result['summary']['component_order_count']);
            $this->assertTrue($result['summary']['has_zh_page']);
            $this->assertTrue($result['summary']['has_en_page']);
            $this->assertSame([], $result['summary']['public_payload_forbidden_keys_found']);
        }
    }

    public function test_biomedical_engineers_rejects_product_substring_if_reintroduced(): void
    {
        $row = $this->row(
            'biomedical-engineers',
            title: 'Bioengineers and biomedical engineers',
            cnTitle: '生物工程师与生物医学工程师',
            soc: '17-2031',
            onet: '17-2031.00',
        );
        $row['EN_Occupation_Schema_JSON'] = $this->encodeJson([
            '@context' => 'https://schema.org',
            '@type' => 'Occupation',
            'name' => 'Bioengineers and biomedical engineers',
            'occupationalCategory' => '17-2031',
            'description' => 'Design systems and products for healthcare settings.',
        ]);

        $result = app(CareerSelectedDisplayAssetMapper::class)->mapRow($row);

        $this->assertStringContainsString(
            'EN_Occupation_Schema_JSON must not include Product schema.',
            implode(' ', $result['errors']),
        );
    }

    public function test_it_rejects_product_schema_hidden_faq_and_forbidden_public_keys(): void
    {
        $row = $this->row('data-scientists');
        $row['EN_Occupation_Schema_JSON'] = $this->encodeJson([
            '@type' => 'Product',
            'name' => 'Unsafe',
            'occupationalCategory' => '15-2051',
        ]);
        $faq = json_decode($row['CN_FAQ_SCHEMA_JSON'], true, 512, JSON_THROW_ON_ERROR);
        $faq['hidden_faq'] = [['name' => 'Hidden']];
        $row['CN_FAQ_SCHEMA_JSON'] = $this->encodeJson($faq);
        $row['EN_Comparison_Block'] = $this->encodeJson([
            'release_gates' => ['sitemap' => false],
        ]);

        $result = app(CareerSelectedDisplayAssetMapper::class)->mapRow($row);

        $errors = implode(' ', $result['errors']);
        $this->assertStringContainsString('EN_Occupation_Schema_JSON must not include Product schema.', $errors);
        $this->assertStringContainsString('cn_faq must not contain hidden FAQ schema.', $errors);
        $this->assertStringContainsString('Forbidden public payload keys found', $errors);
        $this->assertContains('page_payload_json.page.en.adjacent_career_comparison_table.release_gates', $result['summary']['public_payload_forbidden_keys_found']);
    }

    /**
     * @return list<array<string, string>>
     */
    private function d5Rows(): array
    {
        return [
            $this->row('actuaries', title: 'Actuaries', cnTitle: '精算师', soc: '15-2011', onet: '15-2011.00'),
            $this->row('financial-analysts', title: 'Financial and Investment Analysts', cnTitle: '金融与投资分析师', soc: '13-2051', onet: '13-2051.00'),
            $this->row('high-school-teachers', title: 'Secondary School Teachers, Except Special and Career/Technical Education', cnTitle: '高中教师（不含特殊与职业技术教育）', soc: '25-2031', onet: '25-2031.00'),
            $this->row('market-research-analysts', title: 'Market Research Analysts and Marketing Specialists', cnTitle: '市场研究分析师与营销专员', soc: '13-1161', onet: '13-1161.00'),
            $this->row('architectural-and-engineering-managers', title: 'Architectural and Engineering Managers', cnTitle: '建筑与工程经理', soc: '11-9041', onet: '11-9041.00'),
            $this->row('civil-engineers', title: 'Civil Engineers', cnTitle: '土木工程师', soc: '17-2051', onet: '17-2051.00'),
            $this->row('biomedical-engineers', title: 'Bioengineers and Biomedical Engineers', cnTitle: '生物工程师与生物医学工程师', soc: '17-2031', onet: '17-2031.00'),
            $this->row('dentists', title: 'Dentists, General', cnTitle: '普通牙医', soc: '29-1021', onet: '29-1021.00'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function row(
        string $slug,
        string $title = 'Data Scientists',
        string $cnTitle = '数据科学家',
        string $soc = '15-2051',
        string $onet = '15-2051.00',
    ): array {
        $row = array_fill_keys(CareerSelectedDisplayAssetMapper::REQUIRED_HEADERS, '');
        $row['Asset_Version'] = 'v4.2';
        $row['Locale'] = 'bilingual';
        $row['Slug'] = $slug;
        $row['Job_ID'] = $slug;
        $row['SOC_Code'] = $soc;
        $row['O_NET_Code'] = $onet;
        $row['EN_Title'] = $title;
        $row['CN_Title'] = $cnTitle;
        $row['Content_Status'] = 'approved';
        $row['Review_State'] = 'human_reviewed';
        $row['Release_Status'] = 'ready_for_pilot';
        $row['QA_Status'] = 'ready_for_technical_validation';
        $row['Last_Reviewed'] = '2026-05-01';
        $row['Next_Review_Due'] = '2026-11-01';
        $row['EN_SEO_Title'] = $title.' Career Guide';
        $row['EN_SEO_Description'] = 'Career decision evidence.';
        $row['CN_SEO_Title'] = $cnTitle.'职业判断';
        $row['CN_SEO_Description'] = '职业判断证据。';
        $row['EN_Target_Queries'] = $this->encodeJson(['query' => $slug]);
        $row['CN_Target_Queries'] = $this->encodeJson(['query' => $slug]);
        $row['Search_Intent_Type'] = $this->encodeJson(['type' => 'career_decision']);
        $row['EN_H1'] = $title;
        $row['CN_H1'] = $cnTitle;
        $row['EN_Quick_Answer'] = 'Quick answer';
        $row['CN_Quick_Answer'] = '快速回答';
        $row['EN_Snapshot_Data'] = $this->encodeJson(['jobs' => 1000, 'median_wage' => 100000, 'growth' => 'fast']);
        $row['CN_Snapshot_Data'] = $this->encodeJson(['jobs' => 1000, 'median_wage' => 100000, 'growth' => 'fast']);
        $row['CN_Salary_Data_Type'] = 'reference';
        $row['CN_Snapshot_Data_Limitation'] = 'sample-only';
        $row['EN_Definition'] = 'Definition';
        $row['CN_Definition'] = '定义';
        $row['EN_Responsibilities'] = $this->encodeJson(['items' => ['Analyze data']]);
        $row['CN_Responsibilities'] = $this->encodeJson(['items' => ['分析数据']]);
        $row['EN_Comparison_Block'] = $this->encodeJson(['rows' => [['label' => 'adjacent']]]);
        $row['CN_Comparison_Block'] = $this->encodeJson(['rows' => [['label' => '相邻职业']]]);
        $row['EN_How_To_Decide_Fit'] = $this->encodeJson(['items' => ['Check interests']]);
        $row['CN_How_To_Decide_Fit'] = $this->encodeJson(['items' => ['检查兴趣']]);
        $row['EN_RIASEC_Fit'] = $this->encodeJson(['primary' => 'Investigative']);
        $row['CN_RIASEC_Fit'] = $this->encodeJson(['primary' => '研究型']);
        $row['EN_Personality_Fit'] = $this->encodeJson(['traits' => ['high openness']]);
        $row['CN_Personality_Fit'] = $this->encodeJson(['traits' => ['开放性较高']]);
        $row['EN_Caveat'] = 'Caveat';
        $row['CN_Caveat'] = '提醒';
        $row['EN_Next_Steps'] = 'Next steps';
        $row['CN_Next_Steps'] = '下一步';
        $row['AI_Exposure_Score_Normalized'] = '82';
        $row['AI_Exposure_Label'] = 'medium';
        $row['AI_Exposure_Source'] = 'FermatMind interpretation';
        $row['AI_Exposure_Explanation'] = $this->encodeJson(['summary' => 'FermatMind interpretation']);
        $row['EN_FAQ_SCHEMA_JSON'] = $this->faq();
        $row['CN_FAQ_SCHEMA_JSON'] = $this->faq();
        $row['EN_Occupation_Schema_JSON'] = $this->occupationSchema($title, $soc);
        $row['CN_Occupation_Schema_JSON'] = $this->occupationSchema($cnTitle, $soc);
        $row['Claim_Level_Source_Refs'] = $this->sourceRefs($onet);
        $row['EN_Internal_Links'] = $this->internalLinks('en');
        $row['CN_Internal_Links'] = $this->internalLinks('zh');
        $row['Primary_CTA_Label'] = 'Take the Holland / RIASEC Career Interest Test';
        $row['Primary_CTA_URL'] = '/en/tests/holland-career-interest-test-riasec';
        $row['Primary_CTA_Target_Action'] = 'start_riasec_test';
        $row['Secondary_CTA_Label'] = 'Secondary';
        $row['Secondary_CTA_URL'] = $this->encodeJson(['/en/tests/holland-career-interest-test-riasec']);
        $row['Entry_Surface'] = 'career_job_detail';
        $row['Source_Page_Type'] = 'career_job_detail';
        $row['Subject_Type'] = 'job_slug';
        $row['Subject_Slug'] = $slug;
        $row['Primary_Test_Slug'] = 'holland-career-interest-test-riasec';
        $row['Ready_For_Sitemap'] = 'false';
        $row['Ready_For_LLMS'] = 'false';
        $row['Ready_For_Paid'] = 'false';

        return $row;
    }

    private function faq(): string
    {
        return $this->encodeJson([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => [
                ['@type' => 'Question', 'name' => 'Question 1', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Answer']],
                ['@type' => 'Question', 'name' => 'Question 2', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Answer']],
                ['@type' => 'Question', 'name' => 'Question 3', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Answer']],
            ],
        ]);
    }

    private function occupationSchema(string $name, string $category): string
    {
        return $this->encodeJson([
            '@context' => 'https://schema.org',
            '@type' => 'Occupation',
            'name' => $name,
            'occupationalCategory' => $category,
        ]);
    }

    private function sourceRefs(string $onet): string
    {
        return $this->encodeJson([
            'soc_onet_identity' => [
                'source_urls' => [
                    'https://www.onetonline.org/link/details/'.$onet,
                    'https://www.bls.gov/ooh/example.htm',
                ],
                'claims' => ['official occupation identity'],
            ],
            'us_employment_wage_outlook' => [
                'url' => 'https://www.bls.gov/ooh/example.htm',
                'claims' => ['2024 jobs: 1,000', '2024 median salary/wage/pay: $100,000', 'growth outlook: 5%'],
            ],
            'fermatmind_interpretation' => [
                'label' => 'FermatMind interpretation',
                'usage' => 'FermatMind synthesis of official occupational sources and market-signal references.',
            ],
        ]);
    }

    private function internalLinks(string $locale): string
    {
        return $this->encodeJson([
            'related_tests' => [
                '/'.$locale.'/tests/holland-career-interest-test-riasec',
                '/'.$locale.'/tests/mbti-personality-test-16-personality-types',
            ],
            'related_jobs' => [],
            'related_guides' => [],
            'validation_policy' => [
                'related_tests' => 'stable_routes_allowed',
                'related_jobs' => 'requires_later_live_validation',
                'related_guides' => 'requires_later_live_validation',
                'render_policy' => 'hide_or_plain_text_if_unvalidated',
            ],
        ]);
    }

    /**
     * @param  array<mixed>  $value
     */
    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
