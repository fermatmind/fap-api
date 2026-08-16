<?php

declare(strict_types=1);

namespace App\Services\Content;

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

    public function __construct(private readonly BigFivePackLoader $loader) {}

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
        $this->lintVariablesAllowlist($version, $errors);

        return [
            'ok' => $errors === [],
            'pack_id' => BigFivePackLoader::PACK_ID,
            'version' => $version,
            'errors' => $errors,
        ];
    }

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
            if ($qid < 1 || $qid > 120 || isset($seen[$qid])) {
                $errors[] = $this->error($file, $line, 'question_id must be unique in 1..120.');
            }
            $seen[$qid] = true;

            if (! in_array(strtoupper((string) ($row['dimension'] ?? '')), self::DOMAINS, true)) {
                $errors[] = $this->error($file, $line, 'dimension must be one of O/C/E/A/N.');
            }
            if (! in_array((int) ($row['direction'] ?? 0), [1, -1], true)) {
                $errors[] = $this->error($file, $line, 'direction must be 1 or -1.');
            }
            if (trim((string) ($row['text_zh'] ?? '')) === '' || trim((string) ($row['text_en'] ?? '')) === '') {
                $errors[] = $this->error($file, $line, 'question text must exist in zh-CN and en.');
            }
        }
    }

    private function lintFacetMap(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('facet_map.csv', $version);
        $rows = $this->loader->readCsvWithLines($file);
        if (count($rows) !== 120) {
            $errors[] = $this->error($file, 1, 'facet_map rows must be exactly 120.');
        }

        $questions = [];
        $facets = [];
        $domains = [];
        foreach ($rows as $entry) {
            $line = (int) ($entry['line'] ?? 0);
            $row = (array) ($entry['row'] ?? []);
            $qid = (int) ($row['question_id'] ?? 0);
            $facet = strtoupper(trim((string) ($row['facet_code'] ?? '')));
            $domain = strtoupper(trim((string) ($row['domain_code'] ?? '')));
            if ($qid < 1 || $qid > 120 || isset($questions[$qid])) {
                $errors[] = $this->error($file, $line, 'question_id must map exactly once.');
            }
            $questions[$qid] = true;
            if (! in_array($facet, self::FACETS, true) || ! in_array($domain, self::DOMAINS, true) || $domain !== ($facet[0] ?? '')) {
                $errors[] = $this->error($file, $line, 'facet/domain mapping is invalid.');
            }
            $facets[$facet] = ($facets[$facet] ?? 0) + 1;
            $domains[$domain] = ($domains[$domain] ?? 0) + 1;
        }

        foreach (self::FACETS as $facet) {
            if (($facets[$facet] ?? 0) !== 4) {
                $errors[] = $this->error($file, 1, "facet {$facet} must map exactly 4 items.");
            }
        }
        foreach (self::DOMAINS as $domain) {
            if (($domains[$domain] ?? 0) !== 24) {
                $errors[] = $this->error($file, 1, "domain {$domain} must map exactly 24 items.");
            }
        }
    }

    private function lintOptions(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('options_likert5.csv', $version);
        $rows = $this->loader->readCsvWithLines($file);
        $scores = [];
        foreach ($rows as $entry) {
            $score = (int) data_get($entry, 'row.score', 0);
            $scores[$score] = true;
        }
        if (count($rows) !== 5 || array_keys($scores) !== [1, 2, 3, 4, 5]) {
            $errors[] = $this->error($file, 1, 'options must define scores 1..5 exactly once.');
        }
    }

    private function lintPolicy(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('policy.json', $version);
        $policy = $this->loader->readJson($file);
        foreach (['answer_scale', 'percentile_buckets', 'norm_fallback', 'validity_checks', 'validity_items'] as $key) {
            if (! is_array($policy[$key] ?? null)) {
                $errors[] = $this->error($file, 1, "{$key} missing.");
            }
        }
    }

    private function lintSources(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('source_catalog.csv', $version);
        $rows = $this->loader->readCsvWithLines($file);
        if ($rows === []) {
            $errors[] = $this->error($file, 1, 'source_catalog empty.');
        }
        foreach ($rows as $entry) {
            foreach (['source_id', 'scope', 'locale', 'name'] as $key) {
                if (trim((string) data_get($entry, 'row.'.$key, '')) === '') {
                    $errors[] = $this->error($file, (int) ($entry['line'] ?? 0), "{$key} is required.");
                }
            }
        }
    }

    private function lintNormStats(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('norm_stats.csv', $version);
        $rows = $this->loader->readCsvWithLines($file);
        $coverage = [];
        foreach ($rows as $entry) {
            $line = (int) ($entry['line'] ?? 0);
            $row = (array) ($entry['row'] ?? []);
            $group = strtolower(trim((string) ($row['group_id'] ?? '')));
            $level = strtolower(trim((string) ($row['metric_level'] ?? '')));
            $code = strtoupper(trim((string) ($row['metric_code'] ?? '')));
            if ($group === '' || ! in_array($level, ['domain', 'facet'], true) || $code === '') {
                $errors[] = $this->error($file, $line, 'norm identity is invalid.');

                continue;
            }
            if ((float) ($row['mean'] ?? 0) < 1 || (float) ($row['mean'] ?? 0) > 5 || (float) ($row['sd'] ?? 0) <= 0 || (int) ($row['sample_n'] ?? 0) <= 0) {
                $errors[] = $this->error($file, $line, 'norm statistics are invalid.');
            }
            $coverage[$group][$level][$code] = true;
        }

        foreach ((array) config('big5_norms.resolver.required_groups', []) as $requiredGroup) {
            $group = strtolower(trim((string) $requiredGroup));
            if (count((array) ($coverage[$group]['domain'] ?? [])) !== 5 || count((array) ($coverage[$group]['facet'] ?? [])) !== 30) {
                $errors[] = $this->error($file, 1, "{$requiredGroup} must cover 5 domains and 30 facets.");
            }
        }
    }

    private function lintVariablesAllowlist(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('variables_allowlist.json', $version);
        $allowlist = $this->loader->readJson($file);
        if (! is_array($allowlist) || $allowlist === []) {
            $errors[] = $this->error($file, 1, 'variables allowlist missing or empty.');
        }
    }

    /** @return array{file:string,line:int,message:string} */
    private function error(string $file, int $line, string $message): array
    {
        return compact('file', 'line', 'message');
    }

    private function normalizeVersion(?string $version): string
    {
        $version = trim((string) $version);

        return $version !== '' ? $version : BigFivePackLoader::PACK_VERSION;
    }
}
