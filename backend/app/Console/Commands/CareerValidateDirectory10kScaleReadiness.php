<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\CareerDirectoryAuthorityService;
use App\Services\SEO\SitemapGenerator;
use Illuminate\Console\Command;

final class CareerValidateDirectory10kScaleReadiness extends Command
{
    protected $signature = 'career:validate-directory-10k-scale-readiness
        {--expected-public-count= : Optional expected public career detail count}
        {--expected-sitemap-career-urls= : Optional expected EN/ZH career detail URL count}
        {--synthetic-count=10000 : Future scale count used for budget checks}
        {--max-first-page-bytes=262144 : Maximum allowed first-page directory payload size}
        {--json : Emit JSON output}';

    protected $description = 'Validate career directory authority warm/readiness invariants for 10k-scale operation without mutating runtime state.';

    public function handle(CareerDirectoryAuthorityService $directoryAuthority, SitemapGenerator $sitemapGenerator): int
    {
        $startedAt = microtime(true);

        $enPayload = $directoryAuthority->payload('en', 1, 50);
        $zhPayload = $directoryAuthority->payload('zh-CN', 1, 50);
        $enItems = $directoryAuthority->indexableItems('en');
        $zhItems = $directoryAuthority->indexableItems('zh-CN');
        $sitemapCareerUrls = $sitemapGenerator->generateApprovedCareerJobDetailUrls();

        $excludedSlugs = CareerDirectoryAuthorityService::excludedSlugs();
        $allSlugs = array_values(array_unique(array_merge(
            array_map(static fn (array $item): string => (string) ($item['slug'] ?? ''), $enItems),
            array_map(static fn (array $item): string => (string) ($item['slug'] ?? ''), $zhItems),
        )));
        $leakedExcludedSlugs = array_values(array_intersect($excludedSlugs, $allSlugs));

        $expectedPublicCount = $this->nullablePositiveInt($this->option('expected-public-count'));
        $expectedSitemapCareerUrls = $this->nullablePositiveInt($this->option('expected-sitemap-career-urls'));
        $syntheticCount = max(1, (int) $this->option('synthetic-count'));
        $maxFirstPageBytes = max(1024, (int) $this->option('max-first-page-bytes'));

        $firstPageBytes = strlen((string) json_encode($enPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $forbiddenItemFields = $this->forbiddenItemFields($enPayload, $zhPayload);
        $sitemapCareerUrlCount = count($sitemapCareerUrls);
        $publicCount = (int) data_get($enPayload, 'public_truth.public_detail_indexable_count', count($enItems));
        $zhPublicCount = (int) data_get($zhPayload, 'public_truth.public_detail_indexable_count', count($zhItems));

        $errors = [];
        if ($publicCount !== count($enItems) || $zhPublicCount !== count($zhItems)) {
            $errors[] = 'directory_public_truth_count_mismatch';
        }
        if ($publicCount !== $zhPublicCount) {
            $errors[] = 'directory_locale_count_mismatch';
        }
        if ($expectedPublicCount !== null && $publicCount !== $expectedPublicCount) {
            $errors[] = 'expected_public_count_mismatch';
        }
        if ($expectedSitemapCareerUrls !== null && $sitemapCareerUrlCount !== $expectedSitemapCareerUrls) {
            $errors[] = 'expected_sitemap_career_url_count_mismatch';
        }
        if (count((array) data_get($enPayload, 'items', [])) > 100 || count((array) data_get($zhPayload, 'items', [])) > 100) {
            $errors[] = 'directory_first_page_exceeds_page_size_budget';
        }
        if ($firstPageBytes > $maxFirstPageBytes) {
            $errors[] = 'directory_first_page_payload_exceeds_byte_budget';
        }
        if ($forbiddenItemFields !== []) {
            $errors[] = 'directory_payload_contains_full_detail_fields';
        }
        if ($leakedExcludedSlugs !== []) {
            $errors[] = 'excluded_slug_leakage';
        }
        if ($sitemapCareerUrlCount !== ($publicCount * 2)) {
            $errors[] = 'sitemap_career_url_count_not_bilingual_public_count';
        }

        $elapsed = round(microtime(true) - $startedAt, 3);
        $passed = $errors === [];
        $report = [
            'schema_version' => 'career.directory_10k_ops_warm_validate.v1',
            'task' => 'CAREER-DIRECTORY-10K-OPS-WARM-VALIDATE-01',
            'status' => $passed ? 'passed' : 'failed',
            'final_decision' => $passed
                ? 'career_directory_10k_ops_warm_validate_passed_ready_for_deploy_readiness'
                : 'blocked_career_directory_10k_ops_warm_validate_failed',
            'authority_version' => CareerDirectoryAuthorityService::AUTHORITY_VERSION,
            'public_detail_indexable_count_en' => $publicCount,
            'public_detail_indexable_count_zh_cn' => $zhPublicCount,
            'sitemap_career_detail_url_count' => $sitemapCareerUrlCount,
            'expected_public_count' => $expectedPublicCount,
            'expected_sitemap_career_urls' => $expectedSitemapCareerUrls,
            'excluded_slugs' => $excludedSlugs,
            'leaked_excluded_slugs' => $leakedExcludedSlugs,
            'first_page_per_page' => (int) data_get($enPayload, 'pagination.per_page', 0),
            'first_page_item_count_en' => count((array) data_get($enPayload, 'items', [])),
            'first_page_item_count_zh_cn' => count((array) data_get($zhPayload, 'items', [])),
            'first_page_payload_bytes' => $firstPageBytes,
            'max_first_page_bytes' => $maxFirstPageBytes,
            'forbidden_item_fields' => $forbiddenItemFields,
            'synthetic_scale_budget' => [
                'target_directory_count' => $syntheticCount,
                'target_bilingual_detail_url_count' => $syntheticCount * 2,
                'directory_first_page_ssr_limit' => 50,
                'full_directory_ssr_rendering_allowed' => false,
                'sitemap_full_detail_url_exposure_expected' => true,
            ],
            'production_write_performed' => false,
            'runtime_promotion_performed' => false,
            'sitemap_llms_footer_exposure_performed' => false,
            'search_channel_action_performed' => false,
            'url_submission_performed' => false,
            'external_search_api_call_performed' => false,
            'errors' => $errors,
            'elapsed_seconds' => $elapsed,
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->line('status='.$report['status']);
            $this->line('public_detail_indexable_count_en='.$publicCount);
            $this->line('public_detail_indexable_count_zh_cn='.$zhPublicCount);
            $this->line('sitemap_career_detail_url_count='.$sitemapCareerUrlCount);
            $this->line('synthetic_bilingual_detail_url_count='.($syntheticCount * 2));
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int) $value);
    }

    /**
     * @param  array<string, mixed>  ...$payloads
     * @return list<string>
     */
    private function forbiddenItemFields(array ...$payloads): array
    {
        $forbidden = ['truth_summary', 'score_summary', 'provenance_meta', 'sections', 'faq', 'structured_data'];
        $hits = [];

        foreach ($payloads as $payload) {
            foreach ((array) data_get($payload, 'items', []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach ($forbidden as $field) {
                    if (array_key_exists($field, $item)) {
                        $hits[$field] = true;
                    }
                }
            }
        }

        return array_keys($hits);
    }
}
