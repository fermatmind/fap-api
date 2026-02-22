<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Services\Template\TemplateEngine;
use App\Services\Template\TemplateVariableRegistry;

final class BigFiveContentLintService
{
    private const FACETS = [
        'N1', 'N2', 'N3', 'N4', 'N5', 'N6',
        'E1', 'E2', 'E3', 'E4', 'E5', 'E6',
        'O1', 'O2', 'O3', 'O4', 'O5', 'O6',
        'A1', 'A2', 'A3', 'A4', 'A5', 'A6',
        'C1', 'C2', 'C3', 'C4', 'C5', 'C6',
    ];

    private const DOMAINS = ['O', 'C', 'E', 'A', 'N'];

    private const LAYOUT_REQUIRED_SECTIONS = [
        'disclaimer_top',
        'summary',
        'domains_overview',
        'facet_table',
        'top_facets',
        'facets_deepdive',
        'action_plan',
        'disclaimer',
    ];

    public function __construct(
        private readonly BigFivePackLoader $loader,
        private readonly TemplateEngine $templateEngine,
        private readonly TemplateVariableRegistry $templateVariableRegistry,
    ) {}

    /**
     * @return array{ok:bool,pack_id:string,version:string,errors:list<array{file:string,line:int,message:string}>}
     */
    public function lint(?string $version = null): array
    {
        $version = $this->normalizeVersion($version);
        $errors = [];

        $this->lintQuestions($version, $errors);
        $this->lintFacetMap($version, $errors);
        $this->lintOptions($version, $errors);
        $this->lintPolicy($version, $errors);
        $this->lintSources($version, $errors);
        $this->lintNormStats($version, $errors);
        $this->lintBucketCopy($version, $errors);
        $this->lintComplianceCopy($version, $errors);
        $this->lintReportLayout($version, $errors);
        $this->lintBlocks($version, $errors);
        $this->lintBlockConflictRules($version, $errors);
        $this->lintBlocksCoverage($version, $errors);
        $this->lintLayoutSatisfiability($version, $errors);
        $this->lintLanding($version, $errors);
        $this->lintGoldenCases($version, $errors);

        return [
            'ok' => $errors === [],
            'pack_id' => BigFivePackLoader::PACK_ID,
            'version' => $version,
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintQuestions(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('questions_big5_bilingual.csv', $version);
        $rows = $this->loader->readCsvWithLines($file);
        if (count($rows) !== 120) {
            $errors[] = $this->error($file, 1, 'rows must be exactly 120.');
        }

        $seen = [];
        foreach ($rows as $entry) {
            $line = (int) ($entry['line'] ?? 0);
            $row = (array) ($entry['row'] ?? []);

            $qid = (int) ($row['question_id'] ?? 0);
            if ($qid <= 0) {
                $errors[] = $this->error($file, $line, 'question_id must be positive integer.');

                continue;
            }
            $seen[$qid] = true;

            $dimension = strtoupper((string) ($row['dimension'] ?? ''));
            if (! in_array($dimension, self::DOMAINS, true)) {
                $errors[] = $this->error($file, $line, 'dimension must be one of O/C/E/A/N.');
            }

            $direction = (int) ($row['direction'] ?? 0);
            if (! in_array($direction, [1, -1], true)) {
                $errors[] = $this->error($file, $line, 'direction must be 1 or -1.');
            }
        }

        for ($i = 1; $i <= 120; $i++) {
            if (! isset($seen[$i])) {
                $errors[] = $this->error($file, 1, "missing question_id={$i}");
            }
        }
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintFacetMap(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('facet_map.csv', $version);
        $rows = $this->loader->readCsvWithLines($file);
        if (count($rows) !== 120) {
            $errors[] = $this->error($file, 1, 'rows must be exactly 120.');
        }

        $qCount = [];
        $facetCount = [];
        $domainCount = [];

        foreach ($rows as $entry) {
            $line = (int) ($entry['line'] ?? 0);
            $row = (array) ($entry['row'] ?? []);

            $qid = (int) ($row['question_id'] ?? 0);
            if ($qid <= 0) {
                $errors[] = $this->error($file, $line, 'question_id must be positive integer.');

                continue;
            }
            $qCount[$qid] = ($qCount[$qid] ?? 0) + 1;

            $facet = strtoupper((string) ($row['facet_code'] ?? ''));
            if (! in_array($facet, self::FACETS, true)) {
                $errors[] = $this->error($file, $line, 'facet_code invalid.');

                continue;
            }
            $facetCount[$facet] = ($facetCount[$facet] ?? 0) + 1;

            $domain = strtoupper((string) ($row['domain_code'] ?? ''));
            if (! in_array($domain, self::DOMAINS, true)) {
                $errors[] = $this->error($file, $line, 'domain_code invalid.');

                continue;
            }
            $domainCount[$domain] = ($domainCount[$domain] ?? 0) + 1;

            if ($domain !== $facet[0]) {
                $errors[] = $this->error($file, $line, 'domain_code must match facet_code prefix.');
            }
        }

        for ($i = 1; $i <= 120; $i++) {
            if (($qCount[$i] ?? 0) !== 1) {
                $errors[] = $this->error($file, 1, "question_id={$i} must map exactly once.");
            }
        }

        foreach (self::FACETS as $facet) {
            if (($facetCount[$facet] ?? 0) !== 4) {
                $errors[] = $this->error($file, 1, "facet {$facet} must map exactly 4 items.");
            }
        }

        foreach (self::DOMAINS as $domain) {
            if (($domainCount[$domain] ?? 0) !== 24) {
                $errors[] = $this->error($file, 1, "domain {$domain} must map exactly 24 items.");
            }
        }
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintOptions(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('options_likert5.csv', $version);
        $rows = $this->loader->readCsvWithLines($file);
        if (count($rows) !== 5) {
            $errors[] = $this->error($file, 1, 'options_likert5 must have exactly 5 rows.');
        }

        $seen = [];
        foreach ($rows as $entry) {
            $line = (int) ($entry['line'] ?? 0);
            $score = (int) (($entry['row']['score'] ?? 0));
            $seen[$score] = true;
            if ($score < 1 || $score > 5) {
                $errors[] = $this->error($file, $line, 'score must be 1..5.');
            }
        }

        for ($i = 1; $i <= 5; $i++) {
            if (! isset($seen[$i])) {
                $errors[] = $this->error($file, 1, "score={$i} missing.");
            }
        }
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintPolicy(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('policy.json', $version);
        $doc = $this->loader->readJson($file);
        if (! is_array($doc)) {
            $errors[] = $this->error($file, 1, 'invalid json.');

            return;
        }

        foreach (['low', 'mid', 'high'] as $bucket) {
            if (! is_array($doc['percentile_buckets'][$bucket] ?? null)) {
                $errors[] = $this->error($file, 1, "percentile_buckets.{$bucket} missing.");
            }
        }

        if (! is_array($doc['norm_fallback'] ?? null)) {
            $errors[] = $this->error($file, 1, 'norm_fallback missing.');
        }

        if (! is_array($doc['validity_checks'] ?? null)) {
            $errors[] = $this->error($file, 1, 'validity_checks missing.');
        }

        $validityItems = is_array($doc['validity_items'] ?? null) ? $doc['validity_items'] : [];
        if ($validityItems === []) {
            $errors[] = $this->error($file, 1, 'validity_items missing.');

            return;
        }

        foreach ($validityItems as $idx => $item) {
            if (!is_array($item)) {
                $errors[] = $this->error($file, 1, "validity_items[{$idx}] must be object.");
                continue;
            }

            $itemId = trim((string) ($item['item_id'] ?? ''));
            $promptZh = trim((string) ($item['prompt_zh'] ?? ''));
            $promptEn = trim((string) ($item['prompt_en'] ?? ''));
            $expectedCode = (int) ($item['expected_code'] ?? 0);

            if ($itemId === '') {
                $errors[] = $this->error($file, 1, "validity_items[{$idx}].item_id required.");
            }
            if ($promptZh === '' || $promptEn === '') {
                $errors[] = $this->error($file, 1, "validity_items[{$idx}] prompt_zh/prompt_en required.");
            }
            if ($expectedCode < 1 || $expectedCode > 5) {
                $errors[] = $this->error($file, 1, "validity_items[{$idx}].expected_code must be 1..5.");
            }
        }
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintSources(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('source_catalog.csv', $version);
        $rows = $this->loader->readCsvWithLines($file);
        if ($rows === []) {
            $errors[] = $this->error($file, 1, 'source_catalog empty.');

            return;
        }

        $hasGlobal = false;
        $hasZh = false;
        foreach ($rows as $entry) {
            $line = (int) ($entry['line'] ?? 0);
            $row = (array) ($entry['row'] ?? []);
            foreach (['source_id', 'scope', 'locale', 'name'] as $key) {
                if (trim((string) ($row[$key] ?? '')) === '') {
                    $errors[] = $this->error($file, $line, "{$key} is required.");
                }
            }

            if (strtoupper((string) ($row['scope'] ?? '')) === 'GLOBAL') {
                $hasGlobal = true;
            }
            if (strtolower((string) ($row['locale'] ?? '')) === 'zh-cn') {
                $hasZh = true;
            }
        }

        if (! $hasGlobal) {
            $errors[] = $this->error($file, 1, 'must contain at least one GLOBAL source.');
        }
        if (! $hasZh) {
            $errors[] = $this->error($file, 1, 'must contain at least one zh-CN source.');
        }
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintNormStats(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('norm_stats.csv', $version);
        $rows = $this->loader->readCsvWithLines($file);
        if ($rows === []) {
            $errors[] = $this->error($file, 1, 'norm_stats empty.');

            return;
        }

        $coverage = [];
        foreach ($rows as $entry) {
            $line = (int) ($entry['line'] ?? 0);
            $row = (array) ($entry['row'] ?? []);
            $group = trim((string) ($row['group_id'] ?? ''));
            $level = strtolower(trim((string) ($row['metric_level'] ?? '')));
            $code = strtoupper(trim((string) ($row['metric_code'] ?? '')));
            if ($group === '' || $level === '' || $code === '') {
                $errors[] = $this->error($file, $line, 'group_id/metric_level/metric_code required.');

                continue;
            }

            $mean = (float) ($row['mean'] ?? 0.0);
            $sd = (float) ($row['sd'] ?? 0.0);
            if ($mean < 1.0 || $mean > 5.0) {
                $errors[] = $this->error($file, $line, 'mean must be in 1..5.');
            }
            if ($sd <= 0.0) {
                $errors[] = $this->error($file, $line, 'sd must be > 0.');
            }
            $sampleN = (int) ($row['sample_n'] ?? 0);
            if ($sampleN <= 0) {
                $errors[] = $this->error($file, $line, 'sample_n must be > 0.');
            }

            $coverage[strtolower($group)][$level][$code] = true;
        }

        $requiredGroups = (array) config('big5_norms.resolver.required_groups', [
            'en_johnson_all_18-60',
            'zh-CN_prod_all_18-60',
        ]);
        foreach ($requiredGroups as $requiredGroup) {
            if (! is_string($requiredGroup) || trim($requiredGroup) === '') {
                continue;
            }
            $this->assertNormGroupCoverage($file, $coverage, trim($requiredGroup), true, $errors);
        }
    }

    /**
     * @param  array<string,array<string,array<string,bool>>>  $coverage
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function assertNormGroupCoverage(
        string $file,
        array $coverage,
        string $groupId,
        bool $requireFacet,
        array &$errors
    ): void {
        $groupKey = strtolower($groupId);
        $domains = array_keys((array) ($coverage[$groupKey]['domain'] ?? []));
        $facets = array_keys((array) ($coverage[$groupKey]['facet'] ?? []));

        if (count($domains) !== 5 || count(array_intersect($domains, self::DOMAINS)) !== 5) {
            $errors[] = $this->error($file, 1, "{$groupId} must fully cover 5 domain metrics.");
        }

        if ($requireFacet && (count($facets) !== 30 || count(array_intersect($facets, self::FACETS)) !== 30)) {
            $errors[] = $this->error($file, 1, "{$groupId} must fully cover 30 facet metrics.");
        }
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintBucketCopy(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('bucket_copy.csv', $version);
        $rows = $this->loader->readCsvWithLines($file);
        if ($rows === []) {
            $errors[] = $this->error($file, 1, 'bucket_copy empty.');

            return;
        }

        $policy = $this->loader->readJson($this->loader->rawPath('policy.json', $version));
        $extremeFallbackMap = [];
        if (
            is_array($policy)
            && is_array($policy['copy_bucket_fallback'] ?? null)
            && is_array($policy['copy_bucket_fallback']['facets_deepdive'] ?? null)
        ) {
            $extremeFallbackMap = $policy['copy_bucket_fallback']['facets_deepdive'];
        }

        $domainCoverage = [];
        $facetCoverage = [];
        $disclaimerCoverage = [];
        $varsUsed = [];

        foreach ($rows as $entry) {
            $line = (int) ($entry['line'] ?? 0);
            $row = (array) ($entry['row'] ?? []);

            $section = strtolower((string) ($row['section'] ?? ''));
            $metricLevel = strtolower((string) ($row['metric_level'] ?? ''));
            $metricCode = strtoupper((string) ($row['metric_code'] ?? ''));
            $bucket = strtolower((string) ($row['bucket'] ?? ''));
            $locale = strtolower((string) ($row['locale'] ?? ''));

            if ($section === '' || $metricLevel === '' || $metricCode === '' || $bucket === '' || $locale === '') {
                $errors[] = $this->error($file, $line, 'section/metric_level/metric_code/bucket/locale required.');
            }

            if ($metricLevel === 'domain' && in_array($metricCode, self::DOMAINS, true)) {
                $domainCoverage[$locale][$metricCode][$bucket] = true;
            }
            if ($metricLevel === 'facet' && in_array($metricCode, self::FACETS, true) && in_array($bucket, ['low', 'high', 'extreme_low', 'extreme_high'], true)) {
                $facetCoverage[$metricCode][$bucket] = true;
            }
            if ($metricLevel === 'global' && in_array($section, ['disclaimer', 'disclaimer_top'], true) && $bucket === 'all') {
                $disclaimerCoverage[$section][$locale] = true;
            }

            foreach (['title', 'body'] as $field) {
                $template = (string) ($row[$field] ?? '');
                if ($template === '') {
                    continue;
                }

                foreach ($this->templateEngine->extractVariables($template) as $varName) {
                    $varsUsed[$varName] = true;
                }

                $lint = $this->templateEngine->lintString($template, null);
                $unknown = is_array($lint['unknown'] ?? null) ? $lint['unknown'] : [];
                if ($unknown !== []) {
                    $errors[] = $this->error($file, $line, 'unknown template variables: '.implode(', ', $unknown));
                }
            }
        }

        foreach (['zh-cn', 'en'] as $locale) {
            foreach (self::DOMAINS as $domain) {
                foreach (['low', 'mid', 'high'] as $bucket) {
                    if (! isset($domainCoverage[$locale][$domain][$bucket])) {
                        $errors[] = $this->error($file, 1, "domain copy missing: locale={$locale}, domain={$domain}, bucket={$bucket}");
                    }
                }
            }
        }

        foreach (self::FACETS as $facet) {
            foreach (['low', 'high', 'extreme_low', 'extreme_high'] as $bucket) {
                if (isset($facetCoverage[$facet][$bucket])) {
                    continue;
                }
                $fallbackBucket = strtolower((string) ($extremeFallbackMap[$bucket] ?? ''));
                if ($fallbackBucket !== '' && isset($facetCoverage[$facet][$fallbackBucket])) {
                    continue;
                }
                $errors[] = $this->error($file, 1, "facet copy missing: facet={$facet}, bucket={$bucket}");
            }
        }

        foreach (['disclaimer_top', 'disclaimer'] as $section) {
            foreach (['zh-cn', 'en'] as $locale) {
                if (! isset($disclaimerCoverage[$section][$locale])) {
                    $errors[] = $this->error($file, 1, "{$section} copy missing for locale={$locale}");
                }
            }
        }

        foreach ($this->templateVariableRegistry->requiredVariables() as $requiredVar) {
            if (! isset($varsUsed[$requiredVar])) {
                $errors[] = $this->error($file, 1, "required template variable missing from bucket_copy: {$requiredVar}");
            }
        }
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintLanding(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('landing_i18n.json', $version);
        $doc = $this->loader->readJson($file);
        if (! is_array($doc)) {
            $errors[] = $this->error($file, 1, 'invalid json.');

            return;
        }

        if (trim((string) ($doc['slug'] ?? '')) === '') {
            $errors[] = $this->error($file, 1, 'slug is required.');
        }
        if (trim((string) ($doc['canonical_path'] ?? '')) === '') {
            $errors[] = $this->error($file, 1, 'canonical_path is required.');
        }

        foreach (['zh-CN', 'en'] as $locale) {
            $node = is_array($doc['locales'][$locale] ?? null) ? $doc['locales'][$locale] : null;
            if (! is_array($node)) {
                $errors[] = $this->error($file, 1, "locales.{$locale} missing.");

                continue;
            }
            if (trim((string) ($node['seo_title'] ?? '')) === '') {
                $errors[] = $this->error($file, 1, "locales.{$locale}.seo_title missing.");
            }
            if (trim((string) ($node['seo_description'] ?? '')) === '') {
                $errors[] = $this->error($file, 1, "locales.{$locale}.seo_description missing.");
            }
            if (! is_array($node['faq'] ?? null)) {
                $errors[] = $this->error($file, 1, "locales.{$locale}.faq must be array.");

                continue;
            }

            foreach ((array) $node['faq'] as $index => $faqItem) {
                if (! is_array($faqItem)) {
                    $errors[] = $this->error($file, 1, "locales.{$locale}.faq.{$index} must be object.");

                    continue;
                }
                $question = trim((string) ($faqItem['q'] ?? $faqItem['question'] ?? ''));
                $answer = trim((string) ($faqItem['a'] ?? $faqItem['answer'] ?? ''));
                if ($question === '') {
                    $errors[] = $this->error($file, 1, "locales.{$locale}.faq.{$index}.question missing.");
                }
                if ($answer === '') {
                    $errors[] = $this->error($file, 1, "locales.{$locale}.faq.{$index}.answer missing.");
                }
            }
        }
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintComplianceCopy(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('legal/disclaimer.json', $version);
        $doc = $this->loader->readJson($file);
        if (! is_array($doc)) {
            $errors[] = $this->error($file, 1, 'legal disclaimer json missing or invalid.');

            return;
        }

        $disclaimerVersion = trim((string) ($doc['disclaimer_version'] ?? ''));
        if ($disclaimerVersion === '') {
            $errors[] = $this->error($file, 1, 'disclaimer_version is required.');
        }

        $effectiveDate = trim((string) ($doc['effective_date'] ?? ''));
        if ($effectiveDate === '') {
            $errors[] = $this->error($file, 1, 'effective_date is required.');
        }

        $textsNode = is_array($doc['texts'] ?? null) ? $doc['texts'] : [];
        $texts = [
            'en' => trim((string) ($textsNode['en'] ?? '')),
            'zh-CN' => trim((string) ($textsNode['zh-CN'] ?? '')),
        ];
        foreach ($texts as $locale => $text) {
            if ($text === '') {
                $errors[] = $this->error($file, 1, "texts.{$locale} is required.");
            }
        }

        $requiredNode = is_array($doc['required_fragments'] ?? null) ? $doc['required_fragments'] : [];
        $prohibitedNode = is_array($doc['prohibited_terms'] ?? null) ? $doc['prohibited_terms'] : [];
        $requiredFragments = [
            'en' => $this->normalizeStringList($requiredNode['en'] ?? null),
            'zh-CN' => $this->normalizeStringList($requiredNode['zh-CN'] ?? null),
        ];
        $prohibitedTerms = [
            'en' => $this->normalizeStringList($prohibitedNode['en'] ?? null),
            'zh-CN' => $this->normalizeStringList($prohibitedNode['zh-CN'] ?? null),
        ];

        foreach (['en', 'zh-CN'] as $locale) {
            if ($requiredFragments[$locale] === []) {
                $errors[] = $this->error($file, 1, "required_fragments.{$locale} must not be empty.");
            }
            if ($prohibitedTerms[$locale] === []) {
                $errors[] = $this->error($file, 1, "prohibited_terms.{$locale} must not be empty.");
            }
        }

        $copyFile = $this->loader->rawPath('bucket_copy.csv', $version);
        $rows = $this->loader->readCsvWithLines($copyFile);
        $disclaimerCopy = [
            'en' => [],
            'zh-CN' => [],
        ];
        foreach ($rows as $entry) {
            $row = (array) ($entry['row'] ?? []);
            $section = strtolower(trim((string) ($row['section'] ?? '')));
            if (! in_array($section, ['disclaimer', 'disclaimer_top'], true)) {
                continue;
            }

            $locale = strtolower(trim((string) ($row['locale'] ?? '')));
            $bucketLocale = $locale === 'zh-cn' ? 'zh-CN' : ($locale === 'en' ? 'en' : '');
            if ($bucketLocale === '') {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));
            $body = trim((string) ($row['body'] ?? ''));
            $combined = trim($title.' '.$body);
            if ($combined !== '') {
                $disclaimerCopy[$bucketLocale][] = $combined;
            }
        }

        foreach (['en', 'zh-CN'] as $locale) {
            $copyText = mb_strtolower(implode("\n", $disclaimerCopy[$locale]));
            $legalText = mb_strtolower($texts[$locale]);

            foreach ($requiredFragments[$locale] as $fragment) {
                $needle = mb_strtolower($fragment);
                if ($needle === '') {
                    continue;
                }
                if (! str_contains($copyText, $needle) && ! str_contains($legalText, $needle)) {
                    $errors[] = $this->error($file, 1, "required compliance fragment missing for {$locale}: {$fragment}");
                }
            }

            foreach ($prohibitedTerms[$locale] as $term) {
                $needle = mb_strtolower($term);
                if ($needle === '') {
                    continue;
                }
                if (str_contains($copyText, $needle) || str_contains($legalText, $needle)) {
                    $errors[] = $this->error($file, 1, "prohibited compliance term found for {$locale}: {$term}");
                }
            }
        }
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintGoldenCases(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('golden_cases.csv', $version);
        $rows = $this->loader->readCsvWithLines($file);
        if ($rows === []) {
            $errors[] = $this->error($file, 1, 'golden_cases empty.');

            return;
        }

        foreach ($rows as $entry) {
            $line = (int) ($entry['line'] ?? 0);
            $row = (array) ($entry['row'] ?? []);

            if (trim((string) ($row['case_id'] ?? '')) === '') {
                $errors[] = $this->error($file, $line, 'case_id required.');
            }

            $answersJson = (string) ($row['answers_json'] ?? '');
            $answers = json_decode($answersJson, true);
            if (! is_array($answers) || count($answers) !== 120) {
                $errors[] = $this->error($file, $line, 'answers_json must decode to 120 answers.');
            }

            $expectedTags = json_decode((string) ($row['expected_tags_json'] ?? ''), true);
            if (! is_array($expectedTags)) {
                $errors[] = $this->error($file, $line, 'expected_tags_json must be json array.');
            }

            $expectedBuckets = json_decode((string) ($row['expected_domain_buckets_json'] ?? ''), true);
            if (! is_array($expectedBuckets)) {
                $errors[] = $this->error($file, $line, 'expected_domain_buckets_json must be json object.');
            }

            $status = strtoupper(trim((string) ($row['expected_norms_status'] ?? '')));
            if (! in_array($status, ['CALIBRATED', 'PROVISIONAL', 'MISSING'], true)) {
                $errors[] = $this->error($file, $line, 'expected_norms_status invalid.');
            }
        }
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintReportLayout(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('report_layout.json', $version);
        $doc = $this->loader->readJson($file);
        if (! is_array($doc)) {
            $errors[] = $this->error($file, 1, 'invalid json.');

            return;
        }

        $sections = is_array($doc['sections'] ?? null) ? $doc['sections'] : [];
        if ($sections === []) {
            $errors[] = $this->error($file, 1, 'sections must not be empty.');

            return;
        }
        $conflictRules = is_array($doc['conflict_rules'] ?? null) ? $doc['conflict_rules'] : [];
        $selector = strtolower(trim((string) ($conflictRules['selector'] ?? '')));
        if ($selector !== 'priority_desc_block_id_asc') {
            $errors[] = $this->error($file, 1, 'conflict_rules.selector must be priority_desc_block_id_asc.');
        }
        $exclusivePolicy = strtolower(trim((string) ($conflictRules['exclusive_group_policy'] ?? '')));
        if ($exclusivePolicy !== 'single_per_group') {
            $errors[] = $this->error($file, 1, 'conflict_rules.exclusive_group_policy must be single_per_group.');
        }

        $seenKeys = [];
        foreach ($sections as $index => $section) {
            if (! is_array($section)) {
                $errors[] = $this->error($file, 1, "sections[{$index}] must be object.");

                continue;
            }

            $key = strtolower(trim((string) ($section['key'] ?? '')));
            if ($key === '') {
                $errors[] = $this->error($file, 1, "sections[{$index}].key is required.");

                continue;
            }
            if (isset($seenKeys[$key])) {
                $errors[] = $this->error($file, 1, "duplicate section key: {$key}");
            }
            $seenKeys[$key] = true;

            $source = strtolower(trim((string) ($section['source'] ?? '')));
            if (! in_array($source, ['copy', 'blocks'], true)) {
                $errors[] = $this->error($file, 1, "sections[{$index}].source must be copy|blocks.");
            }

            $accessLevel = strtolower(trim((string) ($section['access_level'] ?? '')));
            if (! in_array($accessLevel, ['free', 'paid'], true)) {
                $errors[] = $this->error($file, 1, "sections[{$index}].access_level must be free|paid.");
            }

            if (trim((string) ($section['module_code'] ?? '')) === '') {
                $errors[] = $this->error($file, 1, "sections[{$index}].module_code is required.");
            }

            $requiredVariants = is_array($section['required_in_variant'] ?? null) ? $section['required_in_variant'] : [];
            if ($requiredVariants === []) {
                $errors[] = $this->error($file, 1, "sections[{$index}].required_in_variant must not be empty.");
            }
            foreach ($requiredVariants as $variant) {
                $variant = strtolower(trim((string) $variant));
                if (! in_array($variant, ['free', 'full'], true)) {
                    $errors[] = $this->error($file, 1, "sections[{$index}].required_in_variant contains invalid value.");
                }
            }

            $minBlocks = (int) ($section['min_blocks'] ?? -1);
            $maxBlocks = (int) ($section['max_blocks'] ?? -1);
            if ($minBlocks < 0 || $maxBlocks < 0 || $maxBlocks < $minBlocks) {
                $errors[] = $this->error($file, 1, "sections[{$index}] min_blocks/max_blocks invalid.");
            }

            if (strtolower(trim((string) ($section['source'] ?? ''))) === 'blocks') {
                $exclusiveGroups = is_array($section['exclusive_groups'] ?? null) ? $section['exclusive_groups'] : [];
                if ($exclusiveGroups === []) {
                    $errors[] = $this->error($file, 1, "sections[{$index}].exclusive_groups must not be empty for blocks source.");
                }
            }
        }

        foreach (self::LAYOUT_REQUIRED_SECTIONS as $requiredKey) {
            if (! isset($seenKeys[$requiredKey])) {
                $errors[] = $this->error($file, 1, "required section missing in layout: {$requiredKey}");
            }
        }
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintBlocks(string $version, array &$errors): void
    {
        $blocksByFile = $this->loadRawBlocks($version);
        if ($blocksByFile === []) {
            $errors[] = $this->error($this->loader->rawPath('blocks', $version), 1, 'blocks directory is empty.');

            return;
        }

        foreach ($blocksByFile as $file => $blocks) {
            if ($blocks === []) {
                $errors[] = $this->error($file, 1, 'blocks must not be empty.');

                continue;
            }

            foreach ($blocks as $index => $block) {
                if (! is_array($block)) {
                    $errors[] = $this->error($file, 1, "blocks[{$index}] must be object.");

                    continue;
                }

                foreach (['block_id', 'section', 'kind', 'access_level', 'module_code', 'locale', 'title', 'body'] as $requiredKey) {
                    if (trim((string) ($block[$requiredKey] ?? '')) === '') {
                        $errors[] = $this->error($file, 1, "blocks[{$index}].{$requiredKey} is required.");
                    }
                }

                $accessLevel = strtolower(trim((string) ($block['access_level'] ?? '')));
                if (! in_array($accessLevel, ['free', 'paid'], true)) {
                    $errors[] = $this->error($file, 1, "blocks[{$index}].access_level must be free|paid.");
                }

                $locale = strtolower(trim((string) ($block['locale'] ?? '')));
                if (! in_array($locale, ['en', 'zh-cn'], true)) {
                    $errors[] = $this->error($file, 1, "blocks[{$index}].locale must be en|zh-CN.");
                }

                foreach (['title', 'body'] as $templateField) {
                    $template = (string) ($block[$templateField] ?? '');
                    if ($template === '') {
                        continue;
                    }
                    $lint = $this->templateEngine->lintString($template, null);
                    $unknown = is_array($lint['unknown'] ?? null) ? $lint['unknown'] : [];
                    if ($unknown !== []) {
                        $errors[] = $this->error($file, 1, "blocks[{$index}].{$templateField} has unknown variables: ".implode(', ', $unknown));
                    }
                }
            }
        }
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintBlocksCoverage(string $version, array &$errors): void
    {
        $blocksByFile = $this->loadRawBlocks($version);
        if ($blocksByFile === []) {
            return;
        }

        $domainTriples = [];
        $facetTriples = [];
        $facetTableCodes = [];
        foreach ($blocksByFile as $blocks) {
            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $section = strtolower(trim((string) ($block['section'] ?? '')));
                $metricLevel = strtolower(trim((string) ($block['metric_level'] ?? '')));
                $metricCode = strtoupper(trim((string) ($block['metric_code'] ?? '')));
                $bucket = strtolower(trim((string) ($block['bucket'] ?? '')));

                if ($section === 'domains_overview' && $metricLevel === 'domain' && in_array($metricCode, self::DOMAINS, true) && in_array($bucket, ['low', 'mid', 'high'], true)) {
                    $domainTriples[$metricCode.':'.$bucket] = true;
                }
                if ($section === 'facets_deepdive' && $metricLevel === 'facet' && in_array($metricCode, self::FACETS, true) && in_array($bucket, ['low', 'mid', 'high'], true)) {
                    $facetTriples[$metricCode.':'.$bucket] = true;
                }
                if ($section === 'facet_table' && $metricLevel === 'facet' && in_array($metricCode, self::FACETS, true)) {
                    $facetTableCodes[$metricCode] = true;
                }
            }
        }

        if (count($domainTriples) !== 15) {
            $errors[] = $this->error($this->loader->rawPath('blocks', $version), 1, 'domain blocks coverage must be 15/15.');
        }
        if (count($facetTriples) !== 90) {
            $errors[] = $this->error($this->loader->rawPath('blocks', $version), 1, 'facet blocks coverage must be 90/90.');
        }
        if (count($facetTableCodes) !== 30) {
            $errors[] = $this->error($this->loader->rawPath('blocks', $version), 1, 'facet_table must cover 30/30 facets.');
        }
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintBlockConflictRules(string $version, array &$errors): void
    {
        $blocksByFile = $this->loadRawBlocks($version);
        if ($blocksByFile === []) {
            return;
        }

        $seenBlockIds = [];
        $selectorGroups = [];
        foreach ($blocksByFile as $file => $blocks) {
            foreach ($blocks as $index => $block) {
                if (! is_array($block)) {
                    continue;
                }

                $blockId = trim((string) ($block['block_id'] ?? ''));
                if ($blockId !== '') {
                    if (isset($seenBlockIds[$blockId])) {
                        $errors[] = $this->error($file, 1, "duplicate block_id detected: {$blockId}");
                    }
                    $seenBlockIds[$blockId] = $file;
                }

                $section = strtolower(trim((string) ($block['section'] ?? '')));
                $locale = strtolower(trim((string) ($block['locale'] ?? '')));
                $accessLevel = strtolower(trim((string) ($block['access_level'] ?? '')));
                $metricCode = strtoupper(trim((string) ($block['metric_code'] ?? '')));
                $bucket = strtolower(trim((string) ($block['bucket'] ?? '')));
                $selectorKey = implode('|', [$section, $locale, $accessLevel, $metricCode, $bucket]);

                $selectorGroups[$selectorKey][] = [
                    'file' => $file,
                    'index' => $index,
                    'priority' => (int) ($block['priority'] ?? 0),
                    'exclusive_group' => trim((string) ($block['exclusive_group'] ?? '')),
                ];
            }
        }

        foreach ($selectorGroups as $selectorKey => $items) {
            if (count($items) <= 1) {
                continue;
            }

            $prioritySeen = [];
            foreach ($items as $item) {
                $exclusiveGroup = (string) ($item['exclusive_group'] ?? '');
                if ($exclusiveGroup === '') {
                    $errors[] = $this->error(
                        (string) ($item['file'] ?? ''),
                        1,
                        "selector conflict requires exclusive_group: {$selectorKey}"
                    );
                }

                $priority = (int) ($item['priority'] ?? 0);
                if (isset($prioritySeen[$priority])) {
                    $errors[] = $this->error(
                        (string) ($item['file'] ?? ''),
                        1,
                        "selector conflict has duplicate priority={$priority}: {$selectorKey}"
                    );
                }
                $prioritySeen[$priority] = true;
            }
        }
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintLayoutSatisfiability(string $version, array &$errors): void
    {
        $layoutPath = $this->loader->rawPath('report_layout.json', $version);
        $layoutDoc = $this->loader->readJson($layoutPath);
        if (! is_array($layoutDoc)) {
            return;
        }

        $sections = is_array($layoutDoc['sections'] ?? null) ? $layoutDoc['sections'] : [];
        if ($sections === []) {
            return;
        }

        $blocksByFile = $this->loadRawBlocks($version);
        $allBlocks = [];
        foreach ($blocksByFile as $blocks) {
            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $allBlocks[] = $block;
            }
        }

        $counts = [];
        foreach ($allBlocks as $block) {
            $section = strtolower(trim((string) ($block['section'] ?? '')));
            $locale = strtolower(trim((string) ($block['locale'] ?? '')));
            if ($section === '' || $locale === '') {
                continue;
            }
            $counts[$section][$locale] = ($counts[$section][$locale] ?? 0) + 1;
        }

        foreach ($sections as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            $source = strtolower(trim((string) ($section['source'] ?? '')));
            if ($source !== 'blocks') {
                continue;
            }

            $key = strtolower(trim((string) ($section['key'] ?? '')));
            if ($key === '') {
                continue;
            }
            $minBlocks = max(0, (int) ($section['min_blocks'] ?? 0));

            foreach (['en', 'zh-cn'] as $locale) {
                $available = (int) ($counts[$key][$locale] ?? 0);
                if ($available < $minBlocks) {
                    $errors[] = $this->error(
                        $layoutPath,
                        1,
                        "sections[{$index}] ({$key}) unsatisfied for locale={$locale}: min={$minBlocks}, available={$available}"
                    );
                }
            }
        }
    }

    /**
     * @return array<string,list<array<string,mixed>>>
     */
    private function loadRawBlocks(string $version): array
    {
        $dir = $this->loader->rawPath('blocks', $version);
        if (! is_dir($dir)) {
            return [];
        }

        $files = glob($dir.DIRECTORY_SEPARATOR.'*.json');
        if (! is_array($files) || $files === []) {
            return [];
        }
        sort($files);

        $out = [];
        foreach ($files as $file) {
            $doc = $this->loader->readJson($file);
            if (! is_array($doc)) {
                $out[$file] = [];

                continue;
            }
            $blocks = is_array($doc['blocks'] ?? null) ? $doc['blocks'] : [];
            $normalized = [];
            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $normalized[] = $block;
            }
            $out[$file] = $normalized;
        }

        return $out;
    }

    /**
     * @return array{file:string,line:int,message:string}
     */
    private function error(string $file, int $line, string $message): array
    {
        return [
            'file' => $file,
            'line' => $line,
            'message' => $message,
        ];
    }

    private function normalizeVersion(?string $version): string
    {
        $version = trim((string) $version);

        return $version !== '' ? $version : BigFivePackLoader::PACK_VERSION;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $text = trim((string) $item);
            if ($text === '') {
                continue;
            }
            $out[] = $text;
        }

        return array_values(array_unique($out));
    }
}
