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

    public function __construct(
        private readonly BigFivePackLoader $loader,
        private readonly TemplateEngine $templateEngine,
        private readonly TemplateVariableRegistry $templateVariableRegistry,
    ) {
    }

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
     * @param list<array{file:string,line:int,message:string}> $errors
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
            if (!in_array($dimension, self::DOMAINS, true)) {
                $errors[] = $this->error($file, $line, 'dimension must be one of O/C/E/A/N.');
            }

            $direction = (int) ($row['direction'] ?? 0);
            if (!in_array($direction, [1, -1], true)) {
                $errors[] = $this->error($file, $line, 'direction must be 1 or -1.');
            }
        }

        for ($i = 1; $i <= 120; $i++) {
            if (!isset($seen[$i])) {
                $errors[] = $this->error($file, 1, "missing question_id={$i}");
            }
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
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
            if (!in_array($facet, self::FACETS, true)) {
                $errors[] = $this->error($file, $line, 'facet_code invalid.');
                continue;
            }
            $facetCount[$facet] = ($facetCount[$facet] ?? 0) + 1;

            $domain = strtoupper((string) ($row['domain_code'] ?? ''));
            if (!in_array($domain, self::DOMAINS, true)) {
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
     * @param list<array{file:string,line:int,message:string}> $errors
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
            if (!isset($seen[$i])) {
                $errors[] = $this->error($file, 1, "score={$i} missing.");
            }
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintPolicy(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('policy.json', $version);
        $doc = $this->loader->readJson($file);
        if (!is_array($doc)) {
            $errors[] = $this->error($file, 1, 'invalid json.');
            return;
        }

        foreach (['low', 'mid', 'high'] as $bucket) {
            if (!is_array($doc['percentile_buckets'][$bucket] ?? null)) {
                $errors[] = $this->error($file, 1, "percentile_buckets.{$bucket} missing.");
            }
        }

        if (!is_array($doc['norm_fallback'] ?? null)) {
            $errors[] = $this->error($file, 1, 'norm_fallback missing.');
        }

        if (!is_array($doc['validity_checks'] ?? null)) {
            $errors[] = $this->error($file, 1, 'validity_checks missing.');
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
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

        if (!$hasGlobal) {
            $errors[] = $this->error($file, 1, 'must contain at least one GLOBAL source.');
        }
        if (!$hasZh) {
            $errors[] = $this->error($file, 1, 'must contain at least one zh-CN source.');
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
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

        $this->assertNormGroupCoverage($file, $coverage, 'en_johnson_all_18-60', true, $errors);
        $this->assertNormGroupCoverage($file, $coverage, 'zh-CN_prod_all_18-60', true, $errors);
    }

    /**
     * @param array<string,array<string,array<string,bool>>> $coverage
     * @param list<array{file:string,line:int,message:string}> $errors
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
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintBucketCopy(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('bucket_copy.csv', $version);
        $rows = $this->loader->readCsvWithLines($file);
        if ($rows === []) {
            $errors[] = $this->error($file, 1, 'bucket_copy empty.');
            return;
        }

        $domainCoverage = [];
        $facetCoverage = [];
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
            if ($metricLevel === 'facet' && in_array($metricCode, self::FACETS, true) && in_array($bucket, ['low', 'high'], true)) {
                $facetCoverage[$metricCode][$bucket] = true;
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
                    $errors[] = $this->error($file, $line, 'unknown template variables: ' . implode(', ', $unknown));
                }
            }
        }

        foreach (['zh-cn', 'en'] as $locale) {
            foreach (self::DOMAINS as $domain) {
                foreach (['low', 'mid', 'high'] as $bucket) {
                    if (!isset($domainCoverage[$locale][$domain][$bucket])) {
                        $errors[] = $this->error($file, 1, "domain copy missing: locale={$locale}, domain={$domain}, bucket={$bucket}");
                    }
                }
            }
        }

        foreach (self::FACETS as $facet) {
            foreach (['low', 'high'] as $bucket) {
                if (!isset($facetCoverage[$facet][$bucket])) {
                    $errors[] = $this->error($file, 1, "facet copy missing: facet={$facet}, bucket={$bucket}");
                }
            }
        }

        foreach ($this->templateVariableRegistry->requiredVariables() as $requiredVar) {
            if (!isset($varsUsed[$requiredVar])) {
                $errors[] = $this->error($file, 1, "required template variable missing from bucket_copy: {$requiredVar}");
            }
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintLanding(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('landing_i18n.json', $version);
        $doc = $this->loader->readJson($file);
        if (!is_array($doc)) {
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
            if (!is_array($node)) {
                $errors[] = $this->error($file, 1, "locales.{$locale} missing.");
                continue;
            }
            if (trim((string) ($node['seo_title'] ?? '')) === '') {
                $errors[] = $this->error($file, 1, "locales.{$locale}.seo_title missing.");
            }
            if (trim((string) ($node['seo_description'] ?? '')) === '') {
                $errors[] = $this->error($file, 1, "locales.{$locale}.seo_description missing.");
            }
            if (!is_array($node['faq'] ?? null)) {
                $errors[] = $this->error($file, 1, "locales.{$locale}.faq must be array.");
            }
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
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
            if (!is_array($answers) || count($answers) !== 120) {
                $errors[] = $this->error($file, $line, 'answers_json must decode to 120 answers.');
            }

            $expectedTags = json_decode((string) ($row['expected_tags_json'] ?? ''), true);
            if (!is_array($expectedTags)) {
                $errors[] = $this->error($file, $line, 'expected_tags_json must be json array.');
            }

            $expectedBuckets = json_decode((string) ($row['expected_domain_buckets_json'] ?? ''), true);
            if (!is_array($expectedBuckets)) {
                $errors[] = $this->error($file, $line, 'expected_domain_buckets_json must be json object.');
            }

            $status = strtoupper(trim((string) ($row['expected_norms_status'] ?? '')));
            if (!in_array($status, ['CALIBRATED', 'PROVISIONAL', 'MISSING'], true)) {
                $errors[] = $this->error($file, $line, 'expected_norms_status invalid.');
            }
        }
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
}
