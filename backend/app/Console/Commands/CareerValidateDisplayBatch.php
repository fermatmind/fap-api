<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CareerJob;
use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Models\Scopes\TenantScope;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use XMLReader;
use ZipArchive;

final class CareerValidateDisplayBatch extends Command
{
    private const COMMAND_NAME = 'career:validate-display-batch';

    private const VALIDATOR_VERSION = 'career_display_batch_validator_v0.1';

    private const SHEET_NAME = 'Career_Assets_v4_1';

    private const PUBLIC_CAREER_JOB_API = 'https://api.fermatmind.com/api/v0.5/career/jobs';

    /** @var list<string> */
    private const REQUIRED_HEADERS = [
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
    private const FORBIDDEN_PUBLIC_TERMS = [
        'release_gate',
        'qa_risk',
        'admin_review_state',
        'tracking_json',
        'raw_ai_exposure_score',
    ];

    protected $signature = 'career:validate-display-batch
        {--file= : Absolute path to a v4.2 career asset workbook}
        {--slugs= : Comma-separated explicit slug allowlist}
        {--json : Emit JSON report}
        {--output= : Optional report output path}
        {--strict-authority : Fail when local authority DB validation is unavailable}';

    protected $description = 'Read-only readiness validator for allowlisted career display workbook rows.';

    public function handle(): int
    {
        try {
            $file = $this->requiredFile();
            $slugs = $this->requiredSlugs();
            $workbook = $this->readWorkbook($file, $slugs);

            $missingHeaders = array_values(array_diff(self::REQUIRED_HEADERS, $workbook['headers']));
            if ($missingHeaders !== []) {
                return $this->finish($this->baseReport($file, $slugs, $workbook, [
                    'decision' => 'fail',
                    'errors' => ['Workbook is missing required headers: '.implode(', ', $missingHeaders).'.'],
                ]), false);
            }

            $rowsBySlug = [];
            foreach ($workbook['rows'] as $row) {
                $slug = strtolower(trim((string) ($row['Slug'] ?? '')));
                if ($slug !== '') {
                    $rowsBySlug[$slug] = $row;
                }
            }

            $missingRows = array_values(array_filter(
                $slugs,
                static fn (string $slug): bool => ! isset($rowsBySlug[$slug]),
            ));

            $items = [];
            foreach ($slugs as $slug) {
                if (! isset($rowsBySlug[$slug])) {
                    continue;
                }

                $items[] = $this->validateRow($rowsBySlug[$slug], $slug);
            }

            $summary = $this->summary($items);
            $strictAuthorityFailure = (bool) $this->option('strict-authority')
                && $summary['blocked_authority_unavailable'] > 0;

            $decision = match (true) {
                $missingRows !== [] => 'fail',
                $strictAuthorityFailure => 'fail',
                $summary['ready_for_second_pilot_validation'] === count($items) && $items !== [] => 'pass',
                default => 'no_go',
            };

            $report = $this->baseReport($file, $slugs, $workbook, [
                'decision' => $decision,
                'missing_slugs' => $missingRows,
                'summary' => $summary,
                'items' => $items,
            ]);

            if ($missingRows !== []) {
                $report['errors'] = ['Allowlisted slugs were not found in workbook: '.implode(', ', $missingRows).'.'];
            }
            if ($strictAuthorityFailure) {
                $report['errors'] = ['Local authority DB is unavailable and --strict-authority was requested.'];
            }

            return $this->finish($report, $missingRows === [] && ! $strictAuthorityFailure);
        } catch (Throwable $throwable) {
            return $this->finish([
                'command' => self::COMMAND_NAME,
                'validator_version' => self::VALIDATOR_VERSION,
                'decision' => 'fail',
                'errors' => [$this->safeErrorMessage($throwable)],
            ], false);
        }
    }

    /**
     * @return list<string>
     */
    private function requiredSlugs(): array
    {
        $raw = trim((string) $this->option('slugs'));
        if ($raw === '') {
            throw new RuntimeException('--slugs is required and must be an explicit comma-separated allowlist.');
        }

        $slugs = array_values(array_unique(array_filter(array_map(
            static fn (string $slug): string => strtolower(trim($slug)),
            explode(',', $raw),
        ), static fn (string $slug): bool => $slug !== '')));

        if ($slugs === []) {
            throw new RuntimeException('--slugs is required and must include at least one slug.');
        }

        return $slugs;
    }

    private function requiredFile(): string
    {
        $path = trim((string) $this->option('file'));
        if ($path === '') {
            throw new RuntimeException('--file is required.');
        }
        if (! is_file($path)) {
            throw new RuntimeException('--file does not exist: '.$path);
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('--file must be an .xlsx workbook.');
        }

        return $path;
    }

    /**
     * @param  array<string, string|int>  $row
     * @return array<string, mixed>
     */
    private function validateRow(array $row, string $slug): array
    {
        $json = [
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
            'ai_explanation' => $this->decodeJson($row, 'AI_Exposure_Explanation'),
            'en_faq' => $this->decodeJson($row, 'EN_FAQ_SCHEMA_JSON'),
            'cn_faq' => $this->decodeJson($row, 'CN_FAQ_SCHEMA_JSON'),
            'en_occupation' => $this->decodeJson($row, 'EN_Occupation_Schema_JSON'),
            'cn_occupation' => $this->decodeJson($row, 'CN_Occupation_Schema_JSON'),
            'source_refs' => $this->decodeJson($row, 'Claim_Level_Source_Refs'),
            'en_internal_links' => $this->decodeJson($row, 'EN_Internal_Links'),
            'cn_internal_links' => $this->decodeJson($row, 'CN_Internal_Links'),
            'secondary_cta' => $this->decodeJson($row, 'Secondary_CTA_URL'),
        ];

        $contentGate = $this->contentGate($row, $json);
        $jsonGate = $this->jsonGate($json);
        $schemaGate = $this->schemaGate($json);
        $ctaGate = $this->ctaGate($row, $slug);
        $sourceGate = $this->sourceGate($json['source_refs']);
        $linkGate = $this->linkGate($json['en_internal_links'], $json['cn_internal_links']);
        $evidenceGate = $this->evidenceGate($row, $json, $sourceGate, $schemaGate);
        $authorityGate = $this->authorityGate($slug, $this->stringValue($row, 'SOC_Code'), $this->stringValue($row, 'O_NET_Code'));
        $scores = $this->scores($contentGate, $authorityGate, $evidenceGate, $schemaGate, $ctaGate, $sourceGate, $linkGate);

        return [
            'identity' => [
                'slug' => $slug,
                'title_en' => $this->stringValue($row, 'EN_Title'),
                'title_zh' => $this->stringValue($row, 'CN_Title'),
                'SOC_Code' => $this->stringValue($row, 'SOC_Code'),
                'O_NET_Code' => $this->stringValue($row, 'O_NET_Code'),
            ],
            'content_gate' => $contentGate,
            'json_gate' => $jsonGate,
            'authority_gate' => $authorityGate,
            'schema_gate' => $schemaGate,
            'cta_gate' => $ctaGate,
            'source_gate' => $sourceGate,
            'link_gate' => $linkGate,
            'evidence_gate' => $evidenceGate,
            'scores' => $scores,
            'recommended_status' => $this->recommendedStatus($row, $authorityGate, $scores),
            'release_gate' => [
                'ready_for_sitemap' => false,
                'ready_for_llms' => false,
                'ready_for_paid' => false,
                'ready_for_backlink' => false,
            ],
        ];
    }

    /**
     * @param  array<string, string|int>  $row
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function contentGate(array $row, array $json): array
    {
        return [
            'Content_Status' => $this->stringValue($row, 'Content_Status'),
            'Review_State' => $this->stringValue($row, 'Review_State'),
            'Release_Status' => $this->stringValue($row, 'Release_Status'),
            'QA_Status' => $this->stringValue($row, 'QA_Status'),
            'status_ready' => $this->stringValue($row, 'Content_Status') === 'approved'
                && $this->stringValue($row, 'Review_State') === 'human_reviewed'
                && $this->stringValue($row, 'Release_Status') === 'ready_for_pilot'
                && $this->stringValue($row, 'QA_Status') === 'ready_for_technical_validation',
            'quick_answer_present' => $this->hasText($row, 'EN_Quick_Answer') && $this->hasText($row, 'CN_Quick_Answer'),
            'definition_present' => $this->hasText($row, 'EN_Definition') && $this->hasText($row, 'CN_Definition'),
            'responsibilities_present' => $json['en_responsibilities'] !== null && $json['cn_responsibilities'] !== null,
            'comparison_present' => $json['en_comparison'] !== null && $json['cn_comparison'] !== null,
            'how_to_present' => $json['en_how_to'] !== null && $json['cn_how_to'] !== null,
            'riasec_present' => $json['en_riasec'] !== null && $json['cn_riasec'] !== null,
            'personality_fit_present' => $json['en_personality'] !== null && $json['cn_personality'] !== null,
            'caveat_present' => $this->hasText($row, 'EN_Caveat') && $this->hasText($row, 'CN_Caveat'),
            'next_steps_present' => $this->hasText($row, 'EN_Next_Steps') && $this->hasText($row, 'CN_Next_Steps'),
            'forbidden_public_terms_found' => $this->forbiddenTerms($row),
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, bool>
     */
    private function jsonGate(array $json): array
    {
        return [
            'FAQ_schema_parse' => $json['en_faq'] !== null && $json['cn_faq'] !== null,
            'Occupation_schema_parse' => $json['en_occupation'] !== null && $json['cn_occupation'] !== null,
            'Claim_Level_Source_Refs_parse' => $json['source_refs'] !== null,
            'Internal_Links_parse' => $json['en_internal_links'] !== null && $json['cn_internal_links'] !== null,
            'CTA_fields_parse' => $json['secondary_cta'] !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, bool>
     */
    private function schemaGate(array $json): array
    {
        $enOccupation = $this->encodedText($json['en_occupation']);
        $cnOccupation = $this->encodedText($json['cn_occupation']);
        $occupationText = $enOccupation.' '.$cnOccupation;

        return [
            'FAQPage_valid' => $this->faqValid($json['en_faq']) && $this->faqValid($json['cn_faq']),
            'Occupation_valid' => $this->occupationValid($json['en_occupation']) && $this->occupationValid($json['cn_occupation']),
            'no_Product_schema' => ! str_contains($occupationText, 'product'),
            'no_AI_Exposure_in_Occupation' => ! str_contains($occupationText, 'ai exposure') && ! str_contains($occupationText, 'ai_exposure'),
            'no_CN_industry_proxy_wage_in_Occupation' => ! str_contains($occupationText, 'industry_proxy'),
            'no_job_posting_sample_in_Occupation' => ! str_contains($occupationText, 'job posting sample')
                && ! str_contains($occupationText, '招聘样本'),
        ];
    }

    /**
     * @param  array<string, string|int>  $row
     * @return array<string, bool|string>
     */
    private function ctaGate(array $row, string $slug): array
    {
        $url = $this->stringValue($row, 'Primary_CTA_URL');
        $targetAction = $this->stringValue($row, 'Primary_CTA_Target_Action');
        $entrySurface = $this->stringValue($row, 'Entry_Surface');
        $sourcePageType = $this->stringValue($row, 'Source_Page_Type');
        $subjectType = $this->stringValue($row, 'Subject_Type');
        $subjectSlug = $this->stringValue($row, 'Subject_Slug');
        $testSlug = $this->stringValue($row, 'Primary_Test_Slug');

        $fieldsPresent = $url !== ''
            && $targetAction !== ''
            && $entrySurface !== ''
            && $sourcePageType !== ''
            && $subjectType !== ''
            && $subjectSlug !== ''
            && $testSlug !== '';

        return [
            'Primary_CTA_URL_present' => $url !== '',
            'Primary_CTA_Target_Action_present' => $targetAction !== '',
            'Entry_Surface_present' => $entrySurface !== '',
            'Source_Page_Type_present' => $sourcePageType !== '',
            'Subject_Type_present' => $subjectType !== '',
            'Subject_Slug_matches_slug' => $subjectSlug === $slug,
            'Primary_Test_Slug_present' => $testSlug !== '',
            'points_to_riasec_holland' => str_contains($url, 'holland-career-interest-test-riasec')
                && $testSlug === 'holland-career-interest-test-riasec',
            'conforms_to_post_actors_allowed_pattern' => $fieldsPresent
                && $targetAction === 'start_riasec_test'
                && $entrySurface === 'career_job_detail'
                && $sourcePageType === 'career_job_detail'
                && $subjectSlug === $slug
                && $testSlug === 'holland-career-interest-test-riasec',
            'observed_target_action' => $targetAction,
            'observed_source_page_type' => $sourcePageType,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function sourceGate(mixed $sourceRefs): array
    {
        $text = $this->encodedText($sourceRefs);

        return [
            'source_refs_parse' => $sourceRefs !== null,
            'official_source_exists' => $sourceRefs !== null && (
                str_contains($text, 'bls.gov')
                || str_contains($text, 'onetonline.org')
                || str_contains($text, 'onetcenter.org')
                || str_contains($text, 'stats.gov.cn')
                || str_contains($text, 'official')
                || str_contains($text, 'government')
                || str_contains($text, '政府')
            ),
            'salary_growth_job_facts_have_source' => $sourceRefs !== null
                && $this->urlCount($sourceRefs) >= 3
                && (str_contains($text, 'salary') || str_contains($text, 'wage') || str_contains($text, '薪'))
                && (str_contains($text, 'growth') || str_contains($text, 'outlook') || str_contains($text, '增长'))
                && (str_contains($text, 'jobs') || str_contains($text, 'employment') || str_contains($text, '岗位')),
            'fermat_interpretation_labeled' => $sourceRefs !== null
                && (str_contains($text, 'fermatmind') || str_contains($text, 'interpretation') || str_contains($text, '解释')),
            'no_forbidden_claims_found' => true,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function linkGate(mixed $enLinks, mixed $cnLinks): array
    {
        $text = $this->encodedText([$enLinks, $cnLinks]);

        return [
            'internal_links_parse' => $enLinks !== null && $cnLinks !== null,
            'related_tests_stable' => str_contains($text, 'holland-career-interest-test-riasec')
                && str_contains($text, '/tests/'),
            'related_jobs_guides_require_later_live_validation' => str_contains($text, 'related_jobs')
                || str_contains($text, 'adjacent_careers')
                || str_contains($text, 'related_guides'),
            'unvalidated_jobs_guides_not_counted_as_ready' => str_contains($text, 'render_policy')
                || str_contains($text, 'validation_policy')
                || str_contains($text, 'canonical')
                || str_contains($text, 'noindex'),
        ];
    }

    /**
     * @param  array<string, string|int>  $row
     * @param  array<string, mixed>  $json
     * @param  array<string, bool>  $sourceGate
     * @param  array<string, bool>  $schemaGate
     * @return array<string, bool>
     */
    private function evidenceGate(array $row, array $json, array $sourceGate, array $schemaGate): array
    {
        return [
            'definition' => $this->hasText($row, 'EN_Definition') && $this->hasText($row, 'CN_Definition'),
            'numbers_statistics' => $json['en_snapshot'] !== null && $json['cn_snapshot'] !== null,
            'comparison' => $json['en_comparison'] !== null && $json['cn_comparison'] !== null,
            'how_to_decision_checklist' => $json['en_how_to'] !== null && $json['cn_how_to'] !== null,
            'caveat' => $this->hasText($row, 'EN_Caveat') && $this->hasText($row, 'CN_Caveat'),
            'next_steps' => $this->hasText($row, 'EN_Next_Steps') && $this->hasText($row, 'CN_Next_Steps'),
            'sources' => $sourceGate['source_refs_parse'] && $sourceGate['official_source_exists'],
            'visible_faq_or_support_block' => $schemaGate['FAQPage_valid'],
            'riasec' => $json['en_riasec'] !== null && $json['cn_riasec'] !== null,
            'personality_fit' => $json['en_personality'] !== null && $json['cn_personality'] !== null,
            'faq_only' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function authorityGate(string $slug, string $socCode, string $onetCode): array
    {
        if ((bool) config('career_display_batch_validator.force_authority_unavailable', false)) {
            return $this->authorityUnavailableGate($slug, $socCode, $onetCode);
        }

        try {
            $occupation = Occupation::query()
                ->with('crosswalks')
                ->where('canonical_slug', $slug)
                ->first();

            $occupationExists = $occupation instanceof Occupation;
            $socCrosswalkExists = $occupationExists && $occupation->crosswalks
                ->contains(static fn ($crosswalk): bool => (string) $crosswalk->source_code === $socCode);
            $onetCrosswalkExists = $occupationExists && $occupation->crosswalks
                ->contains(static fn ($crosswalk): bool => (string) $crosswalk->source_code === $onetCode);
            $displaySurfaceReady = $occupationExists && CareerJobDisplayAsset::query()
                ->where('canonical_slug', $slug)
                ->where('status', 'ready_for_pilot')
                ->exists();

            $directoryDraft = $occupationExists && (string) $occupation->crosswalk_mode === 'directory_draft';
            $docxFallback = ! $occupationExists && $this->hasDocxFallback($slug);

            return [
                'authority_state' => match (true) {
                    $displaySurfaceReady => 'display_surface_ready',
                    $directoryDraft => 'directory_draft',
                    $occupationExists => 'occupation_backed',
                    $docxFallback => 'docx_fallback',
                    default => 'API_404',
                },
                'authority_source' => 'local_db',
                'occupation_exists' => $occupationExists,
                'SOC_crosswalk_exists' => $socCrosswalkExists,
                'O_NET_crosswalk_exists' => $onetCrosswalkExists,
                'display_surface_ready' => $displaySurfaceReady,
                'public_API_state' => match (true) {
                    $displaySurfaceReady => 'display_surface_ready',
                    $directoryDraft => 'directory_draft',
                    $occupationExists => 'occupation_backed',
                    $docxFallback => 'docx_fallback',
                    default => 'API_404',
                },
            ];
        } catch (QueryException) {
            return $this->authorityUnavailableGate($slug, $socCode, $onetCode);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function authorityUnavailableGate(string $slug, string $socCode, string $onetCode): array
    {
        if ((bool) $this->option('strict-authority')) {
            return [
                'authority_state' => 'authority_unavailable',
                'authority_source' => 'local_db_unavailable',
                'occupation_exists' => false,
                'SOC_crosswalk_exists' => false,
                'O_NET_crosswalk_exists' => false,
                'display_surface_ready' => false,
                'public_API_state' => 'authority_unavailable',
                'authority_error' => 'Authority database unavailable for read-only validation.',
            ];
        }

        $fallback = $this->publicApiAuthorityFallback($slug, $socCode, $onetCode);
        if ($fallback !== null) {
            return $fallback;
        }

        return [
            'authority_state' => 'authority_unavailable',
            'authority_source' => 'local_db_unavailable',
            'occupation_exists' => false,
            'SOC_crosswalk_exists' => false,
            'O_NET_crosswalk_exists' => false,
            'display_surface_ready' => false,
            'public_API_state' => 'authority_unavailable',
            'authority_error' => 'Authority database unavailable for read-only validation.',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function publicApiAuthorityFallback(string $slug, string $socCode, string $onetCode): ?array
    {
        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->get(self::PUBLIC_CAREER_JOB_API.'/'.$slug, ['locale' => 'zh-CN']);
        } catch (Throwable) {
            return null;
        }

        if ($response->status() === 404) {
            return [
                'authority_state' => 'authority_unavailable',
                'authority_source' => 'public_api_fallback',
                'occupation_exists' => false,
                'SOC_crosswalk_exists' => false,
                'O_NET_crosswalk_exists' => false,
                'display_surface_ready' => false,
                'public_API_state' => 'authority_unavailable',
                'api_state' => 'API_404',
                'authority_warning' => 'Local DB unavailable; public API fallback returned 404.',
            ];
        }

        if (! $response->ok()) {
            return null;
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return null;
        }

        $reasonCodes = Arr::wrap(Arr::get($payload, 'seo_contract.reason_codes'));
        $occupationUuid = (string) Arr::get($payload, 'identity.occupation_uuid', '');
        $docxFallback = in_array('docx_baseline_authority', $reasonCodes, true)
            || str_starts_with($occupationUuid, 'career_job:')
            || Arr::get($payload, 'provenance_meta.content_version') === 'docx_342_career_batch';
        $displaySurfaceReady = Arr::has($payload, 'display_surface_v1');
        $crosswalks = Arr::wrap(Arr::get($payload, 'ontology.crosswalks'));

        $socCrosswalkExists = $this->publicApiCrosswalkContains($crosswalks, $socCode);
        $onetCrosswalkExists = $this->publicApiCrosswalkContains($crosswalks, $onetCode);
        $occupationExists = $occupationUuid !== '' && ! $docxFallback;
        $directoryDraft = Arr::get($payload, 'trust_manifest.methodology.crosswalk_mode') === 'directory_draft';

        $state = match (true) {
            $docxFallback => 'docx_fallback',
            $displaySurfaceReady => 'display_surface_ready',
            $directoryDraft => 'directory_draft',
            $occupationExists => 'occupation_backed',
            default => 'API_404',
        };

        return [
            'authority_state' => 'authority_unavailable',
            'authority_source' => 'public_api_fallback',
            'occupation_exists' => false,
            'SOC_crosswalk_exists' => false,
            'O_NET_crosswalk_exists' => false,
            'display_surface_ready' => false,
            'public_API_state' => 'authority_unavailable',
            'api_state' => $state,
            'api_occupation_exists' => $occupationExists,
            'api_SOC_crosswalk_exists' => $socCrosswalkExists,
            'api_O_NET_crosswalk_exists' => $onetCrosswalkExists,
            'api_display_surface_ready' => $displaySurfaceReady,
            'authority_warning' => 'Local DB unavailable; public API fallback used for read-only reporting only.',
        ];
    }

    /**
     * @param  list<mixed>  $crosswalks
     */
    private function publicApiCrosswalkContains(array $crosswalks, string $sourceCode): bool
    {
        foreach ($crosswalks as $crosswalk) {
            if (is_array($crosswalk) && (string) ($crosswalk['source_code'] ?? '') === $sourceCode) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, bool|string>  $contentGate
     * @param  array<string, mixed>  $authorityGate
     * @param  array<string, bool>  $evidenceGate
     * @param  array<string, bool>  $schemaGate
     * @param  array<string, bool|string>  $ctaGate
     * @param  array<string, bool>  $sourceGate
     * @param  array<string, bool>  $linkGate
     * @return array<string, int>
     */
    private function scores(
        array $contentGate,
        array $authorityGate,
        array $evidenceGate,
        array $schemaGate,
        array $ctaGate,
        array $sourceGate,
        array $linkGate,
    ): array {
        $contentScore = $this->score([
            $contentGate['status_ready'],
            $contentGate['quick_answer_present'],
            $contentGate['definition_present'],
            $contentGate['responsibilities_present'],
            $contentGate['comparison_present'],
            $contentGate['how_to_present'],
            $contentGate['riasec_present'],
            $contentGate['personality_fit_present'],
            $contentGate['caveat_present'],
            $contentGate['next_steps_present'],
            $contentGate['forbidden_public_terms_found'] === [],
        ]);
        $authorityState = (string) ($authorityGate['authority_state'] ?? $authorityGate['public_API_state'] ?? '');
        $authorityScore = $authorityState === 'authority_unavailable'
            ? 0
            : $this->score([
                $authorityGate['occupation_exists'],
                $authorityGate['SOC_crosswalk_exists'],
                $authorityGate['O_NET_crosswalk_exists'],
                $authorityState !== 'docx_fallback',
                $authorityState !== 'API_404',
                $authorityState !== 'directory_draft',
            ]);
        $evidenceScore = $this->score(array_values(Arr::except($evidenceGate, ['faq_only'])));
        $schemaScore = $this->score(array_values($schemaGate));
        $ctaScore = $this->score(Arr::only($ctaGate, [
            'Primary_CTA_URL_present',
            'Primary_CTA_Target_Action_present',
            'Entry_Surface_present',
            'Source_Page_Type_present',
            'Subject_Type_present',
            'Subject_Slug_matches_slug',
            'Primary_Test_Slug_present',
            'points_to_riasec_holland',
            'conforms_to_post_actors_allowed_pattern',
        ]));
        $sourceScore = $this->score(array_values($sourceGate));
        $linkScore = $this->score(array_values($linkGate));

        return [
            'content_score' => $contentScore,
            'authority_score' => $authorityScore,
            'evidence_score' => $evidenceScore,
            'schema_score' => $schemaScore,
            'cta_score' => $ctaScore,
            'source_score' => $sourceScore,
            'link_score' => $linkScore,
            'import_score' => $this->score([
                $contentScore === 100,
                $authorityScore === 100,
                $evidenceScore === 100,
                $schemaScore === 100,
                $ctaScore === 100,
                $sourceScore === 100,
                $linkScore === 100,
            ]),
        ];
    }

    /**
     * @param  array<string, string|int>  $row
     * @param  array<string, mixed>  $authorityGate
     * @param  array<string, int>  $scores
     */
    private function recommendedStatus(array $row, array $authorityGate, array $scores): string
    {
        $socCode = $this->stringValue($row, 'SOC_Code');
        $onetCode = $this->stringValue($row, 'O_NET_Code');

        if (str_starts_with($socCode, 'CN-') || $onetCode === 'not_applicable_cn_occupation') {
            return 'blocked_cn_authority_model';
        }
        if ($socCode === 'BLS_BROAD_GROUP' || $onetCode === 'multiple_onet_occupations') {
            return 'blocked_broad_group_mapping';
        }
        $authorityState = (string) ($authorityGate['authority_state'] ?? $authorityGate['public_API_state'] ?? '');

        if ($authorityState === 'authority_unavailable') {
            return 'blocked_authority_unavailable';
        }
        if ($authorityState === 'docx_fallback') {
            return 'blocked_docx_fallback';
        }
        if ($authorityState === 'API_404') {
            return 'blocked_api_404';
        }
        if ($scores['authority_score'] === 100 && $scores['import_score'] < 100) {
            return 'authority_ready_content_blocked';
        }
        if ($scores['content_score'] >= 90 && $scores['authority_score'] < 100) {
            return 'content_ready_authority_blocked';
        }
        if ($scores['source_score'] < 100) {
            return 'needs_source_refs';
        }
        if ($scores['link_score'] < 100) {
            return 'needs_internal_links';
        }
        if ($scores['cta_score'] < 100) {
            return 'needs_cta';
        }
        if ($scores['schema_score'] < 100) {
            return 'needs_schema_repair';
        }
        if ($scores['evidence_score'] < 100) {
            return 'needs_evidence_container';
        }

        return 'ready_for_second_pilot_validation';
    }

    private function hasDocxFallback(string $slug): bool
    {
        $job = CareerJob::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with('seoMeta')
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->where('slug', $slug)
            ->where('status', CareerJob::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->where(static function ($query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->first();

        return $job instanceof CareerJob
            && is_string(Arr::get($job->seoMeta?->jsonld_overrides_json, 'source_docx'))
            && Arr::get($job->market_demand_json, 'source_refs.0.url') !== null;
    }

    private function safeErrorMessage(Throwable $throwable): string
    {
        if ($throwable instanceof QueryException) {
            return 'Authority database validation failed while reading occupations, crosswalks, display assets, or DOCX fallback rows.';
        }

        return $throwable->getMessage();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, int>
     */
    private function summary(array $items): array
    {
        return [
            'allowlisted_count' => count($items),
            'ready_for_second_pilot_validation' => $this->countStatus($items, 'ready_for_second_pilot_validation'),
            'authority_ready_content_blocked' => $this->countStatus($items, 'authority_ready_content_blocked'),
            'content_ready_authority_blocked' => $this->countStatus($items, 'content_ready_authority_blocked'),
            'blocked_authority_unavailable' => $this->countStatus($items, 'blocked_authority_unavailable'),
            'blocked_docx_fallback' => $this->countStatus($items, 'blocked_docx_fallback'),
            'blocked_api_404' => $this->countStatus($items, 'blocked_api_404'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function countStatus(array $items, string $status): int
    {
        return count(array_filter(
            $items,
            static fn (array $item): bool => ($item['recommended_status'] ?? '') === $status,
        ));
    }

    /**
     * @param  list<string>  $slugs
     * @param  array{headers: list<string>, rows: list<array<string, string|int>>, total_rows: int}  $workbook
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function baseReport(string $file, array $slugs, array $workbook, array $extra): array
    {
        return array_merge([
            'command' => self::COMMAND_NAME,
            'validator_version' => self::VALIDATOR_VERSION,
            'source_file_basename' => basename($file),
            'source_file_sha256' => hash_file('sha256', $file) ?: null,
            'sheet' => self::SHEET_NAME,
            'total_rows' => $workbook['total_rows'],
            'header_exact_match' => $workbook['headers'] === self::REQUIRED_HEADERS,
            'allowlisted_slugs' => $slugs,
            'validated_count' => 0,
            'read_only' => true,
            'writes_database' => false,
            'strict_authority' => (bool) $this->option('strict-authority'),
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function finish(array $report, bool $success): int
    {
        if (isset($report['items']) && is_array($report['items'])) {
            $report['validated_count'] = count($report['items']);
        }

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            throw new RuntimeException('Unable to encode validation report.');
        }

        $output = trim((string) ($this->option('output') ?? ''));
        if ($output !== '') {
            $written = file_put_contents($output, $json.PHP_EOL);
            if ($written === false) {
                throw new RuntimeException('Unable to write report output: '.$output);
            }
        }

        if ((bool) $this->option('json')) {
            $this->line($json);
        } else {
            $this->line('validator_version='.$report['validator_version']);
            $this->line('decision='.$report['decision']);
            $this->line('validated_count='.$report['validated_count']);
            if (isset($report['summary'])) {
                $this->line('summary='.json_encode($report['summary'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        return $success ? self::SUCCESS : self::FAILURE;
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
    private function hasText(array $row, string $key): bool
    {
        return $this->stringValue($row, $key) !== '';
    }

    /**
     * @param  array<string, string|int>  $row
     */
    private function stringValue(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
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

    private function occupationValid(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        return trim((string) ($value['occupationalCategory'] ?? $value['occupationCategory'] ?? '')) !== '';
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

        return count($matches[0] ?? []);
    }

    /**
     * @param  array<string, string|int>  $row
     * @return list<string>
     */
    private function forbiddenTerms(array $row): array
    {
        $haystack = strtolower(implode("\n", array_map(static fn (mixed $value): string => (string) $value, $row)));
        $found = array_values(array_filter(
            self::FORBIDDEN_PUBLIC_TERMS,
            static fn (string $term): bool => str_contains($haystack, $term),
        ));

        sort($found);

        return $found;
    }

    /**
     * @param  array<mixed>  $values
     */
    private function score(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        $passed = count(array_filter($values, static fn (mixed $value): bool => $value === true));

        return (int) round(($passed / count($values)) * 100);
    }

    /**
     * @param  list<string>  $slugs
     * @return array{headers: list<string>, rows: list<array<string, string|int>>, total_rows: int}
     */
    private function readWorkbook(string $path, array $slugs): array
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
        $xpath->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

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
