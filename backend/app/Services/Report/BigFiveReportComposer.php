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
    private const DOMAIN_ORDER = ['O', 'C', 'E', 'A', 'N'];

    private const FACET_ORDER = [
        'N1', 'E1', 'O1', 'A1', 'C1',
        'N2', 'E2', 'O2', 'A2', 'C2',
        'N3', 'E3', 'O3', 'A3', 'C3',
        'N4', 'E4', 'O4', 'A4', 'C4',
        'N5', 'E5', 'O5', 'A5', 'C5',
        'N6', 'E6', 'O6', 'A6', 'C6',
    ];

    public function __construct(
        private readonly BigFivePackLoader $packLoader,
        private readonly TemplateEngine $templateEngine,
        private readonly BigFiveTelemetry $bigFiveTelemetry,
    ) {}

    /**
     * @param  array<string,mixed>  $ctx
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
        if (! is_array($copyCompiled)) {
            return [
                'ok' => false,
                'error' => 'REPORT_COPY_COMPILED_MISSING',
                'message' => 'BIG5_OCEAN compiled copy is missing.',
                'status' => 500,
            ];
        }

        $scoreResult = $this->extractScoreResult($result);
        if (! is_array($scoreResult)) {
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
        $overallBucket = $this->resolveOverallBucket($domainBuckets);

        $layoutCompiled = $this->packLoader->readCompiledJson('layout.compiled.json', $version);
        $blocksCompiled = $this->packLoader->readCompiledJson('blocks.compiled.json', $version);

        if (is_array($layoutCompiled) && is_array($blocksCompiled)) {
            $sections = $this->composeFromLayoutAndBlocks(
                $layoutCompiled,
                $blocksCompiled,
                $copyCompiled,
                $templateContext,
                $scoreResult,
                $locale,
                $variant,
                $modulesAllowed,
                $domainBuckets,
                $facetBuckets,
                $overallBucket
            );
        } else {
            $sections = $this->composeLegacyFromCopy(
                $copyCompiled,
                $templateContext,
                $scoreResult,
                $locale,
                $variant,
                $modulesAllowed,
                $domainBuckets,
                $facetBuckets,
                $overallBucket
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
        if ($raw === '' || ! preg_match('/^\d+$/', $raw)) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * @param  array<string,mixed>  $layoutCompiled
     * @param  array<string,mixed>  $blocksCompiled
     * @param  array<string,mixed>  $copyCompiled
     * @param  array<string,mixed>  $scoreResult
     * @param  list<string>  $modulesAllowed
     * @param  array<string,mixed>  $domainBuckets
     * @param  array<string,mixed>  $facetBuckets
     * @return list<array<string,mixed>>
     */
    private function composeFromLayoutAndBlocks(
        array $layoutCompiled,
        array $blocksCompiled,
        array $copyCompiled,
        TemplateContext $templateContext,
        array $scoreResult,
        string $locale,
        string $variant,
        array $modulesAllowed,
        array $domainBuckets,
        array $facetBuckets,
        string $overallBucket
    ): array {
        $sections = [];
        $layoutDoc = is_array($layoutCompiled['layout'] ?? null) ? $layoutCompiled['layout'] : $layoutCompiled;
        $layoutSections = is_array($layoutDoc['sections'] ?? null) ? $layoutDoc['sections'] : [];
        $allBlocks = is_array($blocksCompiled['blocks'] ?? null) ? $blocksCompiled['blocks'] : [];
        $facts = is_array($scoreResult['facts'] ?? null) ? $scoreResult['facts'] : [];

        foreach ($layoutSections as $layoutSection) {
            if (! is_array($layoutSection)) {
                continue;
            }

            $sectionKey = strtolower(trim((string) ($layoutSection['key'] ?? '')));
            if ($sectionKey === '') {
                continue;
            }

            $requiredVariants = $this->normalizeVariants(is_array($layoutSection['required_in_variant'] ?? null) ? $layoutSection['required_in_variant'] : []);
            if ($requiredVariants !== [] && ! in_array($variant, $requiredVariants, true)) {
                continue;
            }

            $accessLevel = strtolower(trim((string) ($layoutSection['access_level'] ?? 'free')));
            if ($variant === ReportAccess::VARIANT_FREE && $accessLevel === 'paid') {
                continue;
            }

            $moduleCode = strtolower(trim((string) ($layoutSection['module_code'] ?? ReportAccess::MODULE_BIG5_CORE)));
            if ($accessLevel === 'paid' && ! in_array($moduleCode, $modulesAllowed, true)) {
                continue;
            }

            $source = strtolower(trim((string) ($layoutSection['source'] ?? 'copy')));
            $blocks = [];
            if ($source === 'blocks') {
                $blocks = $this->buildBlocksSection(
                    $sectionKey,
                    $allBlocks,
                    $templateContext,
                    $facts,
                    $locale,
                    $variant,
                    $domainBuckets,
                    $facetBuckets
                );
            } else {
                $copyRow = $this->selectCopyRowForSection($copyCompiled, $sectionKey, $overallBucket, $locale);
                if (is_array($copyRow)) {
                    $blocks[] = $this->renderBlock($copyRow, $templateContext);
                }
            }

            $minBlocks = max(0, (int) ($layoutSection['min_blocks'] ?? 0));
            $maxBlocks = max(0, (int) ($layoutSection['max_blocks'] ?? 0));
            if ($maxBlocks > 0 && count($blocks) > $maxBlocks) {
                $blocks = array_slice($blocks, 0, $maxBlocks);
            }

            if ($blocks === [] || count($blocks) < $minBlocks) {
                continue;
            }

            $sections[] = $this->makeSection(
                $sectionKey,
                $this->resolveSectionTitle($layoutSection, $locale),
                $blocks,
                $accessLevel,
                $moduleCode
            );
        }

        return $sections;
    }

    /**
     * @param  array<string,mixed>  $copyCompiled
     * @param  array<string,mixed>  $scoreResult
     * @param  list<string>  $modulesAllowed
     * @param  array<string,mixed>  $domainBuckets
     * @param  array<string,mixed>  $facetBuckets
     * @return list<array<string,mixed>>
     */
    private function composeLegacyFromCopy(
        array $copyCompiled,
        TemplateContext $templateContext,
        array $scoreResult,
        string $locale,
        string $variant,
        array $modulesAllowed,
        array $domainBuckets,
        array $facetBuckets,
        string $overallBucket
    ): array {
        $sections = [];

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
        foreach (self::DOMAIN_ORDER as $domain) {
            $bucket = strtolower((string) ($domainBuckets[$domain] ?? 'mid'));
            $row = $this->selectCopyRow($copyCompiled, 'domains_overview', 'domain', $domain, $bucket, $locale);
            if (! is_array($row)) {
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
                if (! is_array($row)) {
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
                if (! is_array($row)) {
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

        return $sections;
    }

    /**
     * @param  list<array<string,mixed>>  $allBlocks
     * @param  array<string,mixed>  $facts
     * @param  array<string,mixed>  $domainBuckets
     * @param  array<string,mixed>  $facetBuckets
     * @return list<array<string,mixed>>
     */
    private function buildBlocksSection(
        string $sectionKey,
        array $allBlocks,
        TemplateContext $templateContext,
        array $facts,
        string $locale,
        string $variant,
        array $domainBuckets,
        array $facetBuckets
    ): array {
        $allowedLevels = $variant === ReportAccess::VARIANT_FREE ? ['free'] : ['free', 'paid'];
        $selectedRows = [];

        if ($sectionKey === 'domains_overview') {
            foreach (self::DOMAIN_ORDER as $domain) {
                $bucket = strtolower((string) ($domainBuckets[$domain] ?? 'mid'));
                $row = $this->selectBlockRow($allBlocks, $sectionKey, $locale, $allowedLevels, $domain, $bucket);
                if (! is_array($row)) {
                    continue;
                }
                $selectedRows[] = $row;
            }
        }

        if ($sectionKey === 'facet_table') {
            foreach (self::FACET_ORDER as $facet) {
                $row = $this->selectBlockRow($allBlocks, $sectionKey, $locale, $allowedLevels, $facet, 'all');
                if (! is_array($row)) {
                    continue;
                }
                $selectedRows[] = $row;
            }
        }

        if ($sectionKey === 'top_facets') {
            $top = is_array($facts['top_strength_facets'] ?? null) ? array_values($facts['top_strength_facets']) : [];
            foreach (array_slice($top, 0, 3) as $facet) {
                $facet = strtoupper(trim((string) $facet));
                if ($facet === '') {
                    continue;
                }
                $bucket = strtolower((string) ($facetBuckets[$facet] ?? 'mid'));
                $row = $this->selectBlockRow($allBlocks, $sectionKey, $locale, $allowedLevels, $facet, $bucket);
                if (! is_array($row)) {
                    continue;
                }
                $selectedRows[] = $row;
            }
        }

        if ($sectionKey === 'facets_deepdive') {
            $strength = is_array($facts['top_strength_facets'] ?? null) ? array_values($facts['top_strength_facets']) : [];
            $growth = is_array($facts['top_growth_facets'] ?? null) ? array_values($facts['top_growth_facets']) : [];
            $candidates = [];
            foreach (array_merge($strength, $growth) as $facet) {
                $facet = strtoupper(trim((string) $facet));
                if ($facet === '') {
                    continue;
                }
                $candidates[$facet] = true;
            }

            foreach (array_keys($candidates) as $facet) {
                $bucket = strtolower((string) ($facetBuckets[$facet] ?? 'mid'));
                $row = $this->selectBlockRow($allBlocks, $sectionKey, $locale, $allowedLevels, $facet, $bucket);
                if (! is_array($row)) {
                    continue;
                }
                $selectedRows[] = $row;
            }
        }

        if ($selectedRows === []) {
            return [];
        }

        $selectedRows = $this->enforceExclusiveGroup($selectedRows);
        $out = [];
        foreach ($selectedRows as $row) {
            $out[] = $this->renderBlock($row, $templateContext);
        }

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $allBlocks
     * @param  list<string>  $allowedLevels
     * @return array<string,mixed>|null
     */
    private function selectBlockRow(
        array $allBlocks,
        string $section,
        string $locale,
        array $allowedLevels,
        string $metricCode,
        string $bucket
    ): ?array {
        $localeCandidates = $this->localeFallbackOrder($locale);
        $bucketCandidates = $this->bucketFallbackOrder($section, $bucket);

        foreach ($bucketCandidates as $candidateBucket) {
            foreach ($localeCandidates as $candidateLocale) {
                $matches = [];
                foreach ($allBlocks as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    if (strtolower((string) ($row['section'] ?? '')) !== strtolower($section)) {
                        continue;
                    }
                    if (strtolower((string) ($row['locale'] ?? '')) !== strtolower($candidateLocale)) {
                        continue;
                    }
                    $accessLevel = strtolower((string) ($row['access_level'] ?? 'free'));
                    if (! in_array($accessLevel, $allowedLevels, true)) {
                        continue;
                    }
                    if (strtoupper((string) ($row['metric_code'] ?? '')) !== strtoupper($metricCode)) {
                        continue;
                    }
                    if (strtolower((string) ($row['bucket'] ?? '')) !== strtolower($candidateBucket)) {
                        continue;
                    }
                    $matches[] = $row;
                }

                if ($matches === []) {
                    continue;
                }

                $winner = $this->resolveConflictsByPriority($matches);
                if (is_array($winner)) {
                    return $winner;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array<string,mixed>>  $matches
     * @return array<string,mixed>|null
     */
    private function resolveConflictsByPriority(array $matches): ?array
    {
        if ($matches === []) {
            return null;
        }

        usort($matches, fn (array $a, array $b): int => $this->compareBlockPriority($a, $b));

        return $matches[0];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function enforceExclusiveGroup(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $bestByGroup = [];
        foreach ($rows as $index => $row) {
            $exclusive = trim((string) ($row['exclusive_group'] ?? ''));
            $key = $exclusive !== '' ? $exclusive : '__row_' . $index;

            if (! isset($bestByGroup[$key])) {
                $bestByGroup[$key] = [
                    'index' => $index,
                    'row' => $row,
                ];
                continue;
            }

            $current = $bestByGroup[$key]['row'];
            if ($this->compareBlockPriority($row, $current) < 0) {
                $bestByGroup[$key]['row'] = $row;
            }
        }

        usort($bestByGroup, static fn (array $a, array $b): int => ((int) $a['index']) <=> ((int) $b['index']));

        $out = [];
        foreach ($bestByGroup as $item) {
            $candidate = $item['row'] ?? null;
            if (! is_array($candidate)) {
                continue;
            }
            $out[] = $candidate;
        }

        return $out;
    }

    /**
     * Higher priority row comes first.
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    private function compareBlockPriority(array $a, array $b): int
    {
        $priorityCmp = ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0));
        if ($priorityCmp !== 0) {
            return $priorityCmp;
        }

        return strcmp((string) ($a['block_id'] ?? ''), (string) ($b['block_id'] ?? ''));
    }

    /**
     * @param  array<string,mixed>  $copyCompiled
     * @return array<string,mixed>|null
     */
    private function selectCopyRowForSection(array $copyCompiled, string $sectionKey, string $overallBucket, string $locale): ?array
    {
        return match ($sectionKey) {
            'disclaimer_top' => $this->selectCopyRow($copyCompiled, 'disclaimer_top', 'global', 'disclaimer_top', 'all', $locale),
            'summary' => $this->selectCopyRow($copyCompiled, 'summary', 'domain', 'overall', $overallBucket, $locale),
            'action_plan' => $this->selectCopyRow($copyCompiled, 'action_plan', 'domain', 'overall', $overallBucket, $locale),
            'disclaimer' => $this->selectCopyRow($copyCompiled, 'disclaimer', 'global', 'disclaimer', 'all', $locale),
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function normalizeVariants(array $variants): array
    {
        $out = [];
        foreach ($variants as $variant) {
            $normalized = strtolower(trim((string) $variant));
            if (! in_array($normalized, [ReportAccess::VARIANT_FREE, ReportAccess::VARIANT_FULL], true)) {
                continue;
            }
            $out[$normalized] = true;
        }

        return array_keys($out);
    }

    /**
     * @param  array<string,mixed>  $layoutSection
     */
    private function resolveSectionTitle(array $layoutSection, string $locale): string
    {
        $isZh = str_starts_with(strtolower($locale), 'zh');
        $title = $isZh
            ? trim((string) ($layoutSection['title_zh'] ?? ''))
            : trim((string) ($layoutSection['title_en'] ?? ''));

        if ($title !== '') {
            return $title;
        }

        return trim((string) ($layoutSection['key'] ?? 'section'));
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @param  array<string,mixed>  $domainsPct
     * @param  array<string,mixed>  $domainBuckets
     * @param  array<string,mixed>  $facetsPct
     * @param  array<string,mixed>  $facetBuckets
     * @param  list<string>  $modulesAllowed
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

        $topStrength = is_array($facts['top_strength_facets'] ?? null) ? array_map('strval', $facts['top_strength_facets']) : [];
        $topGrowth = is_array($facts['top_growth_facets'] ?? null) ? array_map('strval', $facts['top_growth_facets']) : [];
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
                'top_strength_facets' => $topStrength,
                'top_strength_facets_text' => implode(', ', $topStrength),
                'top_growth_facets' => $topGrowth,
                'top_growth_facets_text' => implode(', ', $topGrowth),
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $copyCompiled
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
        $localeCandidates = $this->localeFallbackOrder($locale);
        $bucketCandidates = $this->bucketFallbackOrder($section, $bucket);

        foreach ($bucketCandidates as $candidateBucket) {
            foreach ($localeCandidates as $candidateLocale) {
                foreach ($rows as $row) {
                    if (! is_array($row)) {
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
    private function localeFallbackOrder(string $locale): array
    {
        $locale = trim($locale);
        $fallbackLocale = str_starts_with(strtolower($locale), 'zh') ? 'zh-CN' : 'en';

        return array_values(array_unique([$locale, $fallbackLocale, 'en', 'zh-CN']));
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
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function renderBlock(array $row, TemplateContext $context): array
    {
        $title = (string) ($row['title'] ?? '');
        $body = (string) ($row['body'] ?? '');

        return [
            'id' => (string) ($row['row_id'] ?? ($row['block_id'] ?? '')),
            'metric_level' => (string) ($row['metric_level'] ?? ''),
            'metric_code' => (string) ($row['metric_code'] ?? ''),
            'bucket' => (string) ($row['bucket'] ?? ''),
            'access_level' => (string) ($row['access_level'] ?? 'free'),
            'title' => $this->templateEngine->renderString($title, $context, 'text'),
            'body' => $this->templateEngine->renderString($body, $context, 'text'),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $blocks
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
     * @param  array<string,mixed>  $domainBuckets
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
            if (! isset($counts[$bucket])) {
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
