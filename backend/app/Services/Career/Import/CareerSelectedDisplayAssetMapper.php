<?php

declare(strict_types=1);

namespace App\Services\Career\Import;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
use XMLReader;
use ZipArchive;

final class CareerSelectedDisplayAssetMapper
{
    public const SHEET_NAME = 'Career_Assets_v4_1';

    public const SURFACE_VERSION = 'display.surface.v1';

    public const TEMPLATE_VERSION = 'v4.2';

    public const ASSET_TYPE = 'career_job_public_display';

    public const ASSET_ROLE = 'formal_pilot_master';

    public const STATUS = 'ready_for_pilot';

    public const MAPPER_VERSION = 'career_selected_display_asset_mapper_v0.1';

    /** @var array<string, array{soc: string, onet: string}> */
    public const ALLOWED_SLUGS = [
        'accountants-and-auditors' => ['soc' => '13-2011', 'onet' => '13-2011.00'],
        'acute-care-nurses' => ['soc' => '29-1141', 'onet' => '29-1141.01'],
        'actuaries' => ['soc' => '15-2011', 'onet' => '15-2011.00'],
        'acupuncturists' => ['soc' => '29-1291', 'onet' => '29-1291.00'],
        'adapted-physical-education-specialists' => ['soc' => '25-2059', 'onet' => '25-2059.01'],
        'administrative-law-judges-adjudicators-and-hearing-officers' => ['soc' => '23-1021', 'onet' => '23-1021.00'],
        'administrative-services-managers' => ['soc' => '11-3012', 'onet' => '11-3012.00'],
        'advertising-and-promotions-managers' => ['soc' => '11-2011', 'onet' => '11-2011.00'],
        'advertising-promotions-and-marketing-managers' => ['soc' => '11-2021', 'onet' => '11-2021.00'],
        'advertising-sales-agents' => ['soc' => '41-3011', 'onet' => '41-3011.00'],
        'adult-basic-education-adult-secondary-education-and-english-as-a-second-language-instructors' => ['soc' => '25-3011', 'onet' => '25-3011.00'],
        'adult-literacy-and-ged-teachers' => ['soc' => '25-3011', 'onet' => '25-3011.00'],
        'advanced-practice-psychiatric-nurses' => ['soc' => '29-1141', 'onet' => '29-1141.02'],
        'aerospace-engineering-and-operations-technicians' => ['soc' => '17-3021', 'onet' => '17-3021.00'],
        'agents-and-business-managers-of-artists-performers-and-athletes' => ['soc' => '13-1011', 'onet' => '13-1011.00'],
        'agricultural-and-food-science-technicians' => ['soc' => '19-4012', 'onet' => '19-4012.00'],
        'agricultural-equipment-operators' => ['soc' => '45-2091', 'onet' => '45-2091.00'],
        'agricultural-sciences-teachers-postsecondary' => ['soc' => '25-1041', 'onet' => '25-1041.00'],
        'air-traffic-controllers' => ['soc' => '53-2021', 'onet' => '53-2021.00'],
        'aircraft-and-avionics-equipment-mechanics-and-technicians' => ['soc' => '49-3011', 'onet' => '49-3011.00'],
        'aircraft-cargo-handling-supervisors' => ['soc' => '53-1041', 'onet' => '53-1041.00'],
        'aircraft-launch-and-recovery-officers' => ['soc' => '55-1012', 'onet' => '55-1012.00'],
        'aircraft-launch-and-recovery-specialists' => ['soc' => '55-3012', 'onet' => '55-3012.00'],
        'aircraft-mechanics-and-service-technicians' => ['soc' => '49-3011', 'onet' => '49-3011.00'],
        'aircraft-service-attendants' => ['soc' => '53-6032', 'onet' => '53-6032.00'],
        'aircraft-structure-surfaces-rigging-and-systems-assemblers' => ['soc' => '51-2011', 'onet' => '51-2011.00'],
        'airfield-operations-specialists' => ['soc' => '53-2022', 'onet' => '53-2022.00'],
        'airline-and-commercial-pilots' => ['soc' => '53-2011', 'onet' => '53-2011.00'],
        'airline-pilots-copilots-and-flight-engineers' => ['soc' => '53-2011', 'onet' => '53-2011.00'],
        'allergists-and-immunologists' => ['soc' => '29-1229', 'onet' => '29-1229.01'],
        'ambulance-drivers-and-attendants-except-emergency-medical-technicians' => ['soc' => '53-3011', 'onet' => '53-3011.00'],
        'amusement-and-recreation-attendants' => ['soc' => '39-3091', 'onet' => '39-3091.00'],
        'anesthesiologist-assistants' => ['soc' => '29-1071', 'onet' => '29-1071.01'],
        'anesthesiologists' => ['soc' => '29-1211', 'onet' => '29-1211.00'],
        'animal-care-and-service-workers' => ['soc' => '39-2021', 'onet' => '39-2021.00'],
        'animal-caretakers' => ['soc' => '39-2021', 'onet' => '39-2021.00'],
        'animal-control-workers' => ['soc' => '33-9011', 'onet' => '33-9011.00'],
        'architectural-and-engineering-managers' => ['soc' => '11-9041', 'onet' => '11-9041.00'],
        'architects' => ['soc' => '17-1011', 'onet' => '17-1011.00'],
        'biomedical-engineers' => ['soc' => '17-2031', 'onet' => '17-2031.00'],
        'budget-analysts' => ['soc' => '13-2031', 'onet' => '13-2031.00'],
        'business-intelligence-analysts' => ['soc' => '15-2051', 'onet' => '15-2051.01'],
        'career-and-technical-education-teachers' => ['soc' => '25-2032', 'onet' => '25-2032.00'],
        'chemists-and-materials-scientists' => ['soc' => '19-2031', 'onet' => '19-2031.00'],
        'civil-engineers' => ['soc' => '17-2051', 'onet' => '17-2051.00'],
        'clinical-data-managers' => ['soc' => '15-2051', 'onet' => '15-2051.02'],
        'clinical-laboratory-technologists-and-technicians' => ['soc' => '29-2011', 'onet' => '29-2011.00'],
        'community-health-workers' => ['soc' => '21-1094', 'onet' => '21-1094.00'],
        'compensation-and-benefits-managers' => ['soc' => '11-3111', 'onet' => '11-3111.00'],
        'data-scientists' => ['soc' => '15-2051', 'onet' => '15-2051.00'],
        'dentists' => ['soc' => '29-1021', 'onet' => '29-1021.00'],
        'financial-analysts' => ['soc' => '13-2051', 'onet' => '13-2051.00'],
        'high-school-teachers' => ['soc' => '25-2031', 'onet' => '25-2031.00'],
        'human-resources-managers' => ['soc' => '11-3121', 'onet' => '11-3121.00'],
        'lawyers' => ['soc' => '23-1011', 'onet' => '23-1011.00'],
        'marketing-managers' => ['soc' => '11-2021', 'onet' => '11-2021.00'],
        'market-research-analysts' => ['soc' => '13-1161', 'onet' => '13-1161.00'],
        'pharmacists' => ['soc' => '29-1051', 'onet' => '29-1051.00'],
        'registered-nurses' => ['soc' => '29-1141', 'onet' => '29-1141.00'],
        'web-developers' => ['soc' => '15-1254', 'onet' => '15-1254.00'],
    ];

    /** @var list<string> */
    public const COMPONENT_ORDER = [
        'breadcrumb',
        'hero',
        'fermat_decision_card',
        'primary_cta',
        'career_snapshot_primary_locale',
        'career_snapshot_secondary_locale',
        'fit_decision_checklist',
        'riasec_fit_block',
        'personality_fit_block',
        'definition_block',
        'responsibilities_block',
        'work_context_block',
        'market_signal_card',
        'adjacent_career_comparison_table',
        'ai_impact_table',
        'career_risk_cards',
        'contract_project_risk_block',
        'next_steps_block',
        'faq_block',
        'related_next_pages',
        'source_card',
        'review_validity_card',
        'boundary_notice',
        'final_cta',
    ];

    /** @var list<string> */
    public const REQUIRED_HEADERS = [
        'Asset_Version',
        'Locale',
        'Slug',
        'Job_ID',
        'SOC_Code',
        'O_NET_Code',
        'EN_Title',
        'CN_Title',
        'Content_Status',
        'Review_State',
        'Release_Status',
        'Last_Reviewed',
        'Next_Review_Due',
        'EN_SEO_Title',
        'EN_SEO_Description',
        'CN_SEO_Title',
        'CN_SEO_Description',
        'EN_Target_Queries',
        'CN_Target_Queries',
        'Search_Intent_Type',
        'EN_H1',
        'CN_H1',
        'EN_Quick_Answer',
        'CN_Quick_Answer',
        'EN_Snapshot_Data',
        'CN_Snapshot_Data',
        'CN_Salary_Data_Type',
        'CN_Snapshot_Data_Limitation',
        'EN_Definition',
        'CN_Definition',
        'EN_Responsibilities',
        'CN_Responsibilities',
        'EN_Comparison_Block',
        'CN_Comparison_Block',
        'EN_How_To_Decide_Fit',
        'CN_How_To_Decide_Fit',
        'EN_RIASEC_Fit',
        'CN_RIASEC_Fit',
        'EN_Personality_Fit',
        'CN_Personality_Fit',
        'EN_Caveat',
        'CN_Caveat',
        'EN_Next_Steps',
        'CN_Next_Steps',
        'AI_Exposure_Score_Raw',
        'AI_Exposure_Score_Normalized',
        'AI_Exposure_Label',
        'AI_Exposure_Source',
        'AI_Exposure_Explanation',
        'EN_FAQ_SCHEMA_JSON',
        'CN_FAQ_SCHEMA_JSON',
        'EN_Occupation_Schema_JSON',
        'CN_Occupation_Schema_JSON',
        'Claim_Level_Source_Refs',
        'EN_Internal_Links',
        'CN_Internal_Links',
        'Primary_CTA_Label',
        'Primary_CTA_URL',
        'Primary_CTA_Target_Action',
        'Secondary_CTA_Label',
        'Secondary_CTA_URL',
        'Entry_Surface',
        'Source_Page_Type',
        'Subject_Type',
        'Subject_Slug',
        'Primary_Test_Slug',
        'Ready_For_Sitemap',
        'Ready_For_LLMS',
        'Ready_For_Paid',
        'QA_Status',
    ];

    /** @var list<string> */
    private const FORBIDDEN_PUBLIC_KEYS = [
        'release_gate',
        'release_gates',
        'qa_risk',
        'admin_review_state',
        'tracking_json',
        'raw_ai_exposure_score',
    ];

    /**
     * @param  list<string>  $slugs
     * @return array{headers: list<string>, rows: list<array<string, string|int>>, total_rows: int}
     */
    public function readWorkbook(string $path, array $slugs): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is required to read XLSX workbooks.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open XLSX workbook: '.$path);
        }

        try {
            $sheetPath = $this->resolveSheetPath($zip);
            $sharedStrings = $this->readSharedStrings($zip);

            return $this->readSheetXml($path, $sheetPath, $sharedStrings, $slugs);
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  array<string, string|int>  $row
     * @return array{
     *     slug: string,
     *     row_number: int|null,
     *     expected_soc: string,
     *     expected_onet: string,
     *     payload: array<string, mixed>,
     *     summary: array<string, mixed>,
     *     errors: list<string>
     * }
     */
    public function mapRow(array $row): array
    {
        $slug = strtolower($this->stringValue($row, 'Slug'));
        $errors = [];

        if (! isset(self::ALLOWED_SLUGS[$slug])) {
            $errors[] = 'Slug is not in the selected display asset import allowlist.';
        }

        $expected = self::ALLOWED_SLUGS[$slug] ?? ['soc' => '', 'onet' => ''];
        $this->expect($this->stringValue($row, 'Asset_Version') === self::TEMPLATE_VERSION, 'Asset_Version must be v4.2.', $errors);
        $this->expect($this->stringValue($row, 'SOC_Code') === $expected['soc'], "SOC_Code must be {$expected['soc']}.", $errors);
        $this->expect($this->stringValue($row, 'O_NET_Code') === $expected['onet'], "O_NET_Code must be {$expected['onet']}.", $errors);
        $this->expect($this->stringValue($row, 'Content_Status') === 'approved', 'Content_Status must be approved.', $errors);
        $this->expect($this->stringValue($row, 'Review_State') === 'human_reviewed', 'Review_State must be human_reviewed.', $errors);
        $this->expect($this->stringValue($row, 'Release_Status') === 'ready_for_pilot', 'Release_Status must be ready_for_pilot.', $errors);
        $this->expect($this->stringValue($row, 'QA_Status') === 'ready_for_technical_validation', 'QA_Status must be ready_for_technical_validation.', $errors);

        $decoded = $this->decodedFields($row);
        foreach ($decoded as $field => $value) {
            if ($value === null) {
                $errors[] = "{$field} must parse as JSON.";
            }
        }

        $this->validateContent($row, $errors);
        $this->validateSchema($decoded, $errors);
        $this->validateCta($row, $slug, $errors);
        $this->validateSources($decoded['source_refs'] ?? null, $errors);
        $this->validateLinks($decoded['en_internal_links'] ?? null, $decoded['cn_internal_links'] ?? null, $errors);

        $payload = $this->payload($row, $decoded);
        $forbiddenKeys = $this->forbiddenPublicKeys($payload);
        if ($forbiddenKeys !== []) {
            $errors[] = 'Forbidden public payload keys found: '.implode(', ', $forbiddenKeys).'.';
        }

        $encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payloadSize = is_string($encodedPayload) ? strlen($encodedPayload) : 0;

        return [
            'slug' => $slug,
            'row_number' => is_numeric($row['_row_number'] ?? null) ? (int) $row['_row_number'] : null,
            'expected_soc' => $expected['soc'],
            'expected_onet' => $expected['onet'],
            'payload' => $payload,
            'summary' => [
                'component_order_count' => count(self::COMPONENT_ORDER),
                'has_zh_page' => is_array($payload['page_payload_json']['page']['zh'] ?? null),
                'has_en_page' => is_array($payload['page_payload_json']['page']['en'] ?? null),
                'faq_main_entity_count' => [
                    'zh' => count($payload['structured_data_json']['faq_page']['zh']['mainEntity'] ?? []),
                    'en' => count($payload['structured_data_json']['faq_page']['en']['mainEntity'] ?? []),
                ],
                'source_count' => count($payload['sources_json']['references'] ?? []),
                'payload_size_bytes' => $payloadSize,
                'public_payload_forbidden_keys_found' => $forbiddenKeys,
                'release_gates' => [
                    'sitemap' => false,
                    'llms' => false,
                    'paid' => false,
                    'backlink' => false,
                ],
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, string|int>  $row
     * @return array<string, mixed>
     */
    private function decodedFields(array $row): array
    {
        return [
            'en_snapshot' => $this->decodeJson($row, 'EN_Snapshot_Data'),
            'cn_snapshot' => $this->decodeJson($row, 'CN_Snapshot_Data'),
            'en_responsibilities' => $this->decodeJson($row, 'EN_Responsibilities'),
            'cn_responsibilities' => $this->decodeJson($row, 'CN_Responsibilities'),
            'en_comparison' => $this->decodeJson($row, 'EN_Comparison_Block'),
            'cn_comparison' => $this->decodeJson($row, 'CN_Comparison_Block'),
            'en_how_to' => $this->decodeJson($row, 'EN_How_To_Decide_Fit'),
            'cn_how_to' => $this->decodeJson($row, 'CN_How_To_Decide_Fit'),
            'en_riasec' => $this->decodeJson($row, 'EN_RIASEC_Fit'),
            'cn_riasec' => $this->decodeJson($row, 'CN_RIASEC_Fit'),
            'en_personality' => $this->decodeJson($row, 'EN_Personality_Fit'),
            'cn_personality' => $this->decodeJson($row, 'CN_Personality_Fit'),
            'en_next_steps' => $this->decodeJsonOrText($row, 'EN_Next_Steps'),
            'cn_next_steps' => $this->decodeJsonOrText($row, 'CN_Next_Steps'),
            'ai_explanation' => $this->decodeJsonOrText($row, 'AI_Exposure_Explanation'),
            'en_faq' => $this->decodeJson($row, 'EN_FAQ_SCHEMA_JSON'),
            'cn_faq' => $this->decodeJson($row, 'CN_FAQ_SCHEMA_JSON'),
            'en_occupation' => $this->decodeJson($row, 'EN_Occupation_Schema_JSON'),
            'cn_occupation' => $this->decodeJson($row, 'CN_Occupation_Schema_JSON'),
            'source_refs' => $this->decodeJson($row, 'Claim_Level_Source_Refs'),
            'en_internal_links' => $this->decodeJson($row, 'EN_Internal_Links'),
            'cn_internal_links' => $this->decodeJson($row, 'CN_Internal_Links'),
            'secondary_cta' => $this->decodeJsonOrText($row, 'Secondary_CTA_URL'),
        ];
    }

    /**
     * @param  array<string, string|int>  $row
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function payload(array $row, array $decoded): array
    {
        $slug = $this->stringValue($row, 'Slug');
        $titleEn = $this->stringValue($row, 'EN_Title');
        $titleZh = $this->stringValue($row, 'CN_Title');
        $primaryCta = [
            'label' => $this->stringValue($row, 'Primary_CTA_Label'),
            'href' => $this->stringValue($row, 'Primary_CTA_URL'),
            'target_action' => $this->stringValue($row, 'Primary_CTA_Target_Action'),
            'entry_surface' => $this->stringValue($row, 'Entry_Surface'),
            'source_page_type' => $this->stringValue($row, 'Source_Page_Type'),
            'subject_kind' => $this->stringValue($row, 'Subject_Type'),
            'subject_key' => $slug,
            'test_slug' => $this->stringValue($row, 'Primary_Test_Slug'),
        ];

        $page = [
            'zh' => $this->localizedPage($row, $decoded, 'zh', $titleZh, $primaryCta),
            'en' => $this->localizedPage($row, $decoded, 'en', $titleEn, $primaryCta),
        ];

        return [
            'component_order_json' => self::COMPONENT_ORDER,
            'page_payload_json' => ['page' => $page],
            'seo_payload_json' => [
                'zh' => [
                    'title' => $this->stringValue($row, 'CN_SEO_Title'),
                    'description' => $this->stringValue($row, 'CN_SEO_Description'),
                    'h1' => $this->stringValue($row, 'CN_H1'),
                ],
                'en' => [
                    'title' => $this->stringValue($row, 'EN_SEO_Title'),
                    'description' => $this->stringValue($row, 'EN_SEO_Description'),
                    'h1' => $this->stringValue($row, 'EN_H1'),
                ],
            ],
            'sources_json' => [
                'references' => $this->sourceReferences($decoded['source_refs'] ?? null),
                'source_refs_contract' => 'claim_level_source_refs_normalized_from_workbook',
            ],
            'structured_data_json' => [
                'faq_page' => [
                    'zh' => $this->visibleFaq($decoded['cn_faq'] ?? null),
                    'en' => $this->visibleFaq($decoded['en_faq'] ?? null),
                ],
                'schema_rules' => [
                    'faq_page_source' => 'visible_faq_only',
                    'product_schema_allowed' => false,
                    'occupation_schema_generated_locally' => false,
                    'hidden_faq_schema_allowed' => false,
                    'unsafe_occupation_terms_allowed' => false,
                ],
            ],
            'implementation_contract_json' => $this->implementationContract(),
        ];
    }

    /**
     * @param  array<string, string|int>  $row
     * @param  array<string, mixed>  $decoded
     * @param  array<string, string>  $primaryCta
     * @return array<string, mixed>
     */
    private function localizedPage(array $row, array $decoded, string $locale, string $title, array $primaryCta): array
    {
        $isZh = $locale === 'zh';
        $prefix = $isZh ? 'CN' : 'EN';
        $decodedPrefix = $isZh ? 'cn' : 'en';
        $faq = $this->visibleFaq($decoded[$decodedPrefix.'_faq'] ?? null);
        $internalLinks = $decoded[$decodedPrefix.'_internal_links'] ?? [];

        return [
            'path' => '/'.$locale.'/career/jobs/'.$this->stringValue($row, 'Slug'),
            'breadcrumb' => [
                'label' => $title,
                'slug' => $this->stringValue($row, 'Slug'),
            ],
            'hero' => [
                'h1' => $this->stringValue($row, $prefix.'_H1'),
                'title' => $this->stringValue($row, $prefix.'_H1'),
                'quick_answer' => $this->stringValue($row, $prefix.'_Quick_Answer'),
            ],
            'fermat_decision_card' => [
                'title' => $isZh ? '费马快速判断' : 'Fermat Quick Fit',
                'summary' => $this->stringValue($row, $prefix.'_Quick_Answer'),
                'caveat' => $this->stringValue($row, $prefix.'_Caveat'),
            ],
            'primary_cta' => $primaryCta,
            'career_snapshot_primary_locale' => $decoded[$decodedPrefix.'_snapshot'] ?? [],
            'career_snapshot_secondary_locale' => [
                'salary_data_type' => $this->stringValue($row, 'CN_Salary_Data_Type'),
                'limitation' => $this->stringValue($row, 'CN_Snapshot_Data_Limitation'),
            ],
            'fit_decision_checklist' => $decoded[$decodedPrefix.'_how_to'] ?? [],
            'riasec_fit_block' => $decoded[$decodedPrefix.'_riasec'] ?? [],
            'personality_fit_block' => $decoded[$decodedPrefix.'_personality'] ?? [],
            'definition_block' => $this->stringValue($row, $prefix.'_Definition'),
            'responsibilities_block' => $decoded[$decodedPrefix.'_responsibilities'] ?? [],
            'work_context_block' => [
                'search_intent_type' => $this->decodeJsonOrText($row, 'Search_Intent_Type'),
                'target_queries' => $this->decodeJsonOrText($row, $prefix.'_Target_Queries'),
            ],
            'market_signal_card' => [
                'snapshot' => $decoded[$decodedPrefix.'_snapshot'] ?? [],
                'sample_only_notice' => true,
            ],
            'adjacent_career_comparison_table' => $decoded[$decodedPrefix.'_comparison'] ?? [],
            'ai_impact_table' => [
                'score_normalized' => $this->stringValue($row, 'AI_Exposure_Score_Normalized'),
                'label' => $this->stringValue($row, 'AI_Exposure_Label'),
                'source' => $this->stringValue($row, 'AI_Exposure_Source'),
                'explanation' => $decoded['ai_explanation'] ?? null,
            ],
            'career_risk_cards' => [
                'caveat' => $this->stringValue($row, $prefix.'_Caveat'),
            ],
            'contract_project_risk_block' => [
                'caveat' => $this->stringValue($row, $prefix.'_Caveat'),
            ],
            'next_steps_block' => $decoded[$decodedPrefix.'_next_steps'] ?? $this->stringValue($row, $prefix.'_Next_Steps'),
            'faq_block' => [
                'items' => $this->faqItems($faq),
            ],
            'related_next_pages' => is_array($internalLinks) ? $internalLinks : [],
            'source_card' => [
                'source_refs' => 'sources_json.references',
            ],
            'review_validity_card' => [
                'last_reviewed' => $this->stringValue($row, 'Last_Reviewed'),
                'next_review_due' => $this->stringValue($row, 'Next_Review_Due'),
            ],
            'boundary_notice' => [],
            'final_cta' => $primaryCta,
            'secondary_cta' => [
                'label' => $this->stringValue($row, 'Secondary_CTA_Label'),
                'href' => $decoded['secondary_cta'] ?? $this->stringValue($row, 'Secondary_CTA_URL'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function implementationContract(): array
    {
        return [
            'component_contract_required' => true,
            'h1_count' => 1,
            'cta_count' => 3,
            'related_links_must_validate' => true,
            'json_ld_policy' => 'derive only from visible page content',
            'validation_required_before_sitemap_llms' => [
                'final 200',
                'canonical self',
                'no noindex',
                'public cache',
                'schema valid',
                'internal links final 200',
                'CTA attribution present',
            ],
        ];
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, string|int>  $row
     */
    private function validateContent(array $row, array &$errors): void
    {
        foreach ([
            'EN_Title',
            'CN_Title',
            'EN_H1',
            'CN_H1',
            'EN_Quick_Answer',
            'CN_Quick_Answer',
            'EN_Definition',
            'CN_Definition',
            'EN_Caveat',
            'CN_Caveat',
            'EN_Next_Steps',
            'CN_Next_Steps',
        ] as $field) {
            $this->expect($this->stringValue($row, $field) !== '', "{$field} is required.", $errors);
        }
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  list<string>  $errors
     */
    private function validateSchema(array $decoded, array &$errors): void
    {
        foreach (['en_faq', 'cn_faq'] as $field) {
            $faq = $decoded[$field] ?? null;
            $this->expect($this->faqValid($faq), "{$field} must be a visible FAQPage with at least 3 questions.", $errors);
            $this->expect(! $this->containsHiddenFaq($faq), "{$field} must not contain hidden FAQ schema.", $errors);
        }

        foreach (['en_occupation', 'cn_occupation'] as $field) {
            $occupation = $decoded[$field] ?? null;
            $label = $field === 'en_occupation' ? 'EN_Occupation_Schema_JSON' : 'CN_Occupation_Schema_JSON';
            $text = $this->encodedText($occupation);
            $this->expect(is_array($occupation), "{$label} must parse as Occupation JSON.", $errors);
            $this->expect(trim((string) ($occupation['occupationalCategory'] ?? $occupation['occupationCategory'] ?? '')) !== '', "{$label} must include occupationalCategory.", $errors);
            $this->expect(! str_contains($text, 'product'), "{$label} must not include Product schema.", $errors);
            $this->expect(! str_contains($text, 'ai exposure') && ! str_contains($text, 'ai_exposure'), "{$label} must not include AI Exposure.", $errors);
            $this->expect(! str_contains($text, 'industry_proxy'), "{$label} must not include CN industry proxy wage.", $errors);
            $this->expect(! str_contains($text, 'job posting sample') && ! str_contains($text, '招聘样本'), "{$label} must not include job posting sample terms.", $errors);
        }
    }

    /**
     * @param  array<string, string|int>  $row
     * @param  list<string>  $errors
     */
    private function validateCta(array $row, string $slug, array &$errors): void
    {
        $this->expect(str_contains($this->stringValue($row, 'Primary_CTA_URL'), 'holland-career-interest-test-riasec'), 'Primary_CTA_URL must point to Holland/RIASEC.', $errors);
        $this->expect($this->stringValue($row, 'Primary_CTA_Target_Action') === 'start_riasec_test', 'Primary_CTA_Target_Action must be start_riasec_test.', $errors);
        $this->expect($this->stringValue($row, 'Entry_Surface') === 'career_job_detail', 'Entry_Surface must be career_job_detail.', $errors);
        $this->expect($this->stringValue($row, 'Source_Page_Type') === 'career_job_detail', 'Source_Page_Type must be career_job_detail.', $errors);
        $this->expect($this->stringValue($row, 'Subject_Slug') === $slug, 'Subject_Slug must match Slug.', $errors);
        $this->expect($this->stringValue($row, 'Primary_Test_Slug') === 'holland-career-interest-test-riasec', 'Primary_Test_Slug must be holland-career-interest-test-riasec.', $errors);
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateSources(mixed $sourceRefs, array &$errors): void
    {
        $text = $this->encodedText($sourceRefs);
        $this->expect(is_array($sourceRefs), 'Claim_Level_Source_Refs must parse as JSON.', $errors);
        $this->expect($this->urlCount($sourceRefs) >= 3, 'Claim_Level_Source_Refs must include source URLs.', $errors);
        $this->expect(str_contains($text, 'bls.gov') || str_contains($text, 'onetonline.org') || str_contains($text, 'government') || str_contains($text, 'official') || str_contains($text, '政府'), 'Claim_Level_Source_Refs must include an official source.', $errors);
        $this->expect((str_contains($text, 'salary') || str_contains($text, 'wage') || str_contains($text, '薪'))
            && (str_contains($text, 'growth') || str_contains($text, 'outlook') || str_contains($text, '增长'))
            && (str_contains($text, 'jobs') || str_contains($text, 'employment') || str_contains($text, '岗位')), 'Claim_Level_Source_Refs must source salary/wage, growth/outlook, and jobs/employment facts.', $errors);
        $this->expect(str_contains($text, 'fermatmind') || str_contains($text, 'interpretation') || str_contains($text, '解释'), 'Claim_Level_Source_Refs must label FermatMind interpretation.', $errors);
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateLinks(mixed $enLinks, mixed $cnLinks, array &$errors): void
    {
        $text = $this->encodedText([$enLinks, $cnLinks]);
        $this->expect(is_array($enLinks) && is_array($cnLinks), 'Internal links must parse as JSON objects or arrays.', $errors);
        $this->expect(str_contains($text, 'holland-career-interest-test-riasec') && str_contains($text, '/tests/'), 'Internal links must include stable test routes.', $errors);
        $this->expect(str_contains($text, 'render_policy') || str_contains($text, 'validation_policy') || str_contains($text, 'canonical') || str_contains($text, 'noindex'), 'Internal links must include validation policy for unvalidated jobs/guides.', $errors);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sourceReferences(mixed $sourceRefs): array
    {
        $references = [];
        $this->collectSourceReferences($sourceRefs, '', $references);

        return $references;
    }

    /**
     * @param  list<array<string, mixed>>  $references
     */
    private function collectSourceReferences(mixed $value, string $path, array &$references): void
    {
        if (! is_array($value)) {
            return;
        }

        $urls = [];
        foreach (['url', 'source_url'] as $key) {
            if (isset($value[$key]) && is_string($value[$key]) && $value[$key] !== '') {
                $urls[] = $value[$key];
            }
        }
        if (isset($value['source_urls']) && is_array($value['source_urls'])) {
            foreach ($value['source_urls'] as $url) {
                if (is_string($url) && $url !== '') {
                    $urls[] = $url;
                }
            }
        }

        if ($urls !== []) {
            $label = (string) ($value['label'] ?? $value['source'] ?? $path);
            $usage = $value['usage'] ?? $value['claim'] ?? $value['claims'] ?? null;
            foreach (array_values(array_unique($urls)) as $url) {
                $references[] = [
                    'label' => $label !== '' ? $label : 'source',
                    'url' => $url,
                    'usage' => $usage,
                    'source_type' => $this->sourceType($label, $url),
                ];
            }
        }

        foreach ($value as $key => $child) {
            $childPath = $path === '' ? (string) $key : $path.'.'.(string) $key;
            $this->collectSourceReferences($child, $childPath, $references);
        }
    }

    private function sourceType(string $label, string $url): string
    {
        $text = strtolower($label.' '.$url);
        if (str_contains($text, 'fermatmind') || str_contains($text, 'interpretation')) {
            return 'fermatmind_interpretation';
        }
        if (str_contains($text, 'bls.gov') || str_contains($text, 'onetonline.org') || str_contains($text, 'stats.gov.cn')) {
            return 'official';
        }

        return 'reference';
    }

    /**
     * @return array<string, mixed>
     */
    private function visibleFaq(mixed $faq): array
    {
        if (! is_array($faq)) {
            return ['@type' => 'FAQPage', 'mainEntity' => []];
        }

        return [
            '@context' => $faq['@context'] ?? 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_values(array_filter(
                is_array($faq['mainEntity'] ?? null) ? $faq['mainEntity'] : [],
                static fn (mixed $item): bool => is_array($item) && trim((string) ($item['name'] ?? $item['question'] ?? '')) !== '',
            )),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function faqItems(array $faq): array
    {
        $items = [];
        foreach ($faq['mainEntity'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $answer = $item['acceptedAnswer'] ?? [];
            $items[] = [
                'question' => (string) ($item['name'] ?? $item['question'] ?? ''),
                'answer' => is_array($answer) ? (string) ($answer['text'] ?? '') : (string) $answer,
            ];
        }

        return $items;
    }

    private function faqValid(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        $mainEntity = $value['mainEntity'] ?? null;

        return is_array($mainEntity)
            && count($mainEntity) >= 3
            && array_reduce(
                $mainEntity,
                static fn (bool $carry, mixed $item): bool => $carry
                    && is_array($item)
                    && trim((string) ($item['name'] ?? $item['question'] ?? '')) !== '',
                true,
            );
    }

    private function containsHiddenFaq(mixed $value): bool
    {
        return str_contains($this->encodedText($value), 'hidden_faq')
            || str_contains($this->encodedText($value), 'hidden faq')
            || str_contains($this->encodedText($value), 'not_visible');
    }

    /**
     * @param  array<string, mixed>  $payloads
     * @return list<string>
     */
    private function forbiddenPublicKeys(array $payloads): array
    {
        $found = [];
        foreach ($payloads as $payloadName => $payload) {
            $this->collectForbiddenKeys($payload, $payloadName, $found);
        }

        sort($found);

        return array_values(array_unique($found));
    }

    /**
     * @param  list<string>  $found
     */
    private function collectForbiddenKeys(mixed $value, string $path, array &$found): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $childPath = $path.'.'.$key;
            if (is_string($key) && in_array($key, self::FORBIDDEN_PUBLIC_KEYS, true)) {
                $found[] = $childPath;
            }

            $this->collectForbiddenKeys($child, $childPath, $found);
        }
    }

    /**
     * @param  array<string, string|int>  $row
     */
    private function decodeJson(array $row, string $key): mixed
    {
        $value = $this->stringValue($row, $key);
        if ($value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    /**
     * @param  array<string, string|int>  $row
     */
    private function decodeJsonOrText(array $row, string $key): mixed
    {
        $value = $this->stringValue($row, $key);
        if ($value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function encodedText(mixed $value): string
    {
        if (is_string($value)) {
            return strtolower($value);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return strtolower(is_string($encoded) ? $encoded : '');
    }

    private function urlCount(mixed $value): int
    {
        preg_match_all('/https?:\\/\\//i', $this->encodedText($value), $matches);

        return count($matches[0]);
    }

    /**
     * @param  array<string, string|int>  $row
     */
    private function stringValue(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }

    /**
     * @param  list<string>  $errors
     */
    private function expect(bool $condition, string $message, array &$errors): void
    {
        if (! $condition) {
            $errors[] = $message;
        }
    }

    private function resolveSheetPath(ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (! is_string($workbookXml) || ! is_string($relsXml)) {
            throw new RuntimeException('Invalid XLSX workbook: missing workbook relationships.');
        }

        $workbook = $this->loadXml($workbookXml);
        $xpath = new DOMXPath($workbook);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $sheet = $xpath->query('//x:sheet[@name="'.self::SHEET_NAME.'"]')->item(0);
        if (! $sheet instanceof DOMElement) {
            throw new RuntimeException(self::SHEET_NAME.' sheet not found.');
        }

        $relationshipId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        if ($relationshipId === '') {
            throw new RuntimeException(self::SHEET_NAME.' sheet relationship not found.');
        }

        $rels = $this->loadXml($relsXml);
        $relXpath = new DOMXPath($rels);
        $relXpath->registerNamespace('rel', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $relationship = $relXpath->query('//rel:Relationship[@Id="'.$relationshipId.'"]')->item(0);
        if (! $relationship instanceof DOMElement) {
            throw new RuntimeException(self::SHEET_NAME.' sheet target not found.');
        }

        $target = ltrim($relationship->getAttribute('Target'), '/');
        if ($target === '') {
            throw new RuntimeException(self::SHEET_NAME.' sheet target is empty.');
        }

        return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
    }

    /**
     * @return list<string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! is_string($xml)) {
            return [];
        }

        $document = $this->loadXml($xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $strings = [];
        foreach ($xpath->query('//x:si') as $item) {
            $strings[] = $this->collectText($xpath, $item);
        }

        return $strings;
    }

    /**
     * @param  list<string>  $sharedStrings
     * @param  list<string>  $slugs
     * @return array{headers: list<string>, rows: list<array<string, string|int>>, total_rows: int}
     */
    private function readSheetXml(string $workbookPath, string $sheetPath, array $sharedStrings, array $slugs): array
    {
        if (! class_exists(XMLReader::class)) {
            throw new RuntimeException('XMLReader extension is required to read large XLSX workbooks.');
        }

        $headers = [];
        $rows = [];
        $totalRows = 0;
        $allowlist = array_fill_keys($slugs, true);
        $reader = new XMLReader;
        $uri = 'zip://'.$workbookPath.'#'.$sheetPath;
        if ($reader->open($uri) !== true) {
            throw new RuntimeException('Unable to stream workbook sheet: '.$sheetPath);
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $rowXml = $reader->readOuterXml();
                if ($rowXml === '') {
                    continue;
                }

                $document = $this->loadXml($rowXml);
                $xpath = new DOMXPath($document);
                $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $rowNode = $document->documentElement;
                if (! $rowNode instanceof DOMElement) {
                    continue;
                }

                $cells = [];
                foreach ($xpath->query('x:c', $rowNode) as $cellNode) {
                    if (! $cellNode instanceof DOMElement) {
                        continue;
                    }
                    $cells[$this->columnIndex($cellNode->getAttribute('r'))] = $this->readCellValue($xpath, $cellNode, $sharedStrings);
                }

                if ($cells === []) {
                    continue;
                }

                ksort($cells);
                $maxIndex = max(array_keys($cells));
                $values = [];
                for ($index = 0; $index <= $maxIndex; $index++) {
                    $values[$index] = $cells[$index] ?? '';
                }

                if ($this->valuesAreEmpty($values)) {
                    continue;
                }

                if ($headers === []) {
                    $headers = array_values(array_map(static fn (mixed $value): string => trim((string) $value), $values));

                    continue;
                }

                $assoc = [];
                foreach ($headers as $index => $header) {
                    if ($header !== '') {
                        $assoc[$header] = (string) ($values[$index] ?? '');
                    }
                }
                $totalRows++;
                $slug = strtolower(trim((string) ($assoc['Slug'] ?? '')));
                if (! isset($allowlist[$slug])) {
                    continue;
                }

                $assoc['_row_number'] = (int) $rowNode->getAttribute('r');
                $rows[] = $assoc;
            }
        } finally {
            $reader->close();
        }

        if ($headers === []) {
            throw new RuntimeException(self::SHEET_NAME.' sheet has no header row.');
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'total_rows' => $totalRows,
        ];
    }

    /**
     * @param  list<string>  $sharedStrings
     */
    private function readCellValue(DOMXPath $xpath, DOMElement $cell, array $sharedStrings): string
    {
        $type = $cell->getAttribute('t');
        if ($type === 'inlineStr') {
            $inline = $xpath->query('x:is', $cell)->item(0);

            return $inline instanceof DOMNode ? $this->collectText($xpath, $inline) : '';
        }

        $value = $xpath->query('x:v', $cell)->item(0)?->textContent ?? '';
        if ($type === 's') {
            return $sharedStrings[(int) $value] ?? '';
        }

        return trim($value);
    }

    private function collectText(DOMXPath $xpath, DOMNode $node): string
    {
        $text = '';
        foreach ($xpath->query('.//x:t', $node) as $textNode) {
            $text .= $textNode->textContent;
        }

        return $text;
    }

    private function columnIndex(string $cellRef): int
    {
        if (! preg_match('/^([A-Z]+)/', $cellRef, $matches)) {
            return 0;
        }

        $index = 0;
        foreach (str_split($matches[1]) as $char) {
            $index = ($index * 26) + (ord($char) - ord('A') + 1);
        }

        return $index - 1;
    }

    /**
     * @param  list<string>  $values
     */
    private function valuesAreEmpty(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function loadXml(string $xml): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if ($document->loadXML($xml) !== true) {
                throw new RuntimeException('Invalid XLSX XML part.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }
}
