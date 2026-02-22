<?php

declare(strict_types=1);

namespace App\Services\Content;

final class Sds20ContentLintService
{
    private const REQUIRED_QUESTION_COUNT = 20;

    /**
     * @var list<string>
     */
    private const REQUIRED_FACTOR_KEYS = [
        'psycho_affective',
        'somatic',
        'psychomotor',
        'cognitive',
    ];

    /**
     * @var list<string>
     */
    private const REQUIRED_LAYOUT_SECTIONS = [
        'disclaimer_top',
        'crisis_banner',
        'result_summary_free',
        'paid_deep_dive',
    ];

    public function __construct(
        private readonly Sds20PackLoader $loader,
    ) {
    }

    /**
     * @return array{ok:bool,pack_id:string,version:string,errors:list<array{file:string,line:int,message:string}>}
     */
    public function lint(?string $version = null): array
    {
        $version = $this->loader->normalizeVersion($version);
        $errors = [];

        $this->lintQuestions($version, $errors);
        $this->lintOptions($version, $errors);
        $this->lintPolicy($version, $errors);
        $this->lintLanding($version, $errors);
        $this->lintReportLayout($version, $errors);
        $this->lintBlocks($version, $errors);
        $this->lintGoldenCases($version, $errors);

        return [
            'ok' => $errors === [],
            'pack_id' => Sds20PackLoader::PACK_ID,
            'version' => $version,
            'errors' => $errors,
        ];
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintQuestions(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('questions_sds20_bilingual.csv', $version);
        $rows = $this->loader->readCsvWithLines($file);
        if (count($rows) !== self::REQUIRED_QUESTION_COUNT) {
            $errors[] = $this->error($file, 1, 'questions must contain exactly 20 rows.');
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
            $seen[$qid] = ($seen[$qid] ?? 0) + 1;

            $direction = (int) ($row['direction'] ?? 0);
            if (!in_array($direction, [1, -1], true)) {
                $errors[] = $this->error($file, $line, 'direction must be 1 or -1.');
            }

            if (trim((string) ($row['text_en'] ?? '')) === '') {
                $errors[] = $this->error($file, $line, 'text_en is required.');
            }
            if (trim((string) ($row['text_zh'] ?? '')) === '') {
                $errors[] = $this->error($file, $line, 'text_zh is required.');
            }
        }

        for ($qid = 1; $qid <= self::REQUIRED_QUESTION_COUNT; $qid++) {
            if (($seen[$qid] ?? 0) !== 1) {
                $errors[] = $this->error($file, 1, 'question_id='.$qid.' must appear exactly once.');
            }
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintOptions(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('options_sds20_bilingual.json', $version);
        $doc = $this->loader->readJson($file);
        if (!is_array($doc)) {
            $errors[] = $this->error($file, 1, 'options json invalid.');
            return;
        }

        $codes = array_values(array_map(
            static fn ($code): string => strtoupper(trim((string) $code)),
            (array) ($doc['codes'] ?? [])
        ));
        if ($codes !== ['A', 'B', 'C', 'D']) {
            $errors[] = $this->error($file, 1, 'codes must be [A,B,C,D].');
        }

        $labels = is_array($doc['labels'] ?? null) ? $doc['labels'] : [];
        foreach (['zh-CN', 'en'] as $locale) {
            $format = $labels[$locale] ?? null;
            if (!is_array($format) || count($format) !== 4) {
                $errors[] = $this->error($file, 1, 'labels.'.$locale.' must contain exactly 4 options.');
                continue;
            }

            foreach ($format as $idx => $label) {
                if (trim((string) $label) === '') {
                    $errors[] = $this->error($file, 1, 'labels.'.$locale.'['.$idx.'] is required.');
                }
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
            $errors[] = $this->error($file, 1, 'policy.json invalid.');
            return;
        }

        foreach (['engine_version', 'scoring_spec_version', 'quality_rules', 'crisis_rules', 'factor_map', 'clinical_buckets'] as $key) {
            if (!array_key_exists($key, $doc)) {
                $errors[] = $this->error($file, 1, 'policy field missing: '.$key);
            }
        }

        $engineVersion = trim((string) ($doc['engine_version'] ?? ''));
        if ($engineVersion !== 'v2.0_Factor_Logic') {
            $errors[] = $this->error($file, 1, 'engine_version must be v2.0_Factor_Logic.');
        }

        $speeding = (int) data_get($doc, 'quality_rules.speeding_seconds_lt', 0);
        $straightline = (int) data_get($doc, 'quality_rules.straightlining_run_len_gte', 0);
        if ($speeding <= 0) {
            $errors[] = $this->error($file, 1, 'quality_rules.speeding_seconds_lt must be > 0.');
        }
        if ($straightline <= 0) {
            $errors[] = $this->error($file, 1, 'quality_rules.straightlining_run_len_gte must be > 0.');
        }

        $crisisQid = (int) data_get($doc, 'crisis_rules.item_id', 0);
        $crisisThreshold = (int) data_get($doc, 'crisis_rules.mapped_score_gte', 0);
        if ($crisisQid < 1 || $crisisQid > self::REQUIRED_QUESTION_COUNT) {
            $errors[] = $this->error($file, 1, 'crisis_rules.item_id must be in 1..20.');
        }
        if ($crisisThreshold < 1 || $crisisThreshold > 4) {
            $errors[] = $this->error($file, 1, 'crisis_rules.mapped_score_gte must be in 1..4.');
        }

        $factorMap = is_array($doc['factor_map'] ?? null) ? $doc['factor_map'] : [];
        $allFactorIds = [];
        foreach (self::REQUIRED_FACTOR_KEYS as $factorKey) {
            $items = $factorMap[$factorKey] ?? null;
            if (!is_array($items) || $items === []) {
                $errors[] = $this->error($file, 1, 'factor_map.'.$factorKey.' missing or empty.');
                continue;
            }

            foreach ($items as $qidRaw) {
                $qid = (int) $qidRaw;
                if ($qid < 1 || $qid > self::REQUIRED_QUESTION_COUNT) {
                    $errors[] = $this->error($file, 1, 'factor_map.'.$factorKey.' contains invalid question id.');
                    continue;
                }
                $allFactorIds[] = $qid;
            }
        }

        sort($allFactorIds);
        if ($allFactorIds !== range(1, self::REQUIRED_QUESTION_COUNT)) {
            $errors[] = $this->error($file, 1, 'factor_map must cover question ids 1..20 exactly once.');
        }

        $buckets = is_array($doc['clinical_buckets'] ?? null) ? $doc['clinical_buckets'] : [];
        if ($buckets === []) {
            $errors[] = $this->error($file, 1, 'clinical_buckets missing.');
            return;
        }

        usort($buckets, static fn (array $a, array $b): int => ((int) ($a['min'] ?? 0)) <=> ((int) ($b['min'] ?? 0)));

        $cursor = 0;
        foreach ($buckets as $bucket) {
            if (!is_array($bucket)) {
                $errors[] = $this->error($file, 1, 'clinical bucket item must be object.');
                continue;
            }

            $code = trim((string) ($bucket['code'] ?? ''));
            $min = (int) ($bucket['min'] ?? -1);
            $max = (int) ($bucket['max'] ?? -1);
            if ($code === '') {
                $errors[] = $this->error($file, 1, 'clinical bucket code required.');
            }
            if ($min > $max) {
                $errors[] = $this->error($file, 1, 'clinical bucket min must be <= max.');
            }
            if ($min !== $cursor) {
                $errors[] = $this->error($file, 1, 'clinical buckets must be contiguous from 0 to 100.');
            }
            $cursor = $max + 1;
        }

        if ($cursor - 1 !== 100) {
            $errors[] = $this->error($file, 1, 'clinical buckets must end at 100.');
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
            $errors[] = $this->error($file, 1, 'landing_i18n.json invalid.');
            return;
        }

        foreach (['zh-CN', 'en'] as $locale) {
            $node = is_array($doc[$locale] ?? null) ? $doc[$locale] : null;
            if (!is_array($node)) {
                $errors[] = $this->error($file, 1, 'landing node missing for '.$locale);
                continue;
            }

            $consentText = trim((string) data_get($node, 'consent.text', ''));
            $consentVersion = trim((string) data_get($node, 'consent.version', ''));
            $disclaimerText = trim((string) data_get($node, 'disclaimer.text', ''));
            $disclaimerVersion = trim((string) data_get($node, 'disclaimer.version', ''));
            $crisisHotline = trim((string) ($node['crisis_hotline'] ?? ''));

            if ($consentText === '' || $consentVersion === '') {
                $errors[] = $this->error($file, 1, 'consent text/version missing for '.$locale);
            }
            if ($disclaimerText === '' || $disclaimerVersion === '') {
                $errors[] = $this->error($file, 1, 'disclaimer text/version missing for '.$locale);
            }
            if ($crisisHotline === '') {
                $errors[] = $this->error($file, 1, 'crisis_hotline missing for '.$locale);
            }
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintReportLayout(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('report_layout.json', $version);
        $doc = $this->loader->readJson($file);
        if (!is_array($doc)) {
            $errors[] = $this->error($file, 1, 'report_layout.json invalid.');
            return;
        }

        $layout = is_array($doc['layout'] ?? null) ? $doc['layout'] : $doc;
        $sections = is_array($layout['sections'] ?? null) ? $layout['sections'] : [];
        if ($sections === []) {
            $errors[] = $this->error($file, 1, 'layout.sections cannot be empty.');
            return;
        }

        $keys = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $key = trim((string) ($section['key'] ?? ''));
            if ($key === '') {
                $errors[] = $this->error($file, 1, 'section key is required.');
                continue;
            }
            $keys[] = $key;
        }

        if ($keys !== self::REQUIRED_LAYOUT_SECTIONS) {
            $errors[] = $this->error($file, 1, 'layout sections order must be disclaimer_top -> crisis_banner -> result_summary_free -> paid_deep_dive.');
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintBlocks(string $version, array &$errors): void
    {
        $checks = [
            ['file' => 'blocks/free_blocks.json', 'section' => 'result_summary_free'],
            ['file' => 'blocks/paid_blocks.json', 'section' => 'paid_deep_dive'],
        ];

        foreach ($checks as $check) {
            $file = $this->loader->rawPath((string) $check['file'], $version);
            $expectedSection = (string) $check['section'];

            $doc = $this->loader->readJson($file);
            if (!is_array($doc)) {
                $errors[] = $this->error($file, 1, 'block file invalid json.');
                continue;
            }

            foreach (['zh-CN', 'en'] as $locale) {
                $rows = $doc[$locale] ?? null;
                if (!is_array($rows) || $rows === []) {
                    $errors[] = $this->error($file, 1, $locale.' blocks cannot be empty.');
                    continue;
                }

                foreach ($rows as $idx => $row) {
                    if (!is_array($row)) {
                        $errors[] = $this->error($file, 1, $locale.' block row must be object.');
                        continue;
                    }

                    $line = $idx + 1;
                    $blockId = trim((string) ($row['block_id'] ?? ''));
                    $sectionKey = trim((string) ($row['section_key'] ?? ''));
                    $title = trim((string) ($row['title'] ?? ''));
                    $body = trim((string) ($row['body_md'] ?? ''));

                    if ($blockId === '' || $title === '' || $body === '') {
                        $errors[] = $this->error($file, $line, 'block_id/title/body_md are required.');
                    }
                    if ($sectionKey !== $expectedSection) {
                        $errors[] = $this->error($file, $line, 'section_key must be '.$expectedSection);
                    }
                }
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
        if (count($rows) < 6) {
            $errors[] = $this->error($file, 1, 'golden_cases requires at least 6 rows.');
        }

        foreach ($rows as $entry) {
            $line = (int) ($entry['line'] ?? 0);
            $row = (array) ($entry['row'] ?? []);

            $caseId = trim((string) ($row['case_id'] ?? ''));
            $locale = trim((string) ($row['locale'] ?? ''));
            $answers = strtoupper(trim((string) ($row['answers'] ?? '')));
            $durationMs = (int) ($row['duration_ms'] ?? 0);
            $clinical = trim((string) ($row['expected_clinical_level'] ?? ''));
            $qualityLevel = strtoupper(trim((string) ($row['expected_quality_level'] ?? '')));

            if ($caseId === '') {
                $errors[] = $this->error($file, $line, 'case_id is required.');
            }
            if (!in_array($locale, ['zh-CN', 'en'], true)) {
                $errors[] = $this->error($file, $line, 'locale must be zh-CN or en.');
            }
            if (strlen($answers) !== self::REQUIRED_QUESTION_COUNT || preg_match('/^[ABCD]+$/', $answers) !== 1) {
                $errors[] = $this->error($file, $line, 'answers must be 20 chars in [A-D].');
            }
            if ($durationMs <= 0) {
                $errors[] = $this->error($file, $line, 'duration_ms must be > 0.');
            }
            if (!in_array($clinical, ['normal', 'mild_depression', 'moderate_depression', 'severe_depression'], true)) {
                $errors[] = $this->error($file, $line, 'expected_clinical_level invalid.');
            }
            if (!in_array($qualityLevel, ['A', 'B', 'C', 'D'], true)) {
                $errors[] = $this->error($file, $line, 'expected_quality_level must be A/B/C/D.');
            }

            $this->assertBooleanColumn($file, $line, $row, 'expected_crisis_alert', $errors);
            $this->assertBooleanColumn($file, $line, $row, 'expected_has_somatic_exhaustion_mask', $errors);
        }
    }

    /**
     * @param array<string,string> $row
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function assertBooleanColumn(string $file, int $line, array $row, string $column, array &$errors): void
    {
        $value = trim((string) ($row[$column] ?? ''));
        if (!in_array($value, ['0', '1'], true)) {
            $errors[] = $this->error($file, $line, $column.' must be 0 or 1.');
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
}
