<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Content\BigFivePackLoader;
use App\Services\Observability\BigFiveTelemetry;
use App\Services\Template\TemplateContext;
use App\Services\Template\TemplateEngine;

final class BigFiveReportComposer
{
    public function __construct(
        private readonly BigFivePackLoader $packLoader,
        private readonly TemplateEngine $templateEngine,
        private readonly BigFiveTelemetry $bigFiveTelemetry,
    ) {
    }

    /**
     * @param array<string,mixed> $ctx
     * @return array{ok:bool,report?:array<string,mixed>,error?:string,message?:string,status?:int}
     */
    public function composeVariant(Attempt $attempt, Result $result, string $variant, array $ctx = []): array
    {
        $variant = ReportAccess::normalizeVariant($variant);
        $version = trim((string) ($attempt->dir_version ?? BigFivePackLoader::PACK_VERSION));
        if ($version === '') {
            $version = BigFivePackLoader::PACK_VERSION;
        }

        $copyCompiled = $this->packLoader->readCompiledJson('copy.compiled.json', $version);
        if (!is_array($copyCompiled)) {
            return [
                'ok' => false,
                'error' => 'REPORT_COPY_COMPILED_MISSING',
                'message' => 'BIG5_OCEAN compiled copy is missing.',
                'status' => 500,
            ];
        }

        $scoreResult = $this->extractScoreResult($result);
        if (!is_array($scoreResult)) {
            return [
                'ok' => false,
                'error' => 'REPORT_SCORE_RESULT_MISSING',
                'message' => 'BIG5_OCEAN score result missing.',
                'status' => 500,
            ];
        }

        $locale = trim((string) ($attempt->locale ?? $ctx['locale'] ?? 'zh-CN'));
        if ($locale === '') {
            $locale = 'zh-CN';
        }

        $modulesAllowed = ReportAccess::normalizeModules(is_array($ctx['modules_allowed'] ?? null) ? $ctx['modules_allowed'] : []);
        if ($modulesAllowed === []) {
            $modulesAllowed = ReportAccess::defaultModulesAllowedForLocked('BIG5_OCEAN');
        }

        $domainsPct = is_array($scoreResult['scores_0_100']['domains_percentile'] ?? null)
            ? $scoreResult['scores_0_100']['domains_percentile']
            : [];
        $domainBuckets = is_array($scoreResult['facts']['domain_buckets'] ?? null)
            ? $scoreResult['facts']['domain_buckets']
            : [];
        $facetBuckets = is_array($scoreResult['facts']['facet_buckets'] ?? null)
            ? $scoreResult['facts']['facet_buckets']
            : [];
        $facetsPct = is_array($scoreResult['scores_0_100']['facets_percentile'] ?? null)
            ? $scoreResult['scores_0_100']['facets_percentile']
            : [];

        $templateContext = $this->buildTemplateContext($attempt, $scoreResult, $domainsPct, $domainBuckets, $facetsPct, $facetBuckets, $variant, $modulesAllowed);

        $sections = [];
        $overallBucket = $this->resolveOverallBucket($domainBuckets);

        $disclaimerTop = $this->selectCopyRow($copyCompiled, 'disclaimer_top', 'global', 'disclaimer_top', 'all', $locale);
        if (is_array($disclaimerTop)) {
            $sections[] = $this->makeSection(
                'disclaimer_top',
                'Important Note',
                [$this->renderBlock($disclaimerTop, $templateContext)],
                'free',
                ReportAccess::MODULE_BIG5_CORE
            );
        }

        $summaryBlock = $this->selectCopyRow($copyCompiled, 'summary', 'domain', 'overall', $overallBucket, $locale);
        if (is_array($summaryBlock)) {
            $sections[] = $this->makeSection(
                'summary',
                'Summary',
                [$this->renderBlock($summaryBlock, $templateContext)],
                'free',
                ReportAccess::MODULE_BIG5_CORE
            );
        }

        $domainBlocks = [];
        foreach (['O', 'C', 'E', 'A', 'N'] as $domain) {
            $bucket = strtolower((string) ($domainBuckets[$domain] ?? 'mid'));
            $row = $this->selectCopyRow($copyCompiled, 'domains_overview', 'domain', $domain, $bucket, $locale);
            if (!is_array($row)) {
                continue;
            }
            $domainBlocks[] = $this->renderBlock($row, $templateContext);
        }
        if ($domainBlocks !== []) {
            $sections[] = $this->makeSection(
                'domains_overview',
                'Domains Overview',
                $domainBlocks,
                'free',
                ReportAccess::MODULE_BIG5_CORE
            );
        }

        if ($variant === ReportAccess::VARIANT_FULL && in_array(ReportAccess::MODULE_BIG5_FULL, $modulesAllowed, true)) {
            $topFacets = is_array($scoreResult['facts']['top_strength_facets'] ?? null)
                ? array_values($scoreResult['facts']['top_strength_facets'])
                : [];

            $topFacetBlocks = [];
            foreach ($topFacets as $facet) {
                $facet = strtoupper(trim((string) $facet));
                if ($facet === '') {
                    continue;
                }

                $bucket = strtolower((string) ($facetBuckets[$facet] ?? 'mid'));
                $row = $this->selectCopyRow($copyCompiled, 'top_facets', 'facet', $facet, $bucket, $locale);
                if (!is_array($row)) {
                    continue;
                }
                $topFacetBlocks[] = $this->renderBlock($row, $templateContext);
            }

            if ($topFacetBlocks !== []) {
                $sections[] = $this->makeSection(
                    'top_facets',
                    'Top Facets',
                    $topFacetBlocks,
                    'paid',
                    ReportAccess::MODULE_BIG5_FULL
                );
            }

            $deepDive = [];
            $candidates = array_merge(
                is_array($scoreResult['facts']['top_strength_facets'] ?? null) ? $scoreResult['facts']['top_strength_facets'] : [],
                is_array($scoreResult['facts']['top_growth_facets'] ?? null) ? $scoreResult['facts']['top_growth_facets'] : []
            );
            $seen = [];
            foreach ($candidates as $facet) {
                $facet = strtoupper(trim((string) $facet));
                if ($facet === '' || isset($seen[$facet])) {
                    continue;
                }
                $seen[$facet] = true;

                $bucket = strtolower((string) ($facetBuckets[$facet] ?? 'mid'));
                $row = $this->selectCopyRow($copyCompiled, 'facets_deepdive', 'facet', $facet, $bucket, $locale);
                if (!is_array($row)) {
                    continue;
                }
                $deepDive[] = $this->renderBlock($row, $templateContext);
            }
            if ($deepDive !== []) {
                $sections[] = $this->makeSection(
                    'facets_deepdive',
                    'Facets Deep Dive',
                    $deepDive,
                    'paid',
                    ReportAccess::MODULE_BIG5_FULL
                );
            }
        }

        if ($variant === ReportAccess::VARIANT_FULL && in_array(ReportAccess::MODULE_BIG5_ACTION_PLAN, $modulesAllowed, true)) {
            $planRow = $this->selectCopyRow($copyCompiled, 'action_plan', 'domain', 'overall', $overallBucket, $locale);
            if (is_array($planRow)) {
                $sections[] = $this->makeSection(
                    'action_plan',
                    'Action Plan',
                    [$this->renderBlock($planRow, $templateContext)],
                    'paid',
                    ReportAccess::MODULE_BIG5_ACTION_PLAN
                );
            }
        }

        $disclaimer = $this->selectCopyRow($copyCompiled, 'disclaimer', 'global', 'disclaimer', 'all', $locale);
        if (is_array($disclaimer)) {
            $sections[] = $this->makeSection(
                'disclaimer',
                'Disclaimer',
                [$this->renderBlock($disclaimer, $templateContext)],
                'free',
                ReportAccess::MODULE_BIG5_CORE
            );
        }

        $locked = $variant === ReportAccess::VARIANT_FREE;
        $this->bigFiveTelemetry->recordReportComposed(
            (int) ($attempt->org_id ?? 0),
            $this->numericUserId($attempt->user_id ?? null),
            (string) ($attempt->anon_id ?? ''),
            (string) ($attempt->id ?? ''),
            $locale,
            (string) ($attempt->region ?? ''),
            (string) ($scoreResult['norms']['status'] ?? 'MISSING'),
            (string) ($scoreResult['norms']['group_id'] ?? ''),
            (string) ($scoreResult['quality']['level'] ?? 'D'),
            $variant,
            $locked,
            count($sections),
            (string) ($attempt->pack_id ?? BigFivePackLoader::PACK_ID),
            (string) ($attempt->dir_version ?? BigFivePackLoader::PACK_VERSION)
        );

        return [
            'ok' => true,
            'report' => [
                'schema_version' => 'big5.report.v1',
                'scale_code' => 'BIG5_OCEAN',
                'variant' => $variant,
                'sections' => $sections,
                'norms' => [
                    'status' => (string) ($scoreResult['norms']['status'] ?? 'MISSING'),
                    'group_id' => (string) ($scoreResult['norms']['group_id'] ?? 'global_all'),
                ],
                'quality' => [
                    'level' => (string) ($scoreResult['quality']['level'] ?? 'D'),
                ],
                'generated_at' => now()->toISOString(),
            ],
        ];
    }

    private function numericUserId(mixed $userId): ?int
    {
        if (is_int($userId)) {
            return $userId;
        }
        $raw = trim((string) $userId);
        if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * @param array<string,mixed> $scoreResult
     * @param array<string,mixed> $domainsPct
     * @param array<string,mixed> $domainBuckets
     * @param array<string,mixed> $facetsPct
     * @param array<string,mixed> $facetBuckets
     * @param list<string> $modulesAllowed
     */
    private function buildTemplateContext(
        Attempt $attempt,
        array $scoreResult,
        array $domainsPct,
        array $domainBuckets,
        array $facetsPct,
        array $facetBuckets,
        string $variant,
        array $modulesAllowed
    ): TemplateContext {
        $domains = [];
        foreach ($domainsPct as $code => $pct) {
            $code = strtoupper((string) $code);
            $domains[$code] = [
                'percentile' => (int) $pct,
                'bucket_label' => (string) ($domainBuckets[$code] ?? 'mid'),
            ];
        }

        $facets = [];
        foreach ($facetsPct as $code => $pct) {
            $code = strtoupper((string) $code);
            $facets[$code] = [
                'percentile' => (int) $pct,
                'bucket_label' => (string) ($facetBuckets[$code] ?? 'mid'),
            ];
        }

        $quality = is_array($scoreResult['quality'] ?? null) ? $scoreResult['quality'] : [];
        $norms = is_array($scoreResult['norms'] ?? null) ? $scoreResult['norms'] : [];
        $facts = is_array($scoreResult['facts'] ?? null) ? $scoreResult['facts'] : [];

        $tone = in_array((string) ($quality['level'] ?? 'D'), ['A', 'B'], true) ? 'confident' : 'cautious';

        return TemplateContext::fromArray([
            'attempt_id' => (string) ($attempt->id ?? ''),
            'scale_code' => 'BIG5_OCEAN',
            'variant' => $variant,
            'access_level' => $variant === ReportAccess::VARIANT_FREE ? 'free' : 'full',
            'modules_allowed' => $modulesAllowed,
            'quality' => [
                'level' => (string) ($quality['level'] ?? 'D'),
                'notice' => $tone === 'confident' ? 'stable' : 'retest_recommended',
                'report_tone' => $tone,
            ],
            'norms' => [
                'status' => (string) ($norms['status'] ?? 'MISSING'),
                'group_id' => (string) ($norms['group_id'] ?? 'global_all'),
                'group_label' => (string) ($norms['group_id'] ?? 'global_all'),
            ],
            'domains' => $domains,
            'facets' => $facets,
            'facts' => [
                'top_growth_facets_text' => is_array($facts['top_growth_facets'] ?? null)
                    ? implode(', ', array_map('strval', $facts['top_growth_facets']))
                    : '',
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $copyCompiled
     * @return array<string,mixed>|null
     */
    private function selectCopyRow(
        array $copyCompiled,
        string $section,
        string $metricLevel,
        string $metricCode,
        string $bucket,
        string $locale
    ): ?array {
        $rows = is_array($copyCompiled['rows'] ?? null) ? $copyCompiled['rows'] : [];
        $fallbackLocale = str_starts_with(strtolower($locale), 'zh') ? 'zh-CN' : 'en';
        $localeCandidates = array_values(array_unique([$locale, $fallbackLocale, 'en', 'zh-CN']));
        $bucketCandidates = $this->bucketFallbackOrder($section, $bucket);

        foreach ($bucketCandidates as $candidateBucket) {
            foreach ($localeCandidates as $candidateLocale) {
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    if (strtolower((string) ($row['section'] ?? '')) !== strtolower($section)) {
                        continue;
                    }
                    if (strtolower((string) ($row['metric_level'] ?? '')) !== strtolower($metricLevel)) {
                        continue;
                    }
                    if (strtoupper((string) ($row['metric_code'] ?? '')) !== strtoupper($metricCode)) {
                        continue;
                    }
                    if (strtolower((string) ($row['bucket'] ?? '')) !== strtolower($candidateBucket)) {
                        continue;
                    }
                    if (strtolower((string) ($row['locale'] ?? '')) !== strtolower($candidateLocale)) {
                        continue;
                    }

                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function bucketFallbackOrder(string $section, string $bucket): array
    {
        $bucket = strtolower(trim($bucket));
        if ($bucket === '') {
            $bucket = 'mid';
        }

        if (in_array($section, ['disclaimer', 'disclaimer_top'], true)) {
            return [$bucket, 'all'];
        }

        if ($bucket === 'extreme_low') {
            return ['extreme_low', 'low', 'mid'];
        }

        if ($bucket === 'extreme_high') {
            return ['extreme_high', 'high', 'mid'];
        }

        if ($bucket === 'mid' && in_array($section, ['top_facets', 'facets_deepdive'], true)) {
            return ['mid', 'high', 'low'];
        }

        if ($bucket === 'mid') {
            return ['mid'];
        }

        return [$bucket, 'mid'];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function renderBlock(array $row, TemplateContext $context): array
    {
        $title = (string) ($row['title'] ?? '');
        $body = (string) ($row['body'] ?? '');

        return [
            'id' => (string) ($row['row_id'] ?? ''),
            'metric_level' => (string) ($row['metric_level'] ?? ''),
            'metric_code' => (string) ($row['metric_code'] ?? ''),
            'bucket' => (string) ($row['bucket'] ?? ''),
            'access_level' => (string) ($row['access_level'] ?? 'free'),
            'title' => $this->templateEngine->renderString($title, $context, 'text'),
            'body' => $this->templateEngine->renderString($body, $context, 'text'),
        ];
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return array<string,mixed>
     */
    private function makeSection(string $key, string $title, array $blocks, string $accessLevel, string $moduleCode): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'access_level' => $accessLevel,
            'module_code' => $moduleCode,
            'blocks' => $blocks,
        ];
    }

    /**
     * @param array<string,mixed> $domainBuckets
     */
    private function resolveOverallBucket(array $domainBuckets): string
    {
        $counts = [
            'low' => 0,
            'mid' => 0,
            'high' => 0,
        ];

        foreach ($domainBuckets as $bucket) {
            $bucket = strtolower(trim((string) $bucket));
            if (!isset($counts[$bucket])) {
                continue;
            }
            $counts[$bucket]++;
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function extractScoreResult(Result $result): ?array
    {
        $payload = is_array($result->result_json) ? $result->result_json : [];

        $candidates = [
            $payload['normed_json'] ?? null,
            $payload['breakdown_json']['score_result'] ?? null,
            $payload['axis_scores_json']['score_result'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && isset($candidate['raw_scores'], $candidate['scores_0_100'])) {
                return $candidate;
            }
        }

        return null;
    }
}
