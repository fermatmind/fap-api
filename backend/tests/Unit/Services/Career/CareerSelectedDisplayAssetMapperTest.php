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

    public function test_it_maps_d8_active_rows_and_keeps_software_developers_on_manual_hold(): void
    {
        foreach ($this->d8Rows() as $row) {
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

        $result = app(CareerSelectedDisplayAssetMapper::class)->mapRow(
            $this->row('software-developers', title: 'Software Developers', cnTitle: '软件开发人员', soc: '15-1252', onet: '15-1252.00'),
        );

        $this->assertStringContainsString(
            'Slug is not in the selected display asset import allowlist.',
            implode(' ', $result['errors']),
        );
    }

    public function test_it_maps_d10_cohort_rows_to_display_asset_payloads(): void
    {
        foreach ($this->d10Rows() as $row) {
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
     * @return list<array<string, string>>
     */
    private function d8Rows(): array
    {
        return [
            $this->row('web-developers', title: 'Web Developers', cnTitle: '网页开发人员', soc: '15-1254', onet: '15-1254.00'),
            $this->row('marketing-managers', title: 'Marketing Managers', cnTitle: '营销经理', soc: '11-2021', onet: '11-2021.00'),
            $this->row('lawyers', title: 'Lawyers', cnTitle: '律师', soc: '23-1011', onet: '23-1011.00'),
            $this->row('pharmacists', title: 'Pharmacists', cnTitle: '药剂师', soc: '29-1051', onet: '29-1051.00'),
            $this->row('acupuncturists', title: 'Acupuncturists', cnTitle: '针灸师', soc: '29-1291', onet: '29-1291.00'),
            $this->row('business-intelligence-analysts', title: 'Business Intelligence Analysts', cnTitle: '商业智能分析师', soc: '15-2051', onet: '15-2051.01'),
            $this->row('clinical-data-managers', title: 'Clinical Data Managers', cnTitle: '临床数据经理', soc: '15-2051', onet: '15-2051.02'),
            $this->row('budget-analysts', title: 'Budget Analysts', cnTitle: '预算分析师', soc: '13-2031', onet: '13-2031.00'),
            $this->row('human-resources-managers', title: 'Human Resources Managers', cnTitle: '人力资源经理', soc: '11-3121', onet: '11-3121.00'),
            $this->row('administrative-services-managers', title: 'Administrative Services Managers', cnTitle: '行政服务经理', soc: '11-3012', onet: '11-3012.00'),
            $this->row('advertising-and-promotions-managers', title: 'Advertising and Promotions Managers', cnTitle: '广告与促销经理', soc: '11-2011', onet: '11-2011.00'),
            $this->row('architects', title: 'Architects, Except Landscape and Naval', cnTitle: '建筑师（不含景观与船舶）', soc: '17-1011', onet: '17-1011.00'),
            $this->row('air-traffic-controllers', title: 'Air Traffic Controllers', cnTitle: '空中交通管制员', soc: '53-2021', onet: '53-2021.00'),
            $this->row('airline-and-commercial-pilots', title: 'Airline and Commercial Pilots', cnTitle: '航空公司与商业飞行员', soc: '53-2011', onet: '53-2011.00'),
            $this->row('chemists-and-materials-scientists', title: 'Chemists', cnTitle: '化学家', soc: '19-2031', onet: '19-2031.00'),
            $this->row('clinical-laboratory-technologists-and-technicians', title: 'Medical and Clinical Laboratory Technologists', cnTitle: '医学与临床实验室技师', soc: '29-2011', onet: '29-2011.00'),
            $this->row('community-health-workers', title: 'Community Health Workers', cnTitle: '社区健康工作者', soc: '21-1094', onet: '21-1094.00'),
            $this->row('compensation-and-benefits-managers', title: 'Compensation and Benefits Managers', cnTitle: '薪酬与福利经理', soc: '11-3111', onet: '11-3111.00'),
            $this->row('career-and-technical-education-teachers', title: 'Career/Technical Education Teachers, Secondary School', cnTitle: '中学职业/技术教育教师', soc: '25-2032', onet: '25-2032.00'),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function d10Rows(): array
    {
        return [
            $this->row('acute-care-nurses', title: 'Acute Care Nurses', cnTitle: '急性护理护士', soc: '29-1141', onet: '29-1141.01'),
            $this->row('adapted-physical-education-specialists', title: 'Adapted Physical Education Specialists', cnTitle: '适应性体育教育专家', soc: '25-2059', onet: '25-2059.01'),
            $this->row('administrative-law-judges-adjudicators-and-hearing-officers', title: 'Administrative Law Judges, Adjudicators, and Hearing Officers', cnTitle: '行政法法官、裁决员和听证官', soc: '23-1021', onet: '23-1021.00'),
            $this->row('adult-basic-education-adult-secondary-education-and-english-as-a-second-language-instructors', title: 'Adult Basic Education, Adult Secondary Education, and English as a Second Language Instructors', cnTitle: '成人基础教育、成人中等教育和英语作为第二语言教师', soc: '25-3011', onet: '25-3011.00'),
            $this->row('adult-literacy-and-ged-teachers', title: 'Adult Literacy and GED Teachers', cnTitle: '成人扫盲和GED教师', soc: '25-3011', onet: '25-3011.00'),
            $this->row('advanced-practice-psychiatric-nurses', title: 'Advanced Practice Psychiatric Nurses', cnTitle: '高级执业精神科护士', soc: '29-1141', onet: '29-1141.02'),
            $this->row('advertising-promotions-and-marketing-managers', title: 'Advertising, Promotions, and Marketing Managers', cnTitle: '广告、促销和营销经理', soc: '11-2021', onet: '11-2021.00'),
            $this->row('advertising-sales-agents', title: 'Advertising Sales Agents', cnTitle: '广告销售代理', soc: '41-3011', onet: '41-3011.00'),
            $this->row('aerospace-engineering-and-operations-technicians', title: 'Aerospace Engineering and Operations Technicians', cnTitle: '航空航天工程和操作技术员', soc: '17-3021', onet: '17-3021.00'),
            $this->row('agents-and-business-managers-of-artists-performers-and-athletes', title: 'Agents and Business Managers of Artists, Performers, and Athletes', cnTitle: '艺术家、表演者和运动员的经纪人和业务经理', soc: '13-1011', onet: '13-1011.00'),
            $this->row('agricultural-and-food-science-technicians', title: 'Agricultural and Food Science Technicians', cnTitle: '农业和食品科学技术员', soc: '19-4012', onet: '19-4012.00'),
            $this->row('agricultural-equipment-operators', title: 'Agricultural Equipment Operators', cnTitle: '农业设备操作员', soc: '45-2091', onet: '45-2091.00'),
            $this->row('agricultural-sciences-teachers-postsecondary', title: 'Agricultural Sciences Teachers, Postsecondary', cnTitle: '高等教育农业科学教师', soc: '25-1041', onet: '25-1041.00'),
            $this->row('aircraft-and-avionics-equipment-mechanics-and-technicians', title: 'Aircraft and Avionics Equipment Mechanics and Technicians', cnTitle: '飞机和航空电子设备机械师和技术员', soc: '49-3011', onet: '49-3011.00'),
            $this->row('aircraft-cargo-handling-supervisors', title: 'Aircraft Cargo Handling Supervisors', cnTitle: '飞机货物处理主管', soc: '53-1041', onet: '53-1041.00'),
            $this->row('aircraft-launch-and-recovery-officers', title: 'Aircraft Launch and Recovery Officers', cnTitle: '飞机发射和回收官', soc: '55-1012', onet: '55-1012.00'),
            $this->row('aircraft-launch-and-recovery-specialists', title: 'Aircraft Launch and Recovery Specialists', cnTitle: '飞机发射和回收专家', soc: '55-3012', onet: '55-3012.00'),
            $this->row('aircraft-mechanics-and-service-technicians', title: 'Aircraft Mechanics and Service Technicians', cnTitle: '飞机机械师和服务技术员', soc: '49-3011', onet: '49-3011.00'),
            $this->row('aircraft-service-attendants', title: 'Aircraft Service Attendants', cnTitle: '飞机服务员', soc: '53-6032', onet: '53-6032.00'),
            $this->row('aircraft-structure-surfaces-rigging-and-systems-assemblers', title: 'Aircraft Structure, Surfaces, Rigging, and Systems Assemblers', cnTitle: '飞机结构、表面、索具和系统装配工', soc: '51-2011', onet: '51-2011.00'),
            $this->row('airfield-operations-specialists', title: 'Airfield Operations Specialists', cnTitle: '机场运营专家', soc: '53-2022', onet: '53-2022.00'),
            $this->row('airline-pilots-copilots-and-flight-engineers', title: 'Airline Pilots, Copilots, and Flight Engineers', cnTitle: '航空公司飞行员、副驾驶和飞行工程师', soc: '53-2011', onet: '53-2011.00'),
            $this->row('allergists-and-immunologists', title: 'Allergists and Immunologists', cnTitle: '过敏症和免疫学医师', soc: '29-1229', onet: '29-1229.01'),
            $this->row('ambulance-drivers-and-attendants-except-emergency-medical-technicians', title: 'Ambulance Drivers and Attendants, Except Emergency Medical Technicians', cnTitle: '救护车司机和随车人员（不包括急救医疗技术员）', soc: '53-3011', onet: '53-3011.00'),
            $this->row('amusement-and-recreation-attendants', title: 'Amusement and Recreation Attendants', cnTitle: '娱乐和休闲服务员', soc: '39-3091', onet: '39-3091.00'),
            $this->row('anesthesiologist-assistants', title: 'Anesthesiologist Assistants', cnTitle: '麻醉医师助理', soc: '29-1071', onet: '29-1071.01'),
            $this->row('anesthesiologists', title: 'Anesthesiologists', cnTitle: '麻醉医师', soc: '29-1211', onet: '29-1211.00'),
            $this->row('animal-care-and-service-workers', title: 'Animal Care and Service Workers', cnTitle: '动物护理和服务工作者', soc: '39-2021', onet: '39-2021.00'),
            $this->row('animal-caretakers', title: 'Animal Caretakers', cnTitle: '动物护理员', soc: '39-2021', onet: '39-2021.00'),
            $this->row('animal-control-workers', title: 'Animal Control Workers', cnTitle: '动物控制工作者', soc: '33-9011', onet: '33-9011.00'),
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
